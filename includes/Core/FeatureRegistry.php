<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Feature registry — site-owner control over which BuddyNext features are
 * active.
 *
 * Three tiers:
 *  - mandatory  : always on, no toggle, no filter to disable.
 *  - default_on : on by default, owner can disable in Settings → Features.
 *  - opt_in     : off by default, owner enables in Settings.
 *
 * The registry is the source of truth. Plugin::register_services() calls
 * is_enabled() before binding a feature's services. Settings → Features
 * renders the catalog and persists per-feature state into the
 * 'buddynext_features' option.
 *
 * Extension point: third-party plugins register new features via
 * apply_filters('buddynext_features', $features) — same shape as the
 * canonical entries below.
 *
 * @package BuddyNext\Core
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Catalogues every toggleable feature + resolves its enabled state.
 */
class FeatureRegistry {

	/**
	 * Tier constants — must match the keys in the catalog below.
	 */
	public const TIER_MANDATORY  = 'mandatory';
	public const TIER_DEFAULT_ON = 'default_on';
	public const TIER_OPT_IN     = 'opt_in';

	/**
	 * Option name where per-feature state is stored.
	 */
	private const OPTION_KEY = 'buddynext_features';

	/**
	 * Resolved catalog (cached after first call).
	 *
	 * @var array<string,array{slug:string,label:string,description:string,tier:string,group:string,depends_on:array<int,string>,deprecated?:bool}>|null
	 */
	private ?array $catalog = null;

