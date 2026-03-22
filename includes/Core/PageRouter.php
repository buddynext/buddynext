<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext front-end URL router.
 *
 * Registers rewrite rules and tags so community pages have clean URLs:
 *
 *   /profile/{slug}/    → member profile (slug = bn_profile_slug meta or user-{id})
 *
 * Profile slugs are stored separately from WP credentials — changing a slug
 * never modifies user_login, user_nicename, or email. The default slug is
 * "user-{id}" so no login information is exposed in URLs by default.
 *
 * A static helper, profile_url(), builds the canonical profile URL for any
 * user ID and is used by all templates to generate member links.
 *
 * Flush rewrites (Settings → Permalinks or wp rewrite flush) whenever
 * community pages are created or their slugs change.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

use WP_Query;
use WP_User;

/**
 * Manages BuddyNext rewrite rules for pretty profile URLs.
 */
class PageRouter {

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_rewrites' ) );
		add_action( 'pre_get_posts', array( $this, 'set_profile_user' ) );
	}

	// ── Rewrite rules ─────────────────────────────────────────────────────────

	/**
	 * Register the bn_profile_slug rewrite tag and the profile pretty-URL rule.
	 *
	 * Pattern: /{profile-page-slug}/{user-slug}/
	 * Resolves to: index.php?pagename={profile-page-slug}&bn_profile_slug={user-slug}
	 *
	 * WordPress then loads the profile page normally (theme page template + the
	 * [buddynext_profile] shortcode), and set_profile_user() stores the resolved
	 * user ID in a query var for the shortcode to read.
	 *
	 * @return void
	 */
	public function register_rewrites(): void {
		add_rewrite_tag( '%bn_profile_slug%', '([^/]+)' );

		$page_id   = (int) get_option( 'buddynext_page_profile', 0 );
		$page_slug = $page_id > 0 ? (string) get_post_field( 'post_name', $page_id ) : 'profile';
		$page_slug = trim( $page_slug );

		if ( '' === $page_slug ) {
			return;
		}

		add_rewrite_rule(
			'^' . preg_quote( $page_slug, '/' ) . '/([^/]+)/?$',
			'index.php?pagename=' . $page_slug . '&bn_profile_slug=$matches[1]',
			'top'
		);
	}

	// ── Query filter ──────────────────────────────────────────────────────────

	/**
	 * Resolve bn_profile_slug to a user ID and store it as bn_resolved_user_id.
	 *
	 * Runs on the main query only. The [buddynext_profile] shortcode reads
	 * get_query_var('bn_resolved_user_id') to determine whose profile to display.
	 *
	 * @param WP_Query $query Current main query.
	 * @return void
	 */
	public function set_profile_user( WP_Query $query ): void {
		if ( ! $query->is_main_query() ) {
			return;
		}

		$raw_slug = $query->get( 'bn_profile_slug', '' );
		if ( '' === (string) $raw_slug ) {
			return;
		}

		$user    = $this->resolve_user( sanitize_title( (string) $raw_slug ) );
		$user_id = $user instanceof WP_User ? $user->ID : 0;
		$query->set( 'bn_resolved_user_id', $user_id );
	}

	// ── Static helpers ────────────────────────────────────────────────────────

	/**
	 * Build the canonical BuddyNext profile URL for a user.
	 *
	 * Priority:
	 *   1. bn_profile_slug usermeta (custom slug chosen by the member)
	 *   2. "user-{id}" — safe default that never exposes WP credentials
	 *
	 * Falls back to the WP author archive when the profile page has not been
	 * created yet (e.g. before the setup wizard page step runs).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Absolute URL.
	 */
	public static function profile_url( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$page_id = (int) get_option( 'buddynext_page_profile', 0 );

		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			// Profile page not yet created — fall back to WP author archive.
			return get_author_posts_url( $user_id );
		}

		$page_url = trailingslashit( (string) get_permalink( $page_id ) );

		$custom_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		$slug        = '' !== $custom_slug ? $custom_slug : 'user-' . $user_id;

		return $page_url . rawurlencode( $slug ) . '/';
	}

	/**
	 * Check whether a profile slug is available for a given user to claim.
	 *
	 * A slug is unavailable when:
	 *   - Another user already holds it as bn_profile_slug.
	 *   - It matches the reserved "user-{numeric_id}" pattern for any user other
	 *     than the requesting user (these are auto-generated default slugs).
	 *
	 * @param string $slug    Proposed slug (sanitized with sanitize_title internally).
	 * @param int    $user_id User requesting the slug (excluded from conflict checks).
	 * @return bool
	 */
	public static function is_slug_available( string $slug, int $user_id ): bool {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return false;
		}

		// Block the reserved "user-{id}" pattern for any other user's ID.
		if ( preg_match( '/^user-(\d+)$/', $slug, $m ) && (int) $m[1] !== $user_id ) {
			return false;
		}

		// Check bn_profile_slug usermeta (indexed; slow-query warning is a false positive).
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$taken_by_meta = get_users(
			array(
				'meta_key'   => 'bn_profile_slug',
				'meta_value' => $slug,
				'exclude'    => array( $user_id ),
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		return empty( $taken_by_meta );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Resolve a URL slug to a WordPress user.
	 *
	 * Checks in order:
	 *   1. bn_profile_slug usermeta (custom slug set by the member)
	 *   2. Reserved "user-{id}" pattern (system default — never exposes credentials)
	 *
	 * user_nicename and user_login are intentionally not checked here so that
	 * WP login names are never exposed through profile URLs.
	 *
	 * @param string $slug URL-decoded, sanitized slug.
	 * @return WP_User|null
	 */
	private function resolve_user( string $slug ): ?WP_User {
		// Custom slug set by the member (meta lookup is intentional — indexed column).
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$by_meta = get_users(
			array(
				'meta_key'   => 'bn_profile_slug',
				'meta_value' => $slug,
				'number'     => 1,
			)
		);
		if ( ! empty( $by_meta ) ) {
			return $by_meta[0] instanceof WP_User ? $by_meta[0] : null;
		}

		// System default: "user-{id}" pattern.
		if ( preg_match( '/^user-(\d+)$/', $slug, $m ) ) {
			$by_id = get_user_by( 'ID', (int) $m[1] );
			return $by_id instanceof WP_User ? $by_id : null;
		}

		return null;
	}
}
