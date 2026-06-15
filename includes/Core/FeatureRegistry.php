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

		// Structure only — NO __() here. catalog() is hit by is_enabled() at
		// plugins_loaded (before init); translating labels there triggers WP 6.7's
		// _load_textdomain_just_in_time notice on every page. Human-readable
		// label/description live in labels() and are merged in by_group(), which
		// only renders on the admin Features tab (after init).
		$catalog = array(

			// ── MANDATORY — always on, cannot be disabled ────────────────
			'feed'          => array( 'slug' => 'feed', 'tier' => self::TIER_MANDATORY, 'group' => 'core', 'depends_on' => array() ),
			'profile'       => array( 'slug' => 'profile', 'tier' => self::TIER_MANDATORY, 'group' => 'core', 'depends_on' => array() ),
			'spaces'        => array( 'slug' => 'spaces', 'tier' => self::TIER_MANDATORY, 'group' => 'core', 'depends_on' => array() ),
			'social_graph'  => array( 'slug' => 'social_graph', 'tier' => self::TIER_MANDATORY, 'group' => 'core', 'depends_on' => array() ),
			'notifications' => array( 'slug' => 'notifications', 'tier' => self::TIER_MANDATORY, 'group' => 'core', 'depends_on' => array() ),
			'auth'          => array( 'slug' => 'auth', 'tier' => self::TIER_MANDATORY, 'group' => 'core', 'depends_on' => array() ),
			'search'        => array( 'slug' => 'search', 'tier' => self::TIER_MANDATORY, 'group' => 'core', 'depends_on' => array() ),
			'moderation'    => array( 'slug' => 'moderation', 'tier' => self::TIER_MANDATORY, 'group' => 'core', 'depends_on' => array() ),

			// ── DEFAULT-ON — owner can disable ───────────────────────────
			'hashtags'      => array( 'slug' => 'hashtags', 'tier' => self::TIER_DEFAULT_ON, 'group' => 'community', 'depends_on' => array( 'feed' ) ),
			'reactions'     => array( 'slug' => 'reactions', 'tier' => self::TIER_DEFAULT_ON, 'group' => 'community', 'depends_on' => array( 'feed' ) ),
			'comments'      => array( 'slug' => 'comments', 'tier' => self::TIER_DEFAULT_ON, 'group' => 'community', 'depends_on' => array( 'feed' ) ),
			'sidebar'       => array( 'slug' => 'sidebar', 'tier' => self::TIER_DEFAULT_ON, 'group' => 'community', 'depends_on' => array() ),
			'onboarding'    => array( 'slug' => 'onboarding', 'tier' => self::TIER_DEFAULT_ON, 'group' => 'community', 'depends_on' => array() ),
			'verification'  => array( 'slug' => 'verification', 'tier' => self::TIER_DEFAULT_ON, 'group' => 'community', 'depends_on' => array( 'auth' ) ),
			'announcements' => array( 'slug' => 'announcements', 'tier' => self::TIER_DEFAULT_ON, 'group' => 'community', 'depends_on' => array( 'feed' ) ),

			// ── OPT-IN — off by default ───────────────────────────────────
			'gamification'  => array( 'slug' => 'gamification', 'tier' => self::TIER_OPT_IN, 'group' => 'bridges', 'depends_on' => array() ),
			'jetonomy'      => array( 'slug' => 'jetonomy', 'tier' => self::TIER_OPT_IN, 'group' => 'bridges', 'depends_on' => array( 'feed' ) ),
			'wpmediaverse'  => array( 'slug' => 'wpmediaverse', 'tier' => self::TIER_OPT_IN, 'group' => 'bridges', 'depends_on' => array() ),
			'career_board'  => array( 'slug' => 'career_board', 'tier' => self::TIER_OPT_IN, 'group' => 'bridges', 'depends_on' => array( 'feed' ) ),
			'webhooks'      => array( 'slug' => 'webhooks', 'tier' => self::TIER_OPT_IN, 'group' => 'integrations', 'depends_on' => array() ),
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
		$labels = self::labels();
		$out    = array();
		foreach ( $this->catalog() as $feature ) {
			$slug = (string) ( $feature['slug'] ?? '' );
			// Core features carry no label in catalog() (kept translation-free so
			// it is safe at plugins_loaded); look them up here, at display time.
			// Third-party features added via the `buddynext_features` filter keep
			// whatever label/description they supplied.
			if ( ! isset( $feature['label'] ) ) {
				$feature['label'] = $labels[ $slug ]['label'] ?? $slug;
			}
			if ( ! isset( $feature['description'] ) ) {
				$feature['description'] = $labels[ $slug ]['description'] ?? '';
			}
			$out[ $feature['group'] ][] = $feature;
		}
		return $out;
	}

	/**
	 * Human-readable label + description per feature slug.
	 *
	 * Separated from catalog() so the translatable strings are only evaluated at
	 * display time (the admin Features tab, after init) — never at plugins_loaded,
	 * where they would trip WP 6.7's _load_textdomain_just_in_time notice. The
	 * `__()` literals stay here so they remain extractable by `wp i18n make-pot`.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	private static function labels(): array {
		return array(
			'feed'          => array( 'label' => __( 'Activity feed', 'buddynext' ), 'description' => __( 'Posts, comments, reactions, polls, shares — the heart of the community.', 'buddynext' ) ),
			'profile'       => array( 'label' => __( 'Member profiles', 'buddynext' ), 'description' => __( 'Per-member profile pages with cover, avatar, bio, custom fields.', 'buddynext' ) ),
			'spaces'        => array( 'label' => __( 'Spaces', 'buddynext' ), 'description' => __( 'Topic-scoped sub-communities with their own posts, members, settings.', 'buddynext' ) ),
			'social_graph'  => array( 'label' => __( 'Follows, connections, blocks', 'buddynext' ), 'description' => __( 'The relationships layer the feed and member directory depend on.', 'buddynext' ) ),
			'notifications' => array( 'label' => __( 'Notifications', 'buddynext' ), 'description' => __( 'In-app notifications for follows, reactions, comments, mentions, moderation events.', 'buddynext' ) ),
			'auth'          => array( 'label' => __( 'Login + registration', 'buddynext' ), 'description' => __( 'Custom auth pages and the email verification handshake.', 'buddynext' ) ),
			'search'        => array( 'label' => __( 'Search index', 'buddynext' ), 'description' => __( 'Unified FULLTEXT index across posts, users, spaces, hashtags.', 'buddynext' ) ),
			'moderation'    => array( 'label' => __( 'Moderation', 'buddynext' ), 'description' => __( 'Reports, strikes, suspensions, appeals — the integrity layer.', 'buddynext' ) ),
			'hashtags'      => array( 'label' => __( 'Hashtags', 'buddynext' ), 'description' => __( 'Extract #tags from posts, build trending lists, link to per-tag feeds.', 'buddynext' ) ),
			'reactions'     => array( 'label' => __( 'Reactions', 'buddynext' ), 'description' => __( 'Six default emoji reactions on every post + comment.', 'buddynext' ) ),
			'comments'      => array( 'label' => __( 'Comments', 'buddynext' ), 'description' => __( 'Threaded comments on posts.', 'buddynext' ) ),
			'sidebar'       => array( 'label' => __( 'Sidebar widgets', 'buddynext' ), 'description' => __( 'Right-column widgets on hub pages — trending topics, suggested people, your spaces.', 'buddynext' ) ),
			'onboarding'    => array( 'label' => __( 'Member onboarding flow', 'buddynext' ), 'description' => __( 'Multi-step welcome flow for new members (interests, suggested follows, first post).', 'buddynext' ) ),
			'verification'  => array( 'label' => __( 'Email verification', 'buddynext' ), 'description' => __( 'Send a verification link on registration; gate certain actions on verified status.', 'buddynext' ) ),
			'announcements' => array( 'label' => __( 'Site announcements', 'buddynext' ), 'description' => __( 'Pin an announcement to the top of every member\'s feed.', 'buddynext' ) ),
			'gamification'  => array( 'label' => __( 'Gamification (badges, points, leaderboard)', 'buddynext' ), 'description' => __( 'Bridges WBGamification: earn points, unlock badges, climb the leaderboard. Requires the WBGamification plugin.', 'buddynext' ) ),
			'jetonomy'      => array( 'label' => __( 'Jetonomy forums bridge', 'buddynext' ), 'description' => __( 'Show Jetonomy forum activity in BuddyNext feeds. Requires the Jetonomy plugin.', 'buddynext' ) ),
			'wpmediaverse'  => array( 'label' => __( 'WPMediaVerse direct messages', 'buddynext' ), 'description' => __( 'Bridge WPMediaVerse for member-to-member DMs inside BuddyNext. Requires the WPMediaVerse plugin.', 'buddynext' ) ),
			'career_board'  => array( 'label' => __( 'Career Board jobs bridge', 'buddynext' ), 'description' => __( 'Surface Career Board job posts as activity. Requires Career Board.', 'buddynext' ) ),
			'webhooks'      => array( 'label' => __( 'Outbound webhooks', 'buddynext' ), 'description' => __( 'Send signed HTTPS POSTs to external endpoints on community events. Power-user feature.', 'buddynext' ) ),
		);
	}
}
