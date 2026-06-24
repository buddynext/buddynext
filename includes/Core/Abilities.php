<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WordPress Abilities API registration.
 *
 * Registers every BuddyNext capability as a named ability so they appear in
 * the WP admin UI and can be granted/revoked via the Abilities API (WP 6.9+).
 * Falls back silently on older installs — PermissionService handles the gate.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Manages ability registration.
 */
class Abilities {

	/**
	 * Full list of BuddyNext capabilities.
	 */
	private const CATALOG = array(
		'buddynext-profile/edit-own',
		'buddynext-profile/edit-any',
		'buddynext-profile/view',
		'buddynext-feed/create-post',
		'buddynext-feed/delete-own-post',
		'buddynext-feed/delete-any-post',
		'buddynext-feed/pin-post',
		'buddynext-feed/schedule-post',
		'buddynext-spaces/create',
		'buddynext-spaces/join',
		'buddynext-spaces/join-gated',
		'buddynext-spaces/post',
		'buddynext-spaces/moderate',
		'buddynext-spaces/manage-settings',
		'buddynext-spaces/delete',
		'buddynext-connections/follow',
		'buddynext-connections/connect',
		'buddynext-moderation/report',
		'buddynext-moderation/review-queue',
		'buddynext-moderation/issue-strike',
		'buddynext-moderation/suspend-user',
	);

	/**
	 * Hook ability registration into the WordPress Abilities API init action.
	 *
	 * No-ops on WordPress < 6.9 where the API is unavailable.
	 * Must be called from plugins_loaded so the hook is registered in time.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// WordPress 6.9+ requires every ability to belong to a registered
		// category, so register the BuddyNext category before the abilities.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'do_register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'do_register' ) );
	}

	/**
	 * Register the BuddyNext ability category.
	 *
	 * Runs on wp_abilities_api_categories_init. Each ability references this
	 * category by slug; without it WordPress 6.9+ rejects the registration with
	 * an "ability properties must contain a `category` string" notice (which,
	 * printed before a redirect, also breaks header-sending pages like login).
	 */
	public function do_register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'buddynext',
			array(
				'label'       => __( 'BuddyNext', 'buddynext' ),
				'description' => __( 'Community feed, profiles, spaces, connections, and moderation abilities.', 'buddynext' ),
			)
		);
	}

	/**
	 * Register all BuddyNext abilities with the Abilities API.
	 *
	 * Called on the wp_abilities_api_init action.
	 */
	public function do_register(): void {
		$permissions = function_exists( 'buddynext_service' ) ? buddynext_service( 'permissions' ) : null;

		foreach ( $this->get_catalog() as $ability ) {
			wp_register_ability(
				$ability,
				array(
					'label'               => $this->label_for( $ability ),
					'description'         => $this->description_for( $ability ),
					'category'            => 'buddynext',
					// WordPress 6.9+ requires both callbacks to be valid callables.
					// These abilities are declarative permission advertisements — the
					// real action runs through the matching REST route (which carries
					// its own check) — so execute is a no-op and permission delegates
					// to PermissionService::can() for the ability's own slug.
					'execute_callback'    => array( $this, 'noop' ),
					'permission_callback' => static function () use ( $ability, $permissions ): bool {
						$svc = $permissions instanceof PermissionService ? $permissions : new PermissionService();
						return $svc->can( get_current_user_id(), $ability );
					},
					'meta'                => array(
						'show_in_rest' => true,
					),
				)
			);
		}
	}

	/**
	 * Execute callback for BuddyNext's declarative permission abilities.
	 *
	 * The abilities advertise a BuddyNext permission to the Abilities API and AI
	 * agents; there is nothing to run here (the matching REST route performs the
	 * real work and its own permission check), so this returns a simple
	 * acknowledgement, mirroring the engine's declarative abilities.
	 *
	 * @return bool
	 */
	public function noop(): bool {
		return true;
	}

	/**
	 * Build a human-readable label for an ability slug, as required by the
	 * WordPress 6.9+ Abilities API. E.g. 'buddynext-feed/create-post' becomes
	 * 'Feed: Create post'.
	 *
	 * @param string $ability Ability slug.
	 * @return string
	 */
	private function label_for( string $ability ): string {
		$slug   = str_replace( 'buddynext-', '', $ability );
		$parts  = explode( '/', $slug, 2 );
		$domain = ucfirst( str_replace( '-', ' ', $parts[0] ) );
		$action = isset( $parts[1] ) ? ucfirst( str_replace( '-', ' ', $parts[1] ) ) : '';

		return '' !== $action ? $domain . ': ' . $action : $domain;
	}

	/**
	 * Build a human-readable description for an ability slug.
	 *
	 * WordPress 7.0+ Abilities API rejects a registration whose properties lack
	 * a non-empty 'description' string (emitting a _doing_it_wrong notice on every
	 * page load). Derived from the label so it can never drift from the catalog.
	 *
	 * @param string $ability Ability slug.
	 * @return string
	 */
	private function description_for( string $ability ): string {
		/* translators: %s: human-readable ability label, e.g. "Feed: Create post". */
		return sprintf( __( 'Controls the "%s" permission in BuddyNext.', 'buddynext' ), $this->label_for( $ability ) );
	}

	/**
	 * The ability catalog, filterable via buddynext_abilities.
	 *
	 * Register a custom ability slug here and it is registered with the
	 * Abilities API alongside the built-ins. Pair with buddynext_role_map
	 * (PermissionService) to gate the new ability behind a community role.
	 *
	 * @return string[]
	 */
	public function get_catalog(): array {
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'buddynext_abilities', self::CATALOG ) ) ) );
	}
}
