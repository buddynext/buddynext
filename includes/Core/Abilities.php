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

		add_action( 'wp_abilities_api_init', array( $this, 'do_register' ) );
	}

	/**
	 * Register all BuddyNext abilities with the Abilities API.
	 *
	 * Called on the wp_abilities_api_init action.
	 */
	public function do_register(): void {
		foreach ( self::CATALOG as $ability ) {
			wp_register_ability( $ability, array( 'plugin' => 'buddynext' ) );
		}
	}
}
