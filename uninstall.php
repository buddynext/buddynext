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
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name <> %s",
			$wpdb->esc_like( 'buddynext_' ) . '%',
			$wpdb->esc_like( 'buddynext_pro_' ) . '%',
			// The wipe choice is SHARED with Pro, which reads it when it is deleted: it is removed last, only when Pro is
			// gone (see the end of this file). Deleting it here made a Free-first uninstall turn Pro's delete into a keep.
			'buddynext_delete_data_on_uninstall'
		)
	);

	// Widget instances (widget_buddynext_*), part of the same settings the wipe removes.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'widget_buddynext_' ) . '%' ) );

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
 * deleted directly: Free's 'buddynext' group (Pro's hooks there are left to
 * Pro) plus the two onboarding nudge hooks by exact name; the tables may be
 * absent if AS never ran, hence the guard.
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

	// Action Scheduler rows of Free's own group ('buddynext'), deleted directly
	// because AS is not booted here. Pro's hooks that run in that group
	// (buddynextpro_* / buddynext_pro_*) stay for Pro's uninstall, and no other
	// plugin's group is read (DATA-LIFECYCLE: delete only what you own). The two
	// onboarding nudge hooks also go by exact name whatever their group or status:
	// every release scheduled them in 'buddynext', so this only catches a row whose
	// group row was removed by hand or by a cleanup plugin. Log rows
	// go first, by action id, in bounded batches; the loop stops on a DELETE that
	// fails or removes nothing.
	$bn_as_actions = $wpdb->prefix . 'actionscheduler_actions';
	$bn_as_logs    = $wpdb->prefix . 'actionscheduler_logs';
	$bn_as_present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bn_as_actions ) ) === $bn_as_actions;
	if ( $bn_as_present ) {
		// -1 when the group does not exist: group_id 0 holds every plugin's ungrouped rows.
		$bn_group_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s", 'buddynext' ) ) ?: -1;
		$bn_has_logs = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bn_as_logs ) ) === $bn_as_logs;
		do {
			$bn_ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT action_id FROM `{$bn_as_actions}` WHERE ( group_id = %d AND hook NOT LIKE %s AND hook NOT LIKE %s ) OR hook IN ( %s, %s ) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$bn_group_id,
						$wpdb->esc_like( 'buddynextpro_' ) . '%',
						$wpdb->esc_like( 'buddynext_pro_' ) . '%',
						'bn_onboarding_nudge_24h',
						'bn_onboarding_nudge_72h',
						\BuddyNext\Core\Installer::UNINSTALL_BATCH
					)
				)
			);
			if ( array() === $bn_ids ) {
				break;
			}
			$bn_in = implode( ',', $bn_ids );
			if ( $bn_has_logs ) {
				$wpdb->query( "DELETE FROM `{$bn_as_logs}` WHERE action_id IN ( {$bn_in} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints only.
			}
			$bn_deleted = $wpdb->query( "DELETE FROM `{$bn_as_actions}` WHERE action_id IN ( {$bn_in} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints only.
			$bn_full    = count( $bn_ids ) === \BuddyNext\Core\Installer::UNINSTALL_BATCH;
		} while ( $bn_deleted && $bn_full );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// WP-Cron events (the AS-absent fallback), with any arguments: every event of
	// Free's hook namespace and the two onboarding nudges, and the licence SDK's
	// weekly check named after the slug. Pro's buddynext_pro_* events stay for Pro.
	foreach ( (array) _get_cron_array() as $bn_events ) {
		foreach ( array_keys( (array) $bn_events ) as $bn_hook ) {
			$bn_hook = (string) $bn_hook;
			if ( ( str_starts_with( $bn_hook, 'buddynext_' ) && ! str_starts_with( $bn_hook, 'buddynext_pro_' ) )
				|| in_array( $bn_hook, array( 'bn_onboarding_nudge_24h', 'bn_onboarding_nudge_72h', 'edd_sl_sdk_weekly_license_check_buddynext' ), true ) ) {
				wp_unschedule_hook( $bn_hook );
			}
		}
	}

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
// go. On a network the community lives on the main site only, so uninstall runs
// once, on the main site, and only the main site's choice counts; a subsite
// admin has no say over the shared member meta. Financial data lives in Pro's
// tables and is retained there regardless; Pro reads this same option.
if ( is_multisite() ) {
	switch_to_blog( get_main_site_id() );
}
$bn_wipe_members = (bool) get_option( 'buddynext_delete_data_on_uninstall', false );

/**
 * Purge the community site: always clear settings + infrastructure; drop member data only
 * when the owner opted in.
 *
 * @param string $prefix Table prefix for the site being cleaned.
 * @return void
 */
$bn_clean_site = static function ( $prefix ) use ( $bn_drop_tables, $bn_purge_meta, $bn_purge_infrastructure ) {
	$bn_delete_data = (bool) get_option( 'buddynext_delete_data_on_uninstall', false );
	$bn_purge_infrastructure();
	if ( $bn_delete_data ) {
		// Remove the hub backing pages (published WP pages holding [buddynext_*]
		// shortcodes) BEFORE $bn_purge_meta() sweeps away their buddynext_page_*
		// pointers. Opt-in full wipe only; the default path leaves them in place.
		\BuddyNext\Core\Installer::delete_hub_pages();
		$bn_drop_tables( $prefix );
	}
	// Settings and flags go only with the opt-in wipe. The default keeps them with
	// the spaces, posts and members the owner is told are kept, so a reinstall
	// restores the site as it was (the tables are kept, so their version flags
	// must be too, or a reinstall would re-seed over existing rows).
	// Member user meta is network-wide and handled once, below.
	if ( $bn_delete_data ) {
		// The licence SDK's update cache, named edd_sl_md5( [ slug, key, beta ] ) (libs/edd-sl-sdk
		// Updater::get_cache_key()). Derived exactly, before the sweep below removes the key it hashes:
		// other plugins on the same SDK keep their own edd_sl_* rows, so no wildcard. The key, status and
		// tracking options (buddynext_license*) go with the sweep. With data kept they all stay, and a
		// reinstall reuses them: the same key, the cached version info until it times out, and the last
		// licence status until the weekly check refreshes it.
		delete_option( 'edd_sl_' . md5( (string) wp_json_encode( array( 'buddynext', get_option( 'buddynext_license_key' ), 0 ) ) ) );
		$bn_purge_meta( true );
	}
};

$bn_clean_site( $wpdb->prefix );

if ( $bn_wipe_members ) {
	$bn_purge_meta();
	// Last of all: the shared wipe choice. Pro reads it when it is deleted, so it stays while Pro is installed.
	if ( ! file_exists( WP_PLUGIN_DIR . '/buddynext-pro/buddynext-pro.php' ) ) {
		delete_option( 'buddynext_delete_data_on_uninstall' );
	}
}

// The deletes above went straight to SQL, so a persistent object cache (Redis, Memcached) still holds the old options,
// pages and db version: a reinstall would read them back, point at pages that no longer exist and show the setup wizard
// as complete on a wiped site. Flush it, data kept or removed (transients and Action Scheduler rows were deleted either way).
wp_cache_flush();

if ( is_multisite() ) {
	restore_current_blog();
}
