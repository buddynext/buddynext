<?php
/**
 * BuddyNext uninstall routine.
 *
 * Runs when the plugin is deleted from the WordPress admin (or via WP-CLI).
 * Removes everything BuddyNext owns — and nothing else. WordPress executes this
 * file automatically on uninstall; no register_uninstall_hook() is required.
 *
 * A plugin may only delete what it owns. BuddyNext Pro names its 18 tables with
 * the same `bn_` prefix and keeps options under `buddynext_pro_`, so the previous
 * wildcard sweeps here (`SHOW TABLES LIKE '{prefix}bn_%'` and
 * `option_name LIKE 'buddynext_%'`) reached across the seam: deleting Free
 * dropped Pro's invoices, subscriptions and membership tiers, and wiped Pro's
 * page mapping — on every site running both. Free now names its own tables
 * explicitly (Installer::OWNED_TABLES) and skips the Pro option namespace. Pro
 * cleans up after itself in its own uninstall.php.
 *
 * @package BuddyNext
 */

// Only ever run in the genuine uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// The plugin is not booted during uninstall, so there is no autoloader. Load the
// installer directly for its OWNED_TABLES list — the same class that creates the
// tables names them, so the two cannot drift apart.
require_once __DIR__ . '/includes/Core/Installer.php';

/**
 * Drop the tables BuddyNext owns for a single site's table prefix.
 *
 * Named explicitly, never discovered by prefix match — see the file header.
 *
 * @param string $prefix Table prefix for the site being cleaned.
 * @return void
 */
$bn_drop_tables = static function ( $prefix ) use ( $wpdb ) {
	$bn_tables = array_merge(
		\BuddyNext\Core\Installer::OWNED_TABLES,
		\BuddyNext\Core\Installer::LEGACY_TABLES
	);

	foreach ( $bn_tables as $bn_table ) {
		$bn_full = $prefix . $bn_table;
		// The name is a constant from our own class, not user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$bn_full}`" );
	}
};

/**
 * Remove BuddyNext options and user meta for the current site.
 *
 * @return void
 */
$bn_purge_meta = static function () use ( $wpdb ) {
	// Options: buddynext_* (settings, versions, flags) — but NOT buddynext_pro_*,
	// which belongs to Pro (its page mapping and rewrite version live there).
	// Pro's licence options use the `buddynext-pro_` prefix and never matched.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
			$wpdb->esc_like( 'buddynext_' ) . '%',
			$wpdb->esc_like( 'buddynext_pro_' ) . '%'
		)
	);

	// User meta: bn_* and buddynext_* (last-login, onboarding, privacy, etc.) —
	// EXCEPT the keys Pro owns.
	//
	// This used to be an unqualified `LIKE 'bn\_%'` wildcard, under a comment stating
	// that "Pro writes no user meta under either prefix". That was false. Pro writes
	// bn_ability_* (paid entitlement grants) and bn_email_unsubscribed_* /
	// bn_email_suppressed (email opt-outs) — so deleting the FREE plugin revoked
	// access members had paid for, and erased the record that a member had
	// unsubscribed, which silently re-subscribes them to the next broadcast.
	//
	// Same failure as the table drop that preceded it: discover-by-prefix cannot tell
	// "mine" from "the family's". Name what we own, exclude what we do not.
	$bn_meta_params = array(
		$wpdb->esc_like( 'bn_' ) . '%',
		$wpdb->esc_like( 'buddynext_' ) . '%',
	);

	// One ` AND meta_key NOT LIKE %s` per Pro-owned namespace. The clauses are built
	// from our own class constant and contain only the literal placeholder %s — every
	// value still travels through prepare(). Nothing here is caller input.
	$bn_meta_exclude_sql = '';
	foreach ( \BuddyNext\Core\Installer::PRO_OWNED_USER_META as $bn_pro_key ) {
		$bn_meta_exclude_sql .= ' AND meta_key NOT LIKE %s';
		$bn_meta_params[]     = $wpdb->esc_like( $bn_pro_key ) . '%';
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$bn_meta_sql = "DELETE FROM {$wpdb->usermeta}
		 WHERE ( meta_key LIKE %s OR meta_key LIKE %s )" . $bn_meta_exclude_sql;

	$wpdb->query( $wpdb->prepare( $bn_meta_sql, $bn_meta_params ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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
