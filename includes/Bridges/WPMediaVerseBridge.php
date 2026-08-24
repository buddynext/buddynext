<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WPMediaVerse bridge.
 *
 * Connects BuddyNext to the WPMediaVerse DM engine. Responsible for:
 *
 * 1. Declaring BuddyNext as active so WPMediaVerse's NotificationListener
 *    skips its own notification (avoids duplicates).
 * 2. Blocking DMs from users who are blocked via bn_blocks.
 * 3. Routing new-message events into bn_notifications (type bn.new_message)
 *    so the BuddyNext notification system handles delivery + email prefs.
 *
 * Only boots if WPMediaVerse free is active — checked at hook time via
 * class_exists, not on load, so activation order doesn't matter.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Moderation\ModerationService;
use BuddyNext\Moderation\SafeguardService;
use BuddyNext\Notifications\NotificationService;
use BuddyNext\Media\MediaClient;
use BuddyNext\Media\Galleries;
use BuddyNext\Spaces\SpaceVisibility;
use BuddyNext\Feed\PostService;
use BuddyNext\Feed\IntegrationActivity;

/**
 * WPMediaVerse ↔ BuddyNext integration layer.
 */
class WPMediaVerseBridge {

	/**
	 * Re-entrancy guard for the two-way follow mirror: true while this bridge is
	 * propagating a follow/unfollow into the other store, so the reciprocal
	 * action it triggers there is ignored instead of looping back.
	 *
	 * @var bool
	 */
	private bool $mirroring_follow = false;

	/**
	 * Flag reason carried from the pre-send content gate to the post-send report.
	 *
	 * A DM is checked before it is written (mvs_message_content_check), so at flag
	 * time there is no message ID to report against. The reason is parked here and
	 * consumed by on_message_sent(), which runs once the row exists. Cleared on
	 * consumption so it cannot leak onto a later message in the same request.
	 *
	 * @var string
	 */
	private string $pending_dm_flag = '';

	/**
	 * Object-cache group + TTL for the media -> source-activity lookup. The lookup
	 * scans bn_posts (JSON_CONTAINS on media_ids is not indexable), so the result
	 * is cached: a media's source post never changes once created.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'buddynext_media';

	/**
	 * Cache TTL for the media -> source-activity lookup, in seconds.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 3600;

	/**
	 * Attach the DM safety gates.
	 *
	 * Registered independently of the Platform → Features toggle, and of this
	 * bridge's display half, because BuddyNext's DM surface does not depend on
	 * either. `MessagesData::available()` asks `MediaClient`, which resolves the
	 * engine's own container directly — so `/messages/`, the shell-nav item and
	 * the messages store stay live whenever the engine is present and
	 * `buddynext_enable_dm` is on, regardless of this bridge.
	 *
	 * Gating these three filters on the Features toggle therefore did not disable
	 * DM; it disabled only the checks on it. Turning the integration off left
	 * BuddyNext serving its own DM UI while `bn_blocks` and the recipient's
	 * DM-privacy preference silently stopped applying — a member who had blocked
	 * someone still received their messages, and the Block control went on
	 * promising otherwise.
	 *
	 * The owner keeps both switches that mean something: Features → WPMediaVerse
	 * still controls the integration's display, and Settings → General → Direct
	 * Messaging (`buddynext_enable_dm`) still turns DM off outright. What an owner
	 * cannot do is leave DM on while its safety gates are off, because that is not
	 * a preference — it is the Block button lying.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges, before init().
	 */
	public function init_dm_gates(): void {
		if ( ! class_exists( 'WPMediaVerse\Core\Plugin' ) ) {
			return;
		}

		// Gate DMs on bn_blocks + the recipient's DM-privacy preference.
		add_filter( 'mvs_can_send_message', array( $this, 'check_block' ), 10, 3 );

		// Run DM text through auto-moderation (banned words + Pro rules) so a member
		// can't plant blocked content in a direct message — posts/comments/profile
		// are guarded; DMs were not.
		add_filter( 'mvs_message_content_check', array( $this, 'moderate_dm_content' ), 10, 3 );

		// When that gate denies, report WHY (block vs privacy preference) so the
		// sender sees an accurate notice instead of a generic "blocked".
		add_filter( 'mvs_dm_denial_reason', array( $this, 'dm_denial_reason' ), 10, 3 );
	}

	/**
	 * Attach the integration's display hooks.
	 *
	 * Everything here is the site owner's call, gated on Platform → Features →
	 * WPMediaVerse: the media nav item and tab, follow mirroring, DM
	 * notifications, lightbox comment sync, media activity, and the
	 * single-media redirect. Switching the integration off removes all of it and
	 * WPMediaVerse behaves standalone again.
	 *
	 * The DM safety gates deliberately do not live here — see init_dm_gates().
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		if ( ! class_exists( 'WPMediaVerse\Core\Plugin' ) ) {
			return;
		}

		// Tell WPMediaVerse that BuddyNext is active so it skips its own
		// floating chat panel, standalone messages page, and notifications.
		add_filter( 'mvs_buddynext_active', '__return_true' );

		/*
		 * Turn media reporting ON.
		 *
		 * WPMediaVerse ships `mvs_reports_enabled` defaulting to FALSE — sensible for a
		 * standalone media plugin on a site that may have no moderators. BuddyNext is not that:
		 * it is a UGC community, and a community with no way to report a piece of media has no
		 * abuse path at all.
		 *
		 * It matters doubly here because this same bridge redirects /media/{slug}/ to the source
		 * activity, so MVS's own media page — the one carrying its Report button — never renders
		 * on a BuddyNext site. We take that page away, so we owe the member the control back: the
		 * lightbox now offers Report, and it posts into MVS's existing queue.
		 *
		 * This one stays with the display half deliberately: the page it compensates for is
		 * restored by the same toggle. With the integration off, `redirect_single_media()` is
		 * not registered either, so MVS's own media page — and its own Report button — render
		 * again and the abuse path is intact.
		 *
		 * Priority 5, so a site that genuinely wants it off can still say so at the default 10.
		 */
		add_filter( 'mvs_reports_enabled', '__return_true', 5 );

		// Point WPMediaVerse user-profile links (media grid, lightbox author,
		// REST author_url) at the BuddyNext member profile. Without this, MVS
		// falls back to its own /media/@{login}/ URL, which is not a member
		// profile.
		add_filter( 'mvs_user_profile_url', array( $this, 'member_profile_url' ), 10, 2 );

		// Space albums decide media privacy themselves — opt out of the engine's
		// album-privacy inheritance for them.
		add_filter( 'mvs_album_inherit_privacy', array( $this, 'skip_inherit_for_space_albums' ), 10, 2 );

		// Route new-message events into bn_notifications.
		add_action( 'mvs_message_sent', array( $this, 'on_message_sent' ), 10, 4 );

		// Notify media owner when someone favourites their content.
		add_action( 'mvs_favorite_toggled', array( $this, 'on_favorite_toggled' ), 10, 3 );

		// Notify the media owner when someone reacts to their content, and notify
		// mention targets when they are @mentioned in a media comment. MediaVerse
		// already renders both categories (media_reaction, media_mention) and owns
		// their email; these mirror the events into the BuddyNext notification
		// centre only (can_email=false), so a member sees reactions and mentions in
		// one place — with the centre's Reactions and Mentions tabs, which had the
		// UI but never a feed — without a second email. Reactions come from the
		// service hook (mvs_reaction_added), not the REST toggle, so a reaction made
		// through any path is caught once.
		add_action( 'mvs_reaction_added', array( $this, 'on_media_reaction' ), 10, 3 );
		add_action( 'mvs_mentions_created', array( $this, 'on_media_mention' ), 10, 4 );

		// Space document drives (MVS Pro 2.4.0). MVS holds an opaque drive id and
		// asks US who may see and write a space:<id> drive — it never reads bn_*
		// tables, so the two plugins cannot disagree about space membership. We
		// answer from the ONE canonical resolver (SpaceVisibility + the member
		// role), never a second copy of the rules, and only when the space owner
		// has turned the drive on (mvs_documents_tab, off by default).
		add_filter( 'mvs_document_drive_access', array( $this, 'space_drive_access' ), 10, 4 );
		add_filter( 'mvs_document_drive_visible', array( $this, 'space_drive_visible' ), 10, 4 );
		add_filter( 'mvs_document_drives_for_user', array( $this, 'space_drives_for_user' ), 10, 2 );
		add_filter( 'mvs_document_drive_label', array( $this, 'space_drive_label' ), 10, 3 );

		// A document shared from the composer renders as a link-out card. The
		// post stores only the document id; this seam resolves the card PER VIEWER
		// so a private document's title is never snapshotted into someone else's
		// feed. Gated inside on the 'media' integration/feed toggle.
		add_filter( 'buddynext_render_post_body_document', array( $this, 'render_document_card' ), 10, 2 );

		// Keep the WPMediaVerse follow graph (mvs_follows) and BuddyNext's
		// (bn_follows) in sync both ways. MVS profiles and BN profiles otherwise
		// show divergent follow state for the same pair. A re-entrancy guard plus
		// an is_following() short-circuit prevents the mirror from looping.
		add_action( 'mvs_user_followed', array( $this, 'mirror_mvs_follow' ), 10, 2 );
		add_action( 'mvs_user_unfollowed', array( $this, 'mirror_mvs_unfollow' ), 10, 2 );
		add_action( 'buddynext_user_followed', array( $this, 'mirror_bn_follow' ), 10, 2 );
		add_action( 'buddynext_user_unfollowed', array( $this, 'mirror_bn_unfollow' ), 10, 2 );

