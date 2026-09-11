<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Notification preference catalogue.
 *
 * Single source of truth for the per-type metadata the Notification preferences
 * UI needs: human label, description, group, default in-app + email_freq
 * defaults, and whether the type can send email.
 *
 * Lockstep contract with NotificationMessageService: every type that
 * NotificationMessageService::compose_single() handles MUST exist here so the
 * prefs UI can never present an orphan row. The
 * NotificationPrefCatalogueTest covers this invariant.
 *
 * Filter `buddynext_notification_prefs_catalogue` lets Pro / bridge plugins
 * register additional types without modifying Free.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

/**
 * Type catalogue used by the Notification preferences UI.
 */
class NotificationPrefCatalogue {

	/**
	 * Group identifiers - used by the accordion in templates/settings/notifications.php.
	 */
	public const GROUP_SOCIAL     = 'social';
	public const GROUP_FEED       = 'feed';
	public const GROUP_SPACES     = 'spaces';
	public const GROUP_MESSAGES   = 'messages';
	public const GROUP_MODERATION = 'moderation';
	public const GROUP_GROWTH     = 'growth';

	/**
	 * Per-type site-owner "default on" option. When the owner turns a type's
	 * default off in Settings, that option holds the override for members who have
	 * not chosen for themselves. Lives here (the type-config holder) so BOTH the
	 * delivery path (NotificationPrefService::default_pref) and the settings-page
	 * display path (resolve_for_user) read one source and cannot diverge — the
	 * settings page used to ignore it and show a type ON that the owner had
	 * defaulted OFF (card 10268684581).
	 *
	 * @var array<string,string>
	 */
	private const ADMIN_DEFAULT_OPTION = array(
		'bn.new_follower'         => 'buddynext_notif_default_follow',
		'bn.connection_requested' => 'buddynext_notif_default_connection',
		'bn.connection_accepted'  => 'buddynext_notif_default_connection',
		'bn.post_reacted'         => 'buddynext_notif_default_reaction',
		'bn.post_commented'       => 'buddynext_notif_default_comment',
		'bn.mention'              => 'buddynext_notif_default_mention',
		'bn.space_join_requested' => 'buddynext_notif_default_space_join',
	);

	/**
	 * The effective on-site default for a type: the catalogue default, overridden
	 * by the site-owner's per-type default option when that option EXISTS. An absent
	 * option (null) means "never configured" and keeps the catalogue default. A
	 * stored boolean false comes back as '' — an explicit OFF, not "unset" — so
	 * rest_sanitize_boolean maps '' / '0' / false -> false and '1' / true -> true.
	 *
	 * @param string $slug Type slug.
	 * @return bool
	 */
	public function effective_default_on_site( string $slug ): bool {
		$entry   = $this->all()[ $slug ] ?? array();
		$on_site = (bool) ( $entry['default_on_site'] ?? true );

		if ( isset( self::ADMIN_DEFAULT_OPTION[ $slug ] ) ) {
			$admin_val = get_option( self::ADMIN_DEFAULT_OPTION[ $slug ], null );
			if ( null !== $admin_val ) {
				$on_site = (bool) rest_sanitize_boolean( $admin_val );
			}
		}

		return $on_site;
	}

