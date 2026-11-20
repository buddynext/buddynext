<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Jetonomy bridge.
 *
 * Routes Jetonomy events into BuddyNext surfaces:
 *
 * - Discussion created → bn_search_index (type: discussion) + @mention parsing
 * - Discussion created → BN feed activity (engagement; link card to Jetonomy,
 *   via Feed\IntegrationActivity; filter buddynext_jetonomy_discussion_activity)
 * - Discussion deleted → removes the search entry + the feed activity
 * - Reply / mention / accepted-answer notifications reach the bell through Jetonomy's
 *   community notification contract (IntegrationNotificationListener); Jetonomy owns
 *   the text, the visibility rule and the email, so BN never emails on its behalf
 * - Nav: a Discussions rail item (buddynext_rail_items) and a discussion context nav
 *   (buddynext_context_nav)
 * - Space Discussions tab (linked or on-demand forum) + profile Discussions count
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

defined( 'ABSPATH' ) || exit;

use BuddyNext\Auth\MemberHold;
use BuddyNext\Auth\RestHoldGate;
use BuddyNext\Core\RestRoute;
use BuddyNext\Profile\Handle;
use BuddyNext\Search\SearchService;
use BuddyNext\Feed\IntegrationActivity;
use BuddyNext\Feed\PostService;

/**
 * Jetonomy ↔ BuddyNext integration layer.
 */
class JetonomyBridge {

	/**
	 * Per-request answers of verify_discussion_card(), keyed by card URL.
	 *
	 * @var array<string, string>
	 */
	private array $verified_cards = array();

	/**
	 * Whether this Jetonomy has the is_anonymous column (1.7.0+); null until asked.
	 *
	 * @var bool|null
	 */
	private ?bool $anonymous_supported = null;


	/**
	 * True only while a Jetonomy flag is being copied into the BuddyNext queue, so
	 * forum types are reportable for that one report() call and never through the
	 * member report route.
	 *
	 * @var bool
	 */
	private static bool $copying_flag = false;

	/**
	 * Echo guard for closing a report on both sides. Raised around every write
	 * that fires the other side's close hook, so the close never bounces back.
	 * Separate from self::$syncing on purpose: the comment and card sync must
	 * still run while a moderator's removal trashes the forum item.
	 *
	 * @var bool
	 */
	private static bool $mirroring_report = false;

	/**
	 * Per-request forum item facts (author, forum id, forum title and visibility),
	 * keyed "type:id". The queue asks for the author and the label once per row.
	 *
	 * @var array<string, array{author_id:int, space_id:int, title:string, visibility:string}|null>
	 */
	private array $forum_items = array();

	/**
	 * Jetonomy flag type => BuddyNext report type.
	 */
	private const BN_FORUM_TYPES = array(
		'post'  => 'jetonomy_topic',
		'reply' => 'jetonomy_reply',
	);

	/**
	 * Object-cache group for the bridge's per-view count/list reads.
	 */
	private const CACHE_GROUP = 'buddynext_jetonomy';

	/**
	 * Cache TTL (seconds). A hard staleness bound even if an invalidation hook is
	 * ever missed; the create/delete hooks clear the affected keys immediately.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Re-entrancy guard for the two-way discussion sync.
	 *
	 * Set true while this bridge mirrors a comment into a forum reply (or a reply
	 * into a comment). The reciprocal hook fires synchronously during that write;
	 * seeing the flag, its handler returns immediately, so the mirror never loops.
	 *
	 * Raise it only through mirror(), never by hand — see that method for why.
	 *
	 * It is also an IN-REQUEST guard. It cannot protect a cycle that crosses a
	 * request boundary, so if any leg of this sync is ever moved to a queue, a
	 * cron tick or a webhook, the flag stops working and the pair record
	 * (bn_comments.sync_reply_id) has to carry the guard instead: write the
	 * counterpart id first, and bail when it is already set.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Perform a cross-boundary write with the echo guard raised, and lower it
	 * whatever happens.
	 *
	 * The guard being static means its blast radius is the PHP process, not the
	 * function. If anything throws between raising and lowering it, every later
	 * mirror in that process is silently skipped — no error, no log entry, just
	 * replies that stop appearing in the feed. That failure is invisible in
	 * testing because a request mirroring ONE comment has no second comment to
	 * lose; it shows up in bulk actions, imports and CLI runs, where nobody
	 * looks.
	 *
	 * The window deliberately contains third-party code — the callbacks re-fire
	 * the far side's own hooks so its notifications and counters still run — so
	 * it must never depend on the body completing. `finally` also covers any
	 * early return added to a callback later.
	 *
	 * @param callable $write The cross-boundary write.
	 * @return void
	 */
	private static function mirror( callable $write ): void {
		self::$syncing = true;
		try {
			\BuddyNext\Feed\IntegrationActivity::as_mirror( $write );
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * Suppress BuddyNext and Jetonomy notifications raised by a mirror write.
	 *
	 * @param bool $should Whether to send.
	 * @return bool
	 */
	public function mute_mirror_notifications( $should ) {
		return self::$syncing ? false : (bool) $should;
	}

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 * Bails when Jetonomy is not active so no hooks are wasted on other sites.
	 */
	public function init(): void {
		if ( ! class_exists( 'Jetonomy\Jetonomy' ) ) {
			return;
		}

		// jetonomy_after_create_post fires ($post_id, $space_id) — 2 args only.
		add_action( 'jetonomy_after_create_post', array( $this, 'on_post_created' ), 10, 2 );

		// Keep mirrored rows in step with the forum's anonymous flag: clears what a
		// version before 1.2.5 stored, and any later drift. Admin-only, daily.
		// The check itself runs in the background; an administrator's page load
		// only asks for it, at most once a day.
		add_action( 'admin_init', array( $this, 'queue_anonymous_repair' ) );
		add_action( self::REPAIR_ANONYMOUS_HOOK, array( $this, 'repair_anonymous_mirrors' ) );

		// One sync keeps a discussion's feed card + search row matching its current
		// state. Two triggers: an edit (jetonomy_post_updated: title/body/privacy),
		// and every move into or out of publish (jetonomy_post_publish_transition,
		// fired by Post::create/update/delete for create, approve, restore, trash,
		// scheduled publish and purge - card 10344390418).
		add_action( 'jetonomy_post_updated', array( $this, 'sync_discussion' ), 10, 2 );
		// Jetonomy fires no event when a topic moves to another forum, so the move is read off
		// its own REST route once it has succeeded.
		add_filter( 'rest_request_after_callbacks', array( $this, 'on_move_response' ), 10, 3 );
		// Banned words and the Pro rules apply to forum topics, titles and replies like any other post.
		add_filter( 'jetonomy_before_create_post', array( $this, 'check_new_topic' ), 10, 1 );
		add_filter( 'jetonomy_before_create_reply', array( $this, 'check_new_reply' ), 10, 1 );
		// Jetonomy runs no check when a topic or reply is edited, so the edit routes are checked here.
		add_filter( 'rest_request_before_callbacks', array( $this, 'check_forum_edit' ), 10, 3 );
		add_action( 'jetonomy_post_publish_transition', array( $this, 'sync_discussion' ), 10, 1 );
		// jetonomy_after_delete_post fires ($post_id) from Post::delete() — the
		// PERMANENT purge (empty-trash / CLI / programmatic), a different action
		// from the soft delete above. Mirrors jetonomy_after_delete_reply, which
		// the reply side already hooks.
		add_action( 'jetonomy_after_delete_post', array( $this, 'on_post_hard_deleted' ), 10, 1 );

		// A card must never link to a discussion the viewer cannot open. Checked as
		// it renders, because some causes fire no hook: a forum switched private in
		// Jetonomy, or a card published before post_id was stamped (2026-09-19) whose
		// discussion was later deleted. See verify_discussion_card().
		add_filter( 'buddynext_discussion_card_url', array( $this, 'verify_discussion_card' ), 10, 2 );

		// Forum email choices live in BuddyNext's notification settings: one switch per
		// forum type plus Jetonomy's own fields (Pro's digest), saved to Jetonomy.
		add_action( 'buddynext_notification_prefs_after', array( $this, 'render_partner_prefs' ), 20 );
		// BuddyNext's Email channel is the master switch: off there, no forum email either.
		add_filter( 'jetonomy_send_instant_email', array( $this, 'filter_forum_email' ), 10, 4 );
		add_filter( 'buddynext_integration_notification_payload', array( $this, 'drop_duplicate_report_notice' ), 10, 2 );

		// Inject the forum and "My discussions" links into the BuddyNext left navigation rail.
		add_filter( 'buddynext_rail_items', array( $this, 'inject_discussions_nav_item' ) );

		// A forum linked to a Space leads back to it from Jetonomy's breadcrumb.
		add_filter( 'jetonomy_breadcrumb_html', array( $this, 'add_space_breadcrumb' ), 10, 1 );

		// Bridge: hashtag ↔ tag — when BuddyNext renders a hashtag feed, pull
		// related Jetonomy discussions that share the same tag slug.
		add_filter( 'buddynext_hashtag_related_discussions', array( $this, 'get_related_discussions' ), 10, 2 );

		// Level 2 context nav: Discussion sub-pages (Home / Search / Leaderboard).
		add_filter( 'buddynext_context_nav', array( $this, 'inject_discussion_context_nav' ), 10, 2 );

		// Jetonomy pages (e.g. /community/) render as the plugin's own default —
		// BuddyNext does not inject its nav/wrapper or suppress Jetonomy's own
		// navigation. (Owner rule: BN must not touch Jetonomy pages.) The link
		// INTO discussions lives on BuddyNext's own rail (inject_discussions_nav_item).

		// Register Discussions on BOTH the member-profile and space nav surfaces via
		// the unified Nav API (one registry, one renderer) — profile tab carries a
		// count badge; the space tab links to the forum (or the on-demand provision
		// trigger). Replaces the old buddynext_profile_extra_data + buddynext_space_tabs.
		// A space's type is the authority for its discussion's visibility, so
		// follow it whenever the space is saved. Creation-time correctness
		// alone would let the leak back in the first time an owner locks a
		// space down.
		add_action( 'buddynext_space_updated', array( $this, 'sync_discussion_visibility' ), 10, 3 );

		// The forum follows the space's lifecycle, not only its type: archived, being deleted.
		add_action( 'buddynext_space_archived', array( $this, 'sync_discussion_visibility' ), 10, 1 );
		add_action( 'buddynext_space_unarchived', array( $this, 'sync_discussion_visibility' ), 10, 1 );
		add_action( 'buddynext_space_delete_queued', array( $this, 'retire_space_forum' ), 10, 1 );
		add_action( 'buddynext_space_deleting', array( $this, 'retire_space_forum' ), 10, 1 );
		add_action( 'buddynext_async_withdraw_forum_surfaces', array( $this, 'withdraw_forum_surfaces' ), 10, 2 );
		add_action( 'buddynext_async_reindex_forum_discussions', array( $this, 'reindex_forum_discussions' ), 10, 2 );
		// The space's "who can post" holds in its forum too.
		add_action( 'buddynext_space_fields_saved', array( $this, 'sync_discussion_posting' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'backfill_discussion_posting' ) );
		// One-time copy of an existing forum's members into the space it was linked to.
		add_action( self::COPY_MEMBERS_HOOK, array( $this, 'copy_forum_members' ), 10, 3 );

		// The space decides who is in its linked forum: every active member is on the
		// forum roster, and leaving, removal or a ban takes them off it. Linking is
		// caught at the meta write, so every link path (provision, adopt, link an
		// existing forum, a third party) backfills the members already there. The
		// meta listener is always on: it also resets the forum => space lookup memo.
		add_action( 'added_bn_space_meta', array( $this, 'on_forum_linked' ), 10, 4 );
		add_action( 'updated_bn_space_meta', array( $this, 'on_forum_linked' ), 10, 4 );
		if ( class_exists( '\\Jetonomy\\Models\\SpaceMember' ) ) {
			add_action( self::GRANT_MEMBERS_HOOK, array( $this, 'grant_space_members' ), 10, 3 );
			add_action( 'buddynext_space_member_joined', array( $this, 'grant_forum_access' ), 10, 2 );
			add_action( 'buddynext_space_role_changed', array( $this, 'grant_forum_access' ), 10, 2 );
			add_action( 'buddynext_space_member_left', array( $this, 'revoke_forum_access' ), 10, 2 );
			add_action( 'buddynext_space_member_removed', array( $this, 'revoke_forum_access' ), 10, 2 );
			add_action( 'buddynext_space_user_banned', array( $this, 'revoke_forum_access' ), 10, 2 );
			add_action( 'buddynext_space_user_banned', array( $this, 'restrict_forum_for_ban' ), 10, 2 );
			add_action( 'buddynext_space_user_unbanned', array( $this, 'lift_forum_ban' ), 10, 2 );
			add_filter( 'jetonomy_before_join_space', array( $this, 'veto_forum_join' ), 10, 3 );

			// The other direction: the forum's own joins, leaves and role edits. The
			// space stays the authority, so none of these can bypass its approval or
			// hand out a power the space did not give.
			add_action( 'jetonomy_space_member_joined', array( $this, 'on_forum_member_joined' ), 10, 2 );
			add_action( 'jetonomy_space_member_left', array( $this, 'on_forum_member_left' ), 10, 2 );
			add_action( 'jetonomy_space_member_role_changed', array( $this, 'on_forum_role_changed' ), 10, 3 );

			// One join request, asked or answered in either place. Before Jetonomy's own
			// notices (priority 10), so they can tell BuddyNext has already answered.
			add_action( 'jetonomy_join_request_created', array( $this, 'on_forum_request_created' ), 5, 2 );
			add_action( 'jetonomy_join_request_denied', array( $this, 'on_forum_request_denied' ), 5, 3 );
			add_action( 'jetonomy_join_request_withdrawn', array( $this, 'on_forum_request_withdrawn' ), 5, 2 );
			add_action( 'buddynext_space_join_approved', array( $this, 'settle_forum_request' ), 10, 2 );
			add_action( 'buddynext_space_join_declined', array( $this, 'settle_forum_request' ), 10, 2 );
			add_action( 'buddynext_space_join_request_cancelled', array( $this, 'settle_forum_request' ), 10, 2 );
		}

		add_action( 'buddynext_register_nav', array( $this, 'register_nav_items' ) );

		// Owner controls: register on the integration registry so the site owner can
		// toggle the Discussions tab + the discussion activity from BuddyNext →
		// Integrations (default on). The nav/feed gating below reads those toggles.
		add_filter( 'buddynext_integrations', array( $this, 'register_integration' ) );
		add_action( 'buddynext_integration_search_disabled', array( $this, 'on_search_disabled' ) );
		add_filter( 'buddynext_search_item_url', array( $this, 'search_item_url' ), 10, 3 );
		add_action( 'buddynext_search_prime_item_urls', array( $this, 'prime_search_item_urls' ), 10, 1 );
		add_filter( 'buddynext_integration_reindex_ids', array( $this, 'reindex_ids' ), 10, 4 );
		add_action( 'buddynext_integration_reindex_item', array( $this, 'reindex_item' ), 10, 2 );

		// On-demand space forum: provision + redirect when a member first opens a
		// forumless space's Discussions tab (web).
		// Priority 5 so the on-demand forum provision/redirect runs before
		// PageRouter::dispatch_hub_template (template_redirect:10) renders the
		// spaces directory and exits — otherwise the Discussions tab URL
		// (/spaces/?bn_provision_forum=N) shows the directory instead.
		add_action( 'template_redirect', array( $this, 'maybe_provision_and_redirect' ), 5 );
		add_action( 'buddynext_part_space_hero_after', array( $this, 'render_provision_failure_notice' ) );
		// The owner of a space with no forum is pointed at the switch that adds one.
		add_action( 'buddynext_part_space_about_panel_before', array( $this, 'render_discussion_owner_hint' ) );

		// BuddyNext owns the member profile: Jetonomy's own profile pages hand over
		// to it, as its generated links already do (filter_jetonomy_profile_url).
		add_action( 'template_redirect', array( $this, 'redirect_forum_profile' ), 5 );
		// One inbox: once BuddyNext shows the forum notifications, Jetonomy's own list
		// hands over to the BuddyNext notifications page.
		add_action( 'template_redirect', array( $this, 'redirect_forum_notifications' ), 5 );

		// A held member (e.g. "Paying members only" without a plan) is held off
		// the forum too: Jetonomy has no hold of its own, so the bridge applies
		// BuddyNext's hold to its pages and to jetonomy(-pro)/v1, with the same
		// answer the community routes give.
		add_action( 'template_redirect', array( $this, 'hold_forum_pages' ), 8 );
		add_filter( 'rest_pre_dispatch', array( $this, 'hold_forum_rest' ), 11, 3 );

		// Two-way discussion sync: a comment on a discussion card in the BuddyNext
		// feed becomes a reply in the originating Jetonomy forum topic, and a reply
		// in the forum becomes a comment on the card — so a member who engages on
		// either surface is heard on both. A static in-request guard (self::$syncing)
		// blocks the reciprocal mirror, so the two hooks can never loop.
		add_action( 'buddynext_comment_created', array( $this, 'sync_comment_to_forum' ), 10, 4 );
		// The forum's own rules decide who may reply: a comment on a discussion card
		// is a reply in the forum, so it is refused here when the forum would refuse it.
		add_filter( 'buddynext_comment_before_save', array( $this, 'gate_comment_on_discussion' ), 10, 3 );
		// A mirror write never notifies: the side where the member acted sends the
		// one notification, so a forum reply is one Jetonomy row and a card comment
		// one BuddyNext row, never both (card 10344414409, decision 10062150983).
		add_filter( 'buddynext_notification_should_send', array( $this, 'mute_mirror_notifications' ) );
		// Same rule on Jetonomy's side: the mirrored reply fires Jetonomy's own listeners,
		// so its bell row, email and mention notices are muted for the write too.
		add_filter( 'jetonomy_notification_should_send', array( $this, 'mute_mirror_notifications' ) );
		// A forum reply's comment on the card follows the reply in and out of
		// publish: create, approve, restore, trash, purge (card 10344390418).
		add_action( 'jetonomy_reply_publish_transition', array( $this, 'sync_reply_mirror' ), 10, 3 );

		// Edit propagation both ways, and feed-side delete to the forum, resolved through the pair id
		// stored on the comment at creation (bn_comments.sync_reply_id). Same static
		// guard blocks the reciprocal mirror. Without this the surfaces desync — an
		// edit/delete on one side leaves the other side stale.
		add_action( 'buddynext_comment_updated', array( $this, 'sync_comment_edit_to_forum' ), 10, 1 );
		add_action( 'buddynext_comment_deleted', array( $this, 'sync_comment_delete_to_forum' ), 10, 1 );
		add_action( 'jetonomy_reply_updated', array( $this, 'sync_reply_edit_to_feed' ), 10, 1 );

		// App coverage: REST to provision/fetch a space's forum URL.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Messaging is BuddyNext's native domain. When BN messaging is available,
		// suppress Jetonomy Pro's private-messaging extension on the front end and
		// REST: it registers /messages/ rewrite rules + a template_redirect at
		// priority 1 that hijacks BN's own /messages/ route, which made the Message
		// action non-functional whenever Jetonomy was active. Filtered at read time
		// (option_jetonomy_pro_extensions) so nothing is persisted and it reverts
		// automatically if BN messaging is disabled. Left untouched in wp-admin so
		// the Jetonomy extensions screen still reflects/saves the real setting.
		add_filter( 'option_jetonomy_pro_extensions', array( $this, 'suppress_jetonomy_messaging' ) );

		// Member identity is BuddyNext's when BN is the community (BN is master).
		// Jetonomy added these seams in 1.9.3 specifically for a host like BN to
		// fill; unfilled, a @mention and every profile link inside a discussion
		// resolve to Jetonomy's own profile page instead of the member's BN
		// profile, fragmenting one identity across two places. Point them at BN:
		// the emit filter (user_handle) and the resolve filter are a required
		// PAIR — whatever claims a handle on emit must claim it on resolve, or the
		// composer offers a mention the parser cannot resolve.
		add_filter( 'jetonomy_profile_url', array( $this, 'filter_jetonomy_profile_url' ), 10, 2 );
		add_filter( 'jetonomy_user_handle', array( $this, 'filter_jetonomy_user_handle' ), 10, 2 );
		add_filter( 'jetonomy_resolve_mention_handles', array( $this, 'filter_jetonomy_resolve_mention_handles' ), 10, 2 );
		add_filter( 'jetonomy_user_display_name', array( $this, 'filter_jetonomy_user_display_name' ), 10, 2 );
		add_filter( 'jetonomy_profile_action_url', array( $this, 'filter_jetonomy_profile_action_url' ), 10, 3 );

		// One moderation, not two: a member suspended in BuddyNext is read-only in
		// the forum (they keep reading, as in BuddyNext, but cannot post, reply or
		// vote), and someone a member blocked in BuddyNext is blocked there too.
		// Jetonomy 2.0.7 reads both filters on every permission and block check
		// (REST included); an older Jetonomy never calls them.
		add_filter( 'jetonomy_user_is_silenced', array( $this, 'filter_jetonomy_user_is_silenced' ), 10, 2 );
		add_filter( 'jetonomy_blocked_user_ids', array( $this, 'filter_jetonomy_blocked_user_ids' ), 10, 2 );

		// One moderation queue: a member's flag on a topic or reply is copied into
		// the BuddyNext queue, where Remove, Strike and Suspend work for a BuddyNext
		// moderator who holds no forum capability. Closing on either side closes
		// the other, and a card or comment report closes its forum twin's too.
		add_action( 'jetonomy_after_create_flag', array( $this, 'queue_forum_flag' ), 10, 1 );
		add_filter( 'buddynext_reportable_object_types', array( $this, 'reportable_forum_types' ) );
		add_filter( 'buddynext_removable_object_types', array( $this, 'removable_forum_types' ) );
		// Priority 9: a removed discussion card or mirrored comment trashes its
		// forum twin before core takes the card or comment down at 10.
		add_filter( 'buddynext_content_removal_handled', array( $this, 'remove_reported_forum_item' ), 9, 4 );
		add_filter( 'buddynext_removed_content_author', array( $this, 'reported_forum_author' ), 10, 3 );
		add_filter( 'buddynext_object_label', array( $this, 'forum_object_label' ), 10, 3 );
		add_action( 'buddynext_reports_actioned', array( $this, 'close_forum_flags' ), 10, 4 );
		add_action( 'jetonomy_flag_resolved', array( $this, 'close_bn_report_for_flag' ), 10, 3 );
		add_action( 'jetonomy_content_moderated', array( $this, 'close_bn_report_for_moderated' ), 10, 4 );
	}

	/**
	 * A BuddyNext suspension makes the member read-only in the forum (owner
	 * decision 2026-10-29): a ban would also stop them reading, which a
	 * BuddyNext suspension does not.
	 *
	 * @param bool $silenced Jetonomy's own answer.
	 * @param int  $user_id  Member being checked.
	 * @return bool
	 */
	public function filter_jetonomy_user_is_silenced( $silenced, $user_id ): bool {
		if ( (bool) $silenced || (int) $user_id <= 0 ) {
			return (bool) $silenced;
		}
		return (bool) buddynext_service( 'moderation' )->is_suspended( (int) $user_id );
	}

	/**
	 * Members the viewer blocked in BuddyNext are blocked in the forum too.
	 *
	 * One-way, like a BuddyNext block: only the people the viewer blocked. Mute
	 * and restrict are not mapped (owner decision pending).
	 *
	 * @param int[] $ids       Jetonomy's blocked ids for the viewer.
	 * @param int   $viewer_id Viewer.
	 * @return int[]
	 */
	public function filter_jetonomy_blocked_user_ids( $ids, $viewer_id ): array {
		$ids = array_map( 'intval', (array) $ids );
		if ( (int) $viewer_id <= 0 ) {
			return $ids;
		}
		return array_values( array_unique( array_merge( $ids, buddynext_service( 'blocks' )->blocked_users( (int) $viewer_id ) ) ) );
	}

	/**
	 * Point a Jetonomy profile link at the member's BuddyNext profile.
	 *
	 * @param string $url     Jetonomy's default profile URL.
	 * @param int    $user_id User the link is for.
	 * @return string The BN profile URL, or Jetonomy's default if BN cannot build one.
	 */
	public function filter_jetonomy_profile_url( $url, $user_id ) {
		$bn = \BuddyNext\Core\PageRouter::profile_url( (int) $user_id );
		return '' !== $bn ? $bn : (string) $url;
	}

	/**
	 * Emit the member's BuddyNext handle as their Jetonomy @handle.
	 *
	 * Paired with filter_jetonomy_resolve_mention_handles(): the handle emitted
	 * here is exactly what Handle::resolve() reads back, so a mention composed in
	 * a discussion resolves to the same member on both sides.
	 *
	 * @param string $handle Jetonomy's default handle (user_nicename).
	 * @param mixed  $user   The WP_User the handle is for.
	 * @return string The member's BN handle, or Jetonomy's default.
	 */
	public function filter_jetonomy_user_handle( $handle, $user ) {
		if ( $user instanceof \WP_User && $user->ID > 0 ) {
			$bn = Handle::current( (int) $user->ID );
			if ( '' !== $bn ) {
				return $bn;
			}
		}
		return (string) $handle;
	}

	/**
	 * Resolve discussion @handles the BuddyNext way (custom slug + user-{id}).
	 *
	 * Jetonomy resolves a handle as user_nicename; BuddyNext's Handle::resolve()
	 * also honours a member's custom slug (bn_profile_slug) and the reserved
	 * user-{id} form, which plain nicename resolution misses. Fills only handles
	 * Jetonomy (or a higher-priority filter) left unresolved, so it augments
	 * rather than overrides.
	 *
	 * @param mixed $map     Handle => user-id map resolved so far.
	 * @param mixed $handles Raw handles (no leading '@') to resolve.
	 * @return array<string,int> The augmented map.
	 */
	public function filter_jetonomy_resolve_mention_handles( $map, $handles ) {
		$map = is_array( $map ) ? $map : array();
		foreach ( (array) $handles as $raw ) {
			$handle = (string) $raw;
			if ( '' === $handle || isset( $map[ $handle ] ) ) {
				continue;
			}
			$user = Handle::resolve( $handle );
			if ( $user instanceof \WP_User ) {
				$map[ $handle ] = (int) $user->ID;
			}
		}
		return $map;
	}

	/**
	 * Show the member's BuddyNext name on Jetonomy bylines.
	 *
	 * Without this, Jetonomy's own name format wins — e.g. it renders
	 * "Alex Rivera @alex-rivera" on a discussion byline while every other
	 * BuddyNext surface shows "Alex Rivera". Two names for one member is the
	 * split identity BN-is-master exists to prevent, so the byline is pinned to
	 * the same display_name BuddyNext shows everywhere else (member directory,
	 * profile, feed). Jetonomy keeps its own Anonymous / [deleted] handling —
	 * this only governs a real, named member.
	 *
	 * @param string $name Jetonomy's resolved name.
	 * @param mixed  $user The WP_User the name is for.
	 * @return string The BuddyNext member name, or Jetonomy's default.
	 */
	public function filter_jetonomy_user_display_name( $name, $user ) {
		if ( $user instanceof \WP_User && $user->ID > 0 && '' !== (string) $user->display_name ) {
			return (string) $user->display_name;
		}
		return (string) $name;
	}

	/**
	 * Route Jetonomy's profile-action deep-links at the BuddyNext screens.
	 *
	 * Jetonomy links "view profile", "edit profile" and "notification settings"
	 * (admin bar, user-panel block, notification deep-links) at its OWN profile
	 * sub-screens. When BN owns identity those must land on BN's screens instead,
	 * or a member editing their profile from a discussion ends up on a second,
	 * disconnected editor. Only the actions BN actually provides are remapped.
	 * `digest` (the link in Jetonomy Pro's digest email) lands on the Forum email
	 * card BN renders in its notification settings, since Jetonomy's own edit
	 * screen redirects to BN's, which has no digest control. `badges` is
	 * Jetonomy's own badge system, which BN does not mirror, so it stays put.
	 *
	 * @param string $url     Jetonomy's default action URL.
	 * @param string $action  Action key: profile|edit|notification-settings|badges|digest.
	 * @param int    $user_id User the action is for.
	 * @return string The BN screen URL, or Jetonomy's default when BN has no equivalent.
	 */
	public function filter_jetonomy_profile_action_url( $url, $action, $user_id ) {
		$uid = (int) $user_id;
		switch ( (string) $action ) {
			case 'profile':
				$bn = \BuddyNext\Core\PageRouter::profile_url( $uid );
				break;
			case 'edit':
				$bn = \BuddyNext\Core\PageRouter::edit_profile_url( $uid );
				break;
			case 'notification-settings':
				$bn = \BuddyNext\Core\PageRouter::notification_prefs_url();
				break;
			case 'digest':
				$bn = \BuddyNext\Core\PageRouter::notification_prefs_url() . '#forum-email';
				break;
			default:
				// badges: Jetonomy-specific, no BN equivalent — leave it.
				return (string) $url;
		}
		return '' !== $bn ? $bn : (string) $url;
	}

	/**
	 * Drop Jetonomy Pro's private-messaging extension from the active list when
	 * BuddyNext messaging is available, so BN owns the /messages/ route.
	 *
	 * @param mixed $enabled Stored jetonomy_pro_extensions option value.
	 * @return mixed Filtered list (array) with 'private-messaging' removed, or the
	 *               original value untouched in wp-admin / when BN messaging is off.
	 */
	public function suppress_jetonomy_messaging( $enabled ) {
		if ( ! is_array( $enabled ) || is_admin() ) {
			return $enabled;
		}
		if ( ! class_exists( '\BuddyNext\Messages\MessagesData' )
			|| ! \BuddyNext\Messages\MessagesData::available() ) {
			return $enabled;
		}
		return array_values( array_diff( $enabled, array( 'private-messaging' ) ) );
	}

	/**
	 * Announce a new Jetonomy discussion and refresh cached counts.
	 *
	 * Hooked on: jetonomy_after_create_post( int $post_id, int $space_id ).
	 * The feed card and search row are not made here: Post::create() fires
	 * jetonomy_post_publish_transition first, and sync_discussion() handles it.
	 * Mentions are not notified here either: Jetonomy notifies its own, and
	 * the contract shows that one row in the bell.
	 *
	 * @param int $post_id  Jetonomy discussion ID (jt_posts.id).
	 * @param int $space_id Jetonomy space ID the discussion belongs to.
	 */
	public function on_post_created( int $post_id, int $space_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT author_id, title, content_plain, is_private, status{$this->anonymous_column()} FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a fixed column name or ''.
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $post ) {
			return;
		}

		$author_id = (int) $post->author_id;
		$title     = (string) $post->title;
		$content   = (string) $post->content_plain;

		// An anonymous topic is never handed on with its author: nothing outside
		// Jetonomy learns who wrote it.
		if ( ! empty( $post->is_anonymous ) ) {
			$this->invalidate_member_caches( $author_id );
			$this->invalidate_space_caches( $space_id );
			return;
		}

		/**
		 * Fires after a Jetonomy discussion is indexed in BuddyNext search.
		 *
		 * Third-party code (e.g. per-space feed sync toggle) can hook here to push
		 * the discussion into bn_posts for a specific space when enabled.
		 *
		 * @param int    $post_id   Discussion ID.
		 * @param int    $space_id  Jetonomy space ID.
		 * @param int    $author_id Author user ID.
		 * @param string $title     Discussion title.
		 * @param string $content   Discussion content (plain text).
		 */
		do_action( 'buddynext_jetonomy_post_indexed', $post_id, $space_id, $author_id, $title, $content );

		// A new discussion changes the author's profile count/list and the space's
		// count/list — drop those cached reads so the next view is accurate.
		$this->invalidate_member_caches( $author_id );
		$this->invalidate_space_caches( $space_id );
	}

	/**
	 * Make a discussion's feed card and search row match its current state.
	 *
	 * Hooked on jetonomy_post_updated (an edit) and jetonomy_post_publish_transition
	 * (create, approve, restore, trash, scheduled publish, purge). It reads the row
	 * as it is now, so one method answers every path:
	 * - published and public: restore a withdrawn card, refresh it in place, or
	 *   publish the first one (an approved topic);
	 * - anything else: withdraw the card (reversible, so a restore brings back the
	 *   same card with its date, reactions and comments - card 10320560928);
	 * - search: re-indexed while published, dropped otherwise.
	 * A purged row no longer exists; on_post_hard_deleted() removes that card.
	 * Mentions are not re-sent on edit.
	 *
	 * @param int $post_id  Jetonomy discussion ID.
	 * @param int $space_id Jetonomy forum ID; read from the row when not given.
	 * @return void
	 */
	public function sync_discussion( int $post_id, int $space_id = 0 ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT space_id, author_id, title, content_plain, is_private, status{$this->anonymous_column()} FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a fixed column name or ''.
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $post ) {
			return;
		}
		if ( $space_id <= 0 ) {
			$space_id = (int) $post->space_id;
		}

		$author_id = (int) $post->author_id;
		$title     = (string) $post->title;
		$content   = (string) $post->content_plain;
		$anonymous = ! empty( $post->is_anonymous );
		// A space whose Discussions are switched off keeps the forum's data but shows none of it:
		// no card, no search row. Switching back on republishes both.
		$off       = $this->discussions_off( $this->space_id_for_forum( $space_id ) );
		$is_public = ! $off && $this->is_public_discussion( $space_id, (int) $post->is_private, (string) $post->status, $anonymous );

		// Search index: drop, then re-index while published. A private topic keeps a
		// 'private' row (space members can find it); the search gate reads the
		// BuddyNext space the forum is linked to. An anonymous topic gets no row at
		// all - the row is stored under its author.
		if ( buddynext_integration_enabled( 'jetonomy', 'search' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->prefix . 'bn_search_index',
				array(
					'object_type' => 'discussion',
					'object_id'   => $post_id,
				),
				array( '%s', '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( 'publish' === (string) $post->status && ! $anonymous && ! $off ) {
				( new SearchService() )->index( 'discussion', $post_id, $title, $content, $author_id, $is_public ? 'public' : 'private', $this->space_id_for_forum( $space_id ) );
			}
		}

		// Feed card: refresh in place, add if newly public, remove if no longer public.
		// The card is keyed on the discussion URL, so a topic with no forum has none.
		$url = $this->discussion_url( $post_id, $space_id );
		if ( '' !== $url
			&& buddynext_integration_enabled( 'jetonomy', 'feed' )
			&& (bool) apply_filters( 'buddynext_jetonomy_discussion_activity', true, $post_id ) ) {
			if ( $is_public ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( $content ), 30, '…' );
				// Bring back a card that a previous unpublish WITHDREW (set to draft) —
				// the same id, original date, reactions and comments — rather than
				// minting a new one that resurfaces a months-old thread as new and
				// orphans every reply keyed on the old card id (card 10320560928).
				$restored = IntegrationActivity::restore( $url, 'discussion' );
				// refresh() merges into the card's link_meta (title/description are
				// what the card renders) and matches the card whatever its status, so
				// it also covers an ordinary edit of an already-published card. It
				// returns false only when NO card exists at all — the topic was
				// private/draft from the start and now needs its first card.
				// Stamp post_id alongside the rendered fields so the card carries the
				// one identifier that survives a hard delete (Post::delete fires
				// jetonomy_after_delete_post with only the id, and by then the
				// jt_posts/jt_spaces rows the URL is built from are gone). refresh()
				// merges, so this also backfills post_id onto a card published before
				// the stamp existed the first time its discussion is edited.
				$refreshed = IntegrationActivity::refresh(
					$url,
					'discussion',
					array(
						'title'       => $title,
						'description' => $excerpt,
						'post_id'     => $post_id,
					)
				);
				if ( ! $restored && ! $refreshed ) {
					IntegrationActivity::publish(
						$author_id,
						__( 'started a discussion', 'buddynext' ),
						$url,
						$title,
						'discussion',
						$excerpt,
						$this->space_id_for_forum( $space_id ),
						// Jetonomy notifies its own members of a topic: the Space is not told twice.
						array(
							'post_id'      => $post_id,
							'notify_space' => false,
						)
					);
				}
			} else {
				// Edited from public to private / draft / trash — WITHDRAW the card
				// (reversible: it goes to 'draft', hidden from every feed but its row,
				// date and comments preserved) rather than deleting it. A later
				// republish restores this exact card. Deleting instead destroyed the
				// card and orphaned its comments (card 10320560928).
				IntegrationActivity::withdraw( $url, 'discussion' );
			}
		}

		$this->invalidate_member_caches( $author_id );
		$this->invalidate_space_caches( $space_id );
	}

