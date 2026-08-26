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
			'feed'          => array(
				'slug'       => 'feed',
				'tier'       => self::TIER_MANDATORY,
				'group'      => 'core',
				'depends_on' => array(),
			),
			'profile'       => array(
				'slug'       => 'profile',
				'tier'       => self::TIER_MANDATORY,
				'group'      => 'core',
				'depends_on' => array(),
			),
			// Mandatory, not merely default-on: Spaces is load-bearing structure, not
			// an add-on. Posts carry a space_id, the feed/moderation/notification
			// paths all resolve spaces, and the hub owns its own routes — so there is
			// no coherent "Spaces off" product to ship. It was previously DEFAULT_ON,
			// which rendered an unlocked switch that saved to `buddynext_features` and
			// was then never read by anything (`buddynext_feature_enabled('spaces')`
			// has no call sites). A site owner could switch Spaces "off", see it save,
			// and nothing changed. A control that silently does nothing is worse than
			// no control, so it is locked to match reality — as `feed` and `profile`,
			// the other structural features, already are.
			'spaces'        => array(
				'slug'       => 'spaces',
				'tier'       => self::TIER_MANDATORY,
				'group'      => 'community',
				'depends_on' => array(),
			),
			'social_graph'  => array(
				'slug'       => 'social_graph',
				'tier'       => self::TIER_MANDATORY,
				'group'      => 'core',
				'depends_on' => array(),
			),
			'notifications' => array(
				'slug'       => 'notifications',
				'tier'       => self::TIER_MANDATORY,
				'group'      => 'core',
				'depends_on' => array(),
			),
			'auth'          => array(
				'slug'       => 'auth',
				'tier'       => self::TIER_MANDATORY,
				'group'      => 'core',
				'depends_on' => array(),
			),
			'search'        => array(
				'slug'       => 'search',
				'tier'       => self::TIER_MANDATORY,
				'group'      => 'core',
				'depends_on' => array(),
			),
			'moderation'    => array(
				'slug'       => 'moderation',
				'tier'       => self::TIER_MANDATORY,
				'group'      => 'core',
				'depends_on' => array(),
			),

			// ── DEFAULT-ON — owner can disable ───────────────────────────
			'hashtags'      => array(
				'slug'       => 'hashtags',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array( 'feed' ),
			),
			'reactions'     => array(
				'slug'       => 'reactions',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array( 'feed' ),
			),
			'comments'      => array(
				'slug'       => 'comments',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array( 'feed' ),
			),
			'sidebar'       => array(
				'slug'       => 'sidebar',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array(),
			),
			'onboarding'    => array(
				'slug'       => 'onboarding',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array(),
			),
			'verification'  => array(
				'slug'       => 'verification',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array( 'auth' ),
			),
			'announcements' => array(
				'slug'       => 'announcements',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array( 'feed' ),
			),
			'bookmarks'     => array(
				'slug'       => 'bookmarks',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array(),
			),
			'polls'         => array(
				'slug'       => 'polls',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array( 'feed' ),
			),
			'shares'        => array(
				'slug'       => 'shares',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array( 'feed' ),
			),
			// Direct messages. The catalog toggle is the owner's on/off intent; the
			// separate availability check (WPMediaVerse present) still gates it, so
			// with the engine absent DMs stay hidden regardless of this switch. See
			// MessagesData::entry_enabled().
			'messages'      => array(
				'slug'       => 'messages',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array(),
			),
			// Installable app (PWA): the manifest + service worker. Self-contained
			// (no external key), so default-on; when off the service never boots.
			'pwa'           => array(
				'slug'       => 'pwa',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array(),
			),
			// Scheduled posts: compose now, publish at a future time. The composer
			// control, the create-path status flip and Free's on-demand publisher
			// are all Free (works with Pro absent); Pro layers reschedule/cancel +
			// a management screen on the SAME slug. Off = the schedule button is
			// hidden and the create path never flips a post to 'scheduled'; posts
			// already scheduled still publish (the publisher is never gated).
			'scheduled-posts' => array(
				'slug'       => 'scheduled-posts',
				'tier'       => self::TIER_DEFAULT_ON,
				'group'      => 'community',
				'depends_on' => array( 'feed' ),
			),

			// Integration bridges are NOT features. They gate solely on the
			// per-aspect Integrations toggle (buddynext_integration_enabled), which
			// is the single source of truth — there is no Features-tab master switch
			// for them (a bridge here would be an orphan toggle: it self-guards on
			// its partner constant and wires unconditionally at boot).

			// ── OPT-IN — off by default ───────────────────────────────────
			'webhooks'      => array(
				'slug'       => 'webhooks',
				'tier'       => self::TIER_OPT_IN,
				'group'      => 'integrations',
				'depends_on' => array(),
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

		// External-plugin dependency unmet → forced off. A bridge feature cannot
		// be on when the partner plugin it wraps is absent (the bridge self-guards
		// at hook time too, so this just makes the resolved state consistent and
		// lets Settings → Features render the toggle as unavailable).
		if ( ! $this->presence_met( $slug ) ) {
			return false;
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
	 * Whether the external plugin a feature depends on is active.
	 *
	 * The seam that forces a feature off (and renders its toggle "unavailable")
	 * when a third-party plugin it wraps is absent. No CURRENT feature has an
	 * external dependency — integration bridges are gated by the Integrations
	 * toggle, not the Features tab — so this returns true for every feature
	 * today. A future feature that wraps a third-party plugin adds its
	 * class_exists/defined check here.
	 *
	 * @param string $slug Feature slug.
	 * @return bool
	 */
	public function presence_met( string $slug ): bool {
		unset( $slug );
		return true;
	}

	/**
	 * Human-readable name of the plugin a feature requires, for the "Requires the
	 * X plugin" notice. Empty for every current feature (none has an external
	 * dependency); a future external-dependency feature maps its slug here.
	 *
	 * @param string $slug Feature slug.
	 * @return string
	 */
	public function required_plugin_name( string $slug ): string {
		unset( $slug );
		return '';
	}

	/**
	 * Persist site-owner toggle state.
	 *
	 * @param array<string,bool> $state Map of slug => bool.
	 * @return void
	 */
	public function persist( array $state ): void {
		update_option( self::OPTION_KEY, $this->clean_state( $state ), false );
	}

	/**
	 * Apply tier rules to a raw slug=>bool map and return the storable subset —
	 * mandatory features dropped (they cannot be toggled off), values coerced to
	 * bool, unknown slugs ignored.
	 *
	 * Pure: does NOT write the option. Used both by persist() (which then writes)
	 * and by the Settings API sanitize callback, which must only RETURN the value
	 * to store — writing the option there would re-enter the sanitize callback
	 * and recurse until memory is exhausted.
	 *
	 * @param array<string,bool> $state Map of slug => bool.
	 * @return array<string,bool>
	 */
	public function clean_state( array $state ): array {
		$cleaned = array();
		foreach ( $this->catalog() as $slug => $feature ) {
			if ( self::TIER_MANDATORY === $feature['tier'] ) {
				continue; // Skip mandatory — cannot be persisted off.
			}
			if ( array_key_exists( $slug, $state ) ) {
				$cleaned[ $slug ] = (bool) $state[ $slug ];
			}
		}
		return $cleaned;
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
			// Surface external-plugin availability so the Features UI can render a
			// bridge toggle disabled (with a "Requires X" notice) when its partner
			// plugin is absent.
			$feature['presence_met']    = $this->presence_met( $slug );
			$feature['required_plugin'] = $this->required_plugin_name( $slug );
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
			'feed'          => array(
				'label'       => __( 'Activity feed', 'buddynext' ),
				'description' => __( 'Posts, comments, reactions, polls, shares — the heart of the community.', 'buddynext' ),
			),
			'profile'       => array(
				'label'       => __( 'Member profiles', 'buddynext' ),
				'description' => __( 'Per-member profile pages with cover, avatar, bio, custom fields.', 'buddynext' ),
			),
			'spaces'        => array(
				'label'       => __( 'Spaces', 'buddynext' ),
				'description' => __( 'Topic-scoped sub-communities with their own posts, members, settings.', 'buddynext' ),
			),
			'social_graph'  => array(
				'label'       => __( 'Follows, connections, blocks', 'buddynext' ),
				'description' => __( 'The relationships layer the feed and member directory depend on.', 'buddynext' ),
			),
			'notifications' => array(
				'label'       => __( 'Notifications', 'buddynext' ),
				'description' => __( 'In-app notifications for follows, reactions, comments, mentions, moderation events.', 'buddynext' ),
			),
			'auth'          => array(
				'label'       => __( 'Login + registration', 'buddynext' ),
				'description' => __( 'Custom auth pages and the email verification handshake.', 'buddynext' ),
			),
			'search'        => array(
				'label'       => __( 'Search index', 'buddynext' ),
				'description' => __( 'Unified FULLTEXT index across posts, users, spaces, hashtags.', 'buddynext' ),
			),
			'moderation'    => array(
				'label'       => __( 'Moderation', 'buddynext' ),
				'description' => __( 'Reports, strikes, suspensions, appeals — the integrity layer.', 'buddynext' ),
			),
			'hashtags'      => array(
				'label'       => __( 'Hashtags', 'buddynext' ),
				'description' => __( 'Extract #tags from posts, build trending lists, link to per-tag feeds.', 'buddynext' ),
			),
			'reactions'     => array(
				'label'       => __( 'Reactions', 'buddynext' ),
				'description' => __( 'Six default emoji reactions on every post + comment.', 'buddynext' ),
			),
			'comments'      => array(
				'label'       => __( 'Comments', 'buddynext' ),
				'description' => __( 'Threaded comments on posts.', 'buddynext' ),
			),
			'sidebar'       => array(
				'label'       => __( 'Sidebar widgets', 'buddynext' ),
				'description' => __( 'Right-column widgets on hub pages — trending topics, suggested people, your spaces.', 'buddynext' ),
			),
			'onboarding'    => array(
				'label'       => __( 'Member onboarding flow', 'buddynext' ),
				'description' => __( 'Multi-step welcome flow for new members (interests, suggested follows, first post).', 'buddynext' ),
			),
			'verification'  => array(
				'label'       => __( 'Email verification', 'buddynext' ),
				'description' => __( 'Send a verification link on registration; gate certain actions on verified status.', 'buddynext' ),
			),
			'announcements' => array(
				'label'       => __( 'Site announcements', 'buddynext' ),
				'description' => __( 'Pin an announcement to the top of every member\'s feed.', 'buddynext' ),
			),
			'bookmarks'     => array(
				'label'       => __( 'Bookmarks', 'buddynext' ),
				'description' => __( 'Let members save posts to a private Bookmarks list to read later.', 'buddynext' ),
			),
			'polls'         => array(
				'label'       => __( 'Polls', 'buddynext' ),
				'description' => __( 'Let members attach a poll to a post and vote in the feed.', 'buddynext' ),
			),
			'shares'        => array(
				'label'       => __( 'Re-shares', 'buddynext' ),
				'description' => __( 'Let members re-share another member\'s post into their own feed.', 'buddynext' ),
			),
			'messages'      => array(
				'label'       => __( 'Direct messages', 'buddynext' ),
				'description' => __( 'Private one-to-one messaging between members (requires WPMediaVerse).', 'buddynext' ),
			),
			'pwa'           => array(
				'label'       => __( 'Installable app (PWA)', 'buddynext' ),
				'description' => __( 'Let members install the community as an app and use it offline (manifest + service worker).', 'buddynext' ),
			),
			'scheduled-posts' => array(
				'label'       => __( 'Scheduled posts', 'buddynext' ),
				'description' => __( 'Let members compose a post now and have it publish automatically at a chosen time.', 'buddynext' ),
			),
			'webhooks'      => array(
				'label'       => __( 'Outbound webhooks', 'buddynext' ),
				'description' => __( 'Send signed HTTPS POSTs to external endpoints on community events. Power-user feature.', 'buddynext' ),
			),
		);
	}
}