	/**
	 * Return the full catalog of features, keyed by slug.
	 *
	 * @return array<string,array{slug:string,label:string,description:string,tier:string,group:string,depends_on:array<int,string>,deprecated?:bool}>
	 */
	public function catalog(): array {
		if ( null !== $this->catalog ) {
			return $this->catalog;
		}

		$catalog = array(

			// ── MANDATORY — always on, cannot be disabled ────────────────
			'feed'          => array(
				'slug'        => 'feed',
				'label'       => __( 'Activity feed', 'buddynext' ),
				'description' => __( 'Posts, comments, reactions, polls, shares — the heart of the community.', 'buddynext' ),
				'tier'        => self::TIER_MANDATORY,
				'group'       => 'core',
				'depends_on'  => array(),
			),
			'profile'       => array(
				'slug'        => 'profile',
				'label'       => __( 'Member profiles', 'buddynext' ),
				'description' => __( 'Per-member profile pages with cover, avatar, bio, custom fields.', 'buddynext' ),
				'tier'        => self::TIER_MANDATORY,
				'group'       => 'core',
				'depends_on'  => array(),
			),
			'spaces'        => array(
				'slug'        => 'spaces',
				'label'       => __( 'Spaces', 'buddynext' ),
				'description' => __( 'Topic-scoped sub-communities with their own posts, members, settings.', 'buddynext' ),
				'tier'        => self::TIER_MANDATORY,
				'group'       => 'core',
				'depends_on'  => array(),
			),
			'social_graph'  => array(
				'slug'        => 'social_graph',
				'label'       => __( 'Follows, connections, blocks', 'buddynext' ),
				'description' => __( 'The relationships layer the feed and member directory depend on.', 'buddynext' ),
				'tier'        => self::TIER_MANDATORY,
				'group'       => 'core',
				'depends_on'  => array(),
			),
			'notifications' => array(
				'slug'        => 'notifications',
				'label'       => __( 'Notifications', 'buddynext' ),
				'description' => __( 'In-app notifications for follows, reactions, comments, mentions, moderation events.', 'buddynext' ),
				'tier'        => self::TIER_MANDATORY,
				'group'       => 'core',
				'depends_on'  => array(),
			),
			'auth'          => array(
				'slug'        => 'auth',
				'label'       => __( 'Login + registration', 'buddynext' ),
				'description' => __( 'Custom auth pages and the email verification handshake.', 'buddynext' ),
				'tier'        => self::TIER_MANDATORY,
				'group'       => 'core',
				'depends_on'  => array(),
			),
			'search'        => array(
				'slug'        => 'search',
				'label'       => __( 'Search index', 'buddynext' ),
				'description' => __( 'Unified FULLTEXT index across posts, users, spaces, hashtags.', 'buddynext' ),
				'tier'        => self::TIER_MANDATORY,
				'group'       => 'core',
				'depends_on'  => array(),
			),
			'moderation'    => array(
				'slug'        => 'moderation',
				'label'       => __( 'Moderation', 'buddynext' ),
				'description' => __( 'Reports, strikes, suspensions, appeals — the integrity layer.', 'buddynext' ),
				'tier'        => self::TIER_MANDATORY,
				'group'       => 'core',
				'depends_on'  => array(),
			),

			// ── DEFAULT-ON — owner can disable ───────────────────────────
			'hashtags'      => array(
				'slug'        => 'hashtags',
				'label'       => __( 'Hashtags', 'buddynext' ),
				'description' => __( 'Extract #tags from posts, build trending lists, link to per-tag feeds.', 'buddynext' ),
				'tier'        => self::TIER_DEFAULT_ON,
				'group'       => 'community',
				'depends_on'  => array( 'feed' ),
			),
			'reactions'     => array(
				'slug'        => 'reactions',
				'label'       => __( 'Reactions', 'buddynext' ),
				'description' => __( 'Six default emoji reactions on every post + comment.', 'buddynext' ),
				'tier'        => self::TIER_DEFAULT_ON,
				'group'       => 'community',
				'depends_on'  => array( 'feed' ),
			),
			'comments'      => array(
				'slug'        => 'comments',
				'label'       => __( 'Comments', 'buddynext' ),
				'description' => __( 'Threaded comments on posts.', 'buddynext' ),
				'tier'        => self::TIER_DEFAULT_ON,
				'group'       => 'community',
				'depends_on'  => array( 'feed' ),
			),
			'sidebar'       => array(
				'slug'        => 'sidebar',
				'label'       => __( 'Sidebar widgets', 'buddynext' ),
				'description' => __( 'Right-column widgets on hub pages — trending topics, suggested people, your spaces.', 'buddynext' ),
				'tier'        => self::TIER_DEFAULT_ON,
				'group'       => 'community',
				'depends_on'  => array(),
			),
			'onboarding'    => array(
				'slug'        => 'onboarding',
				'label'       => __( 'Member onboarding flow', 'buddynext' ),
				'description' => __( 'Multi-step welcome flow for new members (interests, suggested follows, first post).', 'buddynext' ),
				'tier'        => self::TIER_DEFAULT_ON,
				'group'       => 'community',
				'depends_on'  => array(),
			),
			'verification'  => array(
				'slug'        => 'verification',
				'label'       => __( 'Email verification', 'buddynext' ),
				'description' => __( 'Send a verification link on registration; gate certain actions on verified status.', 'buddynext' ),
				'tier'        => self::TIER_DEFAULT_ON,
				'group'       => 'community',
				'depends_on'  => array( 'auth' ),
			),
			'announcements' => array(
				'slug'        => 'announcements',
				'label'       => __( 'Site announcements', 'buddynext' ),
				'description' => __( 'Pin an announcement to the top of every member\'s feed.', 'buddynext' ),
				'tier'        => self::TIER_DEFAULT_ON,
				'group'       => 'community',
				'depends_on'  => array( 'feed' ),
			),

			// ── OPT-IN — off by default ───────────────────────────────────
			'gamification'  => array(
				'slug'        => 'gamification',
				'label'       => __( 'Gamification (badges, points, leaderboard)', 'buddynext' ),
				'description' => __( 'Bridges WBGamification: earn points, unlock badges, climb the leaderboard. Requires the WBGamification plugin.', 'buddynext' ),
				'tier'        => self::TIER_OPT_IN,
				'group'       => 'bridges',
				'depends_on'  => array(),
			),
			'jetonomy'      => array(
				'slug'        => 'jetonomy',
				'label'       => __( 'Jetonomy forums bridge', 'buddynext' ),
				'description' => __( 'Show Jetonomy forum activity in BuddyNext feeds. Requires the Jetonomy plugin.', 'buddynext' ),
				'tier'        => self::TIER_OPT_IN,
				'group'       => 'bridges',
				'depends_on'  => array( 'feed' ),
			),
			'wpmediaverse'  => array(
				'slug'        => 'wpmediaverse',
				'label'       => __( 'WPMediaVerse direct messages', 'buddynext' ),
				'description' => __( 'Bridge WPMediaVerse for member-to-member DMs inside BuddyNext. Requires the WPMediaVerse plugin.', 'buddynext' ),
				'tier'        => self::TIER_OPT_IN,
				'group'       => 'bridges',
				'depends_on'  => array(),
			),
			'career_board'  => array(
				'slug'        => 'career_board',
				'label'       => __( 'Career Board jobs bridge', 'buddynext' ),
				'description' => __( 'Surface Career Board job posts as activity. Requires Career Board.', 'buddynext' ),
				'tier'        => self::TIER_OPT_IN,
				'group'       => 'bridges',
				'depends_on'  => array( 'feed' ),
			),
			'webhooks'      => array(
				'slug'        => 'webhooks',
				'label'       => __( 'Outbound webhooks', 'buddynext' ),
				'description' => __( 'Send signed HTTPS POSTs to external endpoints on community events. Power-user feature.', 'buddynext' ),
				'tier'        => self::TIER_OPT_IN,
				'group'       => 'integrations',
				'depends_on'  => array(),
			),
		);

		/**
		 * Filter the feature catalog. Third-party plugins use this to register
		 * new features under the same contract.
		 *
		 * @since 1.2.0
		 *
		 * @param array $catalog Keyed by slug; each entry has slug/label/description/tier/group/depends_on.
		 */
		$catalog = (array) apply_filters( 'buddynext_features', $catalog );

		$this->catalog = $catalog;
		return $catalog;
	}