		// The MVS profile "Message" button dispatches a mvs-open-conversation JS
		// event whose only native listener (the MVS chat panel) is suppressed
		// while BN owns /messages/. Enqueue a tiny listener that routes the click
		// to BN's native conversation instead.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_message_bridge' ) );

		// Unified nav: inject "Media" link into the BuddyNext left rail. This adds
		// a link to the media surface on BuddyNext's OWN pages — it does not alter
		// any WPMediaVerse page.
		add_filter( 'buddynext_rail_items', array( $this, 'inject_media_nav_item' ) );

		// Owner control: register the Media feature on the integration registry so
		// the owner can hide its tab/rail item + media activity from BuddyNext ->
		// Integrations. Scoped to MEDIA only - Direct Messaging shares this engine
		// and is never gated here, so DMs keep working when Media is hidden.
		add_filter( 'buddynext_integrations', array( $this, 'register_integration' ) );

		// WPMediaVerse pages (e.g. /explore-media/) render as the plugin's own
		// default — BuddyNext does not wrap them in its hub shell or inject its
		// sidebar, per the owner rule that BN must not touch MediaVerse pages.

		// NOTE: /messages/ is now a fully NATIVE BuddyNext surface (templates/
		// messages/native.php + the buddynext/messages store) consuming the engine
		// via mvs/v1 — no MVS chat screen is embedded. The former
		// buddynext_render_messages embed + its render_messages()/
		// enqueue_messaging_assets()/print_messaging_config() helpers were removed.

		// NOTE: BuddyNext consumes WPMediaVerse at the REST/API level ONLY and
		// owns 100% of its own UX — WPMediaVerse JS/CSS is never enqueued on
		// BuddyNext pages. (The former enqueue_lightbox() loaded a now-removed
		// mvs asset and 404'd; BN renders its own media + lightbox).

		// Sync MVS lightbox comments → BuddyNext activity comments.
		// When a user comments on a photo via the lightbox, create a matching
		// bn_comments entry threaded under the BuddyNext post that holds the media.
		add_action( 'mvs_comment_created', array( $this, 'sync_lightbox_comment' ), 10, 3 );

		// LinkedIn-style connect note → DM message request. When a connection
		// request carries a note (only when the owner enabled the note step), the
		// note is delivered to the recipient as a direct-message request so they
		// can read the context and decide whether to engage before accepting.
		add_action( 'buddynext_connection_requested', array( $this, 'deliver_note_as_message_request' ), 10, 4 );

		// Surface standalone WPMediaVerse uploads in the activity feed. The
		// upload itself fired no feed entry before, so media shared from the
		// "Upload Media" surface never appeared in the community feed. Deferred +
		// guarded so a photo posted through the BuddyNext composer — which uploads
		// via the same WPMediaVerse path and then creates its OWN feed post — is
		// never duplicated.
		add_action( 'mvs_media_uploaded', array( $this, 'on_media_uploaded' ), 10, 4 );
		add_action( 'buddynext_mvs_media_activity', array( $this, 'publish_media_activity' ), 10, 3 );

		// Withdraw the media feed card when its source is deleted, so the feed
		// never points at dead content — mirroring what JetonomyBridge and
		// CareerBoardBridge already do on delete. WPMediaVerse's mvs_media_deleted
		// hook carries the pre-delete permalink (the slug row is already gone by
		// the time it fires), which is the exact link_url the card was keyed on.
		add_action( 'mvs_media_deleted', array( $this, 'on_media_deleted' ), 10, 3 );

