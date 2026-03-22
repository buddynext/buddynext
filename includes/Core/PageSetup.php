<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Hub page integrity guard.
 *
 * Ensures that every BuddyNext hub page exists as a published WordPress page
 * with the correct shortcode, and that the corresponding buddynext_page_*
 * option points to that page ID.
 *
 * Runs once per schema version on admin_init (guarded by a version option so
 * it never repeats on every request).  AJAX requests are excluded entirely.
 *
 * @package BuddyNext\Core
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Ensures all required BuddyNext hub pages exist and their options are set.
 */
class PageSetup {

	/**
	 * Schema version for the page-setup routine.
	 *
	 * Bump this constant to force a re-run after hub definitions change.
	 */
	public const PAGES_VERSION = 1;

	/**
	 * Hub page definitions.
	 *
	 * Each entry maps an option key to the page properties needed to create
	 * or adopt a matching WordPress page.
	 *
	 * Keys match the option names used by PageRouter::hub_url() so that URL
	 * builders resolve to the correct page IDs.
	 */
	private const HUBS = array(
		'buddynext_page_activity'      => array(
			'title'     => 'Community Feed',
			'slug'      => 'community-feed',
			'shortcode' => '[buddynext_activity]',
		),
		'buddynext_page_spaces'        => array(
			'title'     => 'Spaces',
			'slug'      => 'spaces',
			'shortcode' => '[buddynext_spaces]',
		),
		'buddynext_page_people'        => array(
			'title'     => 'Members',
			'slug'      => 'members',
			'shortcode' => '[buddynext_people]',
		),
		'buddynext_page_notifications' => array(
			'title'     => 'Notifications',
			'slug'      => 'notifications',
			'shortcode' => '[buddynext_notifications]',
		),
		'buddynext_page_messages'      => array(
			'title'     => 'Messages',
			'slug'      => 'messages',
			'shortcode' => '[buddynext_messages]',
		),
		'buddynext_page_auth'          => array(
			'title'     => 'Login',
			'slug'      => 'login',
			'shortcode' => '[buddynext_auth]',
		),
	);

	/**
	 * Register the admin_init hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'ensure_pages' ) );
	}

	/**
	 * Ensure all hub pages exist and options point to them.
	 *
	 * Bails immediately on AJAX requests and when the stored version flag
	 * already matches PAGES_VERSION (meaning this run was already completed).
	 *
	 * @return void
	 */
	public function ensure_pages(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		if ( (int) get_option( 'buddynext_pages_version', 0 ) === self::PAGES_VERSION ) {
			return;
		}

		foreach ( self::HUBS as $option_key => $hub ) {
			$this->ensure_hub_page( $option_key, $hub['title'], $hub['slug'], $hub['shortcode'] );
		}

		update_option( 'buddynext_pages_version', self::PAGES_VERSION );
	}

	/**
	 * Ensure a single hub page exists and its option is set correctly.
	 *
	 * Resolution order:
	 *   1. Option already points to a published page — skip entirely.
	 *   2. A published page containing the hub shortcode already exists —
	 *      adopt it by updating the option to that page's ID.
	 *   3. Neither — create a new page, then set the option.
	 *
	 * @param string $option_key The buddynext_page_* option name.
	 * @param string $title      Human-readable page title.
	 * @param string $slug       Desired post_name for a newly created page.
	 * @param string $shortcode  Shortcode string to embed in the page content.
	 * @return void
	 */
	private function ensure_hub_page( string $option_key, string $title, string $slug, string $shortcode ): void {
		// 1. Option already resolves to a live page.
		$stored_id = (int) get_option( $option_key, 0 );
		if ( $stored_id > 0 && 'publish' === get_post_status( $stored_id ) ) {
			return;
		}

		// 2. Locate an existing published page that already contains this shortcode.
		$existing_id = $this->find_page_by_shortcode( $shortcode );
		if ( $existing_id > 0 ) {
			update_option( $option_key, $existing_id );
			return;
		}

		// 3. Create a new page.
		$new_id = wp_insert_post(
			array(
				'post_title'     => $title,
				'post_name'      => $slug,
				'post_content'   => $shortcode,
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'comment_status' => 'closed',
			)
		);

		if ( ! is_wp_error( $new_id ) && $new_id > 0 ) {
			update_option( $option_key, $new_id );
		}
	}

	/**
	 * Find the ID of a published page whose content contains the given shortcode.
	 *
	 * Uses WP_Query with an exact post_content substring search limited to one
	 * result.  The shortcode string is a literal match, not a regex.
	 *
	 * @param string $shortcode Shortcode string to search for.
	 * @return int Page ID, or 0 when no match is found.
	 */
	private function find_page_by_shortcode( string $shortcode ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$page_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				  WHERE post_type    = 'page'
				    AND post_status  = 'publish'
				    AND post_content LIKE %s
				  LIMIT 1",
				'%' . $wpdb->esc_like( $shortcode ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $page_id;
	}
}
