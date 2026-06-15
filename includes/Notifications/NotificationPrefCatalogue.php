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
	 * Group identifiers - used by the accordion in templates/notifications/prefs.php.
	 */
	public const GROUP_SOCIAL     = 'social';
	public const GROUP_FEED       = 'feed';
	public const GROUP_SPACES     = 'spaces';
	public const GROUP_MESSAGES   = 'messages';
	public const GROUP_MODERATION = 'moderation';
	public const GROUP_GROWTH     = 'growth';

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
	 * }
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$catalogue = array(
			// Social graph.
			'bn.new_follower'           => array(
				'label'              => __( 'New follower', 'buddynext' ),
				'description'        => __( 'Someone started following you.', 'buddynext' ),
				'group'              => self::GROUP_SOCIAL,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.connection_requested'   => array(
				'label'              => __( 'Connection request', 'buddynext' ),
				'description'        => __( 'Someone sent you a connection request.', 'buddynext' ),
				'group'              => self::GROUP_SOCIAL,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.connection_accepted'    => array(
				'label'              => __( 'Connection accepted', 'buddynext' ),
				'description'        => __( 'Someone accepted your connection request.', 'buddynext' ),
				'group'              => self::GROUP_SOCIAL,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.connection_declined'    => array(
				'label'              => __( 'Connection declined', 'buddynext' ),
				'description'        => __( 'Someone declined your connection request.', 'buddynext' ),
				'group'              => self::GROUP_SOCIAL,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => true,
			),

			// Feed activity.
			'bn.post_reacted'           => array(
				'label'              => __( 'Reactions on your posts', 'buddynext' ),
				'description'        => __( 'Someone reacted to a post you authored.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'daily',
				'can_email'          => true,
			),
			'bn.post_commented'         => array(
				'label'              => __( 'Comments on your posts', 'buddynext' ),
				'description'        => __( 'Someone commented on a post you authored.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.comment_reply'          => array(
				'label'              => __( 'Replies to your comments', 'buddynext' ),
				'description'        => __( 'Someone replied to one of your comments.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.post_shared'            => array(
				'label'              => __( 'Shares of your posts', 'buddynext' ),
				'description'        => __( 'Someone shared a post you authored.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'daily',
				'can_email'          => true,
			),
			'bn.mention'                => array(
				'label'              => __( 'Mentions of you', 'buddynext' ),
				'description'        => __( 'Someone mentioned you in a post or comment.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.bookmark_milestone'     => array(
				'label'              => __( 'Bookmark milestones', 'buddynext' ),
				'description'        => __( 'Your post was bookmarked a notable number of times.', 'buddynext' ),
				'group'              => self::GROUP_FEED,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),

			// Spaces.
			'bn.space_join'             => array(
				'label'              => __( 'New members joining your space', 'buddynext' ),
				'description'        => __( 'Someone joined a space you belong to.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			'bn.space_invite'           => array(
				'label'              => __( 'Space invites', 'buddynext' ),
				'description'        => __( 'You were invited to join a space.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_join_requested'   => array(
				'label'              => __( 'Space join requests', 'buddynext' ),
				'description'        => __( 'Someone requested to join a space you moderate.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_request_approved' => array(
				'label'              => __( 'Space request approved', 'buddynext' ),
				'description'        => __( 'Your request to join a space was approved.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_join_declined'    => array(
				'label'              => __( 'Space request declined', 'buddynext' ),
				'description'        => __( 'Your request to join a space was declined.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.space_new_post'         => array(
				'label'              => __( 'New posts in your spaces', 'buddynext' ),
				'description'        => __( 'Someone posted in a space you belong to.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'daily',
				'can_email'          => true,
			),
			'bn.space_role_changed'     => array(
				'label'              => __( 'Space role changes', 'buddynext' ),
				'description'        => __( 'Your role in a space changed.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.bulk_invite'            => array(
				'label'              => __( 'Bulk space invites', 'buddynext' ),
				'description'        => __( 'You were invited to several spaces at once.', 'buddynext' ),
				'group'              => self::GROUP_SPACES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => false,
			),

			// Messages.
			'bn.new_message'            => array(
				'label'              => __( 'Direct messages', 'buddynext' ),
				'description'        => __( 'Someone sent you a direct message.', 'buddynext' ),
				'group'              => self::GROUP_MESSAGES,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),

			// Moderation.
			'bn.user_warned'            => array(
				'label'              => __( 'Moderator warnings', 'buddynext' ),
				'description'        => __( 'A moderator issued a warning about your activity.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.strike_warning'         => array(
				'label'              => __( 'Strike warnings', 'buddynext' ),
				'description'        => __( 'You are close to receiving an account strike.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.strike_issued'          => array(
				'label'              => __( 'Strike issued', 'buddynext' ),
				'description'        => __( 'Your account received a community-guideline strike.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.member_suspended'       => array(
				'label'              => __( 'Account suspended', 'buddynext' ),
				'description'        => __( 'Your account has been suspended.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.user_unsuspended'       => array(
				'label'              => __( 'Account reinstated', 'buddynext' ),
				'description'        => __( 'Your suspended account has been reinstated.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.user_shadow_banned'     => array(
				'label'              => __( 'Account under review', 'buddynext' ),
				'description'        => __( 'Your account is under review; some actions may be limited.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),
			'bn.appeal_submitted'       => array(
				'label'              => __( 'Appeal received', 'buddynext' ),
				'description'        => __( 'Your appeal was received and is under review.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),
			'bn.appeal_resolved'        => array(
				'label'              => __( 'Appeal resolved', 'buddynext' ),
				'description'        => __( 'Your appeal was reviewed and resolved.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
			),
			'bn.report_resolved'        => array(
				'label'              => __( 'Reports you submitted', 'buddynext' ),
				'description'        => __( 'A report you submitted has been reviewed.', 'buddynext' ),
				'group'              => self::GROUP_MODERATION,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			),

			// Growth + system.
			'bn.badge_awarded'          => array(
				'label'              => __( 'Badges earned', 'buddynext' ),
				'description'        => __( 'You earned a new badge.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			'bn.level_up'               => array(
				'label'              => __( 'Level-ups', 'buddynext' ),
				'description'        => __( 'You reached a new level.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			'bn.onboarding_nudge'       => array(
				'label'              => __( 'Onboarding nudges', 'buddynext' ),
				'description'        => __( 'Helpful reminders to finish setting up your profile.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			'bn.daily_digest'           => array(
				'label'              => __( 'Daily digest', 'buddynext' ),
				'description'        => __( 'A single daily roundup of activity for you.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => false,
				'default_email_freq' => 'daily',
				'can_email'          => true,
			),
			'bn.weekly_digest'          => array(
				'label'              => __( 'Weekly digest', 'buddynext' ),
				'description'        => __( 'A single weekly roundup of activity for you.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => false,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			'bn.media_favorited'        => array(
				'label'              => __( 'Media favourited', 'buddynext' ),
				'description'        => __( 'Someone favourited media you posted.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'weekly',
				'can_email'          => true,
			),
			'bn.jetonomy_reply'         => array(
				'label'              => __( 'Discussion replies', 'buddynext' ),
				'description'        => __( 'Someone replied to a discussion you started.', 'buddynext' ),
				'group'              => self::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'immediate',
				'can_email'          => true,
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
	 * owns its own emails). Unknown types keep the legacy default (true) so core
	 * behaviour is unchanged.
	 *
	 * @param string $type Notification type slug.
	 * @return bool
	 */
	public function can_email( string $type ): bool {
		$catalogue = $this->all();
		if ( ! isset( $catalogue[ $type ] ) ) {
			return true;
		}
		return (bool) ( $catalogue[ $type ]['can_email'] ?? true );
	}

	/**
	 * Return catalogue entries grouped by their `group` field.
	 *
	 * Group order is fixed and matches the order the prefs UI renders.
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

		foreach ( $this->all() as $entry ) {
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
				return __( 'Social graph', 'buddynext' );
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
	 * @return array<string, array{on_site: bool, email_freq: string, label: string, group: string, can_email: bool}>
	 */
	public function resolve_for_user( array $stored ): array {
		$out = array();
		foreach ( $this->all() as $slug => $entry ) {
			$on_site    = (bool) ( $entry['default_on_site'] ?? true );
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
				'description' => (string) ( $entry['description'] ?? '' ),
			);
		}

		return $out;
	}
}
