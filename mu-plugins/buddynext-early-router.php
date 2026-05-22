<?php
/**
 * BuddyNext early router — plugin isolation on BuddyNext routes.
 *
 * Must-use plugin. Runs before normal plugins load. Checks the request URI
 * against configured BuddyNext hub slugs and strips non-essential plugins
 * from the active-plugins list so they never consume memory on BN routes.
 *
 * @package BuddyNext
 */

// Guard: only run on front-end HTTP requests. Admin, WP-CLI, and REST
// requests all need the full plugin set.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * Determine whether the current request is a BuddyNext hub route.
 *
 * Reads buddynext_slug_* options from the database and compares against
 * REQUEST_URI before WordPress finishes loading.
 *
 * @return bool
 */
function buddynext_mu_is_bn_request(): bool {
	static $result = null;

	if ( null !== $result ) {
		return $result;
	}

	// REQUEST_URI is always set on real HTTP requests.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	// Strip query string and leading slash.
	$path = ltrim( (string) strtok( $uri, '?' ), '/' );

	if ( '' === $path ) {
		$result = false;
		return false;
	}

	$slug_defaults = array(
		'buddynext_slug_activity'      => 'activity',
		'buddynext_slug_people'        => 'members',
		'buddynext_slug_spaces'        => 'spaces',
		'buddynext_slug_messages'      => 'messages',
		'buddynext_slug_notifications' => 'notifications',
		'buddynext_slug_auth'          => 'login',
	);

	global $wpdb;

	foreach ( $slug_defaults as $option_name => $fallback ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slug = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$slug = ( null !== $slug && '' !== trim( (string) $slug ) ) ? trim( (string) $slug ) : $fallback;

		// Match path starts with this slug.
		if ( 0 === strpos( $path, $slug ) ) {
			$result = true;
			return true;
		}
	}

	$result = false;
	return false;
}

if ( buddynext_mu_is_bn_request() ) {
	/**
	 * Essential plugins that must remain active on BuddyNext routes.
	 * Add third-party plugin slugs here if BuddyNext integrates with them.
	 */
	$bn_whitelist = apply_filters(
		'buddynext_isolation_whitelist',
		array(
			'buddynext/buddynext.php',
			'buddynext-pro/buddynext-pro.php',
			// WPMediaVerse owns the DM engine + media lightbox. MUST stay
			// active on BN routes so the messages page renders the two-pane
			// chat shell instead of the "dependency required" notice.
			'wpmediaverse/wpmediaverse.php',
			'wpmediaverse-pro/wpmediaverse-pro.php',
			'wp-mediaverse/wp-mediaverse.php',
			// Jetonomy bridge.
			'jetonomy/jetonomy.php',
			'jetonomy-pro/jetonomy-pro.php',
			'redis-cache/redis-cache.php',
			'query-monitor/query-monitor.php',
		)
	);

	// Strip active plugins that are not in the whitelist.
	add_filter(
		'option_active_plugins',
		static function ( array $plugins ) use ( $bn_whitelist ): array {
			return array_values(
				array_filter(
					$plugins,
					static function ( string $plugin ) use ( $bn_whitelist ): bool {
						return in_array( $plugin, $bn_whitelist, true );
					}
				)
			);
		}
	);
}
