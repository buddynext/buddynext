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
 * Remove BuddyNext options and (optionally) user meta for the current site.
 *
 * @param bool $options_only When true, remove only the settings/flag OPTIONS and
 *                           keep user meta — the default-delete path, which
 *                           clears plugin config but preserves member content so
 *                           a reinstall is seamless. When false, also purge the
 *                           member-owned user meta (the opt-in full-wipe path).
 * @return void
 */
$bn_purge_meta = static function ( $options_only = false ) use ( $wpdb ) {
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

	// Default-delete keeps member content: the user meta below is member-owned
	// (onboarding, privacy, follows), so it is purged only on the opt-in wipe.
	if ( $options_only ) {
		return;
	}

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

/**
 * Remove BuddyNext's scheduled jobs and transients for the current site.
 *
 * These are pure infrastructure, never member content, so they are cleared on
 * EVERY uninstall regardless of the data-retention setting — leaving them
 * behind was the "211 Action Scheduler actions and transients" orphan the audit
 * flagged. Action Scheduler is not loaded during uninstall, so its rows are
 * deleted directly by our own hook prefix (every BuddyNext job hook starts
 * `buddynext`); the tables may be absent if AS never ran, hence the guard.
 *
 * @return void
 */
$bn_purge_infrastructure = static function () use ( $wpdb ) {
	// BuddyNext transients (options table): both the value and timeout rows.
	// These never matched the `buddynext_%` option sweep because WordPress
	// stores them as `_transient_buddynext_%` / `_transient_timeout_buddynext_%`.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_buddynext_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_buddynext_' ) . '%'
		)
	);

	// Action Scheduler rows for our own hooks, deleted directly because AS is not
	// booted here. Guarded on table existence so an install that never scheduled
	// anything is a no-op.
	$bn_as_actions = $wpdb->prefix . 'actionscheduler_actions';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bn_as_actions ) ) === $bn_as_actions ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$bn_as_actions}` WHERE hook LIKE %s", $wpdb->esc_like( 'buddynext' ) . '%' ) );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// The isolation mu-plugin (buddynext-isolation.php) filters option_active_plugins
	// on front-end routes. Left behind, it keeps stripping plugins with BuddyNext
	// itself deleted — an invisible, unremovable-from-the-UI side effect. Delete it.
	$bn_mu = WP_CONTENT_DIR . '/mu-plugins/buddynext-isolation.php';
	if ( is_file( $bn_mu ) ) {
		wp_delete_file( $bn_mu );
	}
};

// One owner-controlled data policy (audit Decision 6). By DEFAULT a plugin
// delete keeps member content: dropping every table on an accidental
// deactivate-then-delete is destructive and irreversible, and matches nothing a
// careful owner expects. Only when the owner has explicitly ticked
// "Delete all BuddyNext data when uninstalled" do the data tables and user meta
// go. Read BEFORE the option sweep below, which would otherwise remove the flag
// before we consult it. Financial data lives in Pro's tables and is retained
// there regardless; Pro reads this same option.
$bn_delete_data = (bool) get_option( 'buddynext_delete_data_on_uninstall', false );

/**
 * Purge one site: always clear settings + infrastructure; drop member data only
 * when the owner opted in.
 *
 * @param string $prefix Table prefix for the site being cleaned.
 * @return void
 */
$bn_clean_site = static function ( $prefix ) use ( $bn_drop_tables, $bn_purge_meta, $bn_purge_infrastructure, $bn_delete_data ) {
	$bn_purge_infrastructure();
	if ( $bn_delete_data ) {
		$bn_drop_tables( $prefix );
		$bn_purge_meta();
	} else {
		// Keep member content and user meta; still remove pure settings/flags so a
		// clean reinstall starts from defaults rather than stale options.
		$bn_purge_meta( true );
	}
};

if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$bn_blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	foreach ( (array) $bn_blog_ids as $bn_blog_id ) {
		switch_to_blog( (int) $bn_blog_id );
		$bn_clean_site( $wpdb->prefix );
		restore_current_blog();
	}
} else {
	$bn_clean_site( $wpdb->prefix );
}