	/**
	 * Permanently remove a discussion's BuddyNext surfaces when the jt_posts row is
	 * PURGED (not trashed).
	 *
	 * Hooked on: jetonomy_after_delete_post( int $post_id ), fired by Post::delete()
	 * — the hard delete behind empty-trash, `wp jetonomy content delete`, and any
	 * programmatic purge. This is a genuine, irreversible delete, so the card is
	 * REMOVED (unlike the reversible trash path in sync_discussion, which withdraws).
	 *
	 * Post::delete() deletes the jt_posts row BEFORE firing this action and passes
	 * only the id — the discussion URL (built from jt_posts.slug + jt_spaces.slug) is
	 * already gone, so the card cannot be found by link_url. It is found instead by
	 * the post_id stamped into its link_meta at publish/refresh. remove_by_meta()
	 * cascades the card's comments and their child rows and fires
	 * buddynext_post_deleted, so nothing is orphaned.
	 *
	 * @param int $post_id Purged Jetonomy discussion ID.
	 * @return void
	 */
	public function on_post_hard_deleted( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		global $wpdb;

		// Remove the feed card (and cascade its comments/reactions/etc) by the
		// stamped id — the only handle left once the source row is gone.
		IntegrationActivity::remove_by_meta( 'discussion', 'post_id', $post_id );

		// Drop the search-index entry. Keyed on object_id, so it needs no URL; a
		// harmless no-op when a prior trash (sync_discussion) already removed it.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type' => 'discussion',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Drop a member's cached discussion count + panel list.
	 *
	 * @param int $user_id Discussion author whose caches to clear.
	 * @return void
	 */
	private function invalidate_member_caches( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		wp_cache_delete( 'jt_dcount_' . $user_id, self::CACHE_GROUP );
		wp_cache_delete( 'jt_ulist_' . $user_id, self::CACHE_GROUP );
	}

	/**
	 * Drop a space forum's cached discussion count + panel list.
	 *
	 * @param int $forum_id Resolved Jetonomy forum id (jt_posts.space_id).
	 * @return void
	 */
	private function invalidate_space_caches( int $forum_id ): void {
		if ( $forum_id <= 0 ) {
			return;
		}
		wp_cache_delete( 'jt_scount_' . $forum_id, self::CACHE_GROUP );
		wp_cache_delete( 'jt_slist_' . $forum_id, self::CACHE_GROUP );
	}

	/**
	 * Keep a discussion card's link only while the discussion is public.
	 *
	 * One indexed lookup per card, memoised per request. When the discussion is
	 * gone or no longer public the card is withdrawn (hidden from every feed,
	 * restored in place if the discussion is republished) and '' tells the
	 * template to show a neutral line instead of a link that would 404. An older
	 * card without a stamped post_id is matched by its URL and stamped, so the
	 * normal delete path finds it from then on.
	 *
	 * @param string               $url  Card URL.
	 * @param array<string, mixed> $meta Card link_meta.
	 * @return string The URL, or '' when the discussion cannot be opened.
	 */
	public function verify_discussion_card( string $url, array $meta ): string {
		if ( '' === $url ) {
			return $url;
		}
		if ( array_key_exists( $url, $this->verified_cards ) ) {
			return $this->verified_cards[ $url ];
		}

		global $wpdb;
		$topic_id = (int) ( $meta['post_id'] ?? 0 );
		$topic    = null;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $topic_id > 0 ) {
			$topic = $wpdb->get_row( $wpdb->prepare( "SELECT id, space_id, is_private, status{$this->anonymous_column()} FROM {$wpdb->prefix}jt_posts WHERE id = %d", $topic_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a fixed column name or ''.
		} else {
			// No stamp: the topic slug is the URL's last segment; confirm the match
			// by rebuilding the URL, since a slug is only unique within its forum.
			$slug = basename( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.id, p.space_id, p.is_private, p.status{$this->anonymous_column( 'p' )}, p.slug, s.slug AS space_slug FROM {$wpdb->prefix}jt_posts p JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id WHERE p.slug = %s",
					$slug
				)
			);
			foreach ( (array) $rows as $row ) {
				if ( untrailingslashit( $this->discussion_permalink( (string) $row->space_slug, (string) $row->slug ) ) === untrailingslashit( $url ) ) {
					$topic = $row;
					break;
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $topic && $this->is_public_discussion( (int) $topic->space_id, (int) $topic->is_private, (string) $topic->status, ! empty( $topic->is_anonymous ) ) ) {
			if ( $topic_id <= 0 ) {
				IntegrationActivity::refresh( $url, 'discussion', array( 'post_id' => (int) $topic->id ) );
			}
			$this->verified_cards[ $url ] = $url;
			return $url;
		}

		IntegrationActivity::withdraw( $url, 'discussion' );
		$this->verified_cards[ $url ] = '';
		return '';
	}

	/**
	 * Background job that reconciles anonymous and private forum content.
	 */
	public const REPAIR_ANONYMOUS_HOOK = 'buddynext_jetonomy_repair_anonymous';

	/**
	 * Ask for the anonymous-content check: once a day after a clean run, and
	 * again within the hour while a run is still owed.
	 *
	 * Runs on an administrator's dashboard load and does nothing but queue the
	 * job: one transient read, and on the first load of the day one enqueue. The
	 * three joins the check needs (one reads a JSON field and cannot use an
	 * index) used to run right here, inside the page load, and on a large forum
	 * could hold the dashboard for seconds with no sign of why
	 * (card 10393793799).
	 *
	 * Action Scheduler when present, a single WP-Cron event otherwise.
	 *
	 * @return void
	 */
	public function queue_anonymous_repair(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_transient( 'buddynext_jetonomy_anonymous_checked' ) ) {
			return;
		}
		// Marked for an hour only: enough to stop every page load queueing it, short
		// enough that a job which never ran (a stuck scheduler) is asked for again
		// soon. The job itself marks the full day once it has run and found nothing.
		set_transient( 'buddynext_jetonomy_anonymous_checked', 1, HOUR_IN_SECONDS );
		self::enqueue_anonymous_repair();
	}

	/**
	 * Queue one run of the anonymous-content check.
	 *
	 * @return void
	 */
	private static function enqueue_anonymous_repair(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::REPAIR_ANONYMOUS_HOOK, array(), 'buddynext' ) ) {
				as_enqueue_async_action( self::REPAIR_ANONYMOUS_HOOK, array(), 'buddynext' );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::REPAIR_ANONYMOUS_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::REPAIR_ANONYMOUS_HOOK );
		}
	}

	/**
	 * Withdraw anything mirrored from content that is anonymous in the forum.
	 *
	 * Until 1.2.5 an anonymous topic got a feed card and a search row under its
	 * author, and an anonymous or private reply became a signed feed comment. New
	 * writes are refused, but rows already stored would stay until each topic was
	 * next edited. The same drift can come back at any time if the flag changes
	 * in the forum without an edit hook reaching the bridge.
	 *
	 * So this is a reconciliation, not a one-time migration: asked for at most once
	 * a day (queue_anonymous_repair) and run in the background, it re-syncs up to
	 * 200 anonymous topics that still have a card or a search row, and deletes up
	 * to 200 comments mirrored from anonymous or private replies. While it finds
	 * work it queues itself again; once it finds none it waits for the next day.
	 *
	 * @return void
	 */
	public function repair_anonymous_mirrors(): void {
		if ( '' === $this->anonymous_column() ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexed   = $wpdb->get_col(
			"SELECT p.id FROM {$wpdb->prefix}jt_posts p
			 INNER JOIN {$wpdb->prefix}bn_search_index si ON si.object_type = 'discussion' AND si.object_id = p.id
			 WHERE p.is_anonymous = 1
			 LIMIT 200"
		);
		$carded    = $wpdb->get_col(
			"SELECT p.id FROM {$wpdb->prefix}jt_posts p
			 INNER JOIN {$wpdb->prefix}bn_posts c
			         ON c.type = 'discussion' AND c.status = 'published'
			        AND c.link_meta IS NOT NULL AND JSON_VALID( c.link_meta )
			        AND CAST( JSON_UNQUOTE( JSON_EXTRACT( c.link_meta, '$.post_id' ) ) AS UNSIGNED ) = p.id
			 WHERE p.is_anonymous = 1
			 LIMIT 200"
		);
		$topic_ids = array_unique( array_map( 'intval', array_merge( (array) $indexed, (array) $carded ) ) );
		$comments  = $wpdb->get_results(
			"SELECT c.id, c.user_id FROM {$wpdb->prefix}bn_comments c
			 INNER JOIN {$wpdb->prefix}jt_replies r ON r.id = c.sync_reply_id
			 WHERE c.is_deleted = 0 AND ( r.is_anonymous = 1 OR r.is_private = 1 )
			 LIMIT 200"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $topic_ids && ! $comments ) {
			// A clean run: the next check is tomorrow's.
			set_transient( 'buddynext_jetonomy_anonymous_checked', 1, DAY_IN_SECONDS );
			return;
		}

		// Up to 200 of each were found, so there may be more: run again in the
		// background rather than wait for tomorrow.
		self::enqueue_anonymous_repair();

		foreach ( (array) $topic_ids as $topic_id ) {
			$topic_id = (int) $topic_id;
			$this->sync_discussion( $topic_id );
			// sync_discussion() leaves the index and the card alone when that aspect
			// is switched off; a row that carries the author goes regardless.
			IntegrationActivity::withdraw_by_meta( 'discussion', 'post_id', $topic_id );
			$wpdb->delete(
				$wpdb->prefix . 'bn_search_index',
				array(
					'object_type' => 'discussion',
					'object_id'   => $topic_id,
				),
				array( '%s', '%d' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$service = new \BuddyNext\Comments\CommentService();
		foreach ( (array) $comments as $comment ) {
			self::mirror(
				static function () use ( $service, $comment ) {
					$service->delete( (int) $comment->id, (int) $comment->user_id );
				}
			);
		}
	}

	/**
	 * The is_anonymous column for a SELECT list, or '' where Jetonomy has none.
	 *
	 * Jetonomy added the column to jt_posts and jt_replies in 1.7.0. An older
	 * install has no anonymous posting, so reading nothing there is correct and
	 * keeps the bridge from failing on a column that does not exist.
	 *
	 * @param string $alias Table alias the query uses, without the dot.
	 * @return string ", [alias.]is_anonymous" or ''.
	 */
	private function anonymous_column( string $alias = '' ): string {
		if ( null === $this->anonymous_supported ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->anonymous_supported = (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}jt_posts LIKE 'is_anonymous'" );
		}

		if ( ! $this->anonymous_supported ) {
			return '';
		}

		return ', ' . ( '' !== $alias ? $alias . '.' : '' ) . 'is_anonymous';
	}

	/**
	 * WHERE fragment keeping anonymous rows out, or '' where Jetonomy has none.
	 *
	 * @param string $alias Table alias the query uses, without the dot.
	 * @return string " AND alias.is_anonymous = 0" or ''.
	 */
	private function not_anonymous_where( string $alias ): string {
		return '' === $this->anonymous_column() ? '' : " AND {$alias}.is_anonymous = 0";
	}

	/**
	 * Whether a Jetonomy topic should surface as a PUBLIC BuddyNext activity.
	 *
	 * True only when the topic is published, not flagged private, and lives in a
	 * space whose Jetonomy visibility is `public`. Private/secret spaces and
	 * private topics return false so they never leak into the public feed/Explore.
	 * An anonymous topic returns false too: a feed card, a search row and a profile
	 * entry all carry the author, which is the one thing the member asked to keep
	 * out (owner decision 2026-10-11, card 10393042950).
	 *
	 * @param int    $space_id         Jetonomy space ID.
	 * @param int    $is_private_topic jt_posts.is_private (1 = private).
	 * @param string $status           jt_posts.status (expects 'publish').
	 * @param bool   $is_anonymous     jt_posts.is_anonymous.
	 * @return bool
	 */
	private function is_public_discussion( int $space_id, int $is_private_topic, string $status, bool $is_anonymous = false ): bool {
		if ( 0 !== $is_private_topic || $is_anonymous ) {
			return false;
		}
		if ( '' !== $status && 'publish' !== $status ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$visibility = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT visibility FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $space_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return 'public' === $visibility;
	}

	/**
	 * Whether a topic, looked up by id, is one the feed mirrors: published, signed
	 * and in a public forum.
	 *
	 * @param int $post_id jt_posts id.
	 * @return bool
	 */
	private function is_mirrored_topic( int $post_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a fixed column name or ''.
		$topic = $wpdb->get_row(
			$wpdb->prepare( "SELECT space_id, is_private, status{$this->anonymous_column()} FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null !== $topic
			&& $this->is_public_discussion( (int) $topic->space_id, (int) $topic->is_private, (string) $topic->status, ! empty( $topic->is_anonymous ) );
	}

	/**
	 * Build the public URL of a Jetonomy discussion (`base/s/{space}/t/{post}/`).
	 *
	 * Reads the post + space slugs from jt_posts/jt_spaces (Jetonomy fires the
	 * create/delete hooks with ids only). Returns '' when either slug is missing.
	 *
	 * @param int $post_id  jt_posts id.
	 * @param int $space_id jt_spaces id.
	 * @return string
	 */
	private function discussion_url( int $post_id, int $space_id ): string {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_slug  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1", $post_id ) );
		$space_slug = (string) $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $space_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $this->discussion_permalink( $space_slug, $post_slug );
	}

	/**
	 * The forum home URL (no trailing slash), through \Jetonomy\base_url() so the
	 * owner's configured base slug is honoured. Falls back to the stored
	 * `jetonomy_settings['base_slug']` (Jetonomy's default `community`) only when the
	 * Jetonomy helper is unavailable.
	 *
	 * @return string
	 */
	private function forum_home_url(): string {
		if ( function_exists( 'Jetonomy\base_url' ) ) {
			return untrailingslashit( (string) \Jetonomy\base_url() );
		}
		$settings  = get_option( 'jetonomy_settings', array() );
		$base_slug = isset( $settings['base_slug'] ) && '' !== (string) $settings['base_slug'] ? (string) $settings['base_slug'] : 'community';
		return home_url( '/' . ltrim( $base_slug, '/' ) ); // bn-route-ok: built from Jetonomy's configured base slug.
	}

	/**
	 * Build a Jetonomy discussion (topic) permalink from its space + post slug.
	 *
	 * Single source of truth for the discussion URL shape. The base segment always
	 * comes from Jetonomy's configurable base slug (an owner may rename it from
	 * `community` to `discuss` or anything else via Settings → General), resolved
	 * through \Jetonomy\base_url() so BuddyNext never hardcodes the base. Falls back
	 * to the stored `jetonomy_settings['base_slug']` option (Jetonomy's own default
	 * `community`) only when the Jetonomy helper is unavailable.
	 *
	 * @param string $space_slug jt_spaces.slug.
	 * @param string $post_slug  jt_posts.slug.
	 * @return string Discussion permalink, or '' when either slug is empty.
	 */
	public function discussion_permalink( string $space_slug, string $post_slug ): string {
		if ( '' === $space_slug || '' === $post_slug ) {
			return '';
		}

		$base = $this->forum_home_url();

		// Slugs are used as stored, the way Jetonomy's own templates build this link:
		// sanitize_title() already keeps them URL-safe and stores non-ASCII as
		// percent-encoded octets, so encoding again turned %f0 into %25f0 and an
		// emoji or accented title opened "Topic not found".
		return rtrim( $base, '/' ) . '/s/' . $space_slug . '/t/' . $post_slug . '/';
	}

	/**
	 * Inject the Jetonomy links into the BuddyNext left rail.
	 *
	 * Two items, both hidden under Integration Settings > Show in navigation:
	 * - `discussions`: the forum home, shown to guests too. It keeps the rail key the
	 *   single Discussions item always had, so an owner's saved rename, hide or
	 *   reorder of "Discussions" (admin nav overrides are keyed by rail key) still
	 *   applies. No `active` flag: the rail is not drawn on Jetonomy pages.
	 * - `my-discussions`: the logged-in member's own profile Discussions tab, in the
	 *   personal "You" group, active while that tab is open.
	 *
	 * Hooked on: buddynext_rail_items( array $items, string $hub )
	 *
	 * @param array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}> $items Existing rail items.
	 * @return array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}>
	 */
	public function inject_discussions_nav_item( array $items ): array {
		if ( ! buddynext_integration_enabled( 'jetonomy', 'nav' ) ) {
			return $items;
		}

		$items[] = array(
			'key'   => 'discussions',
			'label' => __( 'Discussions', 'buddynext' ),
			'url'   => trailingslashit( $this->forum_home_url() ),
			'icon'  => 'messages-square',
			'show'  => true,
			'order' => 60,
		);

		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return $items;
		}

		$disc_url  = trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $uid ) ) . 'discussions/';
		$disc_path = rtrim( (string) ( wp_parse_url( $disc_url, PHP_URL_PATH ) ?? '' ), '/' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		$items[] = array(
			'key'    => 'my-discussions',
			'label'  => __( 'My discussions', 'buddynext' ),
			'url'    => $disc_url,
			'icon'   => 'list',
			'show'   => true,
			'active' => '' !== $disc_path && str_starts_with( rtrim( $request_uri, '/' ), $disc_path ),
			'group'  => 'you',
			'order'  => 206,
		);

		return $items;
	}

	/**
	 * Resolve the public URL of a BuddyNext space's linked Jetonomy forum.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return string Forum URL, or '' when no forum is linked yet.
	 */
	private function space_forum_url( int $space_id ): string {
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 ) {
			return '';
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$jt_slug = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $forum_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( '' === $jt_slug ) {
			return '';
		}

		$settings  = get_option( 'jetonomy_settings', array() );
		$base_slug = isset( $settings['base_slug'] ) ? (string) $settings['base_slug'] : 'community';

		// Used as stored, like discussion_permalink(): the slug is already URL-safe.
		return home_url( '/' . $base_slug . '/s/' . $jt_slug . '/' ); // bn-route-ok: built from Jetonomy's configured base slug.
	}

	/**
	 * Whether a BuddyNext space currently has a Discussion linked.
	 *
	 * A Discussion is opt-in and never mandatory — a space has one only after the
	 * owner enabled or linked it. Thin boolean wrapper over the link meta for the
	 * owner-facing control and nav gating.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return bool True when a Jetonomy discussion is linked.
	 */
	public function space_has_discussion( int $space_id ): bool {
		return $this->forum_id_for_space( $space_id ) > 0;
	}

	/**
	 * Whether the owner explicitly switched a space's Discussions off. A missing flag (a space
	 * linked before it existed) is not off, so its forum is never closed for lack of a flag.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return bool
	 */
	private function discussions_off( int $space_id ): bool {
		return $space_id > 0 && '0' === (string) buddynext_get_space_field( $space_id, 'discussion_enabled' );
	}

	/**
	 * Whether a space's dedicated Discussion is currently ENABLED (shown to
	 * members). A space keeps its one dedicated discussion for its lifetime; the
	 * owner toggles it on/off with this flag, which hides the tab without ever
	 * discarding the discussion or its content.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return bool True when a discussion exists and the owner has not switched it off.
	 */
	public function space_discussion_enabled( int $space_id ): bool {
		if ( ! $this->space_has_discussion( $space_id ) ) {
			return false;
		}
		// Same reading as discussions_off(): only an explicit '0' is off, so a forum
		// linked before the flag existed still shows its tab.
		return ! $this->discussions_off( $space_id );
	}

	/**
	 * Query arg a failed provision link returns with.
	 *
	 * @var string
	 */
	private const PROVISION_ERROR_ARG = 'bn_provision_error';

	/**
	 * Background job: copy a linked forum's members into its space.
	 *
	 * @var string
	 */
	public const COPY_MEMBERS_HOOK = 'buddynext_jetonomy_copy_forum_members';

	/**
	 * Forum members copied per background run.
	 *
	 * @var int
	 */
	private const COPY_MEMBERS_BATCH = 200;

	/**
	 * Queue one batch of the forum-to-space member copy.
	 *
	 * @param int $space_id BuddyNext space.
	 * @param int $forum_id Linked Jetonomy forum.
	 * @param int $after_id Forum member user id to continue after.
	 * @return void
	 */
	private static function queue_member_copy( int $space_id, int $forum_id, int $after_id ): void {
		$args = array( $space_id, $forum_id, $after_id );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::COPY_MEMBERS_HOOK, $args, 'buddynext' );
			return;
		}
		if ( ! wp_next_scheduled( self::COPY_MEMBERS_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 1, self::COPY_MEMBERS_HOOK, $args );
		}
	}

	/**
	 * Copy one batch of a linked forum's members into the space, then queue the next.
	 *
	 * Runs once per link, started when an owner links an existing forum, and only
	 * for an OPEN space. Joining a private or secret space takes a request or an
	 * invitation, and this job has neither, so those spaces are left alone (the
	 * owner invites people there as usual).
	 *
	 * Who comes across: forum members who joined themselves or were invited. A
	 * member the forum holds through an access rule, a paid tier or anything else
	 * is there because of something that can end, and a copy would outlive it.
	 * Someone banned in the forum or suspended here is skipped too, and the
	 * space's own join still refuses a space ban, an archived space and a
	 * paid-plan gate. The owner is not sent one "joined" notification per person.
	 *
	 * @param int $space_id BuddyNext space.
	 * @param int $forum_id Jetonomy forum the members come from.
	 * @param int $after_id Forum member user id the last batch ended on.
	 * @return void
	 */
	public function copy_forum_members( int $space_id, int $forum_id, int $after_id = 0 ): void {
		// Unlinked (or linked to something else) since the job was queued: stop.
		if ( $space_id <= 0 || $forum_id <= 0
			|| (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' ) !== $forum_id ) {
			return;
		}

		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
		if ( ! is_array( $space ) || 'open' !== (string) ( $space['type'] ?? '' ) ) {
			return;
		}

		global $wpdb;
		// By user id, not OFFSET: jt_space_members is keyed (space_id, user_id), so
		// each batch is one index range however large the forum is.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, source FROM {$wpdb->prefix}jt_space_members
				 WHERE space_id = %d AND user_id > %d
				 ORDER BY user_id ASC
				 LIMIT %d",
				$forum_id,
				max( 0, $after_id ),
				self::COPY_MEMBERS_BATCH
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( empty( $rows ) ) {
			return;
		}

		$quiet = static function ( $send, $data ) use ( $space_id ) {
			return ( 'bn.space_join' === ( $data['type'] ?? '' ) && (int) ( $data['object_id'] ?? 0 ) === $space_id ) ? false : $send;
		};
		add_filter( 'buddynext_notification_should_send', $quiet, 10, 2 );

		$members    = new \BuddyNext\Spaces\SpaceMemberService();
		$moderation = new \BuddyNext\Moderation\ModerationService();
		$can_ban    = class_exists( '\\Jetonomy\\Models\\Restriction' );
		$last_id    = $after_id;
		// An exception inside the batch must not leave the quiet filter on for the rest
		// of the request, which would swallow every other join notification.
		try {
			foreach ( $rows as $row ) {
				$user_id = (int) $row->user_id;
				$last_id = max( $last_id, $user_id );

				// An allow list: only the two ways a person puts themselves in a forum.
				// ponytail: one ban lookup per member (memoised by Jetonomy), in a
				// background batch of 200; a set query if a forum of many thousands is slow.
				if ( $user_id <= 0 || false === get_userdata( $user_id )
					|| ! in_array( (string) $row->source, array( 'manual', 'invite' ), true )
					|| ( $can_ban && \Jetonomy\Models\Restriction::is_banned( $user_id ) )
					|| $moderation->is_suspended( $user_id ) ) {
					continue;
				}

				// A copy of the forum's roster. A refusal (banned from the space, gated) leaves them out.
				\BuddyNext\Feed\IntegrationActivity::as_mirror( static fn() => $members->join( $space_id, $user_id ) );
			}
		} finally {
			remove_filter( 'buddynext_notification_should_send', $quiet, 10 );
		}

		if ( count( $rows ) >= self::COPY_MEMBERS_BATCH ) {
			self::queue_member_copy( $space_id, $forum_id, $last_id );
		}
	}

	/**
	 * Background job: put a space's existing members on its newly linked forum.
	 *
	 * @var string
	 */
	public const GRANT_MEMBERS_HOOK = 'buddynext_jetonomy_grant_space_members';

	/**
	 * User meta (one row per forum, non-unique key) marking a forum roster row the
	 * bridge created. Jetonomy stores only manual/invite/rule/tier as a row's
	 * source, so the bridge keeps its own record: leaving the space removes only
	 * the rows the space gave, never someone who joined the forum themselves.
	 *
	 * @var string
	 */
	private const GRANTED_META = 'buddynext_jt_granted_forum';

	/**
	 * Space meta listing the forums a space has unlinked, newest last (comma
	 * separated ids). Revoking a banned or departed member's access must reach
	 * these too, and re-enabling Discussion offers the latest one back.
	 *
	 * @var string
	 */
	private const UNLINKED_META = 'jetonomy_unlinked_forums';

	/**
	 * Space meta holding the id of the forum this bridge created for the space. Only that
	 * forum may be removed with the space; one an owner linked or an add-on adopted is not.
	 *
	 * @var string
	 */
	private const PROVISIONED_META = 'jetonomy_forum_provisioned';

	/**
	 * Forums this space has unlinked, oldest first.
	 *
	 * @param int $space_id BuddyNext space.
	 * @return int[]
	 */
	private function unlinked_forum_ids( int $space_id ): array {
		$raw = (string) buddynext_get_space_field( $space_id, self::UNLINKED_META );
		return '' === $raw ? array() : array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}

	/**
	 * The forum this space most recently unlinked, if it can still be offered back.
	 *
	 * Gone, or linked to another space since, means there is nothing to offer.
	 *
	 * @param int $space_id BuddyNext space.
	 * @return array{id:int,name:string}|null
	 */
	public function previous_discussion( int $space_id ): ?array {
		if ( $this->space_has_discussion( $space_id ) ) {
			return null;
		}
		foreach ( array_reverse( $this->unlinked_forum_ids( $space_id ) ) as $forum_id ) {
			if ( $this->discussion_exists( $forum_id ) && 0 === $this->space_id_for_forum( $forum_id ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $forum_id ) );
				return array(
					'id'   => $forum_id,
					'name' => $name,
				);
			}
		}
		return null;
	}

	/**
	 * Queue the backfill when a space is linked to a forum.
	 *
	 * @param int    $meta_id  Meta row id (unused).
	 * @param int    $space_id BuddyNext space.
	 * @param string $key      Meta key written.
	 * @param mixed  $value    Value written.
	 * @return void
	 */
	public function on_forum_linked( $meta_id, $space_id, $key, $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- metadata hook signature.
		if ( 'jetonomy_forum_id' !== $key ) {
			return;
		}
		// Any link write (link, relink, unlink) changes forum => space answers,
		// including remembered misses.
		self::$forum_space_memo = array();
		if ( (int) $value > 0 && class_exists( '\\Jetonomy\\Models\\SpaceMember' ) ) {
			self::queue_grant( (int) $space_id, (int) $value, 0 );
		}
	}

	/**
	 * Queue one batch of the space-to-forum roster backfill.
	 *
	 * @param int $space_id BuddyNext space.
	 * @param int $forum_id Linked Jetonomy forum.
	 * @param int $after_id Space member user id to continue after.
	 * @return void
	 */
	private static function queue_grant( int $space_id, int $forum_id, int $after_id ): void {
		$args = array( $space_id, $forum_id, $after_id );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::GRANT_MEMBERS_HOOK, $args, 'buddynext' );
		} elseif ( ! wp_next_scheduled( self::GRANT_MEMBERS_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 1, self::GRANT_MEMBERS_HOOK, $args );
		}
	}

	/**
	 * Put one batch of a space's active members on its linked forum, then queue the next.
	 *
	 * @param int $space_id BuddyNext space.
	 * @param int $forum_id Linked Jetonomy forum.
	 * @param int $after_id Space member user id the last batch ended on.
	 * @return void
	 */
	public function grant_space_members( int $space_id, int $forum_id, int $after_id = 0 ): void {
		if ( $forum_id <= 0 || $this->forum_id_for_space( $space_id ) !== $forum_id ) {
			return; // Unlinked or relinked since the job was queued.
		}

		global $wpdb;
		// Keyset on user_id: one index range per batch however large the space is.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, role FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND status = 'active' AND user_id > %d
				 ORDER BY user_id ASC
				 LIMIT %d",
				$space_id,
				max( 0, $after_id ),
				self::COPY_MEMBERS_BATCH
			)
		);

		$last_id = $after_id;
		foreach ( $rows as $row ) {
			$last_id = max( $last_id, (int) $row->user_id );
			$this->put_on_forum( $forum_id, (int) $row->user_id, (string) $row->role );
		}

		if ( count( $rows ) >= self::COPY_MEMBERS_BATCH ) {
			self::queue_grant( $space_id, $forum_id, $last_id );
		}
	}

	/**
	 * A member joined the space or changed role: match the linked forum roster.
	 *
	 * @param int $space_id BuddyNext space.
	 * @param int $user_id  Member.
	 * @return void
	 */
	public function grant_forum_access( $space_id, $user_id ): void {
		$forum_id = $this->forum_id_for_space( (int) $space_id );
		if ( $forum_id <= 0 ) {
			return;
		}
		$members = new \BuddyNext\Spaces\SpaceMemberService();
		if ( 'active' === $members->get_status( (int) $space_id, (int) $user_id ) ) {
			$this->put_on_forum( $forum_id, (int) $user_id, (string) $members->get_role( (int) $space_id, (int) $user_id ) );
		}
	}

	/**
	 * Someone joined the linked forum from the forum side.
	 *
	 * One member list, not two that drift: a join into the forum of an OPEN space
	 * makes them a member of the space. A private or secret space is never entered
	 * through its forum: the roster row is withdrawn and, for a private space, the
	 * person's request goes to the space's own approval. Joins the space itself
	 * wrote (active members) are left alone, which also ends the loop.
	 *
	 * @param int $forum_id Jetonomy forum id.
	 * @param int $user_id  Member who joined.
	 * @return void
	 */
	public function on_forum_member_joined( $forum_id, $user_id ): void {
		$forum_id = (int) $forum_id;
		$user_id  = (int) $user_id;
		$space_id = $this->space_id_for_forum( $forum_id );
		if ( $space_id <= 0 || $user_id <= 0 || ! class_exists( '\\Jetonomy\\Models\\SpaceMember' ) ) {
			return;
		}

		$members = new \BuddyNext\Spaces\SpaceMemberService();
		if ( 'active' === $members->get_status( $space_id, $user_id ) ) {
			return;
		}

		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
		$type  = (string) ( $space['type'] ?? '' );
		if ( \BuddyNext\Spaces\SpaceService::TYPE_OPEN === $type ) {
			// One join, made in the forum: the space's copy of it must not be rewarded again.
			IntegrationActivity::as_mirror( fn() => $members->join( $space_id, $user_id ) );
			return;
		}

		// A request approved in the forum's queue is an approval of the space request:
		// veto_forum_join() has already checked the reviewer may approve it here.
		$request = self::approved_request( $forum_id, $user_id );
		if ( \BuddyNext\Spaces\SpaceService::TYPE_PRIVATE === $type && null !== $request && $this->admit_approved( $space_id, $forum_id, $user_id, (int) $request->reviewed_by ) ) {
			return;
		}

		\Jetonomy\Models\SpaceMember::remove( $forum_id, $user_id );
		if ( \BuddyNext\Spaces\SpaceService::TYPE_PRIVATE === $type && null === $members->get_status( $space_id, $user_id ) ) {
			$members->request_join( $space_id, $user_id );
		}
		$this->invalidate_member_caches( $user_id );
	}

	/**
	 * Admit a member whose request the forum's queue approved into the private space.
	 *
	 * The forum row stays and is marked as the space's grant (leaving the space takes
	 * it away again). The space records the request if it never saw it, then approves
	 * it as the forum reviewer: the member gets the space's approval notice, and the
	 * forum's approval award is the one award for this entry.
	 *
	 * @param int $space_id    BuddyNext space.
	 * @param int $forum_id    Linked forum.
	 * @param int $user_id     Approved member.
	 * @param int $reviewer_id Who approved it in the forum.
	 * @return bool True when the member is now active in the space.
	 */
	private function admit_approved( int $space_id, int $forum_id, int $user_id, int $reviewer_id ): bool {
		$members = new \BuddyNext\Spaces\SpaceMemberService();
		$status  = $members->get_status( $space_id, $user_id );
		if ( null === $status ) {
			// Recorded only to be approved at once: nobody is asked to review it.
			self::mirror( static fn() => $members->request_join( $space_id, $user_id ) );
		}

		$admitted = IntegrationActivity::as_mirror(
			static fn() => 'invited' === $status
				? $members->join( $space_id, $user_id )
				: $members->approve_request( $space_id, $reviewer_id, $user_id )
		);
		if ( true !== $admitted ) {
			return false;
		}

		if ( ! in_array( (string) $forum_id, (array) get_user_meta( $user_id, self::GRANTED_META, false ), true ) ) {
			add_user_meta( $user_id, self::GRANTED_META, (string) $forum_id );
		}
		self::$answered_in_space[ $forum_id . ':' . $user_id ] = true;
		$this->invalidate_member_caches( $user_id );
		return true;
	}

	/**
	 * The approved forum join request a join at that moment admits, or null.
	 *
	 * Jetonomy writes the approval and then the roster row in one request, so the two
	 * times are seconds apart. An approval from a membership that ended earlier is long
	 * before the new join and does not count.
	 *
	 * @param int    $forum_id Jetonomy forum.
	 * @param int    $user_id  Member.
	 * @param string $at       When the join happened (UTC); empty means now.
	 * @return object|null Request row (reviewed_by, reviewed_at).
	 */
	public static function approved_request( int $forum_id, int $user_id, string $at = '' ): ?object {
		$joined = '' === $at ? time() : strtotime( $at );
		if ( $forum_id <= 0 || $user_id <= 0 || false === $joined || ! class_exists( '\Jetonomy\Models\JoinRequest' ) ) {
			return null;
		}

		global $wpdb;
		// JoinRequest has no read by member and status; (space_id, user_id, status) is its unique key.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT reviewed_by, reviewed_at FROM {$wpdb->prefix}jt_join_requests WHERE space_id = %d AND user_id = %d AND status = 'approved'",
				$forum_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reviewed = is_object( $row ) && null !== $row->reviewed_at ? strtotime( $row->reviewed_at . ' UTC' ) : false;

		return false !== $reviewed && abs( $joined - $reviewed ) <= MINUTE_IN_SECONDS ? $row : null;
	}

	/**
	 * A member asked to join a private space's forum: the space holds the request.
	 *
	 * The space's own staff are told by the space; the forum's notice to the same
	 * owner is dropped as a duplicate (drop_duplicate_report_notice()).
	 *
	 * @param int $forum_id Jetonomy forum.
	 * @param int $user_id  Requester.
	 * @return void
	 */
	public function on_forum_request_created( $forum_id, $user_id ): void {
		$space_id = $this->space_id_for_forum( (int) $forum_id );
		if ( $space_id <= 0 || IntegrationActivity::is_mirror() ) {
			return;
		}
		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
		if ( \BuddyNext\Spaces\SpaceService::TYPE_PRIVATE === (string) ( $space['type'] ?? '' ) ) {
			( new \BuddyNext\Spaces\SpaceMemberService() )->request_join( $space_id, (int) $user_id );
		}
	}

	/**
	 * A request was denied in the forum's queue: the space declines it too.
	 *
	 * A reviewer the space would not let decline leaves the space request pending,
	 * and keeps the forum's own notice, so the member is still told.
	 *
	 * @param int $forum_id    Jetonomy forum.
	 * @param int $user_id     Requester.
	 * @param int $reviewer_id Reviewer.
	 * @return void
	 */
	public function on_forum_request_denied( $forum_id, $user_id, $reviewer_id = 0 ): void {
		$space_id = $this->space_id_for_forum( (int) $forum_id );
		if ( $space_id <= 0 || IntegrationActivity::is_mirror() ) {
			return;
		}
		$members  = new \BuddyNext\Spaces\SpaceMemberService();
		$declined = IntegrationActivity::as_mirror( static fn() => $members->decline_request( $space_id, (int) $reviewer_id, (int) $user_id ) );
		if ( true === $declined ) {
			self::$answered_in_space[ (int) $forum_id . ':' . (int) $user_id ] = true;
		}
	}

	/**
	 * A member withdrew their forum request: the space request goes with it.
	 *
	 * @param int $forum_id Jetonomy forum.
	 * @param int $user_id  Requester.
	 * @return void
	 */
	public function on_forum_request_withdrawn( $forum_id, $user_id ): void {
		$space_id = $this->space_id_for_forum( (int) $forum_id );
		if ( $space_id <= 0 || IntegrationActivity::is_mirror() ) {
			return;
		}
		$members = new \BuddyNext\Spaces\SpaceMemberService();
		IntegrationActivity::as_mirror( static fn() => $members->cancel_request( $space_id, (int) $user_id ) );
	}

	/**
	 * The space decided or cancelled a request: no forum request stays pending.
	 *
	 * Left pending, it could be approved again in the forum's queue later and pay a
	 * second award for an entry the space already made. Withdrawing also clears the
	 * forum's "new join request" notices to its staff.
	 *
	 * @param int $space_id BuddyNext space.
	 * @param int $user_id  Requester.
	 * @return void
	 */
	public function settle_forum_request( $space_id, $user_id ): void {
		$forum_id = $this->forum_id_for_space( (int) $space_id );
		if ( $forum_id <= 0 || IntegrationActivity::is_mirror() || ! class_exists( '\Jetonomy\Models\JoinRequest' ) ) {
			return;
		}
		IntegrationActivity::as_mirror( static fn() => \Jetonomy\Models\JoinRequest::withdraw( $forum_id, (int) $user_id ) );
	}

	/**
	 * Someone left the linked forum: an ordinary member leaves the space too.
	 *
	 * Owners and moderators keep their place: their role comes from the space, and
	 * the space puts the forum row back when they next act.
	 *
	 * @param int $forum_id Jetonomy forum id.
	 * @param int $user_id  Member who left.
	 * @return void
	 */
	public function on_forum_member_left( $forum_id, $user_id ): void {
		$space_id = $this->space_id_for_forum( (int) $forum_id );
		$user_id  = (int) $user_id;
		if ( $space_id <= 0 || $user_id <= 0 ) {
			return;
		}

		$members = new \BuddyNext\Spaces\SpaceMemberService();
		if ( 'active' !== $members->get_status( $space_id, $user_id ) || 'member' !== (string) $members->get_role( $space_id, $user_id ) ) {
			return;
		}
		$members->leave( $space_id, $user_id );
	}

	/**
	 * A role was changed inside the forum: the space's role wins.
	 *
	 * A space owner or moderator is a forum admin or moderator because the space
	 * says so. A role set only in the forum is put back to what the space gave, and
	 * a person the space does not know cannot be made a forum moderator, so no
	 * power is created on a screen the space owner is not watching.
	 *
	 * @param int    $forum_id Jetonomy forum id.
	 * @param int    $user_id  Member.
	 * @param string $role     New forum role.
	 * @return void
	 */
	public function on_forum_role_changed( $forum_id, $user_id, $role ): void {
		$forum_id = (int) $forum_id;
		$user_id  = (int) $user_id;
		$space_id = $this->space_id_for_forum( $forum_id );
		if ( $space_id <= 0 || $user_id <= 0 || ! class_exists( '\\Jetonomy\\Models\\SpaceMember' ) ) {
			return;
		}

		$members = new \BuddyNext\Spaces\SpaceMemberService();
		$active  = 'active' === $members->get_status( $space_id, $user_id );
		$bn_role = $active ? (string) $members->get_role( $space_id, $user_id ) : '';
		$want    = in_array( $bn_role, array( 'owner', 'admin' ), true ) ? 'admin' : ( 'moderator' === $bn_role ? 'moderator' : 'member' );
		if ( (string) $role === $want ) {
			return;
		}

		// Setting the wanted role fires this hook again, now equal, which ends it.
		\Jetonomy\Models\SpaceMember::set_role( $forum_id, $user_id, $want );
		$this->invalidate_member_caches( $user_id );
	}

	/**
	 * A member banned from a space cannot post in, or rejoin, its forum. Issued as the system (0):
	 * BuddyNext already decided who may ban whom.
	 *
	 * @since 1.3.1
	 *
	 * @param int|string $space_id BuddyNext space ID.
	 * @param int|string $user_id  Banned member.
	 * @return void
	 */
	public function restrict_forum_for_ban( $space_id, $user_id ): void {
		$forum_id = $this->forum_id_for_space( (int) $space_id );
		if ( $forum_id > 0 && class_exists( '\\Jetonomy\\Models\\Restriction' ) ) {
			\Jetonomy\Models\Restriction::ban( (int) $user_id, 'space_ban', 0, $forum_id, 'Banned from the BuddyNext space' );
			$this->invalidate_member_caches( (int) $user_id );
		}
	}

	/**
	 * Lift the forum restriction when the space ban is lifted.
	 *
	 * @since 1.3.1
	 *
	 * @param int|string $space_id BuddyNext space ID.
	 * @param int|string $user_id  Member whose ban was lifted.
	 * @return void
	 */
	public function lift_forum_ban( $space_id, $user_id ): void {
		$forum_id = $this->forum_id_for_space( (int) $space_id );
		if ( $forum_id <= 0 || ! class_exists( '\\Jetonomy\\Models\\Restriction' ) ) {
			return;
		}

		foreach ( \Jetonomy\Models\Restriction::active_rows_for_users( array( (int) $user_id ), $forum_id ) as $row ) {
			\Jetonomy\Models\Restriction::remove_ban( (int) $row->id );
		}
		$this->invalidate_member_caches( (int) $user_id );
	}

	/**
	 * Refuse a forum-side join the space itself would refuse (banned, archived, plan-gated). Members
	 * already in the space and the bridge's own roster copies pass.
	 *
	 * @since 1.3.1
	 *
	 * @param true|\WP_Error $proceed  Answer so far.
	 * @param int            $user_id  Member joining.
	 * @param int            $forum_id Jetonomy forum.
	 * @return true|\WP_Error
	 */
	public function veto_forum_join( $proceed, $user_id = 0, $forum_id = 0 ) {
		$space_id = $this->space_id_for_forum( (int) $forum_id );
		if ( is_wp_error( $proceed ) || $space_id <= 0 || IntegrationActivity::is_mirror() ) {
			return $proceed;
		}

		$members = new \BuddyNext\Spaces\SpaceMemberService();
		$space   = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
		if ( null === $space || 'active' === $members->get_status( $space_id, (int) $user_id ) ) {
			return $proceed;
		}
		// Approving a private space's forum request admits the member to the space, so
		// the reviewer must be someone the space lets approve (owner, moderator, site
		// admin), and the space's own gate (a paid plan, a seat cap) must pass. The
		// space words that refusal for the reviewer; Jetonomy hands the request back as pending.
		$request = \BuddyNext\Spaces\SpaceService::TYPE_PRIVATE === (string) ( $space['type'] ?? '' )
			? self::approved_request( (int) $forum_id, (int) $user_id )
			: null;
		$refused = null === $request ? null : $members->approval_refusal( $space_id, (int) $request->reviewed_by, (int) $user_id, false );
		if ( null !== $refused ) {
			return new \WP_Error( 'bn_space_approval_refused', $refused->get_error_message(), array( 'status' => 403 ) );
		}

		if ( ! $members->can_join( $space, (int) $user_id ) ) {
			// A reviewer approving a request is told why it stays pending (the space's gate: a plan, a
			// seat cap, a ban); a member asking to join gets the plain refusal.
			$approving = null !== self::approved_request( (int) $forum_id, (int) $user_id );
			return new \WP_Error(
				'bn_space_join_refused',
				$approving
					? __( 'This member cannot join this space, so the request stays pending.', 'buddynext' )
					: __( 'You cannot join this discussion right now.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return $proceed;
	}

	/**
	 * A member left, was removed or was banned: take off the roster row the space gave.
	 *
	 * @param int $space_id BuddyNext space.
	 * @param int $user_id  Former member.
	 * @return void
	 */
	public function revoke_forum_access( $space_id, $user_id ): void {
		$space_id = (int) $space_id;
		$user_id  = (int) $user_id;
		$granted  = (array) get_user_meta( $user_id, self::GRANTED_META, false );
		if ( empty( $granted ) || ! class_exists( '\\Jetonomy\\Models\\SpaceMember' ) ) {
			return;
		}

		// The forum linked now AND any this space unlinked earlier: unlinking leaves
		// the roster alone, so a member banned afterwards must still lose the old
		// forum. A forum another space has taken over since is not ours to revoke.
		$forums = array_unique( array_merge( array( $this->forum_id_for_space( $space_id ) ), $this->unlinked_forum_ids( $space_id ) ) );
		foreach ( $forums as $forum_id ) {
			if ( $forum_id <= 0 || ! in_array( (string) $forum_id, $granted, true ) ) {
				continue;
			}
			$owner = $this->space_id_for_forum( $forum_id );
			if ( 0 !== $owner && $owner !== $space_id ) {
				continue;
			}
			\Jetonomy\Models\SpaceMember::remove( $forum_id, $user_id );
			delete_user_meta( $user_id, self::GRANTED_META, (string) $forum_id );
		}
		$this->invalidate_member_caches( $user_id );
	}

	/**
	 * Add or update one member's forum roster row from their space role.
	 *
	 * A row the space created follows the space role both ways. A row the member
	 * already had is never lowered (Jetonomy's add() only raises) and never marked,
	 * so leaving the space leaves it alone.
	 *
	 * @param int    $forum_id Jetonomy forum.
	 * @param int    $user_id  Member.
	 * @param string $bn_role  BuddyNext space role.
	 * @return void
	 */
	private function put_on_forum( int $forum_id, int $user_id, string $bn_role ): void {
		if ( $user_id <= 0 || ! class_exists( '\\Jetonomy\\Models\\SpaceMember' ) ) {
			return;
		}
		$role    = in_array( $bn_role, array( 'owner', 'admin' ), true ) ? 'admin' : ( 'moderator' === $bn_role ? 'moderator' : 'member' );
		$granted = in_array( (string) $forum_id, (array) get_user_meta( $user_id, self::GRANTED_META, false ), true );

		// A copy of the space's roster, not a second join: nothing rewards it again.
		\BuddyNext\Feed\IntegrationActivity::as_mirror(
			static function () use ( $forum_id, $user_id, $role, $granted ): void {
				if ( \Jetonomy\Models\SpaceMember::is_member( $forum_id, $user_id ) ) {
					if ( $granted ) {
						\Jetonomy\Models\SpaceMember::set_role( $forum_id, $user_id, $role );
					} else {
						\Jetonomy\Models\SpaceMember::add( $forum_id, $user_id, $role ); // Raise only.
					}
				} elseif ( true === \Jetonomy\Models\SpaceMember::add( $forum_id, $user_id, $role ) ) {
					add_user_meta( $user_id, self::GRANTED_META, (string) $forum_id );
				}
			}
		);
		$this->invalidate_member_caches( $user_id );
	}

	/**
	 * Turn a space's dedicated Discussion on or off. Only flips the enabled flag —
	 * the permanent jetonomy_forum_id link and all discussion content are never
	 * touched, so re-enabling restores the exact same discussion.
	 *
	 * @param int  $space_id BuddyNext space ID.
	 * @param bool $enabled  Desired state.
	 * @return void
	 */
	public function set_discussion_enabled( int $space_id, bool $enabled ): void {
		update_space_meta( absint( $space_id ), 'discussion_enabled', $enabled ? '1' : '0' );
		// The forum follows the switch: off hides and locks it, on opens it again.
		$this->sync_discussion_visibility( $space_id );
	}

	/**
	 * Apply the owner's Discussion on/off choice for a space.
	 *
	 * The one path behind Space settings > Integrations and
	 * POST /spaces/{id}/discussion:
	 * - first enable: create the dedicated discussion, or (initial setup only)
	 *   link one the actor may adopt: a site admin any existing discussion, a
	 *   space owner only one they authored (re-validated, a crafted request
	 *   cannot widen it);
	 * - later on/off: only the enabled flag flips; the discussion and its
	 *   content are never discarded or duplicated.
	 *
	 * @param int  $space_id BuddyNext space ID.
	 * @param bool $enabled  Desired state.
	 * @param int  $link_id  Existing discussion to link on first enable, or 0.
	 * @param int  $actor_id Who is making the change.
	 * @return true|\WP_Error Error when the discussion could not be created.
	 */
	public function apply_discussion_choice( int $space_id, bool $enabled, int $link_id, int $actor_id ) {
		if ( ! $enabled ) {
			$this->set_discussion_enabled( $space_id, false );
			return true;
		}

		if ( ! $this->space_has_discussion( $space_id ) ) {
			// One forum, one space: a forum another space holds is never linked twice.
			if ( $link_id > 0 && ! $this->forum_free_for( $link_id, $space_id ) ) {
				return new \WP_Error( 'bn_discussion_taken', __( 'That discussion already belongs to another space. Pick a different one, or start a new discussion.', 'buddynext' ), array( 'status' => 409 ) );
			}
			$space    = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
			$previous = $this->previous_discussion( $space_id );
			$may_link = $link_id > 0 && (
				// The space's own earlier discussion is always its to take back.
				( null !== $previous && $previous['id'] === $link_id )
				|| ( user_can( $actor_id, 'manage_options' )
					? $this->discussion_exists( $link_id )
					: $this->discussion_owned_by( $link_id, (int) ( $space['owner_id'] ?? 0 ) ) )
			);
			if ( $may_link ) {
				update_space_meta( $space_id, 'jetonomy_forum_id', $link_id );
				// An existing forum comes with its people (an imported wpForo or
				// bbPress forum can have thousands): bring them into an open space,
				// once, in the background. Membership is not mirrored after this.
				self::queue_member_copy( $space_id, $link_id, 0 );
			} elseif ( $this->provision_space_forum( $space_id ) <= 0 ) {
				// Leaving it on would give members a Discussion tab with nothing behind it.
				return new \WP_Error( 'bn_discussion_not_created', __( 'The discussion could not be created, so it was not enabled.', 'buddynext' ), array( 'status' => 500 ) );
			}
		}

		// A forum linked to a private or secret space must not stay open to everyone.
		$this->sync_discussion_visibility( $space_id );

		$this->set_discussion_enabled( $space_id, true );
		return true;
	}

	/**
	 * Resolve the per-space Discussion status for the owner control + tab.
	 *
	 * `has_discussion` — the space has a dedicated discussion (permanent, 1:1).
	 * `enabled`        — the owner has it toggled on (members see the tab).
	 * `name` / `url`   — which discussion is linked, so the settings screen can
	 *                    always show it. The bridge owns all jt_* access.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return array{has_discussion:bool,enabled:bool,forum_id:int,name:string,url:string,previous?:array{id:int,name:string}|null}
	 */
	public function space_discussion_status( int $space_id ): array {
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 ) {
			return array(
				'has_discussion' => false,
				'enabled'        => false,
				'forum_id'       => 0,
				'name'           => '',
				'url'            => '',
				'previous'       => $this->previous_discussion( $space_id ),
			);
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$name = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT title FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $forum_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'has_discussion' => true,
			'enabled'        => $this->space_discussion_enabled( $space_id ),
			'forum_id'       => $forum_id,
			'name'           => $name,
			'url'            => $this->space_forum_url( $space_id ),
		);
	}

	/**
	 * Jetonomy discussions a space owner may attach to their space — ONLY the
	 * discussions that owner authored. Populates the "link an existing discussion"
	 * picker, so a space owner can never attach someone else's discussion.
	 *
	 * @param int $owner_id The space owner the picker is scoped to.
	 * @param int $limit    Max rows (1-200). Default 100.
	 * @return array<int,array{id:int,title:string}>
	 */
	public function linkable_discussions( int $owner_id, int $limit = 100 ): array {
		$owner_id = absint( $owner_id );
		if ( $owner_id <= 0 || ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return array();
		}

		$limit = max( 1, min( 200, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title FROM {$wpdb->prefix}jt_spaces WHERE author_id = %d AND status = 'active' ORDER BY title ASC LIMIT %d",
				$owner_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( (array) $rows as $bn_ld_space ) {
			if ( isset( $bn_ld_space->id, $bn_ld_space->title ) ) {
				$out[] = array(
					'id'    => (int) $bn_ld_space->id,
					'title' => (string) $bn_ld_space->title,
				);
			}
		}

		return $out;
	}

	/**
	 * Whether a Jetonomy discussion (jt_spaces id) exists and is active — the
	 * link-authorization guard for SITE ADMINS, who may attach any discussion.
	 * Members are held to the stricter discussion_owned_by() check instead.
	 *
	 * @param int $forum_id jt_spaces id.
	 * @return bool
	 */
	public function discussion_exists( int $forum_id ): bool {
		$forum_id = absint( $forum_id );
		if ( $forum_id <= 0 ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_spaces WHERE id = %d AND status = 'active' LIMIT 1", $forum_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $found > 0;
	}

	/**
	 * Whether a Jetonomy discussion is authored by a given space owner — the
	 * server-side authorization guard for the "link existing" action, so a crafted
	 * POST can never attach a discussion the space owner does not own.
	 *
	 * @param int $forum_id jt_spaces id.
	 * @param int $owner_id The space owner who must own the discussion.
	 * @return bool
	 */
	public function discussion_owned_by( int $forum_id, int $owner_id ): bool {
		$forum_id = absint( $forum_id );
		$owner_id = absint( $owner_id );
		if ( $forum_id <= 0 || $owner_id <= 0 ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$author_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT author_id FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $forum_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $author_id > 0 && $author_id === $owner_id;
	}

	/**
	 * Search discussions by title for the "link an existing discussion" typeahead
	 * — bounded so it scales to sites with thousands of discussions (never dumps
	 * the full set). Scope mirrors the save-time authorization: pass $owner_id > 0
	 * to restrict to that member's OWN discussions; pass 0 for the admin-only
	 * "search across all discussions" scope.
	 *
	 * A forum already linked to another space is left out: a forum belongs to one
	 * space, so offering it would only lead to a refused save.
	 *
	 * @param string $q            Title query (substring match).
	 * @param int    $owner_id     Restrict to this author; 0 = all authors (admin scope).
	 * @param int    $limit        Max rows (1-50). Default 20.
	 * @param int    $for_space_id Space the picker is for; its own forum stays listed.
	 * @return array<int,array{id:int,title:string}>
	 */
	public function search_discussions( string $q, int $owner_id, int $limit = 20, int $for_space_id = 0 ): array {
		if ( ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return array();
		}

		$limit = max( 1, min( 50, $limit ) );

		global $wpdb;
		$like = '%' . $wpdb->esc_like( trim( $q ) ) . '%';

		// ponytail: anti-join on bn_space_meta (indexed by meta_key, value unindexed,
		// the same shape as a WP postmeta lookup). Fine for a LIMITed typeahead; add a
		// value index if a site with tens of thousands of linked spaces shows it slow.
		$not_linked_elsewhere = $wpdb->prepare(
			"NOT EXISTS ( SELECT 1 FROM {$wpdb->bn_spacemeta} m
				WHERE m.meta_key = 'jetonomy_forum_id' AND m.meta_value = s.id AND m.bn_space_id <> %d )",
			absint( $for_space_id )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $not_linked_elsewhere is prepared above.
		if ( $owner_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.title FROM {$wpdb->prefix}jt_spaces s WHERE s.author_id = %d AND s.status = 'active' AND s.title LIKE %s AND {$not_linked_elsewhere} ORDER BY s.title ASC LIMIT %d",
					absint( $owner_id ),
					$like,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.title FROM {$wpdb->prefix}jt_spaces s WHERE s.status = 'active' AND s.title LIKE %s AND {$not_linked_elsewhere} ORDER BY s.title ASC LIMIT %d",
					$like,
					$limit
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $bn_sd_row ) {
			if ( isset( $bn_sd_row->id, $bn_sd_row->title ) ) {
				$out[] = array(
					'id'    => (int) $bn_sd_row->id,
					'title' => (string) $bn_sd_row->title,
				);
			}
		}

		return $out;
	}

	/**
	 * What happens to a space's forum when the space is deleted.
	 *
	 * 'purge': the bridge made it for this space, so it goes with it. 'hide': an owner linked
	 * it, so it is closed to the public and locked but its content is kept. 'unlink': an
	 * add-on claimed it (a course discussion), so it is left exactly as it is. 'none': no forum.
	 *
	 * @since 1.3.1
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return string purge | hide | unlink | none
	 */
	public function forum_fate_on_delete( int $space_id ): string {
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 ) {
			return 'none';
		}

		/**
		 * Whether an add-on has claimed a space's forum as its own (a course discussion).
		 *
		 * A claimed forum outlives the space: deleting the community only unlinks it.
		 *
		 * @since 1.3.1
		 *
		 * @param bool $claimed  Whether the forum belongs to something other than the space.
		 * @param int  $space_id BuddyNext space ID.
		 * @param int  $forum_id Linked Jetonomy forum.
		 */
		if ( apply_filters( 'buddynext_space_forum_claimed', false, $space_id, $forum_id ) ) {
			return 'unlink';
		}

		return $this->forum_made_for_space( $space_id, $forum_id ) ? 'purge' : 'hide';
	}

	/**
	 * Whether the bridge made the forum for this space. A forum from before the marker counts only
	 * when its slug is the one provisioning generates and it was created no earlier than the space.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @param int $forum_id Linked Jetonomy forum.
	 * @return bool
	 */
	private function forum_made_for_space( int $space_id, int $forum_id ): bool {
		$marked = (int) buddynext_get_space_field( $space_id, self::PROVISIONED_META );
		if ( $marked > 0 ) {
			return $marked === $forum_id;
		}

		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
		$forum = is_callable( array( '\Jetonomy\Models\Space', 'find' ) ) ? \Jetonomy\Models\Space::find( $forum_id ) : null;
		if ( null === $space || null === $forum || empty( $space['slug'] ) ) {
			return false;
		}

		$slug       = (string) $forum->slug;
		$forum_born = (int) strtotime( (string) ( $forum->created_at ?? '' ) );
		$space_born = (int) strtotime( (string) ( $space['created_at'] ?? '' ) );

		return ( $slug === $space['slug'] || $slug === $space['slug'] . '-' . $space_id )
			&& $forum_born > 0 && $space_born > 0 && $forum_born >= $space_born - MINUTE_IN_SECONDS;
	}

	/**
	 * Close a deleted space's forum at once, then remove it if it is ours. Safe to run twice (a big
	 * space fires this again when its background deletion ends). Below Jetonomy 2.0.3 a delete would
	 * orphan every topic, so there the forum only stays hidden.
	 *
	 * @since 1.3.1
	 *
	 * @param int|string $space_id BuddyNext space ID.
	 * @return void
	 */
	public function retire_space_forum( $space_id ): void {
		$space_id = (int) $space_id;
		$fate     = $this->forum_fate_on_delete( $space_id );
		if ( in_array( $fate, array( 'none', 'unlink' ), true ) || ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return;
		}

		$forum_id = $this->forum_id_for_space( $space_id );
		if ( null === \Jetonomy\Models\Space::find( $forum_id ) ) {
			return;
		}

		\Jetonomy\Models\Space::update(
			$forum_id,
			array(
				'visibility' => 'hidden',
				'status'     => 'locked',
			)
		);

		// The forum's topics were indexed under this space; Jetonomy's bulk purge fires no
		// per-topic hook, so the rows would outlive both the forum and the space.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type' => 'discussion',
				'space_id'    => $space_id,
			),
			array( '%s', '%d' )
		);
		$this->invalidate_space_caches( $forum_id );
		$this->purge_forum_pages( $forum_id );

		if ( 'hide' === $fate ) {
			// Topics of a forum linked after it filled have cards that belong to no space, so the
			// space's own deletion never reaches them.
			$this->withdraw_forum_surfaces( $forum_id, 0 );
		}

		if ( 'purge' === $fate && defined( 'JETONOMY_VERSION' ) && version_compare( JETONOMY_VERSION, '2.0.3', '>=' ) ) {
			\Jetonomy\Models\Space::delete( $forum_id );
			// Each member was granted the forum with a usermeta row; none of them point anywhere now.
			delete_metadata( 'user', 0, self::GRANTED_META, (string) $forum_id, true );
		}
	}

	/**
	 * Take a forum's topics off the feed and out of search, in batches.
	 *
	 * @since 1.3.1
	 *
	 * @param int|string $forum_id Jetonomy forum id.
	 * @param int|string $after    Last topic id already handled.
	 * @return void
	 */
	public function withdraw_forum_surfaces( $forum_id, $after = 0 ): void {
		global $wpdb;
		$forum_id = (int) $forum_id;
		$after    = (int) $after;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_posts WHERE space_id = %d AND id > %d ORDER BY id ASC LIMIT 200", $forum_id, $after )
			)
		);
		foreach ( $ids as $id ) {
			$url = $this->discussion_url( $id, $forum_id );
			if ( '' !== $url ) {
				IntegrationActivity::withdraw( $url, 'discussion' );
			}
			$wpdb->delete(
				$wpdb->prefix . 'bn_search_index',
				array(
					'object_type' => 'discussion',
					'object_id'   => $id,
				),
				array( '%s', '%d' )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 200 === count( $ids ) && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'buddynext_async_withdraw_forum_surfaces', array( $forum_id, end( $ids ) ), 'buddynext' );
		}
	}

	/**
	 * Purge the community pages from the page cache, and Jetonomy's own sidebar list (kept five
	 * minutes, not cleared when a forum is hidden), so guests stop seeing a closed forum.
	 *
	 * @param int $forum_id Jetonomy forum id.
	 * @return void
	 */
	private function purge_forum_pages( int $forum_id ): void {
		// Jetonomy keeps its sidebar "Trending" list for five minutes and does not clear it when a
		// forum is hidden or removed, so a closed forum's topic title kept showing to guests.
		if ( is_callable( array( '\\Jetonomy\\Cache', 'delete_many' ) ) ) {
			\Jetonomy\Cache::delete_many( array( 'sidebar:trending:0:guest', 'sidebar:trending:0:priv', "sidebar:trending:{$forum_id}:guest", "sidebar:trending:{$forum_id}:priv" ) );
		}

		if ( ! function_exists( '\\Jetonomy\\base_url' ) ) {
			return;
		}

		$base  = trailingslashit( \Jetonomy\base_url() );
		$forum = class_exists( '\\Jetonomy\\Models\\Space' ) ? \Jetonomy\Models\Space::find( $forum_id ) : null;
		\BuddyNext\Core\PageCachePurger::purge( $base );
		if ( null !== $forum && '' !== (string) $forum->slug ) {
			\BuddyNext\Core\PageCachePurger::purge( $base . 's/' . $forum->slug . '/' );
		}
	}

	/**
	 * Unlink a space's Discussion (owner opt-out). Clears the link only: the
	 * Jetonomy discussion, its posts and its members stay as they are, and the
	 * space keeps every member it has, including those copied in from the forum
	 * (members joined a community; dropping them silently is worse than keeping
	 * them). New forum activity and members stop reaching the space, and queued
	 * copy/grant batches stop on their own link check. Turning Discussion back on
	 * offers the create-or-link choice again.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return void
	 */
	public function unlink_space_discussion( int $space_id ): void {
		$space_id = absint( $space_id );
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 ) {
			return;
		}
		update_space_meta( $space_id, 'jetonomy_forum_id', 0 );
		// With no discussion behind it the switch reads off, and the forum is
		// remembered so re-enabling offers it back and a later ban still revokes it.
		$this->set_discussion_enabled( $space_id, false );
		update_space_meta( $space_id, self::UNLINKED_META, implode( ',', array_unique( array_merge( $this->unlinked_forum_ids( $space_id ), array( $forum_id ) ) ) ) );
		$this->invalidate_space_caches( $forum_id );

		/**
		 * Fires after a space's Discussion is unlinked from its Jetonomy forum.
		 *
		 * @since 1.2.6
		 *
		 * @param int $space_id BuddyNext space ID.
		 * @param int $forum_id Jetonomy forum that was linked.
		 */
		do_action( 'buddynext_space_discussion_unlinked', $space_id, $forum_id );
	}

	/**
	 * The space type the forum is shaped by: a plan-gated open space is private for its forum, so
	 * the forum is not public to people the space would ask to pay.
	 *
	 * @since 1.3.1
	 *
	 * @param array<string, mixed> $space Hydrated space.
	 * @return string open | private | secret
	 */
	public static function forum_type_for( array $space ): string {
		$type = (string) ( $space['type'] ?? '' );

		return ( \BuddyNext\Spaces\SpaceService::TYPE_OPEN === $type && ! empty( $space['is_gated'] ) ) ? \BuddyNext\Spaces\SpaceService::TYPE_PRIVATE : $type;
	}

	/**
	 * The discussion visibility a BuddyNext space type requires.
	 *
	 * ONE definition, used both when the discussion is created and when the
	 * space's type later changes, so the two can never disagree. This used to
	 * be the literal 'public' at the creation site and nothing at all on
	 * change: enabling Discussion on a private or secret space published its
	 * entire discussion history to the world, silently and permanently, and
	 * nothing re-evaluated it afterwards.
	 *
	 * The enums map 1:1. An unrecognised type resolves to 'hidden' rather than
	 * 'public' - if we cannot tell what a space is, the safe answer is the one
	 * that exposes nothing, and a hidden discussion is recoverable where a
	 * leaked one is not.
	 *
	 * @param string $space_type BuddyNext space type: open | private | secret.
	 * @return string Jetonomy visibility: public | private | hidden.
	 */
	public static function discussion_visibility_for( string $space_type ): string {
		switch ( $space_type ) {
			case \BuddyNext\Spaces\SpaceService::TYPE_OPEN:
				return 'public';
			case \BuddyNext\Spaces\SpaceService::TYPE_PRIVATE:
				return 'private';
			case \BuddyNext\Spaces\SpaceService::TYPE_SECRET:
				return 'hidden';
			default:
				return 'hidden';
		}
	}

	/**
	 * Run the shared banned-content check on a new forum topic (title and body).
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $data Topic data Jetonomy is about to write.
	 * @return mixed The data, or a WP_Error that refuses the topic.
	 */
	public function check_new_topic( $data ) {
		return is_array( $data ) ? $this->scan_forum_text( $data, trim( ( $data['title'] ?? '' ) . "\n" . ( $data['content'] ?? '' ) ) ) : $data;
	}

	/**
	 * Run the same check when a topic or reply is edited, so a clean post cannot be edited into a banned one.
	 *
	 * Jetonomy has no before-update filter, so the edit routes (PATCH on a topic or a reply) are
	 * read here before their callback runs. Only create-time rules are skipped (the 'edit' context).
	 *
	 * @since 1.3.0
	 *
	 * @param mixed            $response Response so far.
	 * @param array            $handler  Matched route handler (unused).
	 * @param \WP_REST_Request $request  The request.
	 * @return mixed The response, or a WP_Error that refuses the edit.
	 */
	public function check_forum_edit( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		if ( ! $request instanceof \WP_REST_Request || 'PATCH' !== $request->get_method() || ! preg_match( '#^/jetonomy/v1/(?:posts|replies)/\d+$#', $request->get_route() ) ) {
			return $response;
		}
		$text = trim( (string) $request->get_param( 'title' ) . "\n" . (string) $request->get_param( 'content' ) );
		if ( '' === $text || is_wp_error( $response ) ) {
			return $response;
		}
		$checked = $this->scan_forum_text( array( 'author_id' => get_current_user_id() ), $text, 'edit' );
		return is_wp_error( $checked ) ? $checked : $response;
	}

	/**
	 * Run the shared banned-content check on a new forum reply.
	 *
	 * @param mixed $data Reply data Jetonomy is about to write.
	 * @return mixed The data, or a WP_Error that refuses the reply.
	 */
	public function check_new_reply( $data ) {
		return is_array( $data ) ? $this->scan_forum_text( $data, (string) ( $data['content'] ?? '' ) ) : $data;
	}

	/**
	 * The one check behind both: banned words, blocked links and the Pro rules.
	 *
	 * Only a hard block refuses the write (a flag verdict lets it through, as it does for a comment). The
	 * member sees one generic line; which word or rule fired is not told to them.
	 *
	 * @param array<string,mixed> $data Row Jetonomy is about to write.
	 * @param string              $text What the member wrote.
	 * @param string              $context 'create' or 'edit': the edit context skips the create-time rules.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function scan_forum_text( array $data, string $text, string $context = 'create' ) {
		if ( '' === trim( $text ) || ! function_exists( 'buddynext_service' ) ) {
			return $data;
		}
		$verdict = buddynext_service( 'safeguard' )->check_content( wp_strip_all_tags( $text ), '', (int) ( $data['author_id'] ?? 0 ), 0, $context, 'post' );
		if ( ! is_wp_error( $verdict ) || \BuddyNext\Moderation\SafeguardService::is_flag_verdict( $verdict ) ) {
			return $data;
		}
		return new \WP_Error( 'bn_forum_content_blocked', __( 'This cannot be posted because it contains content that is not allowed in this community.', 'buddynext' ), array( 'status' => 422 ) );
	}

	/**
	 * Re-place a topic's card and search row when it moves to another forum.
	 *
	 * Jetonomy's move changes the topic's forum and fires nothing, so the card kept its old
	 * Space and address and the search row kept the old Space: a topic moved into a private
	 * forum stayed findable by its title. Reads the successful response of the move route.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed            $response Response so far.
	 * @param array            $handler  Matched route handler (unused).
	 * @param \WP_REST_Request $request  The request.
	 * @return mixed The response, untouched.
	 */
	public function on_move_response( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() || $response instanceof \WP_Error ) {
			return $response;
		}
		if ( ! preg_match( '#^/jetonomy/v1/posts/(\d+)/move$#', $request->get_route(), $match ) ) {
			return $response;
		}
		if ( $response instanceof \WP_REST_Response && $response->get_status() >= 300 ) {
			return $response;
		}
		$this->on_topic_moved( (int) $match[1] );
		return $response;
	}

	/**
	 * Point a moved topic's existing card at its new forum and re-index it.
	 *
	 * The card keeps its id, date and comments: it is rewritten in place to the new address
	 * and moved to the new forum's Space, then the normal sync refreshes the search row and
	 * withdraws the card if the new forum is not public.
	 *
	 * @since 1.3.0
	 *
	 * @param int $post_id Jetonomy topic id.
	 * @return void
	 */
	public function on_topic_moved( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT space_id, title, content_plain FROM {$wpdb->prefix}jt_posts WHERE id = %d", $post_id ) );
		if ( null === $row ) {
			return;
		}
		$space_id = (int) $row->space_id;
		$url      = $this->discussion_url( $post_id, $space_id );
		if ( '' !== $url ) {
			IntegrationActivity::rewrite_by_meta(
				'discussion',
				'post_id',
				$post_id,
				__( 'started a discussion', 'buddynext' ),
				$url,
				(string) $row->title,
				wp_trim_words( wp_strip_all_tags( (string) $row->content_plain ), 30, '…' ),
				array(
					'post_id'      => $post_id,
					'notify_space' => false,
				)
			);
			IntegrationActivity::set_space_by_meta( 'discussion', 'post_id', $post_id, $this->space_id_for_forum( $space_id ) );
		}
		$this->sync_discussion( $post_id, $space_id );
	}

	/**
	 * Keep a linked discussion's visibility in step with its space's type.
	 *
	 * Creation-time correctness is not enough: an owner who opens a space to
	 * the public, or locks a public one down, expects the conversation inside
	 * it to follow. Without this the discussion keeps whatever it was born
	 * with, so the first time anyone tightens a space the leak reappears.
	 *
	 * Only writes when the value actually differs, so an unrelated space edit
	 * does not churn Jetonomy rows.
	 *
	 * @param int   $space_id BuddyNext space ID.
	 * @param int   $user_id  Actor (unused; the space's type is the authority).
	 * @param array $fields   Fields saved.
	 * @return void
	 */
	public function sync_discussion_visibility( $space_id, $user_id = 0, $fields = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $user_id/$fields are required by the buddynext_space_updated hook signature (3 args); the space's type is the authority here.
		$space_id = (int) $space_id;
		$forum_id = (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );
		if ( $space_id <= 0 || $forum_id <= 0 || ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return;
		}

		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return;
		}

		$type = self::forum_type_for( $space );
		$want = self::discussion_visibility_for( $type );
		$join = self::discussion_join_policy_for( $type );
		$jt   = \Jetonomy\Models\Space::find( $forum_id );
		if ( ! $jt ) {
			return;
		}

		// The space decides the forum's state: open, read-only while archived, closed while
		// it is being deleted or its Discussions are off. Without this a type change reopened a forum the delete had hidden.
		if ( \BuddyNext\Spaces\SpaceService::is_deleting( $space_id ) || $this->discussions_off( $space_id ) ) {
			$want   = 'hidden';
			$status = 'locked';
		} else {
			$status = ! empty( $space['is_archived'] ) ? 'archived' : 'active';
		}

		// Visibility and join policy move together: the forum is only as open as
		// the space. Writing visibility alone left a reopened space's forum on
		// "approval" and a newly linked public forum open to anyone.
		$change = array();
		if ( (string) ( $jt->visibility ?? '' ) !== $want ) {
			$change['visibility'] = $want;
		}
		if ( (string) ( $jt->status ?? '' ) !== $status ) {
			$change['status'] = $status;
		}
		if ( (string) ( $jt->join_policy ?? '' ) !== $join ) {
			$change['join_policy'] = $join;
		}
		if ( empty( $change ) ) {
			return;
		}

		\Jetonomy\Models\Space::update( $forum_id, $change );
		$this->purge_forum_pages( $forum_id );

		// The search rows were written under the old type. Going private is clamped
		// by the search service, but a reopened space needs each topic decided
		// again, so re-index them in the background.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'buddynext_async_reindex_forum_discussions', array( $forum_id, 0 ), 'buddynext' );
		} else {
			$this->reindex_forum_discussions( $forum_id, 0 );
		}
	}

	/**
	 * Re-index one forum's published topics in batches of 200.
	 *
	 * @param int $forum_id Jetonomy forum id.
	 * @param int $offset   Rows already done.
	 * @return void
	 */
	public function reindex_forum_discussions( int $forum_id, int $offset = 0 ): void {
		global $wpdb;
		$batch = 200;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}jt_posts WHERE space_id = %d AND status = 'publish' ORDER BY id ASC LIMIT %d OFFSET %d",
				$forum_id,
				$batch,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $ids as $id ) {
			$this->sync_discussion( (int) $id, $forum_id );
		}
		if ( count( $ids ) === $batch && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'buddynext_async_reindex_forum_discussions', array( $forum_id, $offset + $batch ), 'buddynext' );
		}
	}

	/**
	 * How a space's linked forum admits people, from the space's type.
	 *
	 * @param string $space_type Space type (open, private, secret).
	 * @return string Jetonomy join_policy: open, approval or invite.
	 */
	public static function discussion_join_policy_for( string $space_type ): string {
		switch ( $space_type ) {
			case \BuddyNext\Spaces\SpaceService::TYPE_OPEN:
				return 'open';
			case \BuddyNext\Spaces\SpaceService::TYPE_PRIVATE:
				return 'approval';
			default:
				return 'invite';
		}
	}

	/**
	 * Carry a saved "who can post" to the space's linked forum.
	 *
	 * Forum roles only say owner, moderator or member, so this is the forum's own
	 * who_can_post setting rather than a demoted role: replying and voting are not
	 * what the space restricted. A plan rule added through
	 * buddynext_space_can_post is not carried.
	 *
	 * @param int   $space_id BuddyNext space ID.
	 * @param array $saved    Saved field key => value.
	 * @return void
	 */
	public function sync_discussion_posting( $space_id, $saved = array() ): void {
		if ( array_key_exists( 'who_can_post', (array) $saved ) ) {
			$this->apply_discussion_posting( (int) $space_id, true );
		}
	}

	/**
	 * Write the space's posting rule to its forum, when it differs.
	 *
	 * @param int  $space_id  BuddyNext space ID.
	 * @param bool $may_clear Whether "members" may clear a stricter forum rule. False
	 *                        when linking: an adopted forum keeps its owner's rule
	 *                        until the space's setting is actually saved.
	 * @return void
	 */
	private function apply_discussion_posting( int $space_id, bool $may_clear ): void {
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 || ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return;
		}

		$map  = array(
			'mods'  => 'moderators',
			'owner' => 'admins',
		);
		$want = $map[ (string) buddynext_get_space_field( $space_id, 'who_can_post' ) ] ?? '';
		$have = (string) ( \Jetonomy\Models\Space::get_settings( $forum_id )['who_can_post'] ?? '' );
		if ( $want === $have || ( '' === $want && ! $may_clear ) ) {
			return;
		}

		\Jetonomy\Models\Space::update(
			$forum_id,
			array( 'settings' => wp_json_encode( \Jetonomy\Models\Space::merge_settings( $forum_id, array( 'who_can_post' => $want ) ) ) )
		);
	}

	/**
	 * Once: spaces restricted before the rule was carried still had open forums.
	 *
	 * Bounded by the sites own restricted spaces, which is a short list.
	 *
	 * @return void
	 */
	public function backfill_discussion_posting(): void {
		if ( get_option( 'buddynext_discussion_posting_synced' ) ) {
			return;
		}
		update_option( 'buddynext_discussion_posting_synced', 1, false );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_ids = (array) $wpdb->get_col(
			"SELECT rule.bn_space_id FROM {$wpdb->prefix}bn_space_meta rule
			 INNER JOIN {$wpdb->prefix}bn_space_meta forum ON forum.bn_space_id = rule.bn_space_id AND forum.meta_key = 'jetonomy_forum_id'
			 WHERE rule.meta_key = 'who_can_post' AND rule.meta_value IN ( 'mods', 'owner' )"
		);
		foreach ( $space_ids as $space_id ) {
			$this->apply_discussion_posting( (int) $space_id, false );
		}
	}

	/**
	 * Provision (once) the ONE dedicated Jetonomy discussion a BuddyNext space
	 * owns for its lifetime, and store the permanent link.
	 *
	 * A space's discussion is 1:1 and permanent: once created it is NEVER
	 * duplicated or recreated. If the space already has a linked discussion id
	 * this returns it unchanged (so turning the toggle off then on reuses the
	 * exact same discussion — the enabled flag, not this link, is what hides it).
	 *
	 * The new discussion is created with a collision-proof slug derived from the
	 * space slug (suffixed with the space id when that base slug is already taken),
	 * so it can NEVER silently adopt an unrelated Jetonomy space that merely shares
	 * a slug — that slug-matching was a hijack risk and is gone.
	 *
	 * Visibility is derived from the space's own type, never assumed.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return int Jetonomy discussion (jt_spaces) id, or 0 on failure.
	 */
	public function provision_space_forum( int $space_id ): int {
		$existing = (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );
		if ( $existing > 0 ) {
			return $existing;
		}

		if ( ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return 0;
		}

		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return 0;
		}

		/**
		 * Adopt an existing Jetonomy space instead of provisioning a new one.
		 *
		 * ASKED, not assumed. This file cannot know why a space exists - a
		 * community linked to a Learnomy course already has a Jetonomy space
		 * carrying that course's access rule, and provisioning a second one
		 * gives the course TWO discussions: the one the rule gates, and this
		 * one, which carries no rule and which nobody is enrolled into.
		 * Reproduced before this guard existed: one course, jt spaces #37 and
		 * #38.
		 *
		 * Free has no business knowing what a Learnomy course is, so it asks
		 * and whoever does know answers. Return a jt_spaces id to adopt, or 0
		 * to let provisioning proceed.
		 *
		 * @since 1.1.2
		 *
		 * @param int   $forum_id Adopted discussion id, or 0 to create one.
		 * @param int   $space_id BuddyNext space being given a discussion.
		 * @param array $space    The space row.
		 */
		$adopted = (int) apply_filters( 'buddynext_adopt_discussion_space', 0, $space_id, $space );
		// A forum another space already holds is passed over and a fresh one is
		// made: linking it twice would let two spaces write one forum's access.
		if ( $adopted > 0 && $this->forum_free_for( $adopted, $space_id ) ) {
			update_space_meta( $space_id, 'jetonomy_forum_id', $adopted );
			$this->set_discussion_enabled( $space_id, true );
			$this->apply_discussion_posting( $space_id, false );

			return $adopted;
		}

		$owner_id = (int) ( $space['owner_id'] ?? 0 );
		$args     = array(
			'title'      => (string) ( $space['name'] ?? '' ),
			'slug'       => $this->unique_discussion_slug( (string) ( $space['slug'] ?? '' ), $space_id ),
			'author_id'  => $owner_id,
			'visibility' => self::discussion_visibility_for( self::forum_type_for( $space ) ),
			'status'     => 'active',
		);
		// The owner's forum admin row is a copy of their space role, not a join of
		// their own: as a mirror write it earns no forum join award.
		$created = IntegrationActivity::as_mirror( static fn() => \Jetonomy\Models\Space::create( $args, $owner_id ) );
		// Jetonomy 2.0.1 can answer with a WP_Error. Casting that to int gives 1,
		// which would link this space to whichever forum has id 1.
		$forum_id = is_wp_error( $created ) ? 0 : (int) $created;

		if ( $forum_id > 0 ) {
			update_space_meta( $space_id, 'jetonomy_forum_id', $forum_id );
			update_space_meta( $space_id, self::PROVISIONED_META, $forum_id );
			$this->apply_discussion_posting( $space_id, false );

			// Provisioning a forum IS the intent to use it: record the flag as on,
			// so an earlier explicit '0' (space_discussion_enabled() reads only an
			// explicit '0' as off) cannot hide the forum just created.
			$this->set_discussion_enabled( $space_id, true );

			/**
			 * A discussion was just provisioned for a BuddyNext space.
			 *
			 * The other half of the adopt guard above. When the community IS the
			 * first thing created for a Learnomy course, nothing has written that
			 * course's access rule yet - so Jetonomy, which decides whether to
			 * provision by looking for exactly that rule, would go on to create a
			 * SECOND space later. A listener claims this one for the course
			 * instead, which makes the rule the single source of truth for
			 * "the course's discussion" no matter which plugin got there first.
			 *
			 * @since 1.1.2
			 *
			 * @param int $space_id BuddyNext space.
			 * @param int $forum_id The discussion just created.
			 */
			do_action( 'buddynext_space_discussion_provisioned', $space_id, $forum_id );
		}

		return $forum_id;
	}

	/**
	 * Build a jt_spaces slug that is guaranteed not to collide with an existing
	 * Jetonomy space. Prefers the BuddyNext space slug; when that is already taken
	 * (e.g. an unrelated discussion, or a previously-deleted-then-recreated space),
	 * suffixes the BuddyNext space id — unique per space — and falls back to a
	 * numbered variant in the vanishingly unlikely event that is taken too.
	 *
	 * @param string $base     Preferred slug (the BuddyNext space slug).
	 * @param int    $space_id BuddyNext space ID (used to disambiguate).
	 * @return string A slug not currently present in jt_spaces.
	 */
	private function unique_discussion_slug( string $base, int $space_id ): string {
		$base = '' !== trim( $base ) ? sanitize_title( $base ) : 'space-' . $space_id;

		if ( ! $this->discussion_slug_exists( $base ) ) {
			return $base;
		}

		$candidate = $base . '-' . $space_id;
		$suffix    = 2;
		while ( $this->discussion_slug_exists( $candidate ) ) {
			$candidate = $base . '-' . $space_id . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Whether a jt_spaces row already uses a given slug.
	 *
	 * @param string $slug Slug to test.
	 * @return bool
	 */
	private function discussion_slug_exists( string $slug ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_spaces WHERE slug = %s LIMIT 1", $slug )
		) > 0;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Send Jetonomy's own profile pages to the BuddyNext profile (card 10344441290).
	 *
	 * The bare profile and its public activity pages redirect, so a bookmark or a
	 * search result never opens a second profile outside BuddyNext privacy; posts
	 * land on the Discussions tab. Bookmarks, drafts and Jetonomy's own badges are
	 * the member's private tools: they stay on Jetonomy for that member only, and
	 * anyone else is handed to the BuddyNext profile, where its privacy and blocks
	 * apply. That hand-over depends on the viewer, so it is a 302 a browser never
	 * caches for the owner.
	 *
	 * @return void
	 */
	public function redirect_forum_profile(): void {
		$route = (string) get_query_var( 'jetonomy_route', '' );
		if ( 'profile' !== $route && 'edit-profile' !== $route ) {
			return;
		}

		$slug = (string) get_query_var( 'jetonomy_slug', '' );
		$user = Handle::resolve( $slug );
		if ( ! $user instanceof \WP_User ) {
			$user = get_user_by( 'slug', $slug );
		}
		if ( ! $user instanceof \WP_User ) {
			return; // Unknown member: Jetonomy answers with its own not-found page.
		}

		$uid     = (int) $user->ID;
		$profile = \BuddyNext\Core\PageRouter::profile_url( $uid );
		if ( '' === $profile ) {
			return; // No BuddyNext member directory page: nothing to hand over to.
		}

		if ( 'edit-profile' === $route ) {
			$url = \BuddyNext\Core\PageRouter::edit_profile_url( $uid );
		} else {
			switch ( (string) get_query_var( 'jetonomy_tab', '' ) ) {
				case '':
				case 'activity':
				case 'replies':
				case 'votes':
					$url = $profile;
					break;
				case 'posts':
					$url = trailingslashit( $profile ) . 'discussions/';
					break;
				default:
					// bookmarks, drafts, badges: the member's own pages.
					if ( get_current_user_id() === $uid ) {
						return;
					}
					wp_safe_redirect( $profile, 302 );
					exit;
			}
		}

		wp_safe_redirect( $url, 301 );
		exit;
	}

	/**
	 * Send a member from Jetonomy's notifications page to BuddyNext's.
	 *
	 * Only when BuddyNext's inbox shows the forum notifications (Jetonomy adopted the
	 * notification contract), and only for a logged-in member. A 302: it depends on
	 * the viewer and the site setup.
	 * Template_redirect never runs for REST or admin-ajax, so those stay untouched.
	 *
	 * @return void
	 */
	public function redirect_forum_notifications(): void {
		if ( 'notifications' !== (string) get_query_var( 'jetonomy_route', '' ) || ! is_user_logged_in()
			|| ! \BuddyNext\Notifications\IntegrationNotificationListener::adopted( 'jetonomy' ) ) {
			return;
		}
		// notifications_url() always answers (configured slug, else the default), so
		// there is no empty case to fall back from.
		wp_safe_redirect( \BuddyNext\Core\PageRouter::notifications_url(), 302 );
		exit;
	}

	/**
	 * Send a held member from a forum page to the hold's page.
	 *
	 * The forum counts as the 'discussions' hub, so a hold that keeps that hub
	 * open leaves the forum open. Sitemap and RSS routes are left to Jetonomy.
	 *
	 * @return void
	 */
	public function hold_forum_pages(): void {
		$route = (string) get_query_var( 'jetonomy_route', '' );
		if ( '' === $route || 'sitemap' === $route || 'feed' === $route || ! is_user_logged_in() ) {
			return;
		}

		$hold = MemberHold::get( get_current_user_id() );
		if ( null === $hold || in_array( 'discussions', $hold['hubs'], true ) ) {
			return;
		}

		wp_safe_redirect( $hold['url'] );
		exit;
	}

	/**
	 * Refuse jetonomy(-pro)/v1 to a held member, as buddynext/v1 does.
	 *
	 * @param mixed $result  Pre-dispatch short-circuit, or null.
	 * @param mixed $server  REST server (unused; part of the signature).
	 * @param mixed $request Current request.
	 * @return mixed Unchanged result, or the hold's 403 WP_Error.
	 */
	public function hold_forum_rest( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $server is part of the rest_pre_dispatch signature.
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! is_user_logged_in() ) {
			return $result;
		}

		$route = RestRoute::normalize( $request );
		if ( ! preg_match( '#^/jetonomy(?:-pro)?/v1(?:/|$)#', $route ) ) {
			return $result;
		}

		return ( new RestHoldGate() )->partner_route_refusal( $route ) ?? $result;
	}

	/**
	 * On-demand web flow: provision the forum for `?bn_provision_forum={space}` and
	 * redirect to it. Fired on template_redirect.
	 *
	 * @return void
	 */
	public function maybe_provision_and_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified immediately below before any state change.
		$space_id = isset( $_GET['bn_provision_forum'] ) ? absint( wp_unslash( $_GET['bn_provision_forum'] ) ) : 0;
		if ( $space_id <= 0 ) {
			return;
		}

		// CSRF guard: this GET handler MUTATES the space (creates its forum), so it
		// must carry the per-space nonce the Discussions tab link embeds. Capability
		// alone does not stop CSRF — a moderator could be tricked into loading a
		// forged URL. Verify the nonce before anything else.
		//
		// Provisioning is also gated to the space's owner/moderator (or a site
		// admin) — not any logged-in user, who could otherwise provision a forum on
		// any space by id. Someone who could not use the link anyway hears that, not
		// that it expired.
		$bn_nonce = isset( $_GET['_bnpf'] ) ? sanitize_text_field( wp_unslash( $_GET['_bnpf'] ) ) : '';
		if ( ! $this->can_provision_forum( $space_id, get_current_user_id() ) ) {
			$this->redirect_provision_failure( 'forbidden' );
		}
		if ( ! wp_verify_nonce( $bn_nonce, 'bn_provision_forum_' . $space_id ) ) {
			$this->redirect_provision_failure( 'expired' );
		}

		$this->provision_space_forum( $space_id );
		$url = $this->space_forum_url( $space_id );
		if ( '' === $url ) {
			$this->redirect_provision_failure( 'failed' );
		}

		// Land on the new-topic composer so the member who clicked "Start the
		// first discussion" can write it immediately, not on the empty forum.
		wp_safe_redirect( trailingslashit( $url ) . 'new/' );
		exit;
	}

	/**
	 * Send a failed provision link back to the page it was on, saying why.
	 *
	 * Stays on the requested URL instead of building the space's own: a stranger
	 * holding a crafted id must not be redirected to a hidden space's slug.
	 *
	 * @param string $reason expired|forbidden|failed.
	 * @return never
	 */
	private function redirect_provision_failure( string $reason ): never {
		$here = remove_query_arg( array( 'bn_provision_forum', '_bnpf' ), (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed to wp_safe_redirect(), which validates it.
		wp_safe_redirect( add_query_arg( self::PROVISION_ERROR_ARG, $reason, $here ) );
		exit;
	}

	/**
	 * Show why a provision link failed, under the hero on whichever tab it landed,
	 * with a fresh link when trying again can work.
	 *
	 * @param array $args Hero arguments (space_id).
	 * @return void
	 */
	public function render_provision_failure_notice( $args ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, matched against a fixed list.
		$reason   = isset( $_GET[ self::PROVISION_ERROR_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::PROVISION_ERROR_ARG ] ) ) : '';
		$messages = array(
			'expired'   => __( 'That link has expired, so no discussion was created.', 'buddynext' ),
			'failed'    => __( 'The discussion could not be created.', 'buddynext' ),
			'forbidden' => __( 'Only the space owner or a moderator can start its discussions, once the owner has turned Discussion on.', 'buddynext' ),
		);
		if ( ! isset( $messages[ $reason ] ) ) {
			return;
		}

		$space_id = (int) ( $args['space_id'] ?? 0 );
		$retry    = $this->can_provision_forum( $space_id, get_current_user_id() ) && 'forbidden' !== $reason;
		?>
		<div class="bn-notice bn-notice--error" role="alert">
			<?php echo esc_html( $messages[ $reason ] ); ?>
			<?php if ( $retry ) : ?>
				<a class="bn-notice__cta" href="<?php echo esc_url( $this->provision_forum_url( $space_id ) ); ?>"><?php esc_html_e( 'Try again', 'buddynext' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Point the space owner at the Discussion switch while the space has no forum.
	 *
	 * Printed above the About panel, for the owner only (can_manage_discussion(), the
	 * rule the switch itself applies), and only while no forum is linked: a forum the
	 * owner switched off is a choice already made, so it gets no hint.
	 *
	 * @param array $args About panel arguments (space).
	 * @return void
	 */
	public function render_discussion_owner_hint( $args ): void {
		$space    = is_array( $args ) ? ( $args['space'] ?? null ) : null;
		$space_id = is_object( $space ) ? (int) ( $space->id ?? 0 ) : 0;
		if ( $space_id <= 0
			|| $this->space_has_discussion( $space_id )
			|| ! $this->can_manage_discussion( $space_id, get_current_user_id() ) ) {
			return;
		}

		$url = add_query_arg( 'bn_stab', 'integrations', buddynext_space_settings_url( (string) ( $space->slug ?? '' ) ) );
		?>
		<p class="bn-notice">
			<?php esc_html_e( 'This space has no Discussions tab yet.', 'buddynext' ); ?>
			<a class="bn-notice__cta" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Turn on Discussions', 'buddynext' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Add a "Back to <Space>" link to Jetonomy's breadcrumb on the pages of a forum
	 * linked to a BuddyNext Space.
	 *
	 * The filter does not pass the forum, so it is read from the route: the topic
	 * route carries the forum slug in jetonomy_space_slug, the forum routes in
	 * jetonomy_slug. The link is one <a> plus Jetonomy's own "/" separator, placed
	 * first inside its <nav class="jt-crumb">, so Jetonomy's CSS styles it (RTL and
	 * dark mode included). A Space the viewer may not see is never named.
	 *
	 * Hooked on: jetonomy_breadcrumb_html( string $html, array $crumbs )
	 *
	 * @param string $html Rendered breadcrumb markup.
	 * @return string
	 */
	public function add_space_breadcrumb( $html ): string {
		$html = (string) $html;
		$nav  = strpos( $html, '<nav class="jt-crumb"' );
		$open = false !== $nav ? strpos( $html, '>', $nav ) : false;
		if ( false === $open ) {
			return $html;
		}

		$route = (string) get_query_var( 'jetonomy_route', '' );
		$slug  = 'post' === $route
			? (string) get_query_var( 'jetonomy_space_slug', '' )
			: ( in_array( $route, array( 'space', 'space-members', 'space-roadmap', 'space-moderation' ), true ) ? (string) get_query_var( 'jetonomy_slug', '' ) : '' );
		if ( '' === $slug || ! is_callable( array( '\\Jetonomy\\Models\\Space', 'find_by_slug' ) ) ) {
			return $html;
		}

		$forum    = \Jetonomy\Models\Space::find_by_slug( $slug );
		$space_id = is_object( $forum ) ? $this->space_id_for_forum( (int) ( $forum->id ?? 0 ) ) : 0;
		$space    = $space_id > 0 ? ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id ) : null;
		if ( null === $space || ! \BuddyNext\Spaces\SpaceVisibility::can_view_space( $space, get_current_user_id() ) ) {
			return $html;
		}

		$link = sprintf(
			'<a href="%1$s" dir="auto">%2$s</a> <span aria-hidden="true">/</span> ',
			esc_url( buddynext_space_url( (string) ( $space['slug'] ?? '' ) ) ),
			/* translators: %s: space name. */
			esc_html( sprintf( __( 'Back to %s', 'buddynext' ), (string) ( $space['name'] ?? '' ) ) )
		);

		return substr( $html, 0, $open + 1 ) . $link . substr( $html, $open + 1 );
	}

	/**
	 * Whether a user may create a space's forum on demand ("Start the first
	 * discussion", POST /spaces/{id}/forum).
	 *
	 * Only once the owner has switched Discussion on: whether the space has a
	 * discussion at all is the owner's choice (can_manage_discussion()), so a
	 * moderator may start the forum the owner asked for but never create one the
	 * owner did not. Then gated on buddynext-moderate-space (space owner or
	 * moderator, plus site admins), so an arbitrary member cannot mutate the space.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  Acting user ID.
	 * @return bool
	 */
	private function can_provision_forum( int $space_id, int $user_id ): bool {
		return $space_id > 0 && $user_id > 0
			&& self::discussion_switched_on()
			&& '1' === (string) buddynext_get_space_field( $space_id, 'discussion_enabled' )
			&& buddynext_can( $user_id, 'buddynext-moderate-space', array( 'space_id' => $space_id ) );
	}

	/**
	 * Refuse a new comment on a discussion card when the forum would refuse the reply.
	 *
	 * The comment becomes a forum reply (sync_comment_to_forum()). Written straight
	 * through the model, that reply skipped everything Jetonomy checks on its own
	 * reply form: a closed topic, a locked or archived forum, a banned or silenced
	 * member. Jetonomy keeps those rules in one place (Content_Gate), so this asks
	 * it and hands the member the forum's own reason.
	 *
	 * @param array<string,mixed>|\WP_Error $data       Comment data, or an earlier refusal.
	 * @param int                           $user_id    Commenter.
	 * @param int|null                      $comment_id Null on create; an id on edit.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function gate_comment_on_discussion( $data, $user_id, $comment_id = null ) {
		if ( self::$syncing || ! is_array( $data )
			|| ! class_exists( '\\Jetonomy\\Permissions\\Content_Gate' ) || ! class_exists( '\\Jetonomy\\Models\\Post' ) ) {
			return $data;
		}

		// A new comment names its post. An edit carries only the new text, so the
		// post comes from the stored comment, and only a comment that was mirrored
		// to the forum is the forum's business.
		$object_type = (string) ( $data['object_type'] ?? '' );
		$object_id   = (int) ( $data['object_id'] ?? 0 );
		if ( null !== $comment_id ) {
			$comments = new \BuddyNext\Comments\CommentService();
			$comment  = $comments->get( (int) $comment_id );
			if ( null === $comment || $comments->get_sync_reply_id( (int) $comment_id ) <= 0 ) {
				return $data;
			}
			$object_type = (string) ( $comment['object_type'] ?? '' );
			$object_id   = (int) ( $comment['object_id'] ?? 0 );
		}
		if ( 'post' !== $object_type ) {
			return $data;
		}

		$card = ( new PostService() )->get( $object_id );
		if ( null === $card || 'discussion' !== (string) ( $card['type'] ?? '' ) ) {
			return $data;
		}

		$topic = \Jetonomy\Models\Post::find( $this->topic_id_for_card( $card ) );
		if ( ! is_object( $topic ) ) {
			return $data;
		}

		if ( null === $comment_id ) {
			$gate = \Jetonomy\Permissions\Content_Gate::check( (int) $user_id, $topic, 0 );

			return is_wp_error( $gate ) ? $gate : $data;
		}

		// Editing: the rule Jetonomy applies to editing a reply (its replies
		// controller). The author must still be allowed to reply in that forum, or
		// the editor must be allowed to edit other people's posts there. A banned
		// or removed member cannot rewrite forum content through the feed.
		$space_id = (int) ( $topic->space_id ?? 0 );
		if ( class_exists( '\\Jetonomy\\Permissions\\Permission_Engine' )
			&& ! \Jetonomy\Permissions\Permission_Engine::can( (int) $user_id, 'create_replies', $space_id )
			&& ! \Jetonomy\Permissions\Permission_Engine::can( (int) $user_id, 'edit_others_posts', $space_id ) ) {
			return new \WP_Error(
				'bn_discussion_edit_refused',
				__( 'This comment is also a reply in Discussions, and you can no longer post there.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return $data;
	}

	/**
	 * Two-way sync — a comment on a discussion card in the BuddyNext feed becomes a
	 * reply in the originating Jetonomy forum topic. Fires on `buddynext_comment_created`.
	 *
	 * @param int    $comment_id  New comment id.
	 * @param string $object_type Commented object type ('post', …).
	 * @param int    $object_id   Commented object id (a bn_posts card).
	 * @param int    $user_id     Commenter.
	 * @return void
	 */
	public function sync_comment_to_forum( int $comment_id, string $object_type, int $object_id, int $user_id ): void {
		if ( self::$syncing || 'post' !== $object_type || $object_id <= 0 || $user_id <= 0 ) {
			return;
		}
		if ( ! class_exists( '\Jetonomy\Models\Reply' ) ) {
			return;
		}

		$card = ( new PostService() )->get( $object_id );
		if ( null === $card || 'discussion' !== (string) ( $card['type'] ?? '' ) ) {
			return;
		}
		$topic_id = $this->topic_id_for_card( $card );
		if ( $topic_id <= 0 ) {
			return;
		}

		$comment = ( new \BuddyNext\Comments\CommentService() )->get( $comment_id );
		$content = null !== $comment ? trim( (string) ( $comment['content'] ?? '' ) ) : '';
		if ( '' === $content ) {
			return;
		}

		self::mirror(
			function () use ( $topic_id, $user_id, $content, $comment_id ) {
				$reply_id = \Jetonomy\Models\Reply::create(
					array(
						'post_id'       => $topic_id,
						'author_id'     => $user_id,
						'content'       => wp_kses_post( $content ),
						'content_plain' => wp_strip_all_tags( $content ),
					)
				);
				if ( ! is_wp_error( $reply_id ) && (int) $reply_id > 0 ) {
					// Persist the pair on the comment so edit/delete can propagate later
					// (both directions resolve through bn_comments.sync_reply_id).
					( new \BuddyNext\Comments\CommentService() )->set_sync_reply_id( $comment_id, (int) $reply_id );
					// Fire the same post-create signal Jetonomy's REST reply path fires so the
					// engine's own listeners (notifications, activity, counts) treat this reply
					// like any other. The reciprocal handler bails on self::$syncing.
					do_action( 'jetonomy_after_create_reply', (int) $reply_id, $topic_id );
				}
			}
		);
	}

	/**
	 * Keep a forum reply's comment on its discussion card matching the reply.
	 *
	 * Hooked on jetonomy_reply_publish_transition( $reply_id, $delta, $created_at ),
	 * which Reply::create/update/delete fire whenever a reply enters or leaves
	 * publish. A new or approved reply gets its comment (dated when the reply was
	 * written), a restored reply gets the same comment back, and a trashed,
	 * unapproved or purged reply loses it (card 10344390418). A reply written from
	 * the card itself is skipped by the self::$syncing guard: its comment exists.
	 *
	 * @param int    $reply_id   Jetonomy reply id.
	 * @param int    $delta      +1 entering publish, -1 leaving it.
	 * @param string $created_at Reply creation time (MySQL, UTC).
	 * @return void
	 */
	public function sync_reply_mirror( int $reply_id, int $delta, string $created_at = '' ): void {
		if ( self::$syncing || $reply_id <= 0 || 0 === $delta ) {
			return;
		}

		$comments = new \BuddyNext\Comments\CommentService();
		$comment  = $comments->find_by_sync_reply_id( $reply_id );

		if ( $delta < 0 ) {
			if ( null !== $comment && empty( $comment['is_deleted'] ) ) {
				self::mirror(
					static function () use ( $comments, $comment ) {
						$comments->delete( (int) $comment['id'], (int) $comment['user_id'] );
					}
				);
			}
			return;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reply = $wpdb->get_row(
			// Every column: is_anonymous and is_private exist only on newer Jetonomy,
			// and reading the row whole asks for neither by name.
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jt_replies WHERE id = %d LIMIT 1", $reply_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Comments are plain text; Jetonomy keeps a plain copy with paragraph breaks.
		$content = null !== $reply ? trim( (string) $reply['content_plain'] ) : '';
		if ( null === $reply || 'publish' !== (string) $reply['status'] || '' === $content ) {
			return;
		}

		// A feed comment is public and signed. An anonymous reply must not be, and a
		// private reply is for the topic author and moderators only.
		if ( ! empty( $reply['is_anonymous'] ) || ! empty( $reply['is_private'] ) ) {
			return;
		}

		if ( null !== $comment ) {
			if ( ! empty( $comment['is_deleted'] ) ) {
				$comments->restore( (int) $comment['id'], $content );
			}
			return;
		}

		$author_id = (int) $reply['author_id'];
		$card_id   = $this->card_id_for_topic( (int) $reply['post_id'] );
		if ( $author_id <= 0 || $card_id <= 0 ) {
			return; // No real author, or the topic has no feed card to mirror onto.
		}

		self::mirror(
			static function () use ( $comments, $author_id, $card_id, $content, $reply_id, $created_at ) {
				// CommentService applies its own permission/verification gate and sanitizes;
				// a WP_Error (e.g. an unverified author) simply means no mirrored comment.
				$comment_id = $comments->create( $author_id, 'post', $card_id, $content, null, '' !== $created_at ? $created_at : null );
				if ( ! is_wp_error( $comment_id ) && (int) $comment_id > 0 ) {
					// Persist the pair so edit/delete propagate in both directions.
					$comments->set_sync_reply_id( (int) $comment_id, $reply_id );
				}
			}
		);
	}

	/**
	 * Two-way sync — editing a feed comment updates the mirrored forum reply.
	 * Fires on `buddynext_comment_updated`. Resolves the pair via the comment's
	 * sync_reply_id; a no-op for unsynced comments. Reply::update is model-level
	 * (fires no controller hook), so it cannot loop back.
	 *
	 * @param int $comment_id Edited comment id.
	 * @return void
	 */
	public function sync_comment_edit_to_forum( int $comment_id ): void {
		if ( self::$syncing || $comment_id <= 0 || ! class_exists( '\Jetonomy\Models\Reply' ) ) {
			return;
		}
		$comments = new \BuddyNext\Comments\CommentService();
		$reply_id = $comments->get_sync_reply_id( $comment_id );
		if ( $reply_id <= 0 ) {
			return;
		}
		$comment = $comments->get( $comment_id );
		$content = null !== $comment ? trim( (string) ( $comment['content'] ?? '' ) ) : '';
		if ( '' === $content ) {
			return;
		}
		self::mirror(
			function () use ( $reply_id, $content ) {
				\Jetonomy\Models\Reply::update(
					$reply_id,
					array(
						'content'       => wp_kses_post( $content ),
						'content_plain' => wp_strip_all_tags( $content ),
					)
				);
			}
		);
	}

	/**
	 * Two-way sync — deleting a feed comment removes the mirrored forum reply.
	 * Fires on `buddynext_comment_deleted`. The comment is soft-deleted (row
	 * persists), so its sync_reply_id is still readable at delete time.
	 *
	 * @param int $comment_id Deleted comment id.
	 * @return void
	 */
	public function sync_comment_delete_to_forum( int $comment_id ): void {
		if ( self::$syncing || $comment_id <= 0 || ! class_exists( '\Jetonomy\Models\Reply' ) ) {
			return;
		}
		$reply_id = ( new \BuddyNext\Comments\CommentService() )->get_sync_reply_id( $comment_id );
		if ( $reply_id <= 0 ) {
			return;
		}
		self::mirror(
			function () use ( $reply_id ) {
				\Jetonomy\Models\Reply::delete( $reply_id );
			}
		);
	}

	/**
	 * Two-way sync — editing a forum reply updates the mirrored feed comment.
	 * Fires on `jetonomy_reply_updated`. Resolves the pair via find_by_sync_reply_id
	 * and updates as the comment's own author so BuddyNext's ownership gate passes.
	 * CommentService::update fires buddynext_comment_updated, which the guard blocks.
	 *
	 * @param int $reply_id Edited reply id.
	 * @return void
	 */
	public function sync_reply_edit_to_feed( int $reply_id ): void {
		if ( self::$syncing || $reply_id <= 0 ) {
			return;
		}
		$comments = new \BuddyNext\Comments\CommentService();
		$comment  = $comments->find_by_sync_reply_id( $reply_id );
		if ( null === $comment ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$content = trim( (string) $wpdb->get_var( $wpdb->prepare( "SELECT content_plain FROM {$wpdb->prefix}jt_replies WHERE id = %d LIMIT 1", $reply_id ) ) );
		if ( '' === $content ) {
			return;
		}
		self::mirror(
			function () use ( $comments, $comment, $content ) {
				$comments->update( (int) $comment['id'], (int) $comment['user_id'], $content );
			}
		);
	}

	/**
	 * Resolve the Jetonomy topic (jt_posts) id a discussion card points at.
	 *
	 * The card's link_url is the discussion permalink `…/s/{space}/t/{post}/`; the
	 * slugs map back to jt_spaces + jt_posts (scoped, so slugs need not be globally
	 * unique).
	 *
	 * @param array<string,mixed> $card A bn_posts discussion card row.
	 * @return int Topic id, or 0.
	 */
	private function topic_id_for_card( array $card ): int {
		$url = (string) ( $card['link_url'] ?? '' );
		if ( '' === $url || ! preg_match( '~/s/([^/]+)/t/([^/?#]+)~', $url, $m ) ) {
			return 0;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_spaces WHERE slug = %s LIMIT 1", $m[1] ) );
		if ( $space_id <= 0 ) {
			return 0;
		}
		$topic_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_posts WHERE slug = %s AND space_id = %d LIMIT 1", $m[2], $space_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $topic_id;
	}

	/**
	 * Resolve the BuddyNext discussion card id for a Jetonomy topic, or 0 when the
	 * topic was never surfaced as a feed card.
	 *
	 * @param int $topic_id Jetonomy topic (jt_posts) id.
	 * @return int Card id, or 0.
	 */
	private function card_id_for_topic( int $topic_id ): int {
		if ( $topic_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1", $topic_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $space_id <= 0 ) {
			return 0;
		}
		$url = $this->discussion_url( $topic_id, $space_id );
		if ( '' === $url ) {
			return 0;
		}
		return ( new PostService() )->get_id_by_link( 'discussion', $url );
	}

	/**
	 * Copy a member's forum flag into the BuddyNext moderation queue.
	 *
	 * Jetonomy reasons BuddyNext does not list are folded into the nearest one,
	 * with the original named in the note. A duplicate or rate-limited copy just
	 * makes no row: the member's forum report already went through.
	 *
	 * @param object $flag Jetonomy flag row (reporter_id, object_type, object_id, reason, description).
	 * @return void
	 */
	public function queue_forum_flag( $flag ): void {
		$reporter = (int) ( $flag->reporter_id ?? 0 );
		$jt_type  = (string) ( $flag->object_type ?? '' );
		$bn_type  = self::BN_FORUM_TYPES[ $jt_type ] ?? '';
		$item_id  = (int) ( $flag->object_id ?? 0 );
		if ( $reporter <= 0 || '' === $bn_type || $item_id <= 0 ) {
			return; // An automatic flag, or a flagged member (BuddyNext has its own member reports).
		}

		$map    = array(
			'offensive' => 'inappropriate',
			'off_topic' => 'other',
		);
		$reason = sanitize_key( (string) ( $flag->reason ?? 'other' ) );
		$note   = trim( (string) ( $flag->description ?? '' ) );
		if ( isset( $map[ $reason ] ) ) {
			$labels = class_exists( '\\Jetonomy\\Models\\Flag' ) && is_callable( array( \Jetonomy\Models\Flag::class, 'reason_labels' ) ) ? \Jetonomy\Models\Flag::reason_labels() : array();
			$note   = trim(
				/* translators: %s: the reason the member picked in the Discussions report form. */
				sprintf( __( 'Reported in Discussions as: %s', 'buddynext' ), (string) ( $labels[ $reason ] ?? $reason ) ) . "\n\n" . $note
			);
		}

		self::$copying_flag = true;
		try {
			buddynext_service( 'moderation' )->report( $reporter, $bn_type, $item_id, $map[ $reason ] ?? $reason, 0, $note );
		} finally {
			self::$copying_flag = false;
		}
	}

	/**
	 * Accept forum types as report targets while a flag is copied in, and only then.
	 *
	 * Open to the member report route, any member could file a report on any topic
	 * id, and the "your report was reviewed" notice could name a topic they cannot see.
	 *
	 * @param string[] $types Reportable types.
	 * @return string[]
	 */
	public function reportable_forum_types( $types ): array {
		$types = (array) $types;
		return self::$copying_flag ? array_merge( $types, array_values( self::BN_FORUM_TYPES ) ) : $types;
	}

	/**
	 * Offer "Remove content" on forum reports, which remove_reported_forum_item() handles.
	 *
	 * @param string[] $types Removable types.
	 * @return string[]
	 */
	public function removable_forum_types( $types ): array {
		return array_merge( (array) $types, array_values( self::BN_FORUM_TYPES ) );
	}

	/**
	 * Take reported forum content down: the topic or reply goes to the forum trash.
	 *
	 * A reported topic or reply is trashed through Jetonomy's system entry, which
	 * grants no forum capability: BuddyNext already checked that the actor may act
	 * on the report. A removed discussion card or mirrored comment trashes its forum
	 * twin too, and core then takes the card or comment down itself.
	 *
	 * @param bool   $handled     Whether an earlier handler already removed it.
	 * @param string $object_type Reported type.
	 * @param int    $object_id   Reported id.
	 * @param int    $actor_id    Moderator (0 = automated).
	 * @return bool
	 */
	public function remove_reported_forum_item( $handled, $object_type, $object_id, $actor_id = 0 ): bool {
		if ( $handled || ! class_exists( '\Jetonomy\Moderation\Moderation_Service' ) ) {
			return (bool) $handled;
		}

		$jt_type = array_search( (string) $object_type, self::BN_FORUM_TYPES, true );
		if ( false !== $jt_type ) {
			return $this->trash_forum_item( (string) $jt_type, (int) $object_id, (int) $actor_id );
		}

		// A card or a comment: best effort. BuddyNext's own content is the
		// moderator's to remove even when the forum refuses the twin.
		$twin = $this->forum_twin( (string) $object_type, (int) $object_id );
		if ( null !== $twin ) {
			$this->trash_forum_item( $twin[0], $twin[1], (int) $actor_id );
		}

		return false;
	}

	/**
	 * Trash a topic or reply as a BuddyNext moderation action.
	 *
	 * The echo guard is raised because the trash fires jetonomy_content_moderated
	 * synchronously, before the BuddyNext report is closed: answering it would close
	 * the report first and the moderator would get "already actioned".
	 *
	 * @param string $jt_type 'post' (topic) or 'reply'.
	 * @param int    $id      Item id.
	 * @param int    $actor   Moderator (0 = automated).
	 * @return bool True when the item is in the trash (or already gone); false when
	 *              the forum refused, e.g. a banned or silenced moderator.
	 */
	private function trash_forum_item( string $jt_type, int $id, int $actor ): bool {
		if ( ! class_exists( '\\Jetonomy\\Moderation\\Moderation_Service' ) ) {
			return false;
		}
		self::$mirroring_report = true;
		try {
			$done = \Jetonomy\Moderation\Moderation_Service::system_set_object_status( $jt_type, $id, 'trash', $actor );
		} finally {
			self::$mirroring_report = false;
		}

		return ! is_wp_error( $done ) || in_array( $done->get_error_code(), array( 'jetonomy_already_handled', 'jetonomy_not_found' ), true );
	}

	/**
	 * Name the author of a reported topic or reply, for Strike, Suspend and the
	 * takedown notice.
	 *
	 * @param int    $author_id   Author resolved so far.
	 * @param string $object_type Reported type.
	 * @param int    $object_id   Reported id.
	 * @return int
	 */
	public function reported_forum_author( $author_id, $object_type, $object_id ): int {
		if ( (int) $author_id > 0 ) {
			return (int) $author_id;
		}
		$item = $this->forum_item( (string) $object_type, (int) $object_id );

		return null !== $item ? $item['author_id'] : 0;
	}

	/**
	 * Label a reported topic or reply without its title.
	 *
	 * The label reaches the queue and the reporter's notice. A topic title can be
	 * private, so the label is the kind of item, plus its forum only when the
	 * viewer can see that forum.
	 *
	 * @param string $label       Label so far.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	public function forum_object_label( $label, $object_type, $object_id ): string {
		if ( ! in_array( $object_type, self::BN_FORUM_TYPES, true ) ) {
			return (string) $label;
		}

		$item   = $this->forum_item( (string) $object_type, (int) $object_id );
		$forum  = '';
		$viewer = get_current_user_id();
		if ( null !== $item && '' !== $item['title'] ) {
			$visible = class_exists( '\Jetonomy\Permissions\Permission_Engine' )
				? \Jetonomy\Permissions\Permission_Engine::can( $viewer, 'read', $item['space_id'] )
				: 'public' === $item['visibility'];
			$forum   = $visible ? $item['title'] : '';
		}

		if ( 'jetonomy_topic' === $object_type ) {
			return '' !== $forum
				/* translators: 1: discussion id, 2: forum name. */
				? sprintf( __( 'Discussion #%1$d in %2$s', 'buddynext' ), (int) $object_id, $forum )
				/* translators: %d: discussion id. */
				: sprintf( __( 'Discussion #%d', 'buddynext' ), (int) $object_id );
		}

		return '' !== $forum
			/* translators: 1: reply id, 2: forum name. */
			? sprintf( __( 'Discussion reply #%1$d in %2$s', 'buddynext' ), (int) $object_id, $forum )
			/* translators: %d: reply id. */
			: sprintf( __( 'Discussion reply #%d', 'buddynext' ), (int) $object_id );
	}

	/**
	 * Author and forum of a topic or reply, read once per request.
	 *
	 * @param string $object_type jetonomy_topic | jetonomy_reply.
	 * @param int    $id          Item id.
	 * @return array{author_id:int, space_id:int, title:string, visibility:string}|null
	 */
	private function forum_item( string $object_type, int $id ): ?array {
		if ( $id <= 0 || ! in_array( $object_type, self::BN_FORUM_TYPES, true ) ) {
			return null;
		}
		$key = $object_type . ':' . $id;
		if ( array_key_exists( $key, $this->forum_items ) ) {
			return $this->forum_items[ $key ];
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = 'jetonomy_topic' === $object_type
			? $wpdb->get_row(
				$wpdb->prepare(
					"SELECT p.author_id, p.space_id, s.title, s.visibility FROM {$wpdb->prefix}jt_posts p LEFT JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id WHERE p.id = %d",
					$id
				),
				ARRAY_A
			)
			: $wpdb->get_row(
				$wpdb->prepare(
					"SELECT r.author_id, p.space_id, s.title, s.visibility FROM {$wpdb->prefix}jt_replies r LEFT JOIN {$wpdb->prefix}jt_posts p ON p.id = r.post_id LEFT JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id WHERE r.id = %d",
					$id
				),
				ARRAY_A
			);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->forum_items[ $key ] = is_array( $row )
			? array(
				'author_id'  => (int) $row['author_id'],
				'space_id'   => (int) $row['space_id'],
				'title'      => (string) $row['title'],
				'visibility' => (string) $row['visibility'],
			)
			: null;

		return $this->forum_items[ $key ];
	}

	/**
	 * The forum twin of a BuddyNext discussion card or mirrored comment.
	 *
	 * @param string $object_type 'post' or 'comment'.
	 * @param int    $object_id   Card or comment id.
	 * @return array{0:string, 1:int}|null [ 'post'|'reply', forum item id ], or null.
	 */
	private function forum_twin( string $object_type, int $object_id ): ?array {
		if ( 'post' === $object_type ) {
			$card  = ( new PostService() )->get( $object_id );
			$topic = null !== $card && 'discussion' === (string) ( $card['type'] ?? '' ) ? $this->topic_id_for_card( $card ) : 0;
			return $topic > 0 ? array( 'post', $topic ) : null;
		}
		if ( 'comment' === $object_type ) {
			$reply = ( new \BuddyNext\Comments\CommentService() )->get_sync_reply_id( $object_id );
			return $reply > 0 ? array( 'reply', $reply ) : null;
		}

		return null;
	}

	/**
	 * A moderator closed reports in BuddyNext: close the forum flags and the
	 * BuddyNext report on the twin (card and topic, comment and reply), so the
	 * same content can never be struck twice.
	 *
	 * @param string $object_type Reported type.
	 * @param int    $object_id   Reported id.
	 * @param string $status      resolved | dismissed.
	 * @param int    $actor_id    Moderator recorded on the reports.
	 * @return void
	 */
	public function close_forum_flags( $object_type, $object_id, $status, $actor_id = 0 ): void {
		if ( self::$mirroring_report ) {
			return;
		}
		$object_type = (string) $object_type;
		$jt_type     = array_search( $object_type, self::BN_FORUM_TYPES, true );
		$item        = false !== $jt_type ? array( (string) $jt_type, (int) $object_id ) : $this->forum_twin( $object_type, (int) $object_id );
		if ( null !== $item ) {
			$this->settle_forum_reports( $item[0], $item[1], (string) $status, (int) $actor_id, $object_type );
		}
	}

	/**
	 * A forum moderator resolved a flag in Jetonomy: close the BuddyNext copy.
	 *
	 * @param int    $flag_id Jetonomy flag id.
	 * @param string $status  valid | dismissed.
	 * @param int    $user_id Forum moderator.
	 * @return void
	 */
	public function close_bn_report_for_flag( $flag_id, $status, $user_id = 0 ): void {
		if ( self::$mirroring_report || ! class_exists( '\Jetonomy\Models\Flag' ) ) {
			return;
		}
		$flag = \Jetonomy\Models\Flag::find( (int) $flag_id );
		if ( null !== $flag && isset( self::BN_FORUM_TYPES[ (string) $flag->object_type ] ) ) {
			$this->settle_forum_reports( (string) $flag->object_type, (int) $flag->object_id, 'dismissed' === $status ? 'dismissed' : 'resolved', (int) $user_id, '' );
		}
	}

	/**
	 * A topic or reply was moderated in Jetonomy, which closes its flags without
	 * firing jetonomy_flag_resolved: close the BuddyNext copy too. A hold keeps
	 * the flags open, so it keeps the report open.
	 *
	 * @param string $action  approve | spam | trash | hold.
	 * @param string $type    'post' or 'reply'.
	 * @param int    $id      Item id.
	 * @param int    $user_id Forum moderator (0 = system).
	 * @return void
	 */
	public function close_bn_report_for_moderated( $action, $type, $id, $user_id = 0 ): void {
		$status = array(
			'approve' => 'dismissed',
			'spam'    => 'resolved',
			'trash'   => 'resolved',
		)[ (string) $action ] ?? '';
		if ( self::$mirroring_report || '' === $status || ! isset( self::BN_FORUM_TYPES[ (string) $type ] ) ) {
			return;
		}
		$this->settle_forum_reports( (string) $type, (int) $id, $status, (int) $user_id, '' );
	}

	/**
	 * Close every report on one forum item and its BuddyNext twin, except the one
	 * the action started from.
	 *
	 * @param string $jt_type 'post' (topic) or 'reply'.
	 * @param int    $jt_id   Forum item id.
	 * @param string $status  resolved | dismissed.
	 * @param int    $actor   Who acted.
	 * @param string $origin  BuddyNext type the close started from; '' when it
	 *                        started in Jetonomy, whose flags are then already closed.
	 * @return void
	 */
	private function settle_forum_reports( string $jt_type, int $jt_id, string $status, int $actor, string $origin ): void {
		if ( $jt_id <= 0 ) {
			return;
		}
		$moderation = buddynext_service( 'moderation' );
		$bn_type    = 'post' === $jt_type ? 'post' : 'comment';
		$bn_id      = 0;
		if ( $bn_type !== $origin ) {
			$comment = 'reply' === $jt_type ? ( new \BuddyNext\Comments\CommentService() )->find_by_sync_reply_id( $jt_id ) : null;
			$bn_id   = 'post' === $jt_type ? $this->card_id_for_topic( $jt_id ) : (int) ( $comment['id'] ?? 0 );
		}

		self::$mirroring_report = true;
		try {
			if ( '' !== $origin && class_exists( '\Jetonomy\Models\Flag' ) ) {
				\Jetonomy\Models\Flag::resolve_siblings_for( $jt_type, $jt_id, $actor, 'dismissed' === $status ? 'dismissed' : 'valid', 0 );
			}
			if ( self::BN_FORUM_TYPES[ $jt_type ] !== $origin ) {
				$moderation->close_mirrored_reports( self::BN_FORUM_TYPES[ $jt_type ], $jt_id, $status, $actor );
			}

			// The card or comment is BuddyNext's own content, so its report closes only
			// under the actor's BuddyNext authority; a forum-only moderator leaves it open.
			$open = $bn_id > 0 ? $moderation->open_report_id_for( $bn_type, $bn_id ) : 0;
			if ( $open > 0 ) {
				$closed = 'dismissed' === $status ? $moderation->dismiss( $open, $actor ) : $moderation->resolve( $open, $actor );
				// Resolving lifts an auto-hide; re-read the topic so a card whose
				// topic is gone stays withdrawn.
				if ( true === $closed && 'post' === $bn_type ) {
					$this->sync_discussion( $jt_id );
				}
			}
		} finally {
			self::$mirroring_report = false;
		}
	}

	/**
	 * Whether a user may choose a space's Discussion: turn it on or off, link an
	 * existing forum, unlink it, or search forums to link.
	 *
	 * The one rule behind the web settings save and every /discussion and
	 * /discussion-search REST verb. The Discussion decides who the space talks
	 * with and which forum carries its name, so it is the owner's call (site
	 * admins pass through buddynext-own-space); a space moderator or a member with
	 * the community moderator rank does not get it. Jetonomy must be active and
	 * its site switch on, as the settings screen only renders the control then.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  Acting user ID.
	 * @return bool
	 */
	public function can_manage_discussion( int $space_id, int $user_id ): bool {
		return $space_id > 0 && $user_id > 0
			&& self::discussion_switched_on()
			&& buddynext_can( $user_id, 'buddynext-own-space', array( 'space_id' => $space_id ) );
	}

	/**
	 * Whether Jetonomy is active and the site admin left its integration on.
	 *
	 * @return bool
	 */
	private static function discussion_switched_on(): bool {
		return class_exists( 'Jetonomy\Jetonomy' ) && buddynext_integration_enabled( 'jetonomy', 'nav' );
	}

	/**
	 * REST permission gate for POST /spaces/{id}/forum.
	 *
	 * View-level, NOT manage-level: this route is the app's only way to RESOLVE a
	 * space's forum id, and every member who can see the space needs that. Gating
	 * it on buddynext-moderate-space made the Discussions tab a hard error for
	 * every non-owner (even when the forum already existed, readable via
	 * /jetonomy/v1). Whether the caller may PROVISION a missing forum is decided
	 * in the handler (rest_provision_forum) — a plain member gets the zero-state,
	 * never a 403, matching the web tab's owner-only "Start the first discussion"
	 * trigger.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function rest_forum_access_permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}

		$space_id = (int) $request['id'];
		$space    = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );

		// Same 404 for missing and not-viewable (secret) so existence isn't leaked.
		if ( null === $space || ! \BuddyNext\Spaces\SpaceVisibility::can_view_space( $space, get_current_user_id() ) ) {
			return new \WP_Error( 'not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		return true;
	}

	/**
	 * Register the space-forum REST route (app coverage).
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>\d+)/forum',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_provision_forum' ),
				'permission_callback' => array( $this, 'rest_forum_access_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// The owner's Discussion on/off control (Space settings > Integrations):
		// read the status, or turn it on (optionally linking an existing one) / off.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>\d+)/discussion',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_discussion_status' ),
					'permission_callback' => array( $this, 'rest_discussion_manage_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_set_discussion' ),
					'permission_callback' => array( $this, 'rest_discussion_manage_permission' ),
					'args'                => array(
						'enabled' => array(
							'required' => true,
							'type'     => 'boolean',
						),
						'link_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'rest_unlink_discussion' ),
					'permission_callback' => array( $this, 'rest_discussion_manage_permission' ),
				),
			)
		);

		// Typeahead for the "link an existing discussion" picker. Bounded search so
		// it scales past a bounded <select> on sites with thousands of discussions.
		// Same owner gate as the /discussion verbs; scope is role-derived server-side
		// (admins search all, owners only their own) - the client cannot widen it.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>\d+)/discussion-search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_search_discussions' ),
				'permission_callback' => array( $this, 'rest_discussion_manage_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'q'  => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Member Discussions credential panel (app coverage). Public read — the same
		// credential-first payload the SSR profile tab renders, so web and app show
		// the same accepted-answers/reputation strip plus recent discussions.
		register_rest_route(
			'buddynext/v1',
			'/members/(?P<id>\d+)/discussions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_member_discussions' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST: a member's Discussions credential panel (credentials + recent
	 * discussions). Mirrors the SSR profile tab so the native app renders it too.
	 *
	 * @param \WP_REST_Request $request Request ({id}).
	 * @return \WP_REST_Response|\WP_Error 404 when the member does not exist.
	 */
	public function rest_member_discussions( \WP_REST_Request $request ) {
		$user_id = absint( $request['id'] );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'not_found', __( 'Member not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// SECURITY: same profile-visibility gate the profile route applies.
		//
		// This route is public (`permission_callback => '__return_true'`) and only
		// checked that the id resolved, so a private / followers-only / blocked-from
		// profile still handed out discussion titles, URLs, space names, reputation
		// and trust level. ProfileController::get_item() has gated this since the
		// equivalent leak was closed there; the app-parity route beside it did not.
		//
		// Same 404 as a missing member, deliberately, so existence is not leaked
		// either - a 403 here would confirm the account exists.
		$privacy = buddynext_service( 'privacy' );
		if ( $privacy instanceof \BuddyNext\SocialGraph\PrivacyService
			&& ! $privacy->can_view_profile( get_current_user_id(), $user_id ) ) {
			return new \WP_Error( 'not_found', __( 'Member not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$payload = $this->member_discussion_profile( $user_id, 20 );

		$discussions = array_map(
			static function ( $row ): array {
				return array(
					'id'          => (int) $row->id,
					'title'       => (string) $row->title,
					'url'         => isset( $row->url ) ? (string) $row->url : '',
					'reply_count' => (int) $row->reply_count,
					'vote_score'  => (int) $row->vote_score,
					'space_name'  => isset( $row->space_name ) ? (string) $row->space_name : '',
					'created_at'  => (string) $row->created_at,
				);
			},
			$payload['discussions']
		);

		return new \WP_REST_Response(
			array(
				'accepted_answers' => (int) $payload['accepted_answers'],
				'reputation'       => (int) $payload['reputation'],
				'trust_level'      => (int) $payload['trust_level'],
				'discussion_count' => (int) $payload['discussion_count'],
				'discussions'      => $discussions,
			),
			200
		);
	}

	/**
	 * REST: typeahead search for the "link an existing discussion" picker.
	 *
	 * Scope is derived from the caller's role, NOT the request: a site admin
	 * searches across all discussions; the space owner searches only the space
	 * owner's own discussions — matching the save-time authorization so the client
	 * can never widen its own scope.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_search_discussions( \WP_REST_Request $request ): \WP_REST_Response {
		$space_id = (int) $request['id'];
		$q        = (string) $request->get_param( 'q' );

		$owner_id = 0;
		if ( ! current_user_can( 'manage_options' ) ) {
			$space    = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
			$owner_id = (int) ( $space['owner_id'] ?? 0 );
		}

		return new \WP_REST_Response(
			array( 'results' => $this->search_discussions( $q, $owner_id, 20, $space_id ) ),
			200
		);
	}

	/**
	 * Permission for /discussion (GET, POST, DELETE) and /discussion-search:
	 * can_manage_discussion(), the rule the web settings save applies.
	 *
	 * @param \WP_REST_Request $request Request (id).
	 * @return true|\WP_Error
	 */
	public function rest_discussion_manage_permission( \WP_REST_Request $request ) {
		$visible = $this->rest_forum_access_permission( $request );
		if ( true !== $visible ) {
			return $visible;
		}
		if ( ! $this->can_manage_discussion( (int) $request['id'], get_current_user_id() ) ) {
			$message = self::discussion_switched_on()
				? __( 'Only the space owner can change its discussion.', 'buddynext' )
				: __( 'Discussions are turned off on this site.', 'buddynext' );
			return new \WP_Error( 'rest_forbidden', $message, array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /spaces/{id}/discussion - has_discussion, enabled, forum_id, name, url.
	 *
	 * @param \WP_REST_Request $request Request (id).
	 * @return \WP_REST_Response
	 */
	public function rest_discussion_status( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->space_discussion_status( (int) $request['id'] ), 200 );
	}

	/**
	 * POST /spaces/{id}/discussion - apply the on/off choice, return the new status.
	 *
	 * @param \WP_REST_Request $request Request (id, enabled, link_id).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_set_discussion( \WP_REST_Request $request ) {
		$space_id = (int) $request['id'];
		$result   = $this->apply_discussion_choice( $space_id, (bool) $request['enabled'], (int) $request['link_id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $this->space_discussion_status( $space_id ), 200 );
	}

	/**
	 * DELETE /spaces/{id}/discussion - unlink the space's discussion, return the new status.
	 *
	 * @param \WP_REST_Request $request Request (id).
	 * @return \WP_REST_Response
	 */
	public function rest_unlink_discussion( \WP_REST_Request $request ): \WP_REST_Response {
		$space_id = (int) $request['id'];
		$this->unlink_space_discussion( $space_id );
		return new \WP_REST_Response( $this->space_discussion_status( $space_id ), 200 );
	}

	/**
	 * REST: provision (or fetch) a space's forum and return its URL — for the app.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_provision_forum( \WP_REST_Request $request ): \WP_REST_Response {
		$space_id = (int) $request['id'];
		$existing = (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );

		// Resolve-first: an existing forum is returned to ANY viewer the
		// permission gate admitted (view-level). Only a MISSING forum requires
		// the moderate-space capability to create — a plain member gets the
		// zero-state instead of a 403, mirroring the web tab where "Start the
		// first discussion" is an owner/moderator-only trigger.
		$forum_id = $existing;
		if ( 0 >= $forum_id && $this->can_provision_forum( $space_id, get_current_user_id() ) ) {
			$forum_id = $this->provision_space_forum( $space_id );
		}

		return new \WP_REST_Response(
			array(
				'forum_id'      => $forum_id,
				'forum_url'     => $forum_id > 0 ? $this->space_forum_url( $space_id ) : '',
				// The zero-state discriminator: false + forum_id 0 → "no
				// discussions yet, and you can't start them"; true → the caller
				// may POST again after owner action, or just provisioned.
				'can_provision' => $this->can_provision_forum( $space_id, get_current_user_id() ),
			),
			200
		);
	}

	/**
	 * Register the Discussions tab on the profile AND space nav surfaces.
	 *
	 * Hooked on `buddynext_register_nav`. Profile tab carries a lazy count badge of
	 * the member's published discussions; the space tab is a clean link to the
	 * space's forum (or the on-demand provision trigger). Both gated on Jetonomy.
	 *
	 * @param \BuddyNext\Nav\NavRegistry $registry The shared nav registry.
	 * @return void
	 */
	public function register_nav_items( \BuddyNext\Nav\NavRegistry $registry ): void {
		// Active AND the owner hasn't hidden the Discussions tab (Integrations control).
		$jetonomy_active = static fn(): bool => class_exists( 'Jetonomy\Jetonomy' )
			&& buddynext_integration_enabled( 'jetonomy', 'nav' );

		// Profile: a primary tab owning the member's discussions panel — clean URL +
		// the content-seam render, same as every other profile tab.
		$registry->register(
			array(
				'id'          => 'discussions',
				'surface'     => 'profile',
				'integration' => 'jetonomy',
				'layer'       => 'primary',
				'label'       => __( 'Discussions', 'buddynext' ),
				'priority'    => 60,
				'condition'   => $jetonomy_active,
				'url'         => static fn( \BuddyNext\Nav\NavContext $c ): string => trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . 'discussions/',
				'count'       => fn( \BuddyNext\Nav\NavContext $c ): int => $this->discussion_count( $c->subject_id ),
				'render'      => function ( \BuddyNext\Nav\NavContext $c ): void {
					$this->render_profile_discussions_panel( $c->subject_id );
				},
			)
		);

		// Space: a primary tab owning the space's discussions panel in-hub — the
		// counterpart to the profile Discussions tab. URL-only like every other
		// space tab (clean /spaces/{slug}/discussions/ link rendered as a real <a>,
		// NOT a reactive in-page tab): the space surface server-renders one panel
		// per clean URL rather than pre-rendering all of them. The panel lists the
		// linked forum's threads and links out to Jetonomy (full-load, deny-listed)
		// for reading/posting; the no-forum empty state offers the on-demand
		// provision trigger. Count badges the linked forum's published thread total.
		$registry->register(
			array(
				'id'          => 'discussions',
				'surface'     => 'space',
				'integration' => 'jetonomy',
				'layer'       => 'primary',
				'label'       => __( 'Discussions', 'buddynext' ),
				'priority'    => 35,
				// Per-space: only when THIS space's owner has enabled its dedicated
				// discussion. A space with none (or with it toggled off) shows no tab.
				'condition'   => fn( \BuddyNext\Nav\NavContext $c ): bool => $jetonomy_active()
					&& $this->space_discussion_enabled( $c->subject_id ),
				'url'         => function ( \BuddyNext\Nav\NavContext $c ): string {
					return trailingslashit( \BuddyNext\Core\PageRouter::space_url( $c->subject_id ) ) . 'discussions/';
				},
				'count'       => fn( \BuddyNext\Nav\NavContext $c ): int => $this->space_discussion_count( $c->subject_id ),
				'render'      => function ( \BuddyNext\Nav\NavContext $c ): void {
					$this->render_space_discussions_panel( $c->subject_id, $c->viewer_id );
				},
			)
		);
	}

	/**
	 * Register Jetonomy on the integration registry so the owner can toggle its
	 * Discussions tab + discussion activity from BuddyNext → Integrations.
	 *
	 * @param array<string,array<string,mixed>> $items Registered integrations.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_integration( array $items ): array {
		if ( class_exists( 'Jetonomy\Jetonomy' ) ) {
			$items['jetonomy'] = array(
				'label'            => __( 'Jetonomy', 'buddynext' ),
				'version'          => defined( 'JETONOMY_VERSION' ) ? JETONOMY_VERSION : null,
				// No hard floor evidenced for the model/action seams this bridge
				// consumes, so none is declared (an invented floor would be worse
				// than an honest null). Tested against 2.0.3: every Jetonomy hook, class,
				// method and function BuddyNext and BuddyNext Pro use was checked against
				// the partner code at that tag on 2026-10-19 (all present; hook argument
				// lists unchanged; Space::create() and Space::update() may return a
				// WP_Error, handled where the result is read).
				'tested_version'   => '2.0.7', // Verified 2026-11-11 on the 1.2.9 release site against this partner release (functional suite and bridge checks).
				// Forum notifications reach the BuddyNext bell through a contract
				// Jetonomy offers from 2.0.1 (jetonomy_community_notification_types).
				// On 2.0.0 the forum still notifies in its own list and the bell
				// stays silent, so the status screen says which update brings it
				// instead of reporting the integration as fine.
				'feature_versions' => array(
					__( 'Forum notifications in the BuddyNext bell', 'buddynext' ) => '2.0.1',
					// Before 2.0.3 deleting a forum left every topic behind, so a deleted Space's forum is only hidden.
					__( 'Removing a deleted Space\'s forum with its topics', 'buddynext' ) => '2.0.3',
				),
				'has_nav'          => true,
				'nav_label'        => __( 'Discussions', 'buddynext' ),
				'has_feed'         => true,
				'has_search'       => true,
			);
		}
		return $items;
	}

	/**
	 * Purge Jetonomy discussions from the search index when the owner switches search off.
	 *
	 * Gating the write path only stops NEW content. Everything indexed while the
	 * integration was enabled would otherwise keep surfacing in search forever, with no way
	 * for the owner to reach it — which is the half of this bug that is actually visible to
	 * members.
	 *
	 * The bridge owns the discussion <-> jetonomy mapping, not Free core: core fires the
	 * action with the integration KEY and each bridge decides which object_type that means.
	 *
	 * @param string $key Integration key that was switched off.
	 * @return void
	 */
	public function on_search_disabled( string $key ): void {
		if ( 'jetonomy' !== $key ) {
			return;
		}

		( new SearchService() )->deindex_type( 'discussion' );
	}

	/**
	 * The next published topics to re-index when discussion search is switched on.
	 *
	 * @param int[]  $ids      Ids so far.
	 * @param string $key      Integration key.
	 * @param int    $after_id Return only ids greater than this.
	 * @param int    $limit    Most ids to return.
	 * @return int[]
	 */
	public function reindex_ids( array $ids, string $key, int $after_id, int $limit ): array {
		if ( 'jetonomy' !== $key ) {
			return $ids;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_posts WHERE id > %d AND status = 'publish' ORDER BY id ASC LIMIT %d", $after_id, $limit ) ) );
	}

	/**
	 * Re-index one topic. The caller has switched new feed cards off.
	 *
	 * @param string $key Integration key.
	 * @param int    $id  jt_posts id.
	 * @return void
	 */
	public function reindex_item( string $key, int $id ): void {
		if ( 'jetonomy' === $key ) {
			$this->sync_discussion( $id );
		}
	}

	/**
	 * Link a 'discussion' search result to its forum topic.
	 *
	 * @param string $url       URL so far ('' when nothing has answered).
	 * @param string $type      Search object type.
	 * @param int    $object_id jt_posts id.
	 * @return string
	 */
	public function search_item_url( string $url, string $type, int $object_id ): string {
		if ( 'discussion' !== $type || $object_id <= 0 ) {
			return $url;
		}

		// A page of results is loaded together by prime_search_item_urls(); a
		// single lookup from anywhere else loads just this one.
		if ( ! array_key_exists( $object_id, $this->topic_urls ) ) {
			$this->prime_search_item_urls( array( 'discussion' => array( $object_id ) ) );
		}

		return '' !== ( $this->topic_urls[ $object_id ] ?? '' ) ? $this->topic_urls[ $object_id ] : $url;
	}

	/**
	 * Topic id => permalink, as built during this request ('' = no such topic).
	 *
	 * @var array<int, string>
	 */
	private array $topic_urls = array();

	/**
	 * Build the links for a page of forum topics in one query.
	 *
	 * Each topic link needs its own slug and its forum's slug. Asked one row at
	 * a time that was three queries per search result.
	 *
	 * @param array<string, int[]> $by_type Result ids, keyed by object type.
	 * @return void
	 */
	public function prime_search_item_urls( $by_type ): void {
		$ids = array_values( array_diff( array_filter( array_map( 'absint', (array) ( ( (array) $by_type )['discussion'] ?? array() ) ) ), array_keys( $this->topic_urls ) ) );
		if ( ! $ids ) {
			return;
		}

		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %d placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.slug AS post_slug, s.slug AS space_slug
				   FROM {$wpdb->prefix}jt_posts p
				   LEFT JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id
				  WHERE p.id IN ({$in})",
				$ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		foreach ( $ids as $id ) {
			$this->topic_urls[ $id ] = '';
		}
		foreach ( (array) $rows as $row ) {
			$this->topic_urls[ (int) $row->id ] = $this->discussion_permalink( (string) $row->space_slug, (string) $row->post_slug );
		}
	}

	/**
	 * Render the space Discussions panel — the registry content seam for the space
	 * Discussions tab. Self-contained: the bridge owns all jt_* access, so it
	 * resolves the linked forum's threads + the forum/provision context + the
	 * viewer's posting permission from just the space id + viewer, then renders the
	 * shared discussions part. This replaces the old hardcoded spaces/home.php
	 * branch that instantiated the bridge directly, so the space tab and its panel
	 * are now declared together like every other registry tab.
	 *
	 * @param int $space_id Space ID.
	 * @param int $viewer_id Current viewer user ID (0 = logged out).
	 * @return void
	 */
	public function render_space_discussions_panel( int $space_id, int $viewer_id ): void {
		$space = ( new \BuddyNext\Spaces\SpaceService() )->get_object( $space_id );
		if ( null === $space ) {
			return;
		}

		// Same gate as the feed composer: an active member whose role meets the space's
		// "who can post", or a site admin, on a non-archived space.
		$can_post = \BuddyNext\Spaces\SpacePostGuard::can_contribute( $space_id, $viewer_id );

		$forum_ctx = $this->space_forum_context( $space_id );

		buddynext_get_template(
			'parts/space-discussions-panel.php',
			array(
				'space'         => $space,
				'discussions'   => $this->space_discussions( $space_id, 20 ),
				'forum_url'     => (string) $forum_ctx['forum_url'],
				'forum_linked'  => (bool) $forum_ctx['linked'],
				'provision_url' => (string) $forum_ctx['provision_url'],
				'empty_state'   => $this->discussion_empty_state( $space, $viewer_id, $can_post, (string) $forum_ctx['forum_url'] ),
				// The header "Start a discussion" button follows the same rule as the empty-state CTA.
				'can_post'      => $can_post,
				// Provisioning (creating the forum) is gated to the space owner /
				// moderator, unlike posting. The empty-state "Start the first
				// discussion" CTA must follow provisioning, not posting: a member who
				// could post but not provision otherwise saw a CTA that failed the
				// permission check and fell through to All Spaces.
				'can_provision' => $this->can_provision_forum( $space_id, $viewer_id ),
			)
		);
	}

	/**
	 * Empty-state arguments for a linked forum with no threads, by what this viewer
	 * can actually do next: post, log in, join, or nothing yet.
	 *
	 * @param object $space     Space row.
	 * @param int    $viewer_id Viewer (0 = logged out).
	 * @param bool   $can_post  Whether the viewer may start a discussion.
	 * @param string $forum_url Linked forum URL.
	 * @return array<string,mixed> Arguments for parts/empty-state.php.
	 */
	private function discussion_empty_state( object $space, int $viewer_id, bool $can_post, string $forum_url ): array {
		$state = array(
			'icon'  => 'messages-square',
			'title' => __( 'No discussions yet', 'buddynext' ),
			'body'  => __( 'Discussions will appear here once they start.', 'buddynext' ),
		);

		if ( $can_post && '' !== $forum_url ) {
			// Straight to the new-topic composer, not the forum landing.
			return array_merge(
				$state,
				array(
					'body'      => __( 'Be the first to start a conversation in this space.', 'buddynext' ),
					'cta_url'   => trailingslashit( $forum_url ) . 'new/',
					'cta_label' => __( 'Start a discussion', 'buddynext' ),
					'cta_icon'  => 'plus',
				)
			);
		}

		$space_id = (int) $space->id;
		$members  = new \BuddyNext\Spaces\SpaceMemberService();

		$status = $members->get_status( $space_id, $viewer_id );
		if ( 'pending' === $status ) {
			$state['body'] = __( 'You can start a discussion once your join request is approved.', 'buddynext' );
			return $state;
		}

		// A member the space restricts, an invitee, or a space that refuses this
		// viewer (archived, plan gate): joining is not their next step.
		if ( null !== $status || ! $members->can_join( $space, $viewer_id ) ) {
			return $state;
		}

		$method = \BuddyNext\Spaces\SpaceTypeRegistry::instance()->join_method( (string) $space->type );
		if ( 'invite' === $method ) {
			return $state;
		}

		if ( $viewer_id <= 0 ) {
			return array_merge(
				$state,
				array(
					'body'      => __( 'Log in to start a discussion in this space.', 'buddynext' ),
					'cta_url'   => \BuddyNext\Core\PageRouter::login_url( trailingslashit( \BuddyNext\Core\PageRouter::space_url( $space_id ) ) . 'discussions/' ),
					'cta_label' => __( 'Log in', 'buddynext' ),
				)
			);
		}

		$direct = 'direct' === $method;
		return array_merge(
			$state,
			array(
				'body'       => $direct
					? __( 'Join this space to start a discussion.', 'buddynext' )
					: __( 'Request to join this space to start a discussion.', 'buddynext' ),
				'cta_action' => $direct ? 'actions.joinSpace' : 'actions.requestJoin',
				'cta_label'  => $direct ? __( 'Join to start a discussion', 'buddynext' ) : __( 'Request to join', 'buddynext' ),
				'cta_dialog' => false,
			)
		);
	}

	/**
	 * Render the profile Discussions panel — the registry content seam for the
	 * profile Discussions tab. The bridge owns all jt_* access, so it self-fetches
	 * the member's discussions and hands them to the shared part. Replaces the old
	 * hardcoded profile-tab-panel.php branch.
	 *
	 * @param int $user_id Profile being viewed.
	 * @return void
	 */
	public function render_profile_discussions_panel( int $user_id ): void {
		$profile = $this->member_discussion_profile( $user_id, 20 );
		buddynext_get_template(
			'parts/profile/discussions-panel.php',
			array(
				'profile_user_id'  => $user_id,
				'discussions'      => $profile['discussions'],
				'discussion_count' => $profile['discussion_count'],
				'accepted_answers' => $profile['accepted_answers'],
				'reputation'       => $profile['reputation'],
				'trust_level'      => $profile['trust_level'],
				'forum_url'        => trailingslashit( $this->forum_home_url() ),
			)
		);
	}

	/**
	 * Count a member's published Jetonomy discussions (the bridge owns jt_* access).
	 *
	 * @param int $user_id Discussion author ID.
	 * @return int
	 */
	public function discussion_count( int $user_id ): int {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return 0;
		}

		// Nav badges call this on EVERY profile view — cache the COUNT so a large
		// forum does not pay a jt_posts scan per page load. Invalidated on the
		// member's post create/delete (invalidate_member_caches).
		$cache_key = 'jt_dcount_' . $user_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts p
				 INNER JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id
				 WHERE p.author_id = %d{$this->profile_listable_where()}",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Take the viewer's blocked authors out of the shared topic count, so the tab
	 * badge matches the list under it. Costs a query only for a viewer who has
	 * blocked someone.
	 *
	 * @param int $count    Shared count.
	 * @param int $forum_id Jetonomy forum id.
	 * @return int
	 */
	private function count_without_blocked( int $count, int $forum_id ): int {
		$viewer_id = get_current_user_id();
		if ( $viewer_id <= 0 || $count <= 0 ) {
			return $count;
		}
		$blocked = array_filter( array_map( 'intval', (array) buddynext_service( 'blocks' )->blocked_users( $viewer_id ) ) );
		if ( empty( $blocked ) ) {
			return $count;
		}
		global $wpdb;
		$marks = implode( ',', array_fill( 0, count( $blocked ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$hidden = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE space_id = %d AND status = 'publish' AND is_private = 0 AND author_id IN ({$marks})",
				array_merge( array( $forum_id ), array_values( $blocked ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return max( 0, $count - $hidden );
	}

	/**
	 * Which of a member's topics their profile may list (aliases p = topic, s = forum).
	 *
	 * A profile is a public page, so it lists what the feed would mirror: published,
	 * signed topics in a public forum. A topic in a private or hidden forum, a
	 * private topic and an anonymous topic all stay off it, for every viewer. One
	 * answer for everyone is what lets the list and the count be cached per member.
	 *
	 * @return string WHERE fragment with a leading AND.
	 */
	private function profile_listable_where(): string {
		return " AND p.status = 'publish' AND p.is_private = 0 AND s.visibility = 'public'" . $this->not_anonymous_where( 'p' );
	}

	/**
	 * List a user's published Jetonomy discussions, newest first, joined to
	 * their space for the name/slug. Powers the profile "Discussions" tab so the
	 * template never reaches into jt_* tables itself (the bridge owns that access).
	 *
	 * @param int $user_id Discussion author ID.
	 * @param int $limit   Max rows (1-50). Default 20.
	 * @return object[] Each row: id, title, slug, reply_count, vote_score, created_at, space_name, space_slug, url.
	 */
	public function user_discussions( int $user_id, int $limit = 20 ): array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		// The profile panel + REST both request the default page; cache that hot
		// path (invalidated on the member's post create/delete). Non-default limits
		// are rare and bypass the cache so they never serve a truncated set.
		$use_cache = ( 20 === $limit );
		$cache_key = 'jt_ulist_' . $user_id;
		if ( $use_cache ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.title, p.slug, p.reply_count, p.vote_score, p.created_at,
				        s.title AS space_name, s.slug AS space_slug
				 FROM {$wpdb->prefix}jt_posts p
				 INNER JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id
				 WHERE p.author_id = %d{$this->profile_listable_where()}
				 ORDER BY p.created_at DESC
				 LIMIT %d",
				$user_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// Resolve each discussion's permalink through the base-slug-aware helper so
		// the template renders a ready URL and never rebuilds the /s/…/t/… path.
		foreach ( $rows as $row ) {
			$row->url = $this->discussion_permalink( (string) $row->space_slug, (string) $row->slug );
		}

		if ( $use_cache ) {
			wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $rows;
	}

	/**
	 * Count a member's accepted answers — the real credential Jetonomy tracks.
	 *
	 * An accepted answer (jt_replies.is_accepted) is an outcome (this member solved
	 * someone's question), unlike started-discussion counts which are activity churn.
	 * The bridge owns all jt_* access, so it self-fetches.
	 *
	 * @param int $user_id Reply author ID.
	 * @return int
	 */
	public function accepted_answer_count( int $user_id ): int {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_replies WHERE author_id = %d AND is_accepted = 1 AND status = 'publish'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Read a member's Jetonomy reputation + trust level from its UserProfile model.
	 *
	 * Reputation/trust are the standing Jetonomy maintains for a member — the
	 * credential worth surfacing on a profile. Returns zeros when Jetonomy or the
	 * profile row is unavailable so callers never have to null-check.
	 *
	 * @param int $user_id Member ID.
	 * @return array{reputation:int,trust_level:int}
	 */
	public function member_reputation( int $user_id ): array {
		$user_id = absint( $user_id );
		$out     = array(
			'reputation'  => 0,
			'trust_level' => 0,
		);
		if ( $user_id <= 0 || ! class_exists( '\Jetonomy\Models\UserProfile' ) ) {
			return $out;
		}

		$profile = \Jetonomy\Models\UserProfile::find_by_user( $user_id );
		if ( $profile ) {
			$out['reputation']  = (int) ( $profile->reputation ?? 0 );
			$out['trust_level'] = (int) ( $profile->trust_level ?? 0 );
		}

		return $out;
	}

	/**
	 * Assemble the full profile Discussions payload — the single source both the
	 * SSR panel and the REST route consume, so web and app render the same tab
	 * (app-coverage / REST-first house rule).
	 *
	 * Credential-first: accepted answers + reputation/trust are the outcomes worth
	 * showing; the recent-discussions list is supporting context, not the headline.
	 *
	 * @param int $user_id Member being viewed.
	 * @param int $limit   Max discussion rows (1-50). Default 20.
	 * @return array{discussions:object[],discussion_count:int,accepted_answers:int,reputation:int,trust_level:int}
	 */
	public function member_discussion_profile( int $user_id, int $limit = 20 ): array {
		$credentials = $this->member_reputation( $user_id );

		return array(
			'discussions'      => $this->user_discussions( $user_id, $limit ),
			'discussion_count' => $this->discussion_count( $user_id ),
			'accepted_answers' => $this->accepted_answer_count( $user_id ),
			'reputation'       => $credentials['reputation'],
			'trust_level'      => $credentials['trust_level'],
		);
	}

	/**
	 * Resolve the Jetonomy forum (jt_spaces) id linked to a BuddyNext space.
	 *
	 * The link option stores the Jetonomy forum id — jt_posts.space_id is that
	 * forum id, NOT the BN space id, so every jt_posts query keyed by space must
	 * map through here first. Returns 0 when no forum has been provisioned yet.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return int Jetonomy forum id, or 0 when unlinked.
	 */
	private function forum_id_for_space( int $space_id ): int {
		$space_id = absint( $space_id );
		if ( $space_id <= 0 ) {
			return 0;
		}
		return (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );
	}

	/**
	 * Per-request memo: Jetonomy forum id => linked BuddyNext space id.
	 *
	 * @var array
	 */
	private static array $forum_space_memo = array();

	/**
	 * Resolve the BuddyNext space a Jetonomy forum is linked to — the inverse of
	 * forum_id_for_space().
	 *
	 * Needed because every Jetonomy hook hands us a `jt_spaces.id` (a forum id),
	 * while every BuddyNext feed/privacy surface keys on a `bn_spaces.id`. Without
	 * this mapping a discussion activity is written with no space at all, and the
	 * space's own "Share activity to the main feed" toggle can never suppress it —
	 * FeedService excludes by `space_id` and lets a NULL space through.
	 *
	 * Memoized per request, misses included: it runs on every Jetonomy roster add,
	 * and a bulk tier sync on unlinked forums would otherwise re-scan space meta per
	 * member. on_forum_linked() clears the memo whenever a link is written, so a
	 * forum read before its space is linked (space creation fires Jetonomy's hooks
	 * before the jetonomy_forum_id meta is written) resolves once the link exists.
	 *
	 * A forum belongs to one space. Data written before that was enforced can hold
	 * a duplicate; the earliest link answers, every time (duplicate_forum_links()
	 * lists them for the owner).
	 *
	 * @param int $forum_id Jetonomy forum id (jt_spaces.id).
	 * @return int BuddyNext space id, or 0 when the forum is not linked to a space.
	 */
	private function space_id_for_forum( int $forum_id ): int {
		$forum_id = absint( $forum_id );
		if ( $forum_id <= 0 ) {
			return 0;
		}

		if ( isset( self::$forum_space_memo[ $forum_id ] ) ) {
			return self::$forum_space_memo[ $forum_id ];
		}

		global $wpdb;

		// Space meta has no lookup-by-VALUE accessor, so this reverse resolution has
		// to be a direct query. Memoized above, so it runs at most once per forum
		// per request.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT bn_space_id FROM {$wpdb->bn_spacemeta}
				 WHERE meta_key = 'jetonomy_forum_id' AND meta_value = %s
				 ORDER BY meta_id ASC
				 LIMIT 1",
				(string) $forum_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		self::$forum_space_memo[ $forum_id ] = $space_id;

		return $space_id;
	}

	/**
	 * Whether a forum may be linked to this space: no other space holds it.
	 *
	 * @param int $forum_id Jetonomy forum id.
	 * @param int $space_id Space that wants it.
	 * @return bool
	 */
	private function forum_free_for( int $forum_id, int $space_id ): bool {
		$holder = $this->space_id_for_forum( $forum_id );
		return 0 === $holder || $holder === $space_id;
	}

	/**
	 * Forums linked to more than one space, from data written before a forum was
	 * held to one space. Read-only: nothing is unlinked, the owner decides.
	 *
	 * @return array<int,int[]> Forum id => space ids, earliest link first.
	 */
	public static function duplicate_forum_links(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin/CLI report, no cache wanted.
		$rows = $wpdb->get_results(
			"SELECT meta_value AS forum_id, GROUP_CONCAT( bn_space_id ORDER BY meta_id ASC ) AS space_ids
			 FROM {$wpdb->bn_spacemeta}
			 WHERE meta_key = 'jetonomy_forum_id' AND meta_value <> '' AND meta_value <> '0'
			 GROUP BY meta_value
			 HAVING COUNT(*) > 1
			 ORDER BY MIN( meta_id ) ASC"
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->forum_id ] = array_map( 'intval', explode( ',', (string) $row->space_ids ) );
		}
		return $out;
	}

	/**
	 * List a space's published Jetonomy discussions, newest first, joined to the
	 * author for the display name/login. Powers the in-hub space "Discussions" tab
	 * so the template never reaches into jt_* tables itself (the bridge owns that
	 * access) — the space counterpart to user_discussions(). Takes a BuddyNext
	 * space id and maps to the linked Jetonomy forum internally.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @param int $limit    Max rows (1-50). Default 20.
	 * @return object[] Each row: id, title, slug, reply_count, vote_score, created_at, author_id, author_name, author_login.
	 */
	public function space_discussions( int $space_id, int $limit = 20 ): array {
		$rows = $this->public_space_discussions( $space_id, $limit );

		// The author still sees their own private topics here, as in the forum.
		// They are read per viewer and never enter the shared list above.
		$viewer_id = get_current_user_id();
		$forum_id  = $viewer_id > 0 && class_exists( 'Jetonomy\Models\Post' ) ? $this->forum_id_for_space( $space_id ) : 0;
		if ( $forum_id <= 0 ) {
			return $this->without_blocked( $rows, $viewer_id );
		}

		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$own = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.title, p.slug, p.reply_count, p.vote_score, p.created_at,
				        p.author_id, u.display_name AS author_name, u.user_login AS author_login{$this->anonymous_column( 'p' )}
				 FROM {$wpdb->prefix}jt_posts p
				 LEFT JOIN {$wpdb->users} u ON u.ID = p.author_id
				 WHERE p.space_id = %d AND p.status = 'publish' AND p.is_private = 1 AND p.author_id = %d
				 ORDER BY p.created_at DESC
				 LIMIT %d",
				$forum_id,
				$viewer_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $own ) ) {
			return $this->without_blocked( $rows, $viewer_id );
		}

		$rows = array_merge( $rows, $own );
		usort( $rows, static fn( $a, $b ): int => strcmp( (string) $b->created_at, (string) $a->created_at ) );

		return $this->without_blocked( array_slice( $rows, 0, $limit ), $viewer_id );
	}

	/**
	 * Drop topics by members the viewer has blocked.
	 *
	 * The shared list is cached once for everyone, so the block is applied per
	 * viewer on top of it, as the forum itself does through
	 * jetonomy_blocked_user_ids.
	 *
	 * @param array<int,object> $rows      Topic rows.
	 * @param int               $viewer_id Viewer (0 = logged out).
	 * @return array<int,object>
	 */
	private function without_blocked( array $rows, int $viewer_id ): array {
		if ( $viewer_id <= 0 || empty( $rows ) ) {
			return $rows;
		}
		$blocked = array_map( 'intval', (array) buddynext_service( 'blocks' )->blocked_users( $viewer_id ) );
		if ( empty( $blocked ) ) {
			return $rows;
		}
		return array_values( array_filter( $rows, static fn( $row ): bool => ! in_array( (int) $row->author_id, $blocked, true ) ) );
	}

	/**
	 * The discussions every member of the space may see: one shared, cached list.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @param int $limit    Max rows (1-50).
	 * @return array<int,object>
	 */
	private function public_space_discussions( int $space_id, int $limit = 20 ): array {
		if ( ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return array();
		}
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		// Cache the default page (keyed by the resolved forum id, matching the
		// invalidation hooks); odd limits bypass the cache.
		$use_cache = ( 20 === $limit );
		$cache_key = 'jt_slist_' . $forum_id;
		if ( $use_cache ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.title, p.slug, p.reply_count, p.vote_score, p.created_at,
				        p.author_id, u.display_name AS author_name, u.user_login AS author_login{$this->anonymous_column( 'p' )}
				 FROM {$wpdb->prefix}jt_posts p
				 LEFT JOIN {$wpdb->users} u ON u.ID = p.author_id
				 WHERE p.space_id = %d AND p.status = 'publish' AND p.is_private = 0
				 ORDER BY p.created_at DESC
				 LIMIT %d",
				$forum_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = is_array( $rows ) ? $rows : array();

		// A topic marked private in the forum is for its author and the forum's
		// moderators. This list is one shared, cached page for every member of the
		// space, so it carries no private topic at all (the query above, and the
		// count beside it). space_discussions() adds the viewer's own on top;
		// moderators read the rest in the forum.
		// It used to print the private topic's title to every member.
		//
		// The forum lists an anonymous topic without its author; so does this tab.
		foreach ( $rows as $row ) {
			if ( ! empty( $row->is_anonymous ) ) {
				$row->author_id    = 0;
				$row->author_name  = __( 'Anonymous', 'buddynext' );
				$row->author_login = '';
			}
		}

		if ( $use_cache ) {
			wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $rows;
	}

	/**
	 * Count a space's published Jetonomy discussions (the bridge owns jt_* access).
	 * Takes a BuddyNext space id and maps to the linked Jetonomy forum internally.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return int
	 */
	public function space_discussion_count( int $space_id ): int {
		if ( ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return 0;
		}
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 ) {
			return 0;
		}

		// Nav badges call this on EVERY space view — cache the COUNT keyed by the
		// resolved forum id (invalidated on that forum's post create/delete).
		$cache_key = 'jt_scount_' . $forum_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $this->count_without_blocked( (int) $cached, $forum_id );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE space_id = %d AND status = 'publish' AND is_private = 0",
				$forum_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $this->count_without_blocked( $count, $forum_id );
	}

	/**
	 * Build the on-demand provision-trigger URL for a space's forum. Visiting it
	 * (handled on template_redirect) creates the forum the first time and redirects
	 * to it — so no empty forums exist until a member actually opens Discussions.
	 * Carries a per-space nonce because the GET handler mutates state (CSRF guard).
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return string
	 */
	private function provision_forum_url( int $space_id ): string {
		// Base the trigger on the SPACE's own URL, never the /spaces/ directory: if
		// the provision handler falls through (expired nonce, or a viewer who may
		// post but not provision), WordPress renders the base URL — and rendering
		// the All Spaces directory is exactly the "thrown back to All Spaces"
		// symptom. Off the space URL, a fall-through stays on the space.
		$base = \BuddyNext\Core\PageRouter::space_url( absint( $space_id ) );
		if ( '' === $base ) {
			$base = \BuddyNext\Core\PageRouter::spaces_url();
		}
		return add_query_arg(
			array(
				'bn_provision_forum' => absint( $space_id ),
				'_bnpf'              => wp_create_nonce( 'bn_provision_forum_' . absint( $space_id ) ),
			),
			$base
		);
	}

	/**
	 * Display bundle for the in-hub space Discussions panel: the linked forum URL
	 * (full-load, deny-listed) for "open in community", whether a forum is linked
	 * yet, and the provision-trigger URL for the no-forum empty state. Keeps the
	 * template free of jt_* tables, link options, and nonce plumbing.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return array{forum_url:string,linked:bool,provision_url:string}
	 */
	public function space_forum_context( int $space_id ): array {
		return array(
			'forum_url'     => $this->space_forum_url( $space_id ),
			'linked'        => $this->forum_id_for_space( $space_id ) > 0,
			'provision_url' => $this->provision_forum_url( $space_id ),
		);
	}

	/**
	 * Inject Discussion context nav items (Home / Search / Leaderboard).
	 *
	 * Only fires when the main nav section is "discussions".
	 *
	 * @param array  $items   Existing context nav items.
	 * @param string $section Current active section.
	 * @return array
	 */
	public function inject_discussion_context_nav( array $items, string $section ): array {
		if ( 'discussions' !== $section ) {
			return $items;
		}

		$base        = function_exists( 'Jetonomy\base_url' ) ? \Jetonomy\base_url() : home_url( '/community' ); // bn-route-ok: canonical Jetonomy API first, literal only as absent-plugin fallback.
		$current_url = home_url( add_query_arg( array() ) );

		$items[] = array(
			'label'  => __( 'Home', 'buddynext' ),
			'url'    => $base . '/',
			'active' => trailingslashit( $current_url ) === trailingslashit( $base . '/' ),
		);
		$items[] = array(
			'label'  => __( 'Search', 'buddynext' ),
			'url'    => $base . '/search/',
			'active' => false !== strpos( $current_url, '/search/' ),
		);
		$items[] = array(
			'label'  => __( 'Leaderboard', 'buddynext' ),
			'url'    => $base . '/leaderboard/',
			'active' => false !== strpos( $current_url, '/leaderboard/' ),
		);

		return $items;
	}

	/**
	 * Filter callback: return Jetonomy discussions tagged with the given slug.
	 *
	 * Hooked to `buddynext_hashtag_related_discussions` so the hashtag feed
	 * template can display "Related Discussions" from Jetonomy forums.
	 *
	 * @param array  $discussions Existing discussions array (empty by default).
	 * @param string $hashtag_slug The hashtag/tag slug to search for.
	 * @return array Array of discussion objects with id, title, post_slug, author_name, reply_count, vote_score, created_at.
	 */
	public function get_related_discussions( array $discussions, string $hashtag_slug ): array {
		if ( ! class_exists( 'Jetonomy\Models\Tag' ) ) {
			return $discussions;
		}

		if ( ! \Jetonomy\Models\Tag::exists( $hashtag_slug ) ) {
			return $discussions;
		}

		$jt_posts = \Jetonomy\Models\Tag::list_by_tag( $hashtag_slug, 5 );

		foreach ( $jt_posts as $jt_post ) {
			// Jetonomy's tag list applies no visibility rule, and a hashtag page is
			// public: keep only the topics the feed would mirror.
			if ( ! $this->is_mirrored_topic( (int) $jt_post->id ) ) {
				continue;
			}

			// Resolve the public discussion URL here (the bridge owns Jetonomy
			// table access) so the hashtag template never queries jt_* itself.
			$jt_url = $this->discussion_url( (int) $jt_post->id, (int) $jt_post->space_id );

			$discussions[] = array(
				'id'          => (int) $jt_post->id,
				'title'       => $jt_post->title,
				'slug'        => $jt_post->post_slug,
				'space_id'    => (int) $jt_post->space_id,
				'url'         => '' !== $jt_url ? $jt_url : home_url( '/community/' ), // bn-route-ok: bridge-supplied URL first, literal only as fallback.
				'author_id'   => (int) $jt_post->author_id,
				'author_name' => $jt_post->author_name,
				'reply_count' => (int) $jt_post->reply_count,
				'vote_score'  => (int) $jt_post->vote_score,
				'created_at'  => $jt_post->created_at,
				'source'      => 'jetonomy',
			);
		}

		return $discussions;
	}

	/**
	 * Show the forum's email choices in the member's notification settings.
	 *
	 * Jetonomy sends forum email and owns where the choices are stored; the card
	 * (parts/settings-partner-prefs.php) always shows its email switches, and adds
	 * Jetonomy's own fields (Pro's digest, saved via PATCH
	 * /jetonomy/v1/users/me/digest-preferences) when Jetonomy renders any.
	 *
	 * @param int $user_id Member whose settings are open.
	 */
	public function render_partner_prefs( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$fields = '';
		if ( has_action( 'jetonomy_profile_notification_fields' ) ) {
			ob_start();
			do_action( 'jetonomy_profile_notification_fields', $user_id );
			$fields = trim( (string) ob_get_clean() );
		}

		buddynext_get_template(
			'parts/settings-partner-prefs.php',
			array(
				'bn_pp_user_id' => $user_id,
				'bn_pp_fields'  => $fields,
				'bn_pp_rest'    => rest_url( 'jetonomy/v1/users/me/digest-preferences' ),
				'bn_pp_field'   => 'digest_frequency',
				'bn_pp_key'     => 'frequency',
			)
		);
	}

	/**
	 * Recipients whose Jetonomy notice was dropped in favour of BuddyNext's own, keyed to
	 * the dropped type, so the email Jetonomy sends right after the notice is dropped too.
	 *
	 * @var array<int,string>
	 */
	private static array $dropped_notice = array();

	/**
	 * Forum join requests ("forum:user") the space answered in this request, so the
	 * forum's own answer to the member is not a second notice.
	 *
	 * @var array<string,bool>
	 */
	private static array $answered_in_space = array();

	/**
	 * Honour BuddyNext's Email channel on Jetonomy's instant forum email.
	 *
	 * The member's Email switch in BuddyNext's notification settings is the master
	 * switch for every community email, the forum's included: off there means no
	 * forum email. An absent setting means on, as everywhere in BuddyNext. The read is
	 * user meta, cached by WordPress after the first recipient lookup. A report-reviewed
	 * email whose notice was dropped as a duplicate is dropped with it.
	 *
	 * @param mixed $send    Whether Jetonomy will send the email.
	 * @param mixed $user_id Recipient.
	 * @param mixed $key     Email template key.
	 * @param mixed $type    Notification type.
	 * @return bool
	 */
	public function filter_forum_email( $send, $user_id = 0, $key = '', $type = '' ): bool {
		$user_id = (int) $user_id;
		if ( ! $send || $user_id <= 0 ) {
			return (bool) $send;
		}
		if ( '' !== (string) $type && ( self::$dropped_notice[ $user_id ] ?? '' ) === (string) $type ) {
			unset( self::$dropped_notice[ $user_id ] );
			return false;
		}
		$channels = buddynext_service( 'notification_prefs' )->get_channel_prefs( $user_id );
		return ! ( array_key_exists( 'email', $channels ) && empty( $channels['email'] ) );
	}

	/**
	 * One notification for one forum event BuddyNext has already told the member about.
	 *
	 * A resolved forum report the member also filed in BuddyNext, a join request the
	 * space answered, or a new request the space owner was told of by the space: Jetonomy
	 * tells them too, in other words, so the second bell row (and its email) is dropped
	 * (space_already_told()). Jetonomy's own row is marked read so its unread count does
	 * not keep an item nobody can open. An event BuddyNext did not tell them of (a report
	 * it rate-limited, a denial the space refused to follow) keeps Jetonomy's notice, so
	 * nobody is left untold.
	 *
	 * @param mixed $payload Contract payload.
	 * @param mixed $source  Source slug.
	 * @return mixed
	 */
	public function drop_duplicate_report_notice( $payload, $source = '' ) {
		if ( 'jetonomy' !== $source || ! is_array( $payload ) ) {
			return $payload;
		}
		$type      = (string) ( $payload['type'] ?? '' );
		$recipient = (int) ( $payload['recipient_id'] ?? 0 );
		$object_id = (int) ( $payload['object_id'] ?? 0 );
		if ( $recipient <= 0 || $object_id <= 0 || ! $this->space_already_told( $type, $payload, $recipient, $object_id ) ) {
			return $payload;
		}

		self::$dropped_notice[ $recipient ] = $type;
		$row_id                             = (int) ( $payload['notification_id'] ?? 0 );
		if ( $row_id > 0 && class_exists( '\\Jetonomy\\Models\\Notification' ) ) {
			\Jetonomy\Models\Notification::mark_read_for_user( $recipient, array( $row_id ) );
		}
		$payload['recipient_id'] = 0; // BuddyNext stores no row for a payload without a recipient.
		return $payload;
	}

	/**
	 * Whether BuddyNext has already sent this recipient its own notice for a forum event.
	 *
	 * - flag_resolved: they reported the forum item in BuddyNext too, which tells them.
	 * - join_request_result: the space answered the request in this same request.
	 * - join_request: the recipient owns the space, which already holds the request
	 *   and tells its owner. Forum moderators and site admins keep the forum's notice.
	 *
	 * @param string              $type      Jetonomy notification type.
	 * @param array<string,mixed> $payload   Contract payload.
	 * @param int                 $recipient Recipient.
	 * @param int                 $object_id Object id (a forum id for join requests).
	 * @return bool
	 */
	private function space_already_told( string $type, array $payload, int $recipient, int $object_id ): bool {
		if ( 'flag_resolved' === $type ) {
			$bn_type = self::BN_FORUM_TYPES[ (string) ( $payload['object_type'] ?? '' ) ] ?? '';
			return '' !== $bn_type && buddynext_service( 'moderation' )->user_has_reported( $recipient, $bn_type, $object_id );
		}
		if ( 'join_request_result' === $type ) {
			return ! empty( self::$answered_in_space[ $object_id . ':' . $recipient ] );
		}
		if ( 'join_request' === $type ) {
			$space_id = $this->space_id_for_forum( $object_id );
			$space    = $space_id > 0 ? ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id ) : null;
			return null !== $space && (int) ( $space['owner_id'] ?? 0 ) === $recipient
				&& 'pending' === ( new \BuddyNext\Spaces\SpaceMemberService() )->get_status( $space_id, (int) ( $payload['actor_id'] ?? 0 ) );
		}
		return false;
	}
}