	/**
	 * Return the full type catalogue keyed by type slug.
	 *
	 * Each entry: {
	 *   slug              string  - type key (e.g. 'bn.new_follower').
	 *   label             string  - translated human label.
	 *   description       string  - translated 1-line description.
	 *   group             string  - one of the GROUP_* constants.
	 *   default_on_site   bool    - implicit default for the on_site channel.
	 *   default_email_freq string - implicit default email frequency.
	 *   can_email         bool    - whether the type produces a transactional email.
	 *   moderator_only    bool    - optional; when true the prefs UI hides the row
	 *                               from non-moderators (see grouped()).
	 *   email_only        bool    - optional; when true the row has no in-app
	 *                               channel, so the prefs UI hides its In-app toggle
	 *                               and shows only the email frequency (e.g. 'digest').
	 * }
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$catalogue = array(
			// Social graph.
			'bn.new_follower'             => array(
				'label'              => __( 'New follower', 'buddynext' ),
				'description'        => __( 'Someone started following you.', 'buddynext' ),
				'group'              => self::GROUP_SOCIAL,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.follow_requested'         => array(
				'label'              => __( 'Follow request', 'buddynext' ),
				'description'        => __( 'Someone requested to follow you.', 'buddynext' ),
				'group'              => self::GROUP_SOCIAL,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.connection_requested'     => array(
				'label'              => __( 'Connection request', 'buddynext' ),
				'description'        => __( 'Someone sent you a connection request.', 'buddynext' ),
				'group'              => self::GROUP_SOCIAL,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.connection_accepted'      => array(
				'label'              => __( 'Connection accepted', 'buddynext' ),
				'description'        => __( 'Someone accepted your connection request.', 'buddynext' ),
				'group'              => self::GROUP_SOCIAL,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),

			// Feed activity.
			'bn.post_reacted'             => array(
				'label'              => __( 'Reactions on your posts', 'buddynext' ),
				'description'        => __( 'Someone reacted to a post you authored.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'daily',
				'can_email'          => true,
			),
			'bn.comment_reacted'          => array(
				'label'              => __( 'Reactions on your comments', 'buddynext' ),
				'description'        => __( 'Someone reacted to a comment you authored.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'daily',
				'can_email'          => true,
			),
			'bn.post_commented'           => array(
				'label'              => __( 'Comments on your posts', 'buddynext' ),
				'description'        => __( 'Someone commented on a post you authored.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.comment_reply'            => array(
				'label'              => __( 'Replies to your comments', 'buddynext' ),
				'description'        => __( 'Someone replied to one of your comments.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.post_shared'              => array(
				'label'              => __( 'Shares of your posts', 'buddynext' ),
				'description'        => __( 'Someone shared a post you authored.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'daily',
				'can_email'          => true,
			),
			'bn.mention'                  => array(
				'label'              => __( 'Mentions of you', 'buddynext' ),
				'description'        => __( 'Someone mentioned you in a post or comment.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.bookmark_milestone'       => array(
				'label'              => __( 'Bookmark milestones', 'buddynext' ),
				'description'        => __( 'Your post was bookmarked a notable number of times.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),

			// Spaces.
			'bn.space_join'               => array(
				'label'              => __( 'New members joining your space', 'buddynext' ),
				'description'        => __( 'Someone joined a space you belong to.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			'bn.space_invite'             => array(
				'label'              => __( 'Space invites', 'buddynext' ),
				'description'        => __( 'You were invited to join a space.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_join_requested'     => array(
				'label'              => __( 'Space join requests', 'buddynext' ),
				'description'        => __( 'Someone requested to join a space you moderate.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_request_approved'   => array(
				'label'              => __( 'Space request approved', 'buddynext' ),
				'description'        => __( 'Your request to join a space was approved.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_ownership_received' => array(
				'label'              => __( 'Space ownership received', 'buddynext' ),
				'description'        => __( 'You became the owner of a space.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_join_declined'      => array(
				'label'              => __( 'Space request declined', 'buddynext' ),
				'description'        => __( 'Your request to join a space was declined.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_new_post'           => array(
				'label'              => __( 'New posts in your spaces', 'buddynext' ),
				'description'        => __( 'Someone posted in a space you belong to.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'daily',
				'can_email'          => true,
			),
			'bn.space_media_unlinked'     => array(
				'label'              => __( 'Your media removed from a space', 'buddynext' ),
				'description'        => __( 'A space owner or moderator removed media you shared from the space. You keep your own copy.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				// A quick, low-stakes unlink (the member keeps their own copy) - the
				// in-app bell is enough, no email. can_email=false, no template needed.
				'default_email_freq' => 'off',
				'can_email'          => false,
			),
			'bn.announcement'             => array(
				'label'              => __( 'Announcements', 'buddynext' ),
				'description'        => __( 'An admin or space moderator posted an announcement.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_role_changed'       => array(
				'label'              => __( 'Space role changes', 'buddynext' ),
				'description'        => __( 'Your role in a space changed.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.bulk_invite'              => array(
				'label'              => __( 'Bulk space invites', 'buddynext' ),
				'description'        => __( 'You were invited to several spaces at once.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => false,
			),

			// Messages.
			'bn.new_message'              => array(
				'label'              => __( 'Direct messages', 'buddynext' ),
				'description'        => __( 'Someone sent you a direct message.', 'buddynext' ),
				'group'              => self::GROUP_MESSAGES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),

			// Moderation.
			'bn.user_warned'              => array(
				'label'              => __( 'Moderator warnings', 'buddynext' ),
				'description'        => __( 'A moderator issued a warning about your activity.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.strike_warning'           => array(
				'label'              => __( 'Strike warnings', 'buddynext' ),
				'description'        => __( 'You are close to receiving an account strike.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.strike_issued'            => array(
				'label'              => __( 'Strike issued', 'buddynext' ),
				'description'        => __( 'Your account received a community-guideline strike.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.member_suspended'         => array(
				'label'              => __( 'Account suspended', 'buddynext' ),
				'description'        => __( 'Your account has been suspended.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.user_unsuspended'         => array(
				'label'              => __( 'Account reinstated', 'buddynext' ),
				'description'        => __( 'Your suspended account has been reinstated.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.user_shadow_banned'       => array(
				'label'              => __( 'Account under review', 'buddynext' ),
				'description'        => __( 'Your account is under review; some actions may be limited.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),
			'bn.appeal_submitted'         => array(
				'label'              => __( 'Appeal received', 'buddynext' ),
				'description'        => __( 'A member appealed a moderation action and it is awaiting review.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
				// Sent only to site administrators (ModerationListener::on_appeal_submitted),
				// so the prefs row is hidden from members who could never receive it.
				'moderator_only'     => true,
			),
			'bn.appeal_resolved'          => array(
				'label'              => __( 'Appeal resolved', 'buddynext' ),
				'description'        => __( 'Your appeal was reviewed and resolved.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.report_resolved'          => array(
				'label'              => __( 'Reports you submitted', 'buddynext' ),
				'description'        => __( 'A report you submitted has been reviewed.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),
			'bn.new_report'               => array(
				'label'              => __( 'New reports to review', 'buddynext' ),
				'description'        => __( 'New content was reported and is awaiting moderator review.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
				// Sent only to site admins and space owners/moderators
				// (ModerationListener::notify_moderators_of_report), so the prefs row is
				// hidden from members who could never receive it.
				'moderator_only'     => true,
			),
			'bn.post_approved'            => array(
				'label'              => __( 'Post approved', 'buddynext' ),
				'description'        => __( 'Your post was approved and is now live.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.post_rejected'            => array(
				'label'              => __( 'Post not approved', 'buddynext' ),
				'description'        => __( 'A post you submitted was not approved by the moderators.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.content_removed'          => array(
				'label'              => __( 'Content removed', 'buddynext' ),
				'description'        => __( 'Something you posted was removed by a moderator.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),

			// Growth + system.
			// Badges + level-ups originate in wb-gamification (a partner plugin), so
			// BN mirrors them in the notification center for display only and never
			// emails them: the integration owns its own email templates, and BN
			// emailing too would double up. can_email=false — the collect-only rule
			// for all partner-sourced notifications (matches the Jetonomy listener).
			'bn.badge_awarded'            => array(
				'label'              => __( 'Badges earned', 'buddynext' ),
				'description'        => __( 'You earned a new badge.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),
			'bn.level_up'                 => array(
				'label'              => __( 'Level-ups', 'buddynext' ),
				'description'        => __( 'You reached a new level.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),
			'bn.onboarding_nudge'         => array(
				'label'              => __( 'Onboarding nudges', 'buddynext' ),
				'description'        => __( 'Helpful reminders to finish setting up your profile.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			'bn.daily_digest'             => array(
				'label'              => __( 'Daily digest', 'buddynext' ),
				'description'        => __( 'A single daily roundup of activity for you.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => false,
				'default_email_freq' => 'daily',
				'can_email'          => true,
			),
			'bn.weekly_digest'            => array(
				'label'              => __( 'Weekly digest', 'buddynext' ),
				'description'        => __( 'A single weekly roundup of activity for you.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => false,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			// Master email-digest switch. The digest email's one-click unsubscribe
			// writes (user, 'digest', email_freq='off'); CronService::get_digest_user_ids
			// excludes anyone carrying that row from EVERY digest. It is a real
			// catalogue entry (not an internal pseudo-type) so get_all_prefs() surfaces
			// it and the settings UI + REST render an on/off control — otherwise the
			// unsubscribe was a one-way door with no member-facing way back
			// (card 10264293350). 'off' suppresses; 'daily'/'weekly' re-enrols on that
			// cadence.
			//
			// can_email is FALSE: this switch does not itself SEND a mail. The actual
			// digest sends are bn.daily_digest / bn.weekly_digest (their own templates);
			// this row only GATES them, and the cron reads the suppressor row directly
			// (raw SQL NOT EXISTS), never through can_email(). Marking it emailable
			// would demand a seeded 'digest' template that nothing ever sends.
			//
			// email_only: there is no in-app digest, so the prefs UI hides the In-app
			// toggle and — because the control IS the email frequency — renders the
			// frequency selector for this row even though can_email is false (the
			// template treats email_only OR can_email as "show the frequency").
			'digest'                      => array(
				'label'              => __( 'Email digests', 'buddynext' ),
				'description'        => __( 'A periodic email roundup of your unread notifications. Set to Off to stop all digest emails; choose Daily or Weekly to resume.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => false,
				'default_email_freq' => 'daily',
				'can_email'          => false,
				'email_only'         => true,
			),
			'bn.media_favorited'          => array(
				'label'              => __( 'Media favourited', 'buddynext' ),
				'description'        => __( 'Someone favourited media you posted.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			// Reactions + mentions on media are mirrored from WPMediaVerse, which
			// owns their email. can_email is FALSE so BuddyNext never sends a second
			// email for a partner event (the collect-only bridge rule); the centre
			// shows them so members see everything in one place.
			'bn.media_reaction'           => array(
				'label'              => __( 'Reactions on your media', 'buddynext' ),
				'description'        => __( 'Someone reacted to media you posted.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),
			'bn.media_mention'            => array(
				'label'              => __( 'Mentions in media comments', 'buddynext' ),
				'description'        => __( 'Someone mentioned you in a comment on media.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),
		);

		/**
		 * Filter the notification-pref catalogue.
		 *
		 * Pro / bridge plugins use this to register additional notification
		 * types. Each entry must follow the array shape above (slug, label,
		 * description, group, default_on_site, default_email_freq, can_email).
		 * Entries are keyed by type slug; bridges should re-key with their own
		 * 'bn.bridge_*' slug.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string, array<string, mixed>> $catalogue Catalogue keyed by type slug.
		 */
		$catalogue = (array) apply_filters( 'buddynext_notification_prefs_catalogue', $catalogue );

