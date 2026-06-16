<?php
/**
 * BuddyNext uninstall routine.
 *
 * Runs when the plugin is deleted from the WordPress admin (or via WP-CLI).
 * Drops every BuddyNext database table and removes BuddyNext options and user
 * meta so a delete leaves nothing behind. WordPress executes this file
 * automatically on uninstall — no register_uninstall_hook() is required.
 *
 * @package BuddyNext
 */

// Only ever run in the genuine uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Drop every {$prefix}bn_* table for a single site's table prefix.
 *
 * @param string $prefix Table prefix for the site being cleaned.
 * @return void
 */
$bn_drop_tables = static function ( $prefix ) use ( $wpdb ) {
	$like   = $wpdb->esc_like( $prefix . 'bn_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	foreach ( (array) $tables as $table ) {
		// Table names come straight from SHOW TABLES, so they are safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
};

/**
 * Remove BuddyNext options and user meta for the current site.
 *
 * @return void
 */
$bn_purge_meta = static function () use ( $wpdb ) {
	// Options: buddynext_* (settings, versions, flags).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'buddynext_' ) . '%' )
	);

	// User meta: bn_* and buddynext_* (last-login, onboarding, privacy, etc.).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( 'bn_' ) . '%',
			$wpdb->esc_like( 'buddynext_' ) . '%'
		)
	);
};

if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$bn_blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	foreach ( (array) $bn_blog_ids as $bn_blog_id ) {
		switch_to_blog( (int) $bn_blog_id );
		$bn_drop_tables( $wpdb->prefix );
		$bn_purge_meta();
		restore_current_blog();
	}
} else {
	$bn_drop_tables( $wpdb->prefix );
	$bn_purge_meta();
}