		// Media links resolve to the activity the item was posted in, not a
		// dedicated /media/{slug}/ page — every upload already becomes an activity
		// (photo post or media card), so a standalone public page per item is
		// redundant SEO/crawl surface the owner rarely wants. Owner can switch to
		// dedicated pages (Settings -> General -> Discovery). Standalone MVS (no BN)
		// keeps its native single pages: this filter simply isn't attached there.
		add_filter( 'mvs_single_media_redirect', array( $this, 'redirect_single_media' ), 10, 3 );
	}

	/**
	 * When true, `on_media_uploaded()` skips the standalone-upload feed sync for
	 * the current request. Set by BuddyNext-owned upload surfaces (composer,
	 * avatar, cover, comment media) because those either create their own post
	 * explicitly on submit or create none at all — only the standalone
	 * WPMediaVerse "Upload Media" surface should auto-surface in the feed.
	 *
	 * @var bool
	 */
	private static $suppress_upload_activity = false;

	/**
	 * Toggle auto feed-sync suppression for BuddyNext-originated uploads.
	 *
	 * BuddyNext upload endpoints wrap their engine `handle()` call in this so an
	 * abandoned or removed composer photo — and avatar/cover changes — never
	 * auto-publish a post the member never confirmed. `mvs_media_uploaded` fires
	 * synchronously inside `handle()`, so a request-scoped flag is sufficient.
	 *
	 * @param bool $suppress Whether to suppress the next upload's feed sync.
	 * @return void
	 */
	public static function suppress_upload_activity( bool $suppress = true ): void {
		self::$suppress_upload_activity = $suppress;
	}

	/**
	 * Defer a feed entry for a fresh WPMediaVerse upload.
	 *
	 * Runs on `mvs_media_uploaded`. Only standalone WPMediaVerse uploads (the
	 * dashboard "Upload Media" surface, which posts to `mvs/v1/media` directly)
	 * should auto-surface here. BuddyNext-originated uploads route through
	 * `buddynext/v1/me/media` and set the suppression flag, so a composer photo
	 * only ever posts when the member explicitly clicks Post (via PostService),
	 * and abandoning or removing it publishes nothing.
	 *
	 * @param int    $media_id   WPMediaVerse media-index id.
	 * @param array  $file_data  Upload metadata (privacy, user_id, …).
	 * @param int    $user_id    Uploader user id.
	 * @param string $media_type Resolved type: photo|video|audio|document.
	 * @return void
	 */
	public function on_media_uploaded( $media_id, $file_data, $user_id, $media_type ): void {
		$media_id = (int) $media_id;
		$user_id  = (int) $user_id;
		if ( $media_id <= 0 || $user_id <= 0 ) {
			return;
		}

		// BuddyNext-owned surfaces own their own posting decision — never
		// auto-publish on their behalf.
		if ( self::$suppress_upload_activity ) {
			return;
		}

		// Only public uploads belong in the public feed.
		$privacy = is_array( $file_data ) ? (string) ( $file_data['privacy'] ?? 'public' ) : 'public';
		if ( 'public' !== $privacy ) {
			return;
		}

		$args = array( $media_id, $user_id, (string) $media_type );

		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			if ( ! as_next_scheduled_action( 'buddynext_mvs_media_activity', $args, 'buddynext' ) ) {
				as_schedule_single_action( time() + 120, 'buddynext_mvs_media_activity', $args, 'buddynext' );
			}
			return;
		}

		// No Action Scheduler (rare): publish inline, accepting the small
		// composer-duplicate risk over losing the activity entirely.
		$this->publish_media_activity( $media_id, $user_id, (string) $media_type );
	}

	/**
	 * Publish the deferred feed entry for an upload, unless it was already
	 * surfaced by a BuddyNext post (composer photo/media post or a prior run).
	 *
	 * Photos become a native inline photo post; other media types become a
	 * media card linking to the WPMediaVerse media page.
	 *
	 * @param int    $media_id   WPMediaVerse media-index id.
	 * @param int    $user_id    Uploader user id.
	 * @param string $media_type Resolved media type.
	 * @return void
	 */
	public function publish_media_activity( $media_id, $user_id, $media_type ): void {
		$media_id = (int) $media_id;
		$user_id  = (int) $user_id;
		if ( $media_id <= 0 || $user_id <= 0 || ! buddynext_integration_enabled( 'media', 'feed' ) ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attached = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE media_ids IS NOT NULL AND JSON_CONTAINS(media_ids, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) wp_json_encode( $media_id )
			)
		);
		if ( $attached > 0 ) {
			return;
		}

		if ( in_array( (string) $media_type, array( 'photo', 'image' ), true ) ) {
			( new PostService() )->create(
				$user_id,
				array(
					'type'      => 'photo',
					'content'   => '',
					'media_ids' => array( $media_id ),
				)
			);
			return;
		}

		$url  = '';
		$repo = MediaClient::repo();
		if ( is_object( $repo ) && method_exists( $repo, 'get_permalink' ) ) {
			$url = (string) $repo->get_permalink( $media_id );
		}
		if ( '' === $url ) {
			return;
		}

		IntegrationActivity::publish( $user_id, self::media_activity_verb( (string) $media_type ), $url, '', 'media', '' );
	}

	/**
	 * Withdraw the media feed card when the source media is deleted.
	 *
	 * The 'media' card published on upload is keyed on the media permalink
	 * (`IntegrationActivity` dedups + removes by link_url + type). WPMediaVerse's
	 * `mvs_media_deleted` fires after the slug row is gone, so it passes the
	 * pre-delete permalink — the exact URL the card was stored under. Photo-type
	 * uploads become native `photo` posts (not 'media' cards), so nothing matches
	 * for them here and the call is a no-op; those are the member's own posts.
	 *
	 * @param int    $media_id  Deleted media ID (unused; the URL is the key).
	 * @param int    $author_id Author (unused).
	 * @param string $permalink The media's pre-delete public permalink.
	 * @return void
	 */
	public function on_media_deleted( $media_id, $author_id = 0, $permalink = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		// A permanently deleted document is a media row too, so this same hook
		// carries its id. Remove any composer document card keyed on it — by id,
		// not URL, because the card stores only the id (privacy) and the source
		// row is already gone. Trash fires no hook; a trashed document instead
		// 404s on the next per-viewer render and shows the "unavailable" state.
		$media_id = (int) $media_id;
		if ( $media_id > 0 ) {
			IntegrationActivity::remove_by_meta( 'document', 'doc_id', $media_id );
		}

		$permalink = (string) $permalink;
		if ( '' === $permalink ) {
			return;
		}
		IntegrationActivity::remove( $permalink, 'media' );
	}

	/**
	 * Human verb for a non-photo media activity card.
	 *
	 * @param string $media_type Resolved media type.
	 * @return string
	 */
	private static function media_activity_verb( string $media_type ): string {
		switch ( $media_type ) {
			case 'video':
				return __( 'shared a video', 'buddynext' );
			case 'audio':
				return __( 'shared an audio clip', 'buddynext' );
			default:
				return __( 'shared a photo', 'buddynext' );
		}
	}

	/**
	 * Redirect a WPMediaVerse single-media URL to the activity it was posted in.
	 *
	 * Filters `mvs_single_media_redirect`. When the owner keeps the default
	 * ('activity'), /media/{slug}/ resolves to the source activity (photo post or
	 * media card), so media lives in the community feed and is not exposed as a
	 * separate public page. When there is no source activity, it falls back to the
	 * owner's Media tab, then the Explore feed — never a dead page. When the owner
	 * chose 'dedicated', or when
	 * an earlier filter already set a target, this leaves the decision untouched.
	 *
	 * @param string $redirect Target URL resolved so far ('' = render native page).
	 * @param int    $media_id The media item's id.
	 * @param string $slug     Requested slug (unused; kept for the filter contract).
	 * @return string Target URL, or '' to render WPMediaVerse's native single page.
	 */
	public function redirect_single_media( string $redirect, int $media_id, string $slug ): string {
		unset( $slug );

		// Respect a decision another layer already made.
		if ( '' !== $redirect ) {
			return $redirect;
		}

		// Owner opted into standalone media pages — keep MVS's native single page.
		if ( 'dedicated' === (string) get_option( 'buddynext_media_single_pages', 'activity' ) ) {
			return '';
		}

		// Prefer the activity the media was posted in.
		$post_id = $this->source_post_for_media( $media_id );
		if ( $post_id > 0 ) {
			$url = \BuddyNext\Core\PageRouter::post_url( $post_id );
			if ( '' !== $url ) {
				return $url;
			}
		}

		// No source activity (a direct upload not attached to a post). Land on the
		// owner's Media tab — a BuddyNext surface that shows this member's media in
		// context — rather than the generic Explore overview (the "bounced to
		// overview" symptom). Fall through to the Explore feed only when the owner
		// can't be resolved, then to '' (render the native page) — never a 404.
		$repo  = MediaClient::repo();
		$owner = $repo ? (int) $repo->get( $media_id, 'post_author' ) : 0;
		if ( $owner > 0 ) {
			$profile = \BuddyNext\Core\PageRouter::profile_url( $owner );
			if ( '' !== $profile ) {
				return trailingslashit( $profile ) . 'media/';
			}
		}

		// Fallback: the Explore feed (never a standalone media page, never a 404).
		$explore_id = (int) get_option( 'mvs_page_explore', 0 );
		return $explore_id > 0 ? (string) get_permalink( $explore_id ) : '';
	}

	/**
	 * Find the BuddyNext post a media item was surfaced in (photo post or media card).
	 *
	 * Photo uploads store the media id in `bn_posts.media_ids` (JSON); non-photo
	 * uploads become a media card whose `link_url` is the media's /media/ permalink.
	 * Cached because JSON_CONTAINS can't use an index and a media's source post is
	 * stable once created.
	 *
	 * @param int $media_id Media id.
	 * @return int Source post id, or 0 when the media has no BuddyNext activity.
	 */
	private function source_post_for_media( int $media_id ): int {
		if ( $media_id <= 0 ) {
			return 0;
		}

		$cache_key = 'src_post_' . $media_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$media_url = '';
		$repo      = MediaClient::repo();
		if ( is_object( $repo ) && method_exists( $repo, 'get_permalink' ) ) {
			$media_url = (string) $repo->get_permalink( $media_id );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_posts
				 WHERE ( media_ids IS NOT NULL AND JSON_CONTAINS( media_ids, %s ) )
				    OR ( link_url = %s AND link_url <> '' )
				 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) wp_json_encode( $media_id ),
				$media_url
			)
		);

		// cache-ttl-only: a media->post attachment is immutable once made. There is no event that could invalidate it, because there is no change that can happen.
		wp_cache_set( $cache_key, $post_id, self::CACHE_GROUP, self::CACHE_TTL );
		return $post_id;
	}

	/**
	 * Whether a connection-request note can actually be DELIVERED right now.
	 *
	 * The note is not stored for display anywhere — deliver_note_as_message_request()
	 * below hands it to the messaging engine as a DM message request, and that is
	 * the only way a recipient ever sees it. So when the engine is absent there is
	 * no delivery path, and asking a member to write a note means asking them to
	 * write something nobody will read: they type it, press send, get no error,
	 * and it reaches no one (Basecamp 10185178801).
	 *
	 * This probe is deliberately the SAME condition the delivery method guards on,
	 * so "we asked for a note" and "we can deliver a note" cannot drift apart. If
	 * that guard ever changes, this must change with it — which is why they sit
	 * next to each other.
	 *
	 * @since 1.1.3
	 *
	 * @return bool
	 */
	public static function can_deliver_connection_note(): bool {
		$svc = MediaClient::messaging();

		return is_object( $svc )
			&& method_exists( $svc, 'find_or_create_conversation' )
			&& method_exists( $svc, 'send_message' );
	}

	/**
	 * Deliver a connection-request note to the recipient as a DM message request.
	 *
	 * Fired on buddynext_connection_requested. Only acts when a note is present —
	 * the note step is opt-in via buddynext_connection_require_note, so a 1-click
	 * connect carries no note and this is a no-op. The note is written into a
	 * conversation between the two users; the recipient's participant lands as a
	 * pending request, so it surfaces under their Messages "Requests" tab to accept
	 * or decline — it never auto-opens an active thread with someone they have not
	 * chosen to engage.
	 *
	 * The pending-request status is requested explicitly through the engine's
	 * find_or_create_conversation( …, [ 'force_request' => true ] ) seam (WPMediaVerse
	 * 1.7.1+). The engine still enforces every denial first — a hard block, a
	 * disabled inbox, self, too-new, or the rate limit — so this can never reach a
	 * member who has shut the sender out; it only changes an otherwise-allowed send
	 * from an active thread into a request. Falls back to a plain conversation on
	 * older engine builds that ignore the third argument.
	 *
	 * Hooked on: buddynext_connection_requested( int, int, int, string ).
	 *
	 * @param int    $connection_id Connection row ID (unused).
	 * @param int    $requester_id  User who sent the connection request.
	 * @param int    $recipient_id  User receiving the request.
	 * @param string $note          Optional note attached to the request.
	 * @return void
	 */
	public function deliver_note_as_message_request( int $connection_id, int $requester_id, int $recipient_id, string $note = '' ): void {
		unset( $connection_id );

		$note = trim( $note );
		if ( '' === $note || $requester_id <= 0 || $recipient_id <= 0 ) {
			return;
		}

		if ( ! self::can_deliver_connection_note() ) {
			return;
		}

		$svc = MediaClient::messaging();

		try {
			$conv    = $svc->find_or_create_conversation( $requester_id, $recipient_id, array( 'force_request' => true ) );
			$conv_id = is_array( $conv ) ? (int) ( $conv['conversation_id'] ?? 0 ) : 0;

			if ( $conv_id > 0 ) {
				$svc->send_message( $conv_id, $requester_id, array( 'content' => $note ) );
			}
		} catch ( \Throwable $e ) {
			// Best-effort: the connection request itself already succeeded and its
			// in-app notification still fires. Never let a messaging-engine error
			// bubble back into the connect flow.
			unset( $e );
		}
	}

	/**
	 * Inject a person-specific "Media" link into the BuddyNext left rail.
	 *
	 * Points the viewer at their OWN profile Media tab (the media they have shared),
	 * not the global media Explore page — a "my media" shortcut. Hidden for guests.
	 * The `active` flag is computed from the current REQUEST_URI against that
	 * profile-tab path.
	 *
	 * Hooked on: buddynext_rail_items( array $items, string $hub )
	 *
	 * @param array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}> $items Existing rail items.
	 * @return array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}>
	 */
	public function inject_media_nav_item( array $items ): array {
		$uid = get_current_user_id();
		if ( $uid <= 0 || ! buddynext_integration_enabled( 'media', 'nav' ) ) {
			return $items;
		}

		$media_url  = trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $uid ) ) . 'media/';
		$media_path = rtrim( (string) ( wp_parse_url( $media_url, PHP_URL_PATH ) ?? '' ), '/' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$is_active   = '' !== $media_path && str_starts_with( rtrim( $request_uri, '/' ), $media_path );

		$items[] = array(
			'key'    => 'media',
			'label'  => __( 'Media', 'buddynext' ),
			'url'    => $media_url,
			'icon'   => 'image',
			'show'   => true,
			'active' => $is_active,
			// Personal "You" group — it is the viewer's own media, so it sits with
			// Profile / Discussions / Bookmarks, not in the community group up top.
			'group'  => 'you',
			'order'  => 205,
		);

		return $items;
	}

	/**
	 * Register the Media feature on the integration registry so the owner can hide
	 * its tab/rail item + media activity from BuddyNext -> Integrations.
	 *
	 * Scoped to MEDIA: this engine also powers Direct Messaging, which is NOT gated
	 * here (no message hook reads this toggle), so DMs keep working when Media is off.
	 *
	 * @param array<string,array<string,mixed>> $items Registered integrations.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_integration( array $items ): array {
		if ( MediaClient::available() ) {
			$items['media'] = array(
				'label'    => __( 'Media', 'buddynext' ),
				'version'  => defined( 'MVS_VERSION' ) ? MVS_VERSION : null,
				'has_nav'  => true,
				'has_feed' => true,
				'subtabs'  => array(
					'albums' => __( 'Albums', 'buddynext' ),
				),
			);
		}
		return $items;
	}

	/**
	 * Gate a DM send against the recipient's block list AND DM-access preference.
	 *
	 * BuddyNext layers this on top of MediaVerse's own DM controls via the same
	 * mvs_can_send_message filter — either side can deny, neither overrides the
	 * other. Enforces:
	 *   - recipient has blocked the sender → deny;
	 *   - recipient's "who can DM me" preference (bn_privacy_dm, seeded on
	 *     registration from buddynext_default_dm_access, falling back to that
	 *     option when unset): everyone | members | connections | nobody.
	 * Site admins (manage_options) bypass so staff can always reach members.
	 *
	 * Hooked on: mvs_can_send_message (int $sender_id, int $recipient_id)
	 *
	 * @param bool $allowed      Current allowed state from earlier filters.
	 * @param int  $sender_id    User attempting to send.
	 * @param int  $recipient_id Intended message recipient.
	 * @return bool
	 */
	public function check_block( bool $allowed, int $sender_id, int $recipient_id ): bool {
		if ( ! $allowed ) {
			return false;
		}

		// Staff can always reach anyone.
		if ( user_can( $sender_id, 'manage_options' ) ) {
			return true;
		}

		// Recipient blocked sender → deny. Routed through the BlockService model
		// (the data-access API), never a raw query from this bridge.
		$blocks = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : null;
		if ( is_object( $blocks ) && method_exists( $blocks, 'has_blocked' )
			&& $blocks->has_blocked( $recipient_id, $sender_id ) ) {
			return false;
		}

		// Recipient's DM-access preference. Empty = inherit the site default.
		$pref = (string) get_user_meta( $recipient_id, 'bn_privacy_dm', true );
		if ( '' === $pref ) {
			$pref = (string) get_option( 'buddynext_default_dm_access', 'everyone' );
		}

		switch ( $pref ) {
			case 'nobody':
				return false;
			case 'connections':
				$conn = function_exists( 'buddynext_service' ) ? buddynext_service( 'connections' ) : null;
				return is_object( $conn )
					&& method_exists( $conn, 'are_connected' )
					&& $conn->are_connected( $sender_id, $recipient_id );
			case 'members':
				return $sender_id > 0;
			case 'everyone':
			default:
				return true;
		}
	}

	/**
	 * Auto-moderation gate for DM text (hooked on the engine's
	 * mvs_message_content_check). Runs the message through the same content
	 * safeguard as posts/comments; a WP_Error rejects the send.
	 *
	 * The engine passes a 4th arg (conversation id) that content moderation does
	 * not need — banned-word checks are conversation-agnostic — so the callback
	 * simply does not declare it (WordPress passes it; PHP ignores the extra arg).
	 *
	 * @param true|\WP_Error $result    Running result (WP_Error already blocks).
	 * @param string         $content   Message text.
	 * @param int            $sender_id Sender user ID.
	 * @return true|\WP_Error
	 */
	public function moderate_dm_content( $result, string $content, int $sender_id ) {
		if ( is_wp_error( $result ) || '' === trim( $content ) || ! function_exists( 'buddynext_service' ) ) {
			return $result;
		}

		$guard = buddynext_service( 'safeguard' );
		if ( is_object( $guard ) && method_exists( $guard, 'check_content' ) ) {
			$verdict = $guard->check_content( $content, '', $sender_id, 0, 'create' );

			// A flag lets the message send and files a report once it has an ID (see
			// on_message_sent) — the reactive model, same as posts and comments. Only
			// a hard block stops the send. This used to reject on both, so a
			// severity=flag rule silently refused the DM.
			if ( SafeguardService::is_flag_verdict( $verdict ) ) {
				$this->pending_dm_flag = (string) $verdict->get_error_message();
			} elseif ( is_wp_error( $verdict ) ) {
				return $verdict;
			}
		}

		return $result;
	}

	/**
	 * Translate a check_block() denial into a specific reason code.
	 *
	 * The check_block() gate is boolean, so a denial otherwise surfaces as the
	 * generic 'blocked'. This mirrors its logic to report the real cause — an
	 * actual block stays 'blocked', a "nobody" preference becomes 'dms_disabled',
	 * and a "connections-only" preference becomes 'connections_only' — so the
	 * sender's notice is accurate. Other causes keep the incoming default.
	 *
	 * Hooked on: mvs_dm_denial_reason ( string $reason, int $sender_id, int $recipient_id ).
	 *
	 * @param string $reason       Reason resolved so far (default 'blocked').
	 * @param int    $sender_id    Sender user ID.
	 * @param int    $recipient_id Recipient user ID.
	 * @return string
	 */
	public function dm_denial_reason( string $reason, int $sender_id, int $recipient_id ): string {
		// Staff are never denied by check_block, so there is nothing to translate.
		if ( user_can( $sender_id, 'manage_options' ) ) {
			return $reason;
		}

		// A real block keeps the generic 'blocked' reason (same check as check_block).
		$blocks = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : null;
		if ( is_object( $blocks ) && method_exists( $blocks, 'has_blocked' )
			&& $blocks->has_blocked( $recipient_id, $sender_id ) ) {
			return 'blocked';
		}

		// Otherwise the denial is the recipient's DM-privacy preference.
		$pref = (string) get_user_meta( $recipient_id, 'bn_privacy_dm', true );
		if ( '' === $pref ) {
			$pref = (string) get_option( 'buddynext_default_dm_access', 'everyone' );
		}

		switch ( $pref ) {
			case 'nobody':
				return 'dms_disabled';
			case 'connections':
				return 'connections_only';
			default:
				return $reason;
		}
	}

	/**
	 * Keep the engine's album-privacy inheritance away from space albums.
	 *
	 * Hooked on: mvs_album_inherit_privacy ($inherit, $album_id).
	 *
	 * MVS 2.3.0 clamps a media item to its album's privacy when it is added, so
	 * a photo dropped into a Private album stops being public. That is right for
	 * a personal album, where the album's privacy IS the member's intent.
	 *
	 * It is wrong for a space album. We create every space album with
	 * privacy 'private' as a container flag — deliberately, so a per-album
	 * setting cannot become a second privacy system on top of the space's own
	 * audience (see MediaController::create_space_album). The real decision is
	 * made afterwards by clamp_media_to_space_privacy(), which forces 'private'
	 * only when the space type actually requires membership.
	 *
	 * Letting the engine inherit from that container flag turned every photo in
	 * an OPEN space album private, and they vanished from the public gallery.
	 *
	 * @param bool $inherit  Whether MVS should clamp. Default true.
	 * @param int  $album_id Album receiving the media.
	 * @return bool
	 */
	public function skip_inherit_for_space_albums( bool $inherit, int $album_id ): bool {
		return Galleries::album_space( $album_id ) > 0 ? false : $inherit;
	}

	/**
	 * Resolve a WPMediaVerse user-profile link to the BuddyNext member profile.
	 *
	 * Hooked on: mvs_user_profile_url ($url, $user_id). MVS otherwise falls back
	 * to home_url('/media/@{login}/'), which is not a member profile. Returns the
	 * MVS default untouched if the user can't be resolved.
	 *
	 * @param string $url     URL resolved so far by MVS.
	 * @param int    $user_id User whose profile is being linked.
	 * @return string
	 */
	public function member_profile_url( string $url, int $user_id ): string {
		if ( $user_id <= 0 ) {
			return $url;
		}

		$profile = \BuddyNext\Core\PageRouter::profile_url( $user_id );
		return '' !== $profile ? $profile : $url;
	}

	/**
	 * Resolve the WPMediaVerse follow service, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function mvs_follows(): ?object {
		if ( ! class_exists( '\WPMediaVerse\Core\Plugin' ) ) {
			return null;
		}
		$container = \WPMediaVerse\Core\Plugin::container();
		if ( ! is_object( $container ) || ! $container->has( 'follows' ) ) {
			return null;
		}
		$svc = $container->get( 'follows' );
		return is_object( $svc ) ? $svc : null;
	}

	/**
	 * Resolve BuddyNext's follow service, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function bn_follows(): ?object {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return null;
		}
		$svc = buddynext_service( 'follows' );
		return is_object( $svc ) ? $svc : null;
	}

	/**
	 * Mirror a follow created on a WPMediaVerse profile into bn_follows.
	 *
	 * @param int $follower_id  User who followed.
	 * @param int $following_id User being followed.
	 * @return void
	 */
	public function mirror_mvs_follow( int $follower_id, int $following_id ): void {
		if ( $this->mirroring_follow ) {
			return;
		}
		$bn = $this->bn_follows();
		if ( null === $bn || ! method_exists( $bn, 'follow' ) || ! method_exists( $bn, 'is_following' ) ) {
			return;
		}
		if ( $bn->is_following( $follower_id, $following_id ) ) {
			return;
		}
		// try/finally so a throw in follow() (or a downstream listener) can't leave
		// the re-entrancy guard stuck true, which would silently disable all
		// follow mirroring for the rest of the request.
		$this->mirroring_follow = true;
		try {
			$bn->follow( $follower_id, $following_id );
		} finally {
			$this->mirroring_follow = false;
		}
	}

	/**
	 * Mirror an unfollow on a WPMediaVerse profile into bn_follows.
	 *
	 * @param int $follower_id  User who unfollowed.
	 * @param int $following_id User being unfollowed.
	 * @return void
	 */
	public function mirror_mvs_unfollow( int $follower_id, int $following_id ): void {
		if ( $this->mirroring_follow ) {
			return;
		}
		$bn = $this->bn_follows();
		if ( null === $bn || ! method_exists( $bn, 'unfollow' ) || ! method_exists( $bn, 'is_following' ) ) {
			return;
		}
		if ( ! $bn->is_following( $follower_id, $following_id ) ) {
			return;
		}
		// try/finally so a throw can't leave the re-entrancy guard stuck true.
		$this->mirroring_follow = true;
		try {
			$bn->unfollow( $follower_id, $following_id );
		} finally {
			$this->mirroring_follow = false;
		}
	}

	/**
	 * Mirror a BuddyNext follow into the WPMediaVerse follow graph.
	 *
	 * @param int $follower_id  User who followed.
	 * @param int $following_id User being followed.
	 * @return void
	 */
	public function mirror_bn_follow( int $follower_id, int $following_id ): void {
		if ( $this->mirroring_follow ) {
			return;
		}
		$mvs = $this->mvs_follows();
		if ( null === $mvs || ! method_exists( $mvs, 'follow' ) || ! method_exists( $mvs, 'is_following' ) ) {
			return;
		}
		if ( $mvs->is_following( $follower_id, $following_id ) ) {
			return;
		}
		// try/finally so a throw can't leave the re-entrancy guard stuck true.
		$this->mirroring_follow = true;
		try {
			$mvs->follow( $follower_id, $following_id );
		} finally {
			$this->mirroring_follow = false;
		}
	}

	/**
	 * Mirror a BuddyNext unfollow into the WPMediaVerse follow graph.
	 *
	 * @param int $follower_id  User who unfollowed.
	 * @param int $following_id User being unfollowed.
	 * @return void
	 */
	public function mirror_bn_unfollow( int $follower_id, int $following_id ): void {
		if ( $this->mirroring_follow ) {
			return;
		}
		$mvs = $this->mvs_follows();
		if ( null === $mvs || ! method_exists( $mvs, 'unfollow' ) || ! method_exists( $mvs, 'is_following' ) ) {
			return;
		}
		if ( ! $mvs->is_following( $follower_id, $following_id ) ) {
			return;
		}
		// try/finally so a throw can't leave the re-entrancy guard stuck true.
		$this->mirroring_follow = true;
		try {
			$mvs->unfollow( $follower_id, $following_id );
		} finally {
			$this->mirroring_follow = false;
		}
	}

	/**
	 * Enqueue the listener that routes the MVS profile "Message" button to
	 * BuddyNext's native conversation. Loaded for logged-in visitors only (the
	 * button never renders for guests or on your own profile).
	 *
	 * @return void
	 */
	public function enqueue_message_bridge(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$handle = 'bn-mvs-message-bridge';
		wp_register_script( $handle, '', array(), '1.0.0', array( 'in_footer' => true ) );
		wp_enqueue_script( $handle );

		$messages_url = \BuddyNext\Core\PageRouter::messages_url();
		$inline       = 'document.addEventListener("mvs-open-conversation",function(e){'
			. 'var u=e&&e.detail&&parseInt(e.detail.userId,10);'
			. 'if(!u){return;}'
			. 'var b=' . wp_json_encode( $messages_url ) . ';'
			. 'window.location.href=b+(b.indexOf("?")===-1?"?":"&")+"to="+u;'
			. '});';
		wp_add_inline_script( $handle, $inline );
	}

	/**
	 * Notify the media owner when their content is favourited.
	 *
	 * Only fires a notification on 'added' — not on 'removed' — to avoid
	 * spamming the owner when a user toggles the favourite off.
	 *
	 * Hooked on: mvs_favorite_toggled ($media_id, $user_id, $action). MVS's
	 * FavoriteService emits 'added'/'removed' (not 'add'/'remove').
	 *
	 * @param int    $media_id Media item ID.
	 * @param int    $user_id  User who toggled the favourite.
	 * @param string $action   'added' or 'removed'.
	 */
	public function on_favorite_toggled( int $media_id, int $user_id, string $action ): void {
		if ( 'added' !== $action ) {
			return;
		}

		$owner_id = (int) get_post_field( 'post_author', $media_id );
		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		( new NotificationService() )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.media_favorited',
				'object_type'  => 'media',
				'object_id'    => $media_id,
				'group_key'    => "mvs_fav_{$media_id}",
				'data'         => array( 'media_id' => $media_id ),
			)
		);
	}

	/**
	 * Notify the media owner when someone reacts to their content.
	 *
	 * Mirrors MediaVerse's own media_reaction into the BuddyNext centre (Reactions
	 * tab), collect-only: the catalogue marks bn.media_reaction can_email=false, so
	 * MediaVerse stays the single emailer. Self-reactions are skipped and reactions
	 * on the same media collapse via the group key.
	 *
	 * Hooked on: mvs_reaction_added ($media_id, $user_id, $reaction_type) — the
	 * service hook, so a reaction made through any path (REST toggle or direct) is
	 * caught exactly once.
	 *
	 * @param int    $media_id      Media item ID.
	 * @param int    $user_id       User who reacted.
	 * @param string $reaction_type Reaction slug (e.g. 'like', 'love').
	 */
	public function on_media_reaction( int $media_id, int $user_id, string $reaction_type = '' ): void {
		$owner_id = (int) get_post_field( 'post_author', $media_id );
		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		( new NotificationService() )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.media_reaction',
				'object_type'  => 'media',
				'object_id'    => $media_id,
				'group_key'    => "mvs_reaction_{$media_id}",
				'data'         => array(
					'media_id'      => $media_id,
					'reaction_type' => sanitize_key( $reaction_type ),
				),
			)
		);
	}

	/**
	 * Notify each mentioned member when they are @mentioned in a media comment.
	 *
	 * Mirrors MediaVerse's own media_mention into the BuddyNext centre (Mentions
	 * tab), collect-only. The mentioner is the acting user (the comment author);
	 * self-mentions are skipped.
	 *
	 * Hooked on: mvs_mentions_created ($media_id, $mentioned_ids, $context, $comment_id).
	 *
	 * @param int    $media_id      Media item ID.
	 * @param int[]  $mentioned_ids Users named in the comment.
	 * @param string $context       Where the mention was made (e.g. 'comment').
	 * @param int    $comment_id    The comment carrying the mention.
	 */
	public function on_media_mention( int $media_id, array $mentioned_ids, string $context = '', int $comment_id = 0 ): void {
		$actor_id = get_current_user_id();
		if ( $actor_id <= 0 || $media_id <= 0 ) {
			return;
		}

		$service = new NotificationService();
		foreach ( array_unique( array_map( 'absint', $mentioned_ids ) ) as $recipient_id ) {
			if ( $recipient_id <= 0 || $recipient_id === $actor_id ) {
				continue;
			}
			$service->create(
				array(
					'recipient_id' => $recipient_id,
					'sender_id'    => $actor_id,
					'type'         => 'bn.media_mention',
					'object_type'  => 'media',
					'object_id'    => $media_id,
					'group_key'    => "mvs_mention_{$media_id}_{$recipient_id}",
					'data'         => array(
						'media_id'   => $media_id,
						'comment_id' => (int) $comment_id,
						'context'    => sanitize_key( $context ),
					),
				)
			);
		}
	}

	/**
	 * Load a space IF it is a drive-enabled space drive, else null.
	 *
	 * The one gate every space-drive filter runs first: the drive exists only for
	 * a real space whose owner turned the drive on (mvs_documents_tab, off by
	 * default). Returns null for user/site drives so those pass through untouched.
	 *
	 * @param string $drive_type Drive type.
	 * @param int    $drive_id   Drive id (space id).
	 * @return array<string,mixed>|null
	 */
	private static function drive_space( string $drive_type, int $drive_id ): ?array {
		if ( 'space' !== $drive_type || $drive_id <= 0 ) {
			return null;
		}
		if ( ! (bool) buddynext_get_space_field( $drive_id, 'mvs_documents_tab' ) ) {
			return null;
		}
		$space = buddynext_service( 'spaces' )->get( $drive_id );
		return is_array( $space ) ? $space : null;
	}

	/**
	 * Answer MVS: access level for a space document drive — none|read|write|own.
	 *
	 * A level, not a bool, is the whole point: a member reads the shared drive,
	 * a moderator writes to it, the owner owns it. A non-member reads only when
	 * the space's content is open to them (public), otherwise none — which the
	 * visibility filter then splits into 403 (private) vs 404 (secret).
	 *
	 * @param string $level      MVS default ('none').
	 * @param string $drive_type Drive type.
	 * @param int    $drive_id   Space id.
	 * @param int    $user_id    Viewer.
	 * @return string
	 */
	public function space_drive_access( string $level, string $drive_type, int $drive_id, int $user_id ): string {
		$space = self::drive_space( $drive_type, $drive_id );
		if ( null === $space ) {
			return $level;
		}
		$role = buddynext_service( 'space_members' )->get_role( $drive_id, $user_id );
		if ( 'owner' === $role ) {
			return 'own';
		}
		if ( 'moderator' === $role ) {
			return 'write';
		}
		if ( null !== $role ) {
			// A member reads the shared drive; uploads stay with owner/moderators,
			// so a plain member hits mvs_drive_read_only when they try to write.
			return 'read';
		}
		return SpaceVisibility::can_view_content( $space, $user_id ) ? 'read' : 'none';
	}

	/**
	 * Answer MVS: may this viewer be told the space drive EXISTS.
	 *
	 * Only consulted once access is 'none'. A secret space is invisible (false ->
	 * 404: its existence is the secret); a private/public space is visible (true
	 * -> 403: it exists, contents are not yours). Straight from can_view_space(),
	 * so MVS can never contradict the directory the member is standing on.
	 *
	 * @param bool   $visible    MVS default (false for space).
	 * @param string $drive_type Drive type.
	 * @param int    $drive_id   Space id.
	 * @param int    $user_id    Viewer.
	 * @return bool
	 */
	public function space_drive_visible( bool $visible, string $drive_type, int $drive_id, int $user_id ): bool {
		$space = self::drive_space( $drive_type, $drive_id );
		if ( null === $space ) {
			return $visible;
		}
		return SpaceVisibility::can_view_space( $space, $user_id );
	}

	/**
	 * Answer MVS: which space drives to list for this viewer (drive picker).
	 *
	 * The drive-enabled spaces the viewer is an active member of. Access + label
	 * for each are resolved by the other two filters, so this only names them.
	 *
	 * @param array<int,array<string,mixed>> $drives  MVS's list so far.
	 * @param int                            $user_id Viewer.
	 * @return array<int,array<string,mixed>>
	 */
	public function space_drives_for_user( array $drives, int $user_id ): array {
		if ( $user_id <= 0 ) {
			return $drives;
		}
		foreach ( (array) buddynext_service( 'space_members' )->spaces_for_user( $user_id ) as $space_id ) {
			$space_id = (int) $space_id;
			if ( $space_id > 0 && (bool) buddynext_get_space_field( $space_id, 'mvs_documents_tab' ) ) {
				$drives[] = array(
					'type' => 'space',
					'id'   => $space_id,
				);
			}
		}
		return $drives;
	}

	/**
	 * Answer MVS: a human label for a space drive — the space name.
	 *
	 * @param string $label      MVS default ('').
	 * @param string $drive_type Drive type.
	 * @param int    $drive_id   Space id.
	 * @return string
	 */
	public function space_drive_label( string $label, string $drive_type, int $drive_id ): string {
		if ( 'space' !== $drive_type ) {
			return $label;
		}
		$space = buddynext_service( 'spaces' )->get( $drive_id );
		return is_array( $space ) && ! empty( $space['name'] ) ? (string) $space['name'] : $label;
	}

	/**
	 * Is WPMediaVerse Pro's document drive present on this site.
	 *
	 * The sanctioned bridge guard: bail on the partner's own bootstrap symbol
	 * (the frozen DriveContract) rather than reading an MVS option across the
	 * boundary. Gates the space Files tab, its settings toggle, and the render.
	 *
	 * @return bool
	 */
	public static function documents_available(): bool {
		return class_exists( '\\WPMediaVersePro\\Documents\\DriveContract' );
	}

	/**
	 * The document-attach config the composer needs, read from MVS's OWN app
	 * config (never BuddyNext constants) so the composer can never advertise a
	 * type or size the server will refuse — the exact mismatch that burned the
	 * app once with anonymous links. `enabled` is per-user (the account's
	 * document capability), so an account without a library gets no control at
	 * all rather than an offer-then-refuse.
	 *
	 * @return array{enabled:bool,accept:string,max_size:int}
	 */
	public static function document_composer_config(): array {
		$off = array(
			'enabled'  => false,
			'accept'   => '',
			'max_size' => 0,
		);
		if ( ! self::documents_available() || ! buddynext_integration_enabled( 'media', 'feed' ) ) {
			return $off;
		}
		$res = rest_do_request( new \WP_REST_Request( 'GET', '/mvs/v1/app/config' ) );
		if ( $res->is_error() ) {
			return $off;
		}
		$data = (array) $res->get_data();
		$docs = isset( $data['documents'] ) && is_array( $data['documents'] ) ? $data['documents'] : array();
		if ( empty( $docs['enabled'] ) ) {
			return $off;
		}
		$mimes = isset( $docs['allowed_mimes'] ) && is_array( $docs['allowed_mimes'] ) ? $docs['allowed_mimes'] : array();
		return array(
			'enabled'  => true,
			'accept'   => implode( ',', array_map( 'sanitize_text_field', $mimes ) ),
			'max_size' => isset( $docs['max_size'] ) ? (int) $docs['max_size'] : 0,
		);
	}

	/**
	 * How a document type should be shown in the single-file viewer:
	 *   'native'   — the /preview route serves the file inline (PDF); embed it
	 *                in an iframe.
	 *   'html'     — the /preview route returns a JSON envelope carrying rendered
	 *                HTML (office + text formats); fetch it and print the HTML.
	 *   'download' — no preview; offer the file.
	 *
	 * Driven by MediaVerse's OWN `documents.preview_tiers` (app config), never a
	 * hardcoded "PDF only" guess — MVS renders PDF natively and every office /
	 * text format as server HTML, and it tells us which through this map. Cached
	 * per request; the tiers are a site-wide setting, not per viewer.
	 *
	 * @param string $doc_type MVS document type (pdf|word|excel|…).
	 * @return string 'native'|'html'|'download'.
	 */
	public static function document_preview_mode( string $doc_type ): string {
		static $tiers = null;
		if ( null === $tiers ) {
			$tiers = array(
				'native'      => array(),
				'server_html' => array(),
			);
			if ( self::documents_available() ) {
				$res = rest_do_request( new \WP_REST_Request( 'GET', '/mvs/v1/app/config' ) );
				if ( ! $res->is_error() ) {
					$data = (array) $res->get_data();
					$pt   = isset( $data['documents']['preview_tiers'] ) && is_array( $data['documents']['preview_tiers'] ) ? $data['documents']['preview_tiers'] : array();
					foreach ( array( 'native', 'server_html' ) as $bucket ) {
						$tiers[ $bucket ] = is_array( $pt[ $bucket ] ?? null ) ? $pt[ $bucket ] : array();
					}
				}
			}
		}
		if ( in_array( $doc_type, $tiers['native'], true ) ) {
			return 'native';
		}
		if ( in_array( $doc_type, $tiers['server_html'], true ) ) {
			return 'html';
		}
		return 'download';
	}

	/**
	 * The rendered-HTML preview for a server-HTML document, AS THE VIEWER.
	 *
	 * The /preview route returns a JSON envelope `{ mode:'html', html, … }` for
	 * office + text formats; fetch it (privacy is enforced there like every other
	 * document read) and hand back the inner HTML for the viewer to sanitise and
	 * print. Null when the viewer may not see it or MVS declined a preview.
	 *
	 * @param int $doc_id Document id.
	 * @return string|null Raw preview HTML (caller must kses it), or null.
	 */
	public static function document_preview_html( int $doc_id ): ?string {
		if ( $doc_id <= 0 || ! self::documents_available() ) {
			return null;
		}
		$res = rest_do_request( new \WP_REST_Request( 'GET', '/mvs-pro/v1/documents/' . $doc_id . '/preview' ) );
		if ( $res->is_error() ) {
			return null;
		}
		$data = $res->get_data();
		if ( ! is_array( $data ) || 'html' !== ( $data['mode'] ?? '' ) || empty( $data['html'] ) ) {
			return null;
		}
		return (string) $data['html'];
	}

	/**
	 * The space document-drive view for the Files tab, as plain data.
	 *
	 * BuddyNext owns the space Files UI (MediaVerse ships none — see
	 * docs/architecture/pro/BUDDYNEXT-DRIVE-BRIDGE.md §6). Rather than read MVS
	 * tables, we ask MVS's OWN REST internally for this drive + folder, so every
	 * access decision still routes back through our four drive filters — the two
	 * plugins can never disagree about who may see what. Returns null when the
	 * drive is unavailable or the viewer may not see it (MVS answered 403/404),
	 * so the caller renders nothing rather than an empty shell.
	 *
	 * Folders and documents paginate independently (a drive can carry thousands
	 * of either), so a level with 500 folders is never silently truncated.
	 *
	 * @param int $space_id    Space id (the drive).
	 * @param int $folder      Folder to list (0 = drive root).
	 * @param int $page        1-based document page.
	 * @param int $folder_page 1-based folder page.
	 * @return array{folders:array<int,array<string,mixed>>,documents:array<int,array<string,mixed>>,breadcrumbs:array<int,array{id:int,name:string}>,total:int,pages:int,page:int,folder:int,folder_total:int,folder_pages:int,folder_page:int,can_write:bool}|null
	 */
	public static function space_drive_view( int $space_id, int $folder = 0, int $page = 1, int $folder_page = 1 ): ?array {
		if ( ! self::documents_available() || null === self::drive_space( 'space', $space_id ) ) {
			return null;
		}

		$drive       = 'space:' . $space_id;
		$page        = max( 1, $page );
		$folder_page = max( 1, $folder_page );

		$folders_req = new \WP_REST_Request( 'GET', '/mvs-pro/v1/folders' );
		$folders_req->set_query_params(
			array(
				'drive'    => $drive,
				'parent'   => $folder,
				'orderby'  => 'name',
				'order'    => 'ASC',
				'per_page' => 50,
				'page'     => $folder_page,
			)
		);
		$folders_res = rest_do_request( $folders_req );
		if ( $folders_res->is_error() ) {
			// 403/404 from the drive filters: the viewer may not see this drive.
			return null;
		}

		$docs_req = new \WP_REST_Request( 'GET', '/mvs-pro/v1/documents' );
		$docs_req->set_query_params(
			array(
				'drive'    => $drive,
				'folder'   => $folder,
				'per_page' => 50,
				'page'     => $page,
			)
		);
		$docs_res = rest_do_request( $docs_req );
		if ( $docs_res->is_error() ) {
			return null;
		}

		$folders     = (array) $folders_res->get_data();
		$documents   = (array) $docs_res->get_data();
		$doc_headers = $docs_res->get_headers();
		$fol_headers = $folders_res->get_headers();

		// The current viewer's write level on this drive — the tab shows an
		// upload affordance only when they may actually add to it.
		$access = apply_filters( 'mvs_document_drive_access', 'none', 'space', $space_id, get_current_user_id() );

		return array(
			'folders'      => $folders,
			'documents'    => $documents,
			'breadcrumbs'  => self::drive_breadcrumbs( $folder ),
			'total'        => isset( $doc_headers['X-WP-Total'] ) ? (int) $doc_headers['X-WP-Total'] : count( $documents ),
			'pages'        => isset( $doc_headers['X-WP-TotalPages'] ) ? (int) $doc_headers['X-WP-TotalPages'] : 1,
			'page'         => $page,
			'folder'       => $folder,
			'folder_total' => isset( $fol_headers['X-WP-Total'] ) ? (int) $fol_headers['X-WP-Total'] : count( $folders ),
			'folder_pages' => isset( $fol_headers['X-WP-TotalPages'] ) ? (int) $fol_headers['X-WP-TotalPages'] : 1,
			'folder_page'  => $folder_page,
			'can_write'    => in_array( $access, array( 'write', 'own' ), true ),
		);
	}

	/**
	 * One document from a space drive, for the single-file view.
	 *
	 * Asks MVS's REST for the document (so its privacy gate + our drive filters
	 * both run) and then confirms the row actually belongs to THIS space drive —
	 * without that check a `?bn_doc=` on the Files tab would render any document
	 * the viewer can read, from any drive. Returns null on refusal or a
	 * cross-drive id, so the caller shows "file not found" rather than another
	 * space's file under this space's tab.
	 *
	 * @param int $space_id Space id (the drive this view belongs to).
	 * @param int $doc_id   Document id.
	 * @return array<string,mixed>|null
	 */
	public static function space_drive_document( int $space_id, int $doc_id ): ?array {
		if ( ! self::documents_available() || $doc_id <= 0 || null === self::drive_space( 'space', $space_id ) ) {
			return null;
		}
		$req = new \WP_REST_Request( 'GET', '/mvs-pro/v1/documents/' . $doc_id );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			return null;
		}
		$doc   = (array) $res->get_data();
		$drive = isset( $doc['drive'] ) && is_array( $doc['drive'] ) ? $doc['drive'] : array();
		if ( 'space' !== ( $drive['type'] ?? '' ) || (int) ( $drive['id'] ?? 0 ) !== $space_id ) {
			return null;
		}
		return $doc;
	}

	/**
	 * Search one space drive's documents for the Files tab.
	 *
	 * Delegates to MVS's search REST scoped to this drive (`drive=space:N`), so
	 * the index, relevance and per-row privacy are all MVS's — BuddyNext only
	 * asks the question and lays out the answer. `ready` is false while the
	 * search index is still building, which the UI shows as "preparing" rather
	 * than a false "no results".
	 *
	 * @param int    $space_id Space id (the drive).
	 * @param string $query    Search phrase.
	 * @param int    $page     1-based page.
	 * @return array{items:array<int,array<string,mixed>>,total:int,pages:int,page:int,query:string,ready:bool}|null
	 */
	public static function space_drive_search( int $space_id, string $query, int $page = 1 ): ?array {
		if ( ! self::documents_available() || null === self::drive_space( 'space', $space_id ) ) {
			return null;
		}
		$page = max( 1, $page );
		$req  = new \WP_REST_Request( 'GET', '/mvs-pro/v1/documents/search' );
		$req->set_query_params(
			array(
				'q'        => $query,
				'drive'    => 'space:' . $space_id,
				'page'     => $page,
				'per_page' => 50,
			)
		);
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			return null;
		}
		$data  = (array) $res->get_data();
		$index = isset( $data['index'] ) && is_array( $data['index'] ) ? $data['index'] : array();
		return array(
			'items' => isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array(),
			'total' => isset( $data['total'] ) ? (int) $data['total'] : 0,
			'pages' => isset( $data['pages'] ) ? max( 1, (int) $data['pages'] ) : 1,
			'page'  => $page,
			'query' => (string) $query,
			'ready' => ! empty( $index['ready'] ),
		);
	}

	/**
	 * Validate + prepare a composer document attachment for a post.
	 *
	 * Called at post-create time (as the poster): confirms the poster can
	 * actually see the document they claim to attach — otherwise a crafted
	 * `document_id` could smuggle someone else's document into a post, and every
	 * viewer who CAN see it would then read its title in the feed. Returns the
	 * link_meta to persist (just the id — never a title snapshot), or null to
	 * refuse the attachment.
	 *
	 * @param int $doc_id Document id from the composer.
	 * @return array{doc_id:int}|null
	 */
	public static function resolve_document_for_post( int $doc_id ): ?array {
		if ( $doc_id <= 0 || ! self::documents_available() ) {
			return null;
		}
		$req = new \WP_REST_Request( 'GET', '/mvs-pro/v1/documents/' . $doc_id );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			return null;
		}
		return array( 'doc_id' => $doc_id );
	}

	/**
	 * Fetch one document AS THE CURRENT VIEWER, request-cached.
	 *
	 * The whole privacy model of the document card: the feed stores only the id,
	 * and every render asks MVS "may THIS viewer see it" — so a document that is
	 * private, trashed, or gone answers with an error and its title never
	 * reaches a feed the viewer should not see it in. Cached per (viewer, id) so
	 * the same document rendered twice on a page is one request, not two.
	 *
	 * @param int $doc_id Document id.
	 * @return array<string,mixed>|null The document, or null when the viewer may not see it.
	 */
	private static function fetch_document_as_viewer( int $doc_id ): ?array {
		static $cache = array();
		if ( $doc_id <= 0 || ! self::documents_available() ) {
			return null;
		}
		$key = get_current_user_id() . ':' . $doc_id;
		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}
		$req = new \WP_REST_Request( 'GET', '/mvs-pro/v1/documents/' . $doc_id );
		$res = rest_do_request( $req );
		$doc = $res->is_error() ? null : (array) $res->get_data();

		$cache[ $key ] = $doc;
		return $doc;
	}

	/**
	 * Render a composer document post as a link-out card — per viewer.
	 *
	 * Hooked on `buddynext_render_post_body_document`. Reads only the stored
	 * document id, asks MVS whether THIS viewer may see it, and renders the
	 * shared bridge card (icon + "Document" + the real title + a download link)
	 * when they may — or a neutral, title-less "unavailable" line when they may
	 * not, so a private document never leaks its name into a feed. Falls back to
	 * the plain-text body when the integration's feed aspect is off.
	 *
	 * @param string              $html Incoming card HTML (default '').
	 * @param array<string,mixed> $args Post-body args (link_meta, post_content, bn_post_type).
	 * @return string
	 */
	public function render_document_card( string $html, array $args ): string {
		if ( ! buddynext_integration_enabled( 'media', 'feed' ) ) {
			return $html;
		}
		$meta   = isset( $args['link_meta'] ) && is_array( $args['link_meta'] ) ? $args['link_meta'] : array();
		$doc_id = isset( $meta['doc_id'] ) ? (int) $meta['doc_id'] : 0;
		if ( $doc_id <= 0 ) {
			return $html;
		}

		$doc = self::fetch_document_as_viewer( $doc_id );
		if ( null === $doc ) {
			return self::render_document_unavailable();
		}

		$title = isset( $doc['title'] ) && '' !== (string) $doc['title'] ? (string) $doc['title'] : __( 'Document', 'buddynext' );
		$size  = isset( $doc['file_size'] ) ? (int) $doc['file_size'] : 0;
		$link  = isset( $doc['links']['download'] ) ? (string) $doc['links']['download'] : '';
		if ( '' === $link ) {
			return self::render_document_unavailable();
		}
		// A cookie-auth GET needs a nonce (same as the Files tab download links).
		$url = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), $link );

		// Reference, not embed: the card links OUT to the document; BuddyNext
		// never renders its bytes. Build the preview fresh, per viewer.
		$args['link_preview'] = array(
			'url'   => $url,
			'title' => $title,
			'desc'  => $size > 0 ? size_format( $size ) : '',
		);

		return IntegrationActivity::render_bridge_card( $args, 'file-text', __( 'Document', 'buddynext' ) );
	}

	/**
	 * The neutral, title-less card a viewer sees for a document they cannot open.
	 *
	 * Never names the document — existence is tolerable, the name is the leak.
	 *
	 * @return string
	 */
	private static function render_document_unavailable(): string {
		return '<div class="bn-post-card__bridge-card bn-post-card__bridge-card--document is-unavailable">'
			. '<span class="bn-post-card__bridge-icon">' . buddynext_get_icon( 'file-text' ) . '</span>'
			. '<span class="bn-post-card__bridge-title">' . esc_html__( 'This document isn’t available to you.', 'buddynext' ) . '</span>'
			. '</div>';
	}

	/**
	 * Trail for the current folder, root-first, INCLUDING the current folder.
	 *
	 * MVS stamps a folder object with `breadcrumbs` — its visible ancestors,
	 * root-first — so the current folder's trail is those ancestors plus the
	 * folder itself. The caller renders all but the last as links. Empty at the
	 * drive root, and empty if MVS refuses the folder (the drive gate already
	 * ran, so that only happens on a stale/hidden id — render the root trail).
	 *
	 * @param int $folder Current folder id (0 = root).
	 * @return array<int,array{id:int,name:string}>
	 */
	private static function drive_breadcrumbs( int $folder ): array {
		if ( $folder <= 0 ) {
			return array();
		}
		$req = new \WP_REST_Request( 'GET', '/mvs-pro/v1/folders/' . $folder );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			return array();
		}
		$data  = (array) $res->get_data();
		$trail = array();
		foreach ( (array) ( $data['breadcrumbs'] ?? array() ) as $c ) {
			$trail[] = array(
				'id'   => (int) ( $c['id'] ?? 0 ),
				'name' => (string) ( $c['name'] ?? '' ),
			);
		}
		$trail[] = array(
			'id'   => (int) ( $data['id'] ?? $folder ),
			'name' => (string) ( $data['name'] ?? '' ),
		);
		return $trail;
	}

	/**
	 * Create a bn.new_message notification for each recipient.
	 *
	 * Skips the sender themselves (no self-notification).
	 *
	 * Hooked on: mvs_message_sent ($message_id, $conversation_id, $sender_id, $recipient_ids)
	 *
	 * @param int   $message_id      Message that was sent.
	 * @param int   $conversation_id Conversation the message belongs to.
	 * @param int   $sender_id       User who sent the message.
	 * @param int[] $recipient_ids   Users who should receive the notification.
	 */
	public function on_message_sent( int $message_id, int $conversation_id, int $sender_id, array $recipient_ids ): void {
		// No message, no notification. WPMediaVerse fires this hook with
		// message_id 0 when a conversation is CREATED without an initial send
		// (opening a compose window and picking a recipient), so the recipient
		// was told "X sent you a message" for a message that does not exist -
		// with an empty thread waiting behind it. Reproduced on 1.1.1: create a
		// conversation, 0 rows in mvs_messages, one bn.new_message row carrying
		// data {"message_id": 0}.
		//
		// The root-cause fire is fixed in WPMediaVerse, but this guard stays
		// regardless: the bridge contract says BN validates what a partner
		// hands it rather than trusting the payload, and any other messaging
		// engine wired to this hook gets the same protection. It also keeps
		// auto_flag() below from filing a moderation report against message 0.
		if ( $message_id <= 0 ) {
			return;
		}

		// The pre-send gate (moderate_dm_content) let a flagged message through and
		// left its reason here; now that the message exists and has an ID, file the
		// system report so it reaches the moderation queue. Cleared either way, so a
		// flag can never leak onto the next message sent in the same request.
		if ( '' !== $this->pending_dm_flag ) {
			ModerationService::auto_flag( 'message', $message_id, $this->pending_dm_flag );
			$this->pending_dm_flag = '';
		}

		$service = new NotificationService();

		// Normalise recipient ids — strip sender, ints only.
		$clean_recipients = array_values(
			array_filter(
				array_map( 'intval', $recipient_ids ),
				static fn( int $rid ): bool => $rid > 0 && $rid !== $sender_id
			)
		);

		/**
		 * Fires from the sender's perspective when a DM goes out.
		 *
		 * BN-domain adapter on top of `mvs_message_sent` so gamification
		 * plugins, analytics collectors, and webhook bridges can hook
		 * the BN namespace without depending on the WPMediaVerse hook
		 * being present.
		 *
		 * @param int   $sender_id       Sender (actor).
		 * @param int   $message_id      Message that was sent.
		 * @param int   $conversation_id Conversation the message belongs to.
		 * @param int[] $recipient_ids   Recipients of the message (sender stripped).
		 */
		do_action( 'buddynext_dm_sent', $sender_id, $message_id, $conversation_id, $clean_recipients );

		$blocks = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : null;

		foreach ( $clean_recipients as $recipient_id ) {
			// Restrict gate. WPMV writes the message either way — sender
			// doesn't know they're restricted — but BN won't badge the
			// recipient's bell, fire the recipient-side adapter event, or
			// push to their notification feed. The message still sits in
			// the WPMV inbox; the recipient can find it manually if they
			// look, but no signal interrupts them.
			// Mute is a one-way "stop notifying me about this person": if the
			// recipient muted the sender, the message still lands in their WPMV
			// inbox but no bell notification interrupts them — same suppression as
			// restrict, opposite relationship direction.
			if ( $blocks
				&& ( ( method_exists( $blocks, 'is_restricted' ) && $blocks->is_restricted( $recipient_id, $sender_id ) )
					|| ( method_exists( $blocks, 'is_muted' ) && $blocks->is_muted( $recipient_id, $sender_id ) ) )
			) {
				continue;
			}

			$service->create(
				array(
					'recipient_id' => $recipient_id,
					'sender_id'    => $sender_id,
					'type'         => 'bn.new_message',
					'object_type'  => 'conversation',
					'object_id'    => $conversation_id,
					'group_key'    => "dm_{$conversation_id}_{$recipient_id}",
					'data'         => array( 'message_id' => $message_id ),
				)
			);

			/**
			 * Fires from each recipient's perspective when a DM arrives.
			 *
			 * Per-recipient mirror of `buddynext_dm_sent`. Useful for
			 * gamification rules that award the recipient (e.g. "first
			 * conversation started") or for unread-count counters that
			 * key off the recipient id.
			 *
			 * @param int $recipient_id    Recipient (per-iteration).
			 * @param int $sender_id       Sender (actor).
			 * @param int $message_id      Message id.
			 * @param int $conversation_id Conversation id.
			 */
			do_action( 'buddynext_dm_received', $recipient_id, $sender_id, $message_id, $conversation_id );
		}
	}

	/**
	 * Sync a WPMediaVerse lightbox comment to the BuddyNext activity feed.
	 *
	 * When a user comments on a photo via the MVS lightbox, find the bn_posts
	 * entry that contains that media_id and create a bn_comments row threaded
	 * under it — so the comment appears in the BuddyNext feed as a regular
	 * post comment.
	 *
	 * Signature matches the engine: mvs_comment_created fires
	 * ( $media_id, $user_id, $comment_id, $content, $source ).
	 *
	 * @param int $media_id   MVS media post ID.
	 * @param int $user_id    Commenting user ID (unused; resolved from the comment).
	 * @param int $comment_id WP comment ID created by MVS.
	 */
	public function sync_lightbox_comment( int $media_id, int $user_id, int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		$user_id = (int) $comment->user_id;
		if ( ! $user_id ) {
			return;
		}

		global $wpdb;

		// Find the BuddyNext post that has this media_id in its media_ids JSON
		// array. JSON_CONTAINS does an exact array-element match — a LIKE '%5%'
		// matched 5, 50, 51, 15… (false positives). JSON_VALID guards rows whose
		// media_ids is NULL/empty/non-JSON so the function can't error on them.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bn_post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_posts
				 WHERE media_ids IS NOT NULL AND media_ids <> ''
				   AND JSON_VALID(media_ids) AND JSON_CONTAINS(media_ids, %s)
				   AND status = 'published'
				 ORDER BY created_at DESC LIMIT 1",
				(string) $media_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $bn_post_id ) {
			return;
		}

		// Dedup: this hook can re-fire for the same lightbox comment (re-saves,
		// repeated sync passes). Without a guard each fire inserted another
		// bn_comments row, double-counting and re-notifying. Skip if an identical
		// comment (same post + author + body) already exists.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_comments
				 WHERE object_type = 'post' AND object_id = %d AND user_id = %d AND content = %s
				 LIMIT 1",
				$bn_post_id,
				$user_id,
				wp_kses_post( $comment->comment_content )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $existing > 0 ) {
			return;
		}

		// Create the bn_comments entry.
		$now = current_time( 'mysql' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'object_type' => 'post',
				'object_id'   => $bn_post_id,
				'user_id'     => $user_id,
				'content'     => wp_kses_post( $comment->comment_content ),
				'parent_id'   => 0,
				'created_at'  => $now,
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Increment comment count on the bn_posts row. Guard the service lookup
		// the same way the rest of this bridge does (e.g. the blocks check above):
		// a null container/key must skip the counter, not fatal the sync.
		$post_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'post_service' ) : null;
		if ( $post_service ) {
			$post_service->increment_counter( $bn_post_id, 'comment_count' );
		}

		// Fire BuddyNext hook so notifications/webhooks pick it up. Use the
		// canonical 4-arg signature (comment_id, object_type, object_id, user_id)
		// that CommentService fires and every listener expects — a short 3-arg
		// form would ArgumentCountError-fatal the 4-arg listeners.
		$new_comment_id = (int) $wpdb->insert_id;
		if ( $new_comment_id > 0 ) {
			do_action( 'buddynext_comment_created', $new_comment_id, 'post', $bn_post_id, $user_id );
		}
	}
}