		// Direct-message notifications require DMs to be usable — the WPMediaVerse
		// engine present AND the site owner's DM switch on. Use the canonical
		// entry_enabled() gate (not just available()) so the bn.new_message toggle
		// is dropped when the owner has turned DMs off, not only when the engine
		// is missing — otherwise it showed a dead toggle whenever the plugin was
		// active even with DMs disabled.
		if ( ! \BuddyNext\Messages\MessagesData::entry_enabled() ) {
			unset( $catalogue['bn.new_message'] );
		}

		// Enforce the slug invariant on every entry, INCLUDING bridge/Pro additions
		// registered through the filter above (which would otherwise miss it).
		foreach ( $catalogue as $slug => $entry ) {
			if ( is_array( $entry ) ) {
				$catalogue[ $slug ]['slug'] = $slug;
			}
		}

		return $catalogue;
	}

	/**
	 * Whether a notification type is allowed to produce a transactional email.
	 *
	 * Authoritative gate so BuddyNext never emails on behalf of an integration:
	 * mirrored/aggregated partner types register `can_email = false` (the partner
	 * owns its own emails). UNREGISTERED types — and registered entries that omit
	 * the key — default to FALSE, so a partner mirror type that forgot can_email
	 * can never be emailed (collect-only). All 40 core types set can_email = true
	 * explicitly, so core behaviour is unchanged.
	 *
	 * @param string $type Notification type slug.
	 * @return bool
	 */
	public function can_email( string $type ): bool {
		$catalogue = $this->all();
		if ( ! isset( $catalogue[ $type ] ) ) {
			return false;
		}
		return (bool) ( $catalogue[ $type ]['can_email'] ?? false );
	}

	/**
	 * Return catalogue entries grouped by their `group` field.
	 *
	 * Group order is fixed and matches the order the prefs UI renders. Rows a
	 * type marks `moderator_only` are dropped for viewers who cannot moderate the
	 * site, so a plain member is not offered a preference for a notification only
	 * moderators ever receive. `all()` is deliberately left unfiltered — the
	 * delivery and validation paths must still know every type regardless of who
	 * is acting when a notification is composed.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function grouped(): array {
		$groups = array(
			self::GROUP_SOCIAL     => array(),
			self::GROUP_FEED       => array(),
			self::GROUP_SPACES     => array(),
			self::GROUP_MESSAGES   => array(),
			self::GROUP_MODERATION => array(),
			self::GROUP_GROWTH     => array(),
		);

		// Same receivable-capability gate the moderation REST routes use, so the
		// prefs UI shows a moderator-only row to exactly the users who can receive
		// it (WP admins plus community moderators — not a bare manage_options check,
		// which would miss community-role moderators).
		$can_moderate = ( new \BuddyNext\Core\RoleService() )->can_moderate_site( get_current_user_id() );

		foreach ( $this->all() as $entry ) {
			if ( ! empty( $entry['moderator_only'] ) && ! $can_moderate ) {
				continue;
			}
			$group = isset( $entry['group'] ) ? (string) $entry['group'] : self::GROUP_GROWTH;
			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = array();
			}
			$groups[ $group ][] = $entry;
		}

		return $groups;
	}

	/**
	 * Return the human label for a group identifier.
	 *
	 * @param string $group One of the GROUP_* constants.
	 * @return string Translated label.
	 */
	public function group_label( string $group ): string {
		switch ( $group ) {
			case self::GROUP_SOCIAL:
				return __( 'Follows and connections', 'buddynext' );
			case self::GROUP_FEED:
				return __( 'Feed activity', 'buddynext' );
			case self::GROUP_SPACES:
				return __( 'Spaces', 'buddynext' );
			case self::GROUP_MESSAGES:
				return __( 'Messages', 'buddynext' );
			case self::GROUP_MODERATION:
				return __( 'Moderation', 'buddynext' );
			case self::GROUP_GROWTH:
				return __( 'Growth and digests', 'buddynext' );
			default:
				return ucfirst( $group );
		}
	}

	/**
	 * Merge stored per-user prefs onto the catalogue's defaults.
	 *
	 * Returns one entry per catalogue type with the stored on_site + email_freq
	 * applied when present, falling back to defaults otherwise. This is the
	 * single source of truth for `GET /me/notification-prefs` so the UI can
	 * render every row without overlaying defaults client-side.
	 *
	 * @param array<string, array{on_site: bool, email_freq: string}> $stored Per-user stored prefs.
	 * @return array<string, array{on_site: bool, email_freq: string, label: string, group: string, can_email: bool, email_only: bool, description: string}>
	 */
	public function resolve_for_user( array $stored ): array {
		$out = array();
		foreach ( $this->all() as $slug => $entry ) {
			// The site-owner's per-type default (Settings) is the base a member with no
			// stored choice sees — the same base the delivery path uses (card 10268684581).
			$on_site    = $this->effective_default_on_site( $slug );
			$email_freq = (string) ( $entry['default_email_freq'] ?? 'immediate' );

			if ( isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) ) {
				if ( array_key_exists( 'on_site', $stored[ $slug ] ) ) {
					$on_site = (bool) $stored[ $slug ]['on_site'];
				}
				if ( ! empty( $stored[ $slug ]['email_freq'] ) ) {
					$email_freq = (string) $stored[ $slug ]['email_freq'];
				}
			}

			$out[ $slug ] = array(
				'on_site'     => $on_site,
				'email_freq'  => $email_freq,
				'label'       => (string) ( $entry['label'] ?? $slug ),
				'group'       => (string) ( $entry['group'] ?? self::GROUP_GROWTH ),
				'can_email'   => (bool) ( $entry['can_email'] ?? true ),
				// email_only rows (e.g. the digest master switch) carry no in-app
				// channel: their only control is the email frequency. Surfaced so app
				// clients render the frequency selector for them even though can_email
				// is false, matching the web settings screen.
				'email_only'  => (bool) ( $entry['email_only'] ?? false ),
				'description' => (string) ( $entry['description'] ?? '' ),
			);
		}

		return $out;
	}
}