	/**
	 * Resolve the enabled state for a given feature slug.
	 *
	 * Resolution order (first match wins):
	 *  1. Mandatory tier → always true (cannot be disabled).
	 *  2. Per-feature filter `buddynext_feature_{slug}` — runtime override.
	 *  3. Stored option `buddynext_features[$slug]` — site-owner UI choice.
	 *  4. Tier default (default_on=true, opt_in=false).
	 *  5. Unknown slug → false.
	 *
	 * @param string $slug Feature slug.
	 * @return bool
	 */
	public function is_enabled( string $slug ): bool {
		$catalog = $this->catalog();
		if ( ! isset( $catalog[ $slug ] ) ) {
			return false;
		}
		$feature = $catalog[ $slug ];

		// Mandatory always wins.
		if ( self::TIER_MANDATORY === $feature['tier'] ) {
			return true;
		}

		// Dependency unmet → forced off.
		foreach ( $feature['depends_on'] as $dep ) {
			if ( ! $this->is_enabled( $dep ) ) {
				return false;
			}
		}

		// Tier default.
		$default = ( self::TIER_DEFAULT_ON === $feature['tier'] );

		// Stored option.
		$state = get_option( self::OPTION_KEY, array() );
		if ( is_array( $state ) && array_key_exists( $slug, $state ) ) {
			$default = (bool) $state[ $slug ];
		}

		/**
		 * Per-feature runtime filter. Returns final boolean.
		 *
		 * @since 1.2.0
		 *
		 * @param bool   $enabled  Resolved state from option + tier default.
		 * @param array  $feature  Feature catalog entry.
		 */
		return (bool) apply_filters( "buddynext_feature_{$slug}", $default, $feature );
	}

	/**
	 * Persist site-owner toggle state.
	 *
	 * @param array<string,bool> $state Map of slug => bool.
	 * @return void
	 */
	public function persist( array $state ): void {
		$cleaned = array();
		foreach ( $this->catalog() as $slug => $feature ) {
			if ( self::TIER_MANDATORY === $feature['tier'] ) {
				continue; // Skip mandatory — cannot be persisted off.
			}
			if ( array_key_exists( $slug, $state ) ) {
				$cleaned[ $slug ] = (bool) $state[ $slug ];
			}
		}
		update_option( self::OPTION_KEY, $cleaned, false );
	}

	/**
	 * Convenience: group the catalog by 'group' for the Settings UI.
	 *
	 * @return array<string,array<int,array>>
	 */
	public function by_group(): array {
		$out = array();
		foreach ( $this->catalog() as $feature ) {
			$out[ $feature['group'] ][] = $feature;
		}
		return $out;
	}
}
