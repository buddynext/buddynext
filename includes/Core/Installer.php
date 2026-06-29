<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Database installer.
 *
 * Creates all bn_* tables on activation via dbDelta(). Safe to run on every
 * activation — dbDelta skips tables that already exist with the correct schema.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Handles database table creation and upgrades.
 */
class Installer {

	/**
	 * Filename for the mu-plugin that provides front-end plugin isolation.
	 */
	private const MU_PLUGIN_SLUG = 'buddynext-isolation.php';

	/**
	 * Option holding the md5 of the last-written isolation mu-plugin source, so
	 * maybe_refresh_mu_plugin() can detect drift without reading the file.
	 */
	private const MU_PLUGIN_SIG_OPTION = 'buddynext_mu_plugin_sig';

	/**
	 * Database schema revision. BUMP THIS whenever the schema changes (new table,
	 * new/changed column) so existing installs run the migration on the next
	 * admin load without needing a deactivate/reactivate. Tracked separately from
	 * the (release-locked) plugin version in the buddynext_schema_version option.
	 *
	 * 2 — added bn_invites.space_id (space-linked email invitations).
	 * 3 — seed moderation email templates bn.unsuspension_confirmation +
	 *     bn.new_report (back-fills existing installs via the idempotent
	 *     INSERT IGNORE seeder; no schema change).
	 * 4 — added bn_space_categories.color / text_color / icon_svg / show_in_dir
	 *     for parity with bn_member_types (unified taxonomy editor). Applied to
	 *     existing installs via the idempotent column ALTER in maybe_alter_tables().
	 * 6 — cron-minimisation pass: unschedule buddynext_publish_scheduled (1 min,
	 *     now owned by Pro ScheduledPostsService), buddynext_trending_hashtags
	 *     (30 min, now lazy transient in HashtagService::get_trending), recurring
	 *     buddynext_webhook_retry (5 min, now reactive single-event in
	 *     OutboundWebhookService); migrate buddynext_recount_stats from
	 *     buddynext_5min to 'daily'. No schema change — migration is cron only.
	 * 7 — added bn_presence (indexed integer presence timestamp) to replace the
	 *     non-sargable CAST(meta_value) usermeta scans. Existing bn_last_active
	 *     meta is back-filled once via maybe_backfill_presence(); the writer keeps
	 *     dual-writing meta during the transition so readers can switch safely.
	 * 8 — autoload hygiene: flip the historically-autoloaded per-space settings
	 *     (bn_space_*) and the custom-CSS blob to autoload=off via
	 *     maybe_fix_autoload() so they stop loading on every request as the
	 *     community grows. No schema change.
	 * 10 — converge the legacy buddynext_jetonomy_feed_sync option into the unified
	 *      per-integration key buddynext_integration_jetonomy_feed: carry an explicit
	 *      opt-out ('0') over, then delete the legacy option. The Integration Display
	 *      admin tab now owns the toggle. No schema change.
	 * 11 — Spaces foundation. (a) Added KEY parent (parent_id) on bn_spaces and KEY
	 *      space_status (space_id, status, joined_at) on bn_space_members so sub-space
	 *      lookups and space-roster pagination stay index-backed at 50k members/space
	 *      (no filesort or full scan) — applied to existing installs via the idempotent
	 *      ADD KEY in maybe_alter_tables(), fresh installs inline in CREATE TABLE.
	 *      (b) Added the bn_space_meta table (WP-meta-shaped: meta_id, bn_space_id)
	 *      as the per-space metadata substrate behind the native metadata API. Pro
	 *      shipped this table first with a bespoke ( id, space_id ) schema, so
	 *      maybe_reshape_space_meta() converges any pre-existing table to canonical
	 *      (copying space_id -> bn_space_id) BEFORE dbDelta runs; fresh installs get
	 *      the canonical table inline. Free now owns the table; Pro reads it via the
	 *      *_space_meta() API.
	 */
	private const SCHEMA_VERSION = 11;

	/**
	 * Run the schema migration when the stored revision is behind SCHEMA_VERSION.
	 *
	 * Hooked on admin_init so a plain plugin update (no reactivation) still picks
	 * up column/table changes. Cheap no-op once the versions match.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( 'buddynext_schema_version', 0 ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::run();

		// v6: remove stale cron events from existing installs.
		// Safe to call repeatedly — each step checks the current schedule state.
		CronScheduler::run_cron_migration();

		// v7: seed bn_presence from existing bn_last_active meta so the online
		// filter/sort is populated the moment readers switch to the table.
		global $wpdb;
		self::maybe_backfill_presence( $wpdb->prefix );

		// v8: stop the per-space settings + custom-CSS blob from autoloading.
		self::maybe_fix_autoload();

		// v9: drop the legacy bn_last_active user_meta now that every reader
		// resolves presence from the indexed bn_presence table. Runs AFTER the v7
		// backfill above, so a fresh v6 -> v9 upgrade seeds the table first, then
		// clears the meta.
		self::maybe_drop_last_active_meta();

		// v10: converge the legacy Jetonomy feed-sync option into the unified
		// per-integration key so the admin has a single control, not two.
		self::maybe_migrate_jetonomy_feed_sync();

		// v11: migrate the per-space settings from autoloaded bn_space_{id}_* options
		// into bn_space_meta (the canonical field store). Runs after the field
		// registry boots on init, so register_meta sanitisation applies. Removes the
		// options once copied — readers now resolve these via get_space_meta().
		self::maybe_migrate_space_options();

		update_option( 'buddynext_schema_version', self::SCHEMA_VERSION );
	}

	/**
	 * One-time cleanup: delete the legacy bn_last_active presence user_meta.
	 *
	 * All presence readers migrated to the bn_presence table (F-stage-2) and the
	 * dual-write was dropped, so the meta is now dead weight on wp_usermeta. Uses
	 * the WP metadata API with delete_all so a single call clears every user's row
	 * without a per-user loop. Idempotent — a re-run simply finds nothing to delete.
	 *
	 * @return void
	 */
	private static function maybe_drop_last_active_meta(): void {
		delete_metadata( 'user', 0, \BuddyNext\Realtime\PresenceService::META_KEY, '', true );
	}

	/**
	 * One-time convergence: fold the legacy buddynext_jetonomy_feed_sync option into
	 * the unified per-integration key buddynext_integration_jetonomy_feed.
	 *
	 * Both default ON, so only an explicit opt-out ('0') needs carrying; an absent or
	 * '1' legacy value maps to the unified default-on (no write). The legacy option is
	 * then deleted so the admin sees a single control on the Integration Display tab,
	 * never two. Idempotent — a re-run finds the option already gone and no-ops.
	 *
	 * @return void
	 */
	private static function maybe_migrate_jetonomy_feed_sync(): void {
		$legacy = get_option( 'buddynext_jetonomy_feed_sync', null );
		if ( null === $legacy ) {
			return;
		}
		if ( '0' === (string) $legacy ) {
			update_option( 'buddynext_integration_jetonomy_feed', '0', false );
		}
		delete_option( 'buddynext_jetonomy_feed_sync' );
	}

	/**
	 * One-time autoload hygiene: flip historically-autoloaded options off.
	 *
	 * Per-space settings (bn_space_*) and the custom-CSS blob were created with the
	 * default autoload=on, so every one loaded into alloptions on every request —
	 * a real cost once a community has thousands of spaces. They are read on demand,
	 * never every request, so this flips them off. Idempotent and uses the WP API
	 * (correct cross-version autoload values) rather than a raw UPDATE. No-op on
	 * WP < 6.4 where the bulk helper is absent (readers are autoload-agnostic).
	 *
	 * @return void
	 */
	private static function maybe_fix_autoload(): void {
		global $wpdb;

		if ( ! function_exists( 'wp_set_options_autoload' ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE 'bn\\_space\\_%'
			    OR option_name = 'buddynext_custom_css'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! empty( $names ) ) {
			wp_set_options_autoload( $names, false );
		}
	}

	/**
	 * One-time back-fill of bn_presence from the legacy bn_last_active user_meta.
	 *
	 * Idempotent: an UPSERT keyed on user_id that only ever advances last_active
	 * (GREATEST), so re-running never regresses a fresher value the live dual-write
	 * already wrote. Runs as a single INSERT..SELECT — one bounded query even on a
	 * large site. Skips cleanly when the table or the meta key is absent.
	 *
	 * @param string $p Table prefix.
	 * @return void
	 */
	private static function maybe_backfill_presence( string $p ): void {
		global $wpdb;

		// phpcs:disable -- one-time migration query: interpolated table names, no caching by design.
		$wpdb->query(
			"INSERT INTO {$p}bn_presence (user_id, last_active)
			 SELECT user_id, CAST(meta_value AS UNSIGNED)
			 FROM {$wpdb->usermeta}
			 WHERE meta_key = '" . \BuddyNext\Realtime\PresenceService::META_KEY . "'
			   AND meta_value REGEXP '^[0-9]+\$'
			 ON DUPLICATE KEY UPDATE last_active = GREATEST(last_active, VALUES(last_active))"
		);
		// phpcs:enable
	}

	/**
	 * Run the installer.
	 *
	 * Called on register_activation_hook and on manual version upgrades.
	 */
	public static function run(): void {
		global $wpdb;

		// Detect a brand-new install before the version is stamped below, so the
		// recommended first-run defaults seed only on a genuinely fresh site —
		// never on an upgrade or reactivation.
		$is_fresh_install = false === get_option( 'buddynext_db_version', false );

		// Create/upgrade every bn_* table. Split out so the PHPUnit bootstrap can
		// create the schema without the first-run seeds / hub pages / mu-plugin
		// that follow (which would otherwise collide with test fixtures).
		self::install_schema();

		self::seed_email_templates( $wpdb->prefix );
		self::seed_default_profile_groups_and_fields( $wpdb->prefix );
		self::seed_default_space( $wpdb->prefix );

		update_option( 'buddynext_db_version', BUDDYNEXT_VERSION );
		update_option( 'buddynext_schema_version', self::SCHEMA_VERSION );

		// Fresh install: give the new community the full experience on day one
		// (discovery, DM, engagement surfaces, default notifications, baseline
		// spam protection). add_option never clobbers an existing value. Starter
		// space categories + member types are seeded once so the directory is not
		// empty out of the box; the owner can rename or delete them freely, and
		// the fresh-install guard means deleted ones never come back.
		if ( $is_fresh_install ) {
			RecommendedDefaults::seed();
			self::seed_starter_space_categories( $wpdb->prefix );
			self::seed_starter_member_types( $wpdb->prefix );
		}

		self::create_hub_pages();

		// Seed the integration allow-list option BEFORE writing the mu-plugin, so the
		// data-driven mu-plugin already finds the in-house family on its first run.
		// PluginIsolation::sync_option() keeps it current (incl. Pro) at runtime.
		update_option( PluginIsolation::OPTION, (string) wp_json_encode( PluginIsolation::integration_plugins() ), false );

		self::install_mu_plugin();

		\BuddyNext\Search\SearchService::schedule_reindex_all();
	}

	/**
	 * Create/upgrade all bn_* tables (schema only — no seeds, hub pages, or
	 * mu-plugin). Idempotent: dbDelta skips tables already at the correct schema.
	 *
	 * Split out from run() so the PHPUnit bootstrap can create the tables in the
	 * test DB without the first-run seed data (default space, member types, …)
	 * that would otherwise collide with per-test fixtures.
	 *
	 * @return void
	 */
	public static function install_schema(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// v11: reshape a pre-existing bn_space_meta table to the canonical WP-meta
		// shape BEFORE dbDelta runs. Pro shipped this table first with a bespoke
		// schema (id PK, space_id) that the native metadata API can't use; Free now
		// owns it. Running first means dbDelta then sees a canonical table and does
		// not add a duplicate meta_id column. No-op on fresh installs (table absent).
		self::maybe_reshape_space_meta( $wpdb->prefix );

		// Suppress echo of DB errors during schema creation so that WP-CLI
		// and browser activation do not see unexpected HTML output from dbDelta.
		$wpdb->suppress_errors( true );
		foreach ( self::schema( $wpdb->prefix, $charset ) as $sql ) {
			dbDelta( $sql );
		}
		$wpdb->suppress_errors( false );

		// Idempotent column back-fills for existing installs. dbDelta handles
		// most additive changes, but enum/charset edge cases on older MySQL can
		// silently skip new columns — so we guard each add with an
		// INFORMATION_SCHEMA existence check. Safe to run on every activation.
		self::maybe_alter_tables( $wpdb->prefix );

		// FULLTEXT index for the search service. Skipped under the PHPUnit harness:
		// WP_UnitTestCase wraps each test in a transaction that is rolled back, and
		// InnoDB FULLTEXT does not index uncommitted rows, so MATCH ... AGAINST would
		// never see fixtures inserted inside a test. With no index present,
		// SearchService::has_fulltext_index() returns false and the transaction-safe
		// LIKE fallback runs instead. Errors are suppressed so older engines without
		// FULLTEXT support still activate.
		$wpdb->suppress_errors( true );
		if ( defined( 'WP_TESTS_DOMAIN' ) ) {
			// Test env: actively DROP the index (not just skip creating it) so a DB
			// that already has it from an earlier run still routes through the
			// transaction-safe LIKE path. DROP is a harmless no-op when absent.
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}bn_search_index DROP INDEX ft_search" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		} else {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}bn_search_index ADD FULLTEXT KEY ft_search (title, content)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		$wpdb->suppress_errors( false );
	}

	/**
	 * Apply idempotent column additions to existing tables.
	 *
	 * Each ALTER is guarded by an INFORMATION_SCHEMA column-existence check so
	 * the routine is safe to run on every activation/upgrade without erroring
	 * on installs that already have the column. New columns are added here (not
	 * relied on through dbDelta alone) because dbDelta cannot always alter a
	 * pre-existing table reliably across MySQL/MariaDB versions.
	 *
	 * @param string $p Table prefix.
	 * @return void
	 */
	private static function maybe_alter_tables( string $p ): void {
		global $wpdb;

		// Per-table additive column back-fills. Each clause is a hardcoded
		// constant; table names are built from $wpdb->prefix — no untrusted input.
		$table_columns = array(
			// Schema-parity columns for the unified taxonomy editor (v4).
			// Categories gain colour/icon/directory-visibility to match bn_member_types.
			'bn_space_categories' => array(
				'color'       => "ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#0073aa'",
				'text_color'  => "ADD COLUMN text_color VARCHAR(7) NOT NULL DEFAULT '#ffffff'",
				'icon_svg'    => 'ADD COLUMN icon_svg MEDIUMTEXT NULL',
				'show_in_dir' => 'ADD COLUMN show_in_dir TINYINT(1) NOT NULL DEFAULT 1',
			),
			// v5: a flat field can be surfaced on the registration form.
			'bn_profile_fields'   => array(
				'show_on_register' => 'ADD COLUMN show_on_register TINYINT(1) NOT NULL DEFAULT 0',
			),
		);

		// Per-table additive index back-fills. dbDelta cannot reliably add a KEY
		// to a pre-existing table across MySQL/MariaDB versions, so each new index
		// is added here via a guarded ALTER. Each clause is a hardcoded constant;
		// table names are built from $wpdb->prefix — no untrusted input.
		$table_indexes = array(
			// v11: index sub-space lookups (WHERE parent_id = ?) and roster
			// pagination (WHERE space_id = ? AND status = ? ORDER BY joined_at) so
			// neither scans/filesorts at 50k members.
			'bn_spaces'        => array(
				'parent' => 'ADD KEY parent (parent_id)',
			),
			'bn_space_members' => array(
				'space_status' => 'ADD KEY space_status (space_id, status, joined_at)',
			),
		);

		$wpdb->suppress_errors( true );
		foreach ( $table_columns as $table_slug => $columns ) {
			$table = $p . $table_slug;
			foreach ( $columns as $column => $clause ) {
				if ( self::column_exists( $table, $column ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` {$clause}" );
			}
		}
		foreach ( $table_indexes as $table_slug => $indexes ) {
			$table = $p . $table_slug;
			foreach ( $indexes as $index => $clause ) {
				if ( self::index_exists( $table, $index ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` {$clause}" );
			}
		}
		$wpdb->suppress_errors( false );
	}

	/**
	 * Whether a column exists on a table, via INFORMATION_SCHEMA.
	 *
	 * @param string $table  Fully-prefixed table name.
	 * @param string $column Column name to check.
	 * @return bool
	 */
	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s
				 LIMIT 1',
				DB_NAME,
				$table,
				$column
			)
		);

		return null !== $found;
	}

	/**
	 * Whether a named index exists on a table, via INFORMATION_SCHEMA.
	 *
	 * Mirrors {@see column_exists()} so {@see maybe_alter_tables()} can add a KEY
	 * idempotently (dbDelta cannot reliably alter indexes on a pre-existing table).
	 *
	 * @param string $table Fully-prefixed table name.
	 * @param string $index Index name to check.
	 * @return bool
	 */
	private static function index_exists( string $table, string $index ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s
				 LIMIT 1',
				DB_NAME,
				$table,
				$index
			)
		);

		return null !== $found;
	}

	/**
	 * Migrate per-space settings from bn_space_{id}_* options into bn_space_meta.
	 *
	 * The eight built-in per-space settings used to be standalone options; they are
	 * now core space fields stored in bn_space_meta. This copies any existing
	 * values over (idempotent: skips a key already present in meta) and deletes the
	 * option so the autoloaded-options footprint goes to zero. Runs once at the
	 * v11 upgrade.
	 *
	 * @return void
	 */
	private static function maybe_migrate_space_options(): void {
		global $wpdb;

		$keys    = array(
			'push_to_feed',
			'mvs_media_tab',
			'jetonomy_forum_id',
			'require_join_approval',
			'who_can_post',
			'who_can_invite',
			'banned_words',
			'default_notification_pref',
		);
		$pattern = '^bn_space_[0-9]+_(' . implode( '|', $keys ) . ')$';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name REGEXP %s",
				$pattern
			)
		);

		foreach ( (array) $rows as $row ) {
			if ( ! preg_match( '/^bn_space_(\d+)_(.+)$/', (string) $row->option_name, $m ) ) {
				continue;
			}
			$space_id = (int) $m[1];
			$key      = (string) $m[2];

			if ( '' === (string) get_space_meta( $space_id, $key, true ) ) {
				update_space_meta( $space_id, $key, maybe_unserialize( $row->option_value ) );
			}
			delete_option( (string) $row->option_name );
		}
	}

	/**
	 * Whether a table exists, via INFORMATION_SCHEMA.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
				 LIMIT 1',
				DB_NAME,
				$table
			)
		);

		return null !== $found;
	}

	/**
	 * Converge a pre-existing bn_space_meta table to the canonical WP-meta shape.
	 *
	 * Pro shipped this table first as ( id PK, space_id, meta_key, meta_value ) and
	 * read it with raw SQL; the native metadata API needs ( meta_id PK, bn_space_id,
	 * meta_key, meta_value ). This migrates either the legacy Pro shape OR a partly
	 * dbDelta-merged hybrid to canonical, preserving any rows (white-label brand
	 * blobs) by copying space_id -> bn_space_id. Idempotent and order-critical: it
	 * MUST run before dbDelta so dbDelta does not add a second id column. No-op once
	 * the table is canonical (meta_id present, space_id gone) or absent.
	 *
	 * @param string $p Table prefix.
	 * @return void
	 */
	private static function maybe_reshape_space_meta( string $p ): void {
		global $wpdb;

		$table = $p . 'bn_space_meta';

		if ( ! self::table_exists( $table ) ) {
			return; // Fresh install — dbDelta creates the canonical table.
		}

		$has_space_id = self::column_exists( $table, 'space_id' );
		$has_meta_id  = self::column_exists( $table, 'meta_id' );

		if ( $has_meta_id && ! $has_space_id ) {
			return; // Already canonical.
		}

		$wpdb->suppress_errors( true );

		// Ensure the WP-meta object-id column exists, then carry legacy rows over.
		if ( ! self::column_exists( $table, 'bn_space_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN bn_space_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0" );
		}
		if ( $has_space_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE `{$table}` SET bn_space_id = space_id WHERE bn_space_id = 0 AND space_id > 0" );
		}

		// Rename the legacy PK id -> meta_id (the column the metadata API references).
		if ( ! $has_meta_id && self::column_exists( $table, 'id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` CHANGE id meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT" );
		}

		// Retire the legacy unique key + space_id column (index dropped first).
		if ( $has_space_id ) {
			if ( self::index_exists( $table, 'space_key' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX space_key" );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN space_id" );
		}

		// Ensure the canonical indexes (dbDelta would add them, but be explicit).
		if ( ! self::index_exists( $table, 'bn_space_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY bn_space_id (bn_space_id)" );
		}

		$wpdb->suppress_errors( false );
	}

	/**
	 * Insert default email templates using INSERT IGNORE so existing
	 * customised templates are never overwritten on upgrade.
	 *
	 * @param string $p Table prefix.
	 */
	private static function seed_email_templates( string $p ): void {
		global $wpdb;

		$templates = array(
			array(
				'type'         => 'email_verify',
				'subject'      => 'Verify your email address — {{site_name}}',
				'preview_text' => 'Click the link to confirm your address',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Please verify your email address by clicking the link below:</p><p><a href="{{verify_url}}">Verify my email</a></p><p>This link expires in 24 hours.</p>',
			),
			array(
				'type'         => 'email_change_confirm',
				'subject'      => 'Confirm your new email address — {{site_name}}',
				'preview_text' => 'Confirm the email change on your account',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You asked to change the email address on your {{site_name}} account to this inbox. Confirm the change by clicking the link below:</p><p><a href="{{verify_url}}">Confirm my new email</a></p><p>This link expires in 24 hours. If you did not request this, ignore this email and your address stays the same.</p>',
			),
			array(
				'type'         => 'welcome',
				'subject'      => 'Welcome to {{site_name}}!',
				'preview_text' => 'Your community account is ready',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Welcome to {{site_name}}! Your account is all set — <a href="{{site_url}}">start exploring</a>.</p>',
			),
			array(
				'type'         => 'bn.new_follower',
				'subject'      => 'Someone followed you on {{site_name}}',
				'preview_text' => 'You have a new follower',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You have a new follower on {{site_name}}. <a href="{{action_url}}">See who it is.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.connection_requested',
				'subject'      => 'New connection request on {{site_name}}',
				'preview_text' => 'Someone wants to connect with you',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You have a new connection request on {{site_name}}. <a href="{{action_url}}">View the request.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.connection_accepted',
				'subject'      => 'Connection accepted on {{site_name}}',
				'preview_text' => 'You are now connected',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your connection request was accepted on {{site_name}}. <a href="{{action_url}}">View your connections.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.connection_declined',
				'subject'      => 'An update on your connection request on {{site_name}}',
				'preview_text' => 'An update on your connection request',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your recent connection request on {{site_name}} was not accepted. There are plenty of other members to connect with. <a href="{{site_url}}">Explore the community.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.mention',
				'subject'      => 'You were mentioned on {{site_name}}',
				'preview_text' => 'Someone mentioned you in a post',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You were mentioned in a post on {{site_name}}. <a href="{{action_url}}">See the post.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.post_reacted',
				'subject'      => 'Someone reacted to your post on {{site_name}}',
				'preview_text' => 'New reaction on your post',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your post received a reaction on {{site_name}}. <a href="{{action_url}}">See the reactions.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.post_commented',
				'subject'      => 'New comment on your post — {{site_name}}',
				'preview_text' => 'Someone commented on your post',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your post received a new comment on {{site_name}}. <a href="{{action_url}}">View the comment.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.post_shared',
				'subject'      => 'Your post was shared on {{site_name}}',
				'preview_text' => 'Someone shared your post',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your post was shared on {{site_name}}. <a href="{{action_url}}">View the share.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.space_invite',
				'subject'      => 'You have been invited to a space on {{site_name}}',
				'preview_text' => 'Join this community space',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You have been invited to join a space on {{site_name}}. <a href="{{action_url}}">View the invitation.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.space_join_requested',
				'subject'      => 'New join request for your space — {{site_name}}',
				'preview_text' => 'A member wants to join your space',
				'body_html'    => '<p>Hi {{user_name}},</p><p>A new member has requested to join your space on {{site_name}}. <a href="{{action_url}}">Review the request.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.space_request_approved',
				'subject'      => 'Your space join request was approved — {{site_name}}',
				'preview_text' => 'Welcome to the space',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your request to join a space on {{site_name}} has been approved. <a href="{{action_url}}">Visit the space.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.strike_issued',
				'subject'      => 'A moderation action has been taken on your account — {{site_name}}',
				'preview_text' => 'Important account notice',
				'body_html'    => '<p>Hi {{user_name}},</p><p>A moderation strike has been issued on your account at {{site_name}}. Please review the community guidelines to avoid further action.</p>',
			),
			array(
				'type'         => 'bn.badge_awarded',
				'subject'      => 'You earned a badge on {{site_name}}!',
				'preview_text' => 'Congratulations on your new badge',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Congratulations! You earned a new badge on {{site_name}}. <a href="{{action_url}}">View your profile.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.level_up',
				'subject'      => 'You levelled up on {{site_name}}!',
				'preview_text' => 'Your community level increased',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You have reached a new level on {{site_name}}. <a href="{{action_url}}">See your new level.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.jetonomy_reply',
				'subject'      => 'New reply to your discussion — {{site_name}}',
				'preview_text' => 'Someone replied to your discussion',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your discussion received a new reply on {{site_name}}. <a href="{{action_url}}">View the reply.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.strike_warning',
				'subject'      => 'Warning: your account has received multiple strikes — {{site_name}}',
				'preview_text' => 'You have received multiple moderation strikes',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your account on {{site_name}} has received multiple moderation strikes. Please review our community guidelines to avoid further action.</p><p>If you believe this is in error, you can contact our moderation team.</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.member_suspended',
				'subject'      => 'Your account has been suspended — {{site_name}}',
				'preview_text' => 'Your account has been suspended',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your account on {{site_name}} has been suspended. You will not be able to post or interact with the community during this period.</p><p>If you believe this was done in error, you may submit an appeal from your account page.</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.appeal_resolved',
				'subject'      => 'Your appeal has been reviewed — {{site_name}}',
				'preview_text' => 'Your moderation appeal has been resolved',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your appeal on {{site_name}} has been reviewed and <strong>{{decision}}</strong>.</p><p>If you have questions about this decision, please contact our moderation team.</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.unsuspension_confirmation',
				'subject'      => 'Your account suspension has been lifted — {{site_name}}',
				'preview_text' => 'Welcome back — your suspension has been lifted',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Good news — your account suspension on {{site_name}} has been lifted. You can post and interact with the community again.</p><p>Please review our community guidelines to keep your account in good standing.</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.new_report',
				'subject'      => 'New content report awaiting review — {{site_name}}',
				'preview_text' => 'A member reported content for moderation',
				'body_html'    => '<p>Hi {{user_name}},</p><p>A new report was filed on {{site_name}} and is waiting in the moderation queue.</p><p><a href="{{action_url}}">Review the moderation queue &rarr;</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.daily_digest',
				'subject'      => 'Your daily digest from {{site_name}}',
				'preview_text' => 'Here\'s what happened on {{site_name}} today',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Here\'s a summary of your notifications from today on <a href="{{action_url}}">{{site_name}}</a>:</p>{{notification_list}}<p><a href="{{unsubscribe_url}}">Unsubscribe from digest emails</a></p>',
			),
			array(
				'type'         => 'bn.weekly_digest',
				'subject'      => 'Your weekly digest from {{site_name}}',
				'preview_text' => 'Here\'s your weekly round-up from {{site_name}}',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Here\'s a summary of your notifications from this week on <a href="{{action_url}}">{{site_name}}</a>:</p>{{notification_list}}<p><a href="{{unsubscribe_url}}">Unsubscribe from digest emails</a></p>',
			),
			array(
				'type'         => 'bn.bulk_invite',
				'subject'      => 'You\'ve been invited to join {{site_name}}',
				'preview_text' => 'Accept your invitation and create your account',
				'body_html'    => '<p>Hi {{first_name}},</p><p>You\'ve been invited to join <strong>{{site_name}}</strong>!</p><p><a href="{{invite_url}}">Accept invitation &rarr;</a></p><p>This invitation expires in 7 days.</p>',
			),
			array(
				'type'         => 'bn.onboarding_nudge',
				'subject'      => 'Finish setting up your {{site_name}} profile',
				'preview_text' => 'Complete your onboarding to get the most out of the community',
				'body_html'    => '<p>Hi {{recipient_name}},</p><p>You\'re almost there! Complete your profile setup on <strong>{{site_name}}</strong> to connect with the community.</p><p><a href="{{onboarding_url}}">Complete your profile &rarr;</a></p>',
			),
			array(
				'type'         => 'bn.new_message',
				'subject'      => 'New message on {{site_name}}',
				'preview_text' => 'You have a new direct message',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You have a new direct message on {{site_name}}. <a href="{{action_url}}">Read it.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.media_favorited',
				'subject'      => 'Someone favorited your media on {{site_name}}',
				'preview_text' => 'Your media received a new favorite',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Someone favorited your media on {{site_name}}. <a href="{{action_url}}">View it.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
		);

		// Table name is a hardcoded constant — safe to interpolate. Values use prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $templates as $tpl ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$p}bn_email_templates` (type, subject, preview_text, body_html)
					 VALUES (%s, %s, %s, %s)",
					$tpl['type'],
					$tpl['subject'],
					$tpl['preview_text'],
					$tpl['body_html']
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Seed a couple of generic, deletable space categories so the Spaces
	 * directory has filing buckets from day one. Fresh-install only, so deleted
	 * categories never reappear.
	 *
	 * @param string $p Table prefix.
	 */
	private static function seed_starter_space_categories( string $p ): void {
		global $wpdb;

		$categories = array(
			array( 'General', 'general' ),
			array( 'Announcements', 'announcements' ),
			array( 'Introductions', 'introductions' ),
		);

		$order = 0;
		foreach ( $categories as $cat ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$p}bn_space_categories (name, slug, sort_order) VALUES (%s, %s, %d)",
					$cat[0],
					$cat[1],
					$order
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order += 10;
		}
	}

	/**
	 * Seed a couple of generic, deletable member types so the directory has
	 * editorial labels to use from day one. Fresh-install only.
	 *
	 * @param string $p Table prefix.
	 */
	private static function seed_starter_member_types( string $p ): void {
		global $wpdb;

		$types = array(
			// slug, name, color, self_select.
			array( 'contributor', 'Contributor', '#0073aa', 1 ),
			array( 'staff', 'Staff', '#5b21b6', 0 ),
		);

		$order = 0;
		foreach ( $types as $type ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$p}bn_member_types (slug, name, color, text_color, sort_order, show_in_dir, self_select) VALUES (%s, %s, %s, %s, %d, %d, %d)",
					$type[0],
					$type[1],
					$type[2],
					'#ffffff',
					$order,
					1,
					$type[3]
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order += 10;
		}
	}

	/**
	 * Seed one starter "Open Discussion" space on a fresh install.
	 *
	 * A fresh site otherwise has zero spaces, so the Spaces directory is empty
	 * and the feature looks broken on first run. Seed a single open space owned
	 * by the first administrator, with that admin as its active owner-member.
	 *
	 * Only runs when no spaces exist, so it never re-creates a space an admin
	 * later removed (no persistent flag needed — the empty-table check is the
	 * gate).
	 *
	 * @param string $p Table prefix.
	 */
	private static function seed_default_space( string $p ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_spaces = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$p}bn_spaces`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing_spaces > 0 ) {
			return;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		$owner  = (int) ( $admins[0] ?? 0 );
		if ( $owner <= 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$p . 'bn_spaces',
			array(
				'name'         => 'Open Discussion',
				'slug'         => 'open-discussion',
				'description'  => 'A community space for open conversation.',
				'type'         => 'open',
				'owner_id'     => $owner,
				'member_count' => 1,
				'created_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		$space_id = (int) $wpdb->insert_id;
		if ( $space_id > 0 ) {
			$wpdb->insert(
				$p . 'bn_space_members',
				array(
					'space_id'  => $space_id,
					'user_id'   => $owner,
					'role'      => 'owner',
					'status'    => 'active',
					'joined_at' => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Seed the five built-in profile groups and their fields.
	 *
	 * Uses INSERT IGNORE throughout so re-running the installer never destroys
	 * customised data. Fields are inserted with a subquery that resolves the
	 * group_id by group_key so the seed is order-independent.
	 *
	 * @param string $p Table prefix.
	 */
	private static function seed_default_profile_groups_and_fields( string $p ): void {
		global $wpdb;

		// ── 1. Groups ──────────────────────────────────────────────────────────

		// All five built-in groups are seeded on install (INSERT IGNORE is safe
		// on re-runs). The spec defines these as the canonical group set:
		// Basic Info (flat), Social Links (flat), Work Experience (repeater),
		// Education (repeater), Skills (flat).
		// Format: group_key, label, type, visibility, is_system, sort_order.
		$groups = array(
			array( 'basic_info', 'Basic Info', 'flat', 'public', 1, 1 ),
			array( 'social_links', 'Social Links', 'flat', 'public', 1, 2 ),
			array( 'work_experience', 'Work Experience', 'repeater', 'public', 1, 3 ),
			array( 'education', 'Education', 'repeater', 'public', 1, 4 ),
			array( 'skills', 'Skills', 'flat', 'public', 1, 5 ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $groups as $g ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$p}bn_profile_groups`
					    (group_key, label, type, visibility, is_system, sort_order)
					 VALUES (%s, %s, %s, %s, %d, %d)",
					$g[0],
					$g[1],
					$g[2],
					$g[3],
					$g[4],
					$g[5]
				)
			);
		}

		// ── 2. Fields ─────────────────────────────────────────────────────────
		// Each INSERT uses a subquery to resolve group_id by group_key.
		// Format: group_key, field_key, label, type, is_required, is_searchable, sort_order

		$fields = array(
			// basic_info.
			array( 'basic_info', 'headline', 'Headline', 'text', 0, 0, 1 ),
			array( 'basic_info', 'bio', 'Bio', 'textarea', 0, 0, 2 ),
			array( 'basic_info', 'location', 'Location', 'text', 0, 1, 3 ),
			array( 'basic_info', 'website', 'Website', 'url', 0, 0, 4 ),
			array( 'basic_info', 'pronouns', 'Pronouns', 'text', 0, 0, 5 ),
			array( 'basic_info', 'birth_date', 'Birth Date', 'date', 0, 0, 6 ),

			// social_links.
			array( 'social_links', 'social_twitter', 'Twitter / X', 'url', 0, 0, 1 ),
			array( 'social_links', 'social_linkedin', 'LinkedIn', 'url', 0, 0, 2 ),
			array( 'social_links', 'social_github', 'GitHub', 'url', 0, 0, 3 ),
			array( 'social_links', 'social_instagram', 'Instagram', 'url', 0, 0, 4 ),
			array( 'social_links', 'social_youtube', 'YouTube', 'url', 0, 0, 5 ),

			// work_experience (repeater).
			array( 'work_experience', 'work_company', 'Company', 'text', 0, 0, 1 ),
			array( 'work_experience', 'work_title', 'Job Title', 'text', 0, 0, 2 ),
			array( 'work_experience', 'work_location', 'Location', 'text', 0, 0, 3 ),
			array( 'work_experience', 'work_start_date', 'Start Date', 'date', 0, 0, 4 ),
			array( 'work_experience', 'work_end_date', 'End Date', 'date', 0, 0, 5 ),
			array( 'work_experience', 'work_current', 'Currently Working', 'boolean', 0, 0, 6 ),
			array( 'work_experience', 'work_description', 'Description', 'textarea', 0, 0, 7 ),

			// education (repeater).
			array( 'education', 'edu_institution', 'Institution', 'text', 0, 0, 1 ),
			array( 'education', 'edu_degree', 'Degree', 'text', 0, 0, 2 ),
			array( 'education', 'edu_field', 'Field of Study', 'text', 0, 0, 3 ),
			array( 'education', 'edu_start_year', 'Start Year', 'number', 0, 0, 4 ),
			array( 'education', 'edu_end_year', 'End Year', 'number', 0, 0, 5 ),
			array( 'education', 'edu_current', 'Currently Attending', 'boolean', 0, 0, 6 ),

			// skills (flat).
			array( 'skills', 'interests', 'Skills / Interests', 'text', 0, 1, 1 ),
		);

		foreach ( $fields as $f ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$p}bn_profile_fields`
					    (group_id, field_key, label, type, is_required, is_searchable, sort_order)
					 SELECT id, %s, %s, %s, %d, %d, %d
					   FROM `{$p}bn_profile_groups`
					  WHERE group_key = %s",
					$f[1],
					$f[2],
					$f[3],
					$f[4],
					$f[5],
					$f[6],
					$f[0]
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return the full set of CREATE TABLE statements.
	 *
	 * Each statement is passed individually to dbDelta() so a failure in one
	 * table does not abort the rest.
	 *
	 * @param string $p  Table prefix (e.g. 'wp_').
	 * @param string $cs Charset collation string.
	 * @return string[]
	 */
	private static function schema( string $p, string $cs ): array {
		return array(

			// ── Social Graph ───────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_follows (
				follower_id  BIGINT(20) UNSIGNED NOT NULL,
				following_id BIGINT(20) UNSIGNED NOT NULL,
				status       ENUM('approved','pending') NOT NULL DEFAULT 'approved',
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (follower_id, following_id),
				KEY          following (following_id, status),
				KEY          pending_inbox (following_id, status, created_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_connections (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				requester_id BIGINT(20) UNSIGNED NOT NULL,
				recipient_id BIGINT(20) UNSIGNED NOT NULL,
				status       ENUM('pending','accepted','declined','withdrawn') NOT NULL DEFAULT 'pending',
				note         VARCHAR(280) NOT NULL DEFAULT '',
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY   pair (requester_id, recipient_id),
				KEY          recipient_lookup (recipient_id),
				KEY          recipient_status (recipient_id, status),
				KEY          requester_status (requester_id, status)
			) {$cs};",

			"CREATE TABLE {$p}bn_blocks (
				blocker_id BIGINT(20) UNSIGNED NOT NULL,
				blocked_id BIGINT(20) UNSIGNED NOT NULL,
				type       ENUM('block','mute','restrict') NOT NULL DEFAULT 'block',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (blocker_id, blocked_id),
				KEY         blocked_type (blocked_id, type),
				KEY         blocker_type (blocker_id, type)
			) {$cs};",

			// ── Activity Feed ──────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_posts (
				id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id             BIGINT(20) UNSIGNED NOT NULL,
				space_id            BIGINT(20) UNSIGNED DEFAULT NULL,
				shared_post_id      BIGINT(20) UNSIGNED DEFAULT NULL,
				type                VARCHAR(32) NOT NULL DEFAULT 'text',
				content             LONGTEXT DEFAULT NULL,
				media_ids           JSON DEFAULT NULL,
				link_url            VARCHAR(2083) DEFAULT NULL,
				link_meta           JSON DEFAULT NULL,
				privacy             ENUM('public','followers','connections','space_members','private') NOT NULL DEFAULT 'public',
				status              ENUM('published','draft','pending','scheduled','deleted') NOT NULL DEFAULT 'published',
				reaction_count      INT UNSIGNED NOT NULL DEFAULT 0,
				comment_count       INT UNSIGNED NOT NULL DEFAULT 0,
				share_count         INT UNSIGNED NOT NULL DEFAULT 0,
				is_pinned            TINYINT(1) NOT NULL DEFAULT 0,
				is_announcement      TINYINT(1) NOT NULL DEFAULT 0,
				content_warning      TINYINT(1) NOT NULL DEFAULT 0,
				content_warning_type VARCHAR(32) DEFAULT NULL,
				site_pin_expires_at  DATETIME DEFAULT NULL,
				edited_at           DATETIME DEFAULT NULL,
				scheduled_at        DATETIME DEFAULT NULL,
				last_activity_at    DATETIME DEFAULT NULL,
				created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY         (id),
				KEY                 user_feed (user_id, status, created_at),
				KEY                 space_feed (space_id, status, created_at),
				KEY                 announcement_feed (is_announcement, status, created_at),
				KEY                 explore (privacy, created_at),
				KEY                 active_feed (privacy, status, last_activity_at),
				KEY                 scheduled (scheduled_at),
				KEY                 shared_post (shared_post_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_bookmarks (
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				post_id    BIGINT(20) UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (user_id, post_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_shares (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				post_id    BIGINT(20) UNSIGNED NOT NULL,
				content    TEXT DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  user_post (user_id, post_id),
				KEY         post_shares (post_id)
			) {$cs};",

			// Online presence. Replaces the non-sargable CAST(meta_value) scans over
			// wp_usermeta 'bn_last_active' with an indexed integer column so the member
			// directory online filter / sort and the online-count surfaces stay fast at
			// scale. last_active is a UNIX timestamp; the KEY makes range scans sargable.
			"CREATE TABLE {$p}bn_presence (
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				last_active INT(10) UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (user_id),
				KEY         last_active (last_active)
			) {$cs};",

			"CREATE TABLE {$p}bn_poll_options (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id       BIGINT(20) UNSIGNED NOT NULL,
				option_text   VARCHAR(500) NOT NULL,
				display_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
				vote_count    INT UNSIGNED NOT NULL DEFAULT 0,
				end_date      DATETIME DEFAULT NULL,
				PRIMARY KEY   (id),
				KEY           post_options (post_id, display_order)
			) {$cs};",

			"CREATE TABLE {$p}bn_poll_votes (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id    BIGINT(20) UNSIGNED NOT NULL,
				option_id  BIGINT(20) UNSIGNED NOT NULL,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				voted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  one_vote_per_user (post_id, user_id),
				KEY         option_votes (option_id)
			) {$cs};",

			// ── Spaces ─────────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_spaces (
				id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name               VARCHAR(255) NOT NULL,
				slug               VARCHAR(200) NOT NULL,
				description        TEXT DEFAULT NULL,
				category_id        BIGINT(20) UNSIGNED DEFAULT NULL,
				parent_id          BIGINT(20) UNSIGNED DEFAULT NULL,
				type               ENUM('open','private','secret') NOT NULL DEFAULT 'open',
				owner_id           BIGINT(20) UNSIGNED NOT NULL,
				member_count       INT UNSIGNED NOT NULL DEFAULT 0,
				cover_image_url    VARCHAR(500) DEFAULT NULL,
				avatar_url         VARCHAR(500) DEFAULT NULL,
				rules              TEXT NULL DEFAULT NULL,
				required_ability   VARCHAR(64) NULL DEFAULT NULL,
				accent_color       VARCHAR(16) NULL DEFAULT NULL,
				description_layout VARCHAR(32) NULL DEFAULT 'standard',
				is_archived        TINYINT(1) NOT NULL DEFAULT 0,
				archived_at        DATETIME NULL DEFAULT NULL,
				created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY        (id),
				UNIQUE KEY         slug (slug),
				KEY                owner (owner_id),
				KEY                category (category_id),
				KEY                parent (parent_id),
				KEY                is_archived (is_archived)
			) {$cs};",

			"CREATE TABLE {$p}bn_space_members (
				space_id          BIGINT(20) UNSIGNED NOT NULL,
				user_id           BIGINT(20) UNSIGNED NOT NULL,
				role              ENUM('owner','moderator','member') NOT NULL DEFAULT 'member',
				status            ENUM('active','pending','invited','banned') NOT NULL DEFAULT 'active',
				notification_pref ENUM('all','mentions_only','none') NOT NULL DEFAULT 'all',
				joined_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY       (space_id, user_id),
				KEY               user_role (user_id, role),
				KEY               user_status (user_id, status),
				KEY               space_status (space_id, status, joined_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_space_categories (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name        VARCHAR(100) NOT NULL,
				slug        VARCHAR(100) NOT NULL,
				description TEXT DEFAULT NULL,
				color       VARCHAR(7) NOT NULL DEFAULT '#0073aa',
				text_color  VARCHAR(7) NOT NULL DEFAULT '#ffffff',
				icon_svg    MEDIUMTEXT NULL,
				show_in_dir TINYINT(1) NOT NULL DEFAULT 1,
				sort_order  INT NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY  slug (slug)
			) {$cs};",

			// Per-space metadata — WP-meta-shaped so the native metadata API
			// (get/add/update/delete_metadata, register_meta, WP_Meta_Query, meta
			// cache) works against it once $wpdb->bn_spacemeta is aliased. meta_type
			// is 'bn_space', so WP derives the id column as bn_space_id. This is the
			// extensibility substrate: every new per-space attribute is a meta row,
			// never a new column or an autoloaded option.
			"CREATE TABLE {$p}bn_space_meta (
				meta_id     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				bn_space_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				meta_key    VARCHAR(255) DEFAULT NULL,
				meta_value  LONGTEXT DEFAULT NULL,
				PRIMARY KEY (meta_id),
				KEY         bn_space_id (bn_space_id),
				KEY         meta_key (meta_key(191))
			) {$cs};",

			// ── Notifications + Email ──────────────────────────────────────────

			"CREATE TABLE {$p}bn_notifications (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				recipient_id BIGINT(20) UNSIGNED NOT NULL,
				sender_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				type         VARCHAR(64) NOT NULL,
				object_type  VARCHAR(32) DEFAULT NULL,
				object_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				group_key    VARCHAR(128) DEFAULT NULL,
				group_count  INT UNSIGNED NOT NULL DEFAULT 1,
				data         JSON DEFAULT NULL,
				is_read      TINYINT(1) NOT NULL DEFAULT 0,
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY          bell (recipient_id, is_read, created_at),
				KEY          recipient_group (recipient_id, group_key)
			) {$cs};",

			"CREATE TABLE {$p}bn_notification_prefs (
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				type       VARCHAR(64) NOT NULL,
				on_site    TINYINT(1) NOT NULL DEFAULT 1,
				email_freq ENUM('immediate','daily','weekly','off') NOT NULL DEFAULT 'immediate',
				PRIMARY KEY (user_id, type)
			) {$cs};",

			"CREATE TABLE {$p}bn_email_templates (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				type         VARCHAR(64) NOT NULL,
				subject      VARCHAR(255) NOT NULL,
				preview_text VARCHAR(255) DEFAULT NULL,
				body_html    LONGTEXT NOT NULL,
				enabled      TINYINT(1) NOT NULL DEFAULT 1,
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  type (type)
			) {$cs};",

			"CREATE TABLE {$p}bn_email_log (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				type        VARCHAR(64) NOT NULL,
				digest_date DATE DEFAULT NULL,
				sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         user_type (user_id, type, digest_date)
			) {$cs};",

			"CREATE TABLE {$p}bn_verify_tokens (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				token      VARCHAR(64) NOT NULL,
				type       VARCHAR(32) NOT NULL DEFAULT 'email_verify',
				expires_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  token (token),
				KEY         user_type (user_id, type)
			) {$cs};",

			// ── Reactions + Comments ───────────────────────────────────────────

			"CREATE TABLE {$p}bn_reactions (
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				emoji       VARCHAR(32) NOT NULL DEFAULT 'like',
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (user_id, object_type, object_id),
				KEY         object_reactions (object_type, object_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_comments (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				parent_id   BIGINT(20) UNSIGNED DEFAULT NULL,
				content     TEXT NOT NULL,
				is_edited   TINYINT(1) NOT NULL DEFAULT 0,
				is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         thread (object_type, object_id, parent_id, created_at),
				KEY         user (user_id),
				KEY         deleted (is_deleted)
			) {$cs};",

			// ── Hashtags ───────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_hashtags (
				id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name           VARCHAR(100) NOT NULL,
				slug           VARCHAR(100) NOT NULL,
				post_count     INT UNSIGNED NOT NULL DEFAULT 0,
				follower_count INT UNSIGNED NOT NULL DEFAULT 0,
				created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY    (id),
				UNIQUE KEY     slug (slug)
			) {$cs};",

			"CREATE TABLE {$p}bn_post_hashtags (
				post_id     BIGINT(20) UNSIGNED NOT NULL,
				object_type VARCHAR(32) NOT NULL DEFAULT 'post',
				hashtag_id  BIGINT(20) UNSIGNED NOT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (post_id, object_type, hashtag_id),
				KEY         hashtag_feed (hashtag_id, created_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_hashtag_follows (
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				hashtag_id BIGINT(20) UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (user_id, hashtag_id),
				KEY         hashtag (hashtag_id)
			) {$cs};",

			// ── Profiles ───────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_profile_groups (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				group_key        VARCHAR(100) NOT NULL,
				label            VARCHAR(255) NOT NULL,
				type             ENUM('flat','repeater') NOT NULL DEFAULT 'flat',
				visibility       ENUM('public','followers','connections','private') NOT NULL DEFAULT 'public',
				is_system        TINYINT(1) NOT NULL DEFAULT 0,
				sort_order       INT NOT NULL DEFAULT 0,
				type_restriction VARCHAR(100) DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY  group_key (group_key),
				KEY         type_res (type_restriction)
			) {$cs};",

			"CREATE TABLE {$p}bn_profile_fields (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				group_id      BIGINT(20) UNSIGNED NOT NULL,
				field_key     VARCHAR(100) NOT NULL,
				label         VARCHAR(255) NOT NULL,
				type          VARCHAR(32) NOT NULL DEFAULT 'text',
				options       JSON DEFAULT NULL,
				is_required   TINYINT(1) NOT NULL DEFAULT 0,
				is_searchable TINYINT(1) NOT NULL DEFAULT 0,
				show_on_register TINYINT(1) NOT NULL DEFAULT 0,
				visibility    ENUM('public','followers','connections','private') NOT NULL DEFAULT 'public',
				sort_order    INT NOT NULL DEFAULT 0,
				PRIMARY KEY   (id),
				UNIQUE KEY    field_key (field_key),
				KEY           group_idx (group_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_profile_values (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id          BIGINT(20) UNSIGNED NOT NULL,
				field_id         BIGINT(20) UNSIGNED NOT NULL,
				entry_index      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				value            LONGTEXT DEFAULT NULL,
				entry_visibility ENUM('public','followers','connections','private') DEFAULT NULL,
				PRIMARY KEY      (id),
				UNIQUE KEY       user_field_entry (user_id, field_id, entry_index),
				KEY              field_idx (field_id),
				KEY              user_idx (user_id)
			) {$cs};",

			// ── Search Index ───────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_search_index (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				title       VARCHAR(500) NOT NULL DEFAULT '',
				content     LONGTEXT DEFAULT NULL,
				author_id   BIGINT(20) UNSIGNED DEFAULT NULL,
				space_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				visibility  ENUM('public','private') NOT NULL DEFAULT 'public',
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  object (object_type, object_id),
				KEY         visibility_type (visibility, object_type),
				KEY         author (author_id),
				KEY         space (space_id),
				KEY         updated_order (updated_at)
			) {$cs};",

			// ── Webhooks ────────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_webhook_log (
				id         BIGINT(20) NOT NULL AUTO_INCREMENT,
				source     VARCHAR(100) NOT NULL DEFAULT '',
				action     VARCHAR(100) NOT NULL DEFAULT '',
				user_id    BIGINT(20) NOT NULL DEFAULT 0,
				payload    LONGTEXT NOT NULL,
				status     VARCHAR(20) NOT NULL DEFAULT 'success',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY action (action),
				KEY user_id (user_id),
				KEY created_at (created_at)
			) {$cs};",

			// ── Activity Log ───────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_activity_log (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				action      VARCHAR(64) NOT NULL,
				object_type VARCHAR(32) DEFAULT NULL,
				object_id   BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         user_action (user_id, action, created_at),
				KEY         created_at (created_at)
			) {$cs};",

			// ── Moderation ─────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_reports (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				reporter_id BIGINT(20) UNSIGNED NOT NULL,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				reason      ENUM('spam','harassment','misinformation','inappropriate','fake','impersonation','other') NOT NULL DEFAULT 'other',
				notes       TEXT DEFAULT NULL,
				status      ENUM('pending','dismissed','escalated','resolved') NOT NULL DEFAULT 'pending',
				resolved_by BIGINT(20) UNSIGNED DEFAULT NULL,
				resolved_at DATETIME DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				space_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY  one_per_reporter (reporter_id, object_type, object_id),
				KEY         object_status (object_type, object_id, status),
				KEY         status_date (status, created_at),
				KEY         space (space_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_mod_log (
				id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				actor_id       BIGINT(20) UNSIGNED NOT NULL,
				action         VARCHAR(64) NOT NULL,
				object_type    VARCHAR(32) DEFAULT NULL,
				object_id      BIGINT(20) UNSIGNED DEFAULT NULL,
				target_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
				note           TEXT DEFAULT NULL,
				space_id       BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY    (id),
				KEY            actor (actor_id),
				KEY            target_user (target_user_id),
				KEY            created (created_at),
				KEY            space (space_id),
				KEY            object (object_type, object_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_user_strikes (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				issued_by   BIGINT(20) UNSIGNED NOT NULL,
				reason      TEXT DEFAULT NULL,
				is_reversed TINYINT(1) NOT NULL DEFAULT 0,
				reversed_by BIGINT(20) UNSIGNED DEFAULT NULL,
				reversed_at DATETIME DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         user_active (user_id, is_reversed),
				KEY         issued_by (issued_by)
			) {$cs};",

			// ── Onboarding + Invites ───────────────────────────────────────────

			"CREATE TABLE {$p}bn_invites (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				email      VARCHAR(200) NOT NULL,
				first_name VARCHAR(100) DEFAULT NULL,
				space_id   BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				token      VARCHAR(64) NOT NULL,
				status     ENUM('pending','registered','bounced') NOT NULL DEFAULT 'pending',
				expires_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  token (token),
				KEY         email (email),
				KEY         status_expires (status, expires_at)
			) {$cs};",

			// ── Moderation — Suspensions + Appeals + Space Bans ──────────────────

			"CREATE TABLE {$p}bn_user_suspensions (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id      BIGINT(20) UNSIGNED NOT NULL,
				suspended_by BIGINT(20) UNSIGNED NOT NULL,
				reason       TEXT DEFAULT NULL,
				duration_days INT UNSIGNED DEFAULT NULL,
				hide_posts   TINYINT(1) NOT NULL DEFAULT 0,
				expires_at   DATETIME DEFAULT NULL,
				lifted_at    DATETIME DEFAULT NULL,
				lifted_by    BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY          user_active (user_id, expires_at),
				KEY          active_check (lifted_at, expires_at, user_id),
				KEY          suspended_by (suspended_by)
			) {$cs};",

			"CREATE TABLE {$p}bn_appeals (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				suspension_id BIGINT(20) UNSIGNED NOT NULL,
				strike_id     BIGINT(20) DEFAULT NULL,
				user_id       BIGINT(20) UNSIGNED NOT NULL,
				message       TEXT NOT NULL,
				status        ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
				reviewed_by   BIGINT(20) UNSIGNED DEFAULT NULL,
				reviewer_note TEXT DEFAULT NULL,
				reviewed_at   DATETIME DEFAULT NULL,
				admin_note    TEXT DEFAULT NULL,
				resolved_by   BIGINT(20) UNSIGNED DEFAULT NULL,
				resolved_at   DATETIME DEFAULT NULL,
				created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY   (id),
				KEY           user_status (user_id, status),
				KEY           suspension (suspension_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_space_bans (
				space_id   BIGINT(20) UNSIGNED NOT NULL,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				banned_by  BIGINT(20) UNSIGNED NOT NULL,
				reason     TEXT DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (space_id, user_id),
				KEY         user_bans (user_id)
			) {$cs};",

			// ── Outbound Webhooks ──────────────────────────────────────────────

			// ── Member Types ───────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_member_types (
				id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
				slug        VARCHAR(100)    NOT NULL,
				name        VARCHAR(100)    NOT NULL,
				description TEXT            DEFAULT NULL,
				color       VARCHAR(7)      NOT NULL DEFAULT '#0073aa',
				text_color  VARCHAR(7)      NOT NULL DEFAULT '#ffffff',
				icon_svg    MEDIUMTEXT      DEFAULT NULL,
				sort_order  SMALLINT        NOT NULL DEFAULT 0,
				show_in_dir TINYINT(1)      NOT NULL DEFAULT 1,
				self_select TINYINT(1)      NOT NULL DEFAULT 0,
				created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  uq_slug (slug),
				KEY         idx_sort (sort_order)
			) {$cs};",

			"CREATE TABLE {$p}bn_member_type_assignments (
				id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				type_id     INT UNSIGNED     NOT NULL,
				assigned_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				assigned_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  uq_user_type (user_id, type_id),
				KEY         idx_user_id (user_id),
				KEY         idx_type_id (type_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_outbound_webhooks (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				label      VARCHAR(100) NOT NULL,
				url        VARCHAR(2083) NOT NULL,
				secret     VARCHAR(64) DEFAULT NULL,
				events     JSON DEFAULT NULL,
				is_active  TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id)
			) {$cs};",

			"CREATE TABLE {$p}bn_outbound_webhook_log (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				webhook_id    BIGINT(20) UNSIGNED NOT NULL,
				event         VARCHAR(64) NOT NULL,
				payload       JSON DEFAULT NULL,
				response_code SMALLINT UNSIGNED DEFAULT NULL,
				response_body TEXT DEFAULT NULL,
				status        ENUM('success','error') NOT NULL DEFAULT 'success',
				attempt       TINYINT UNSIGNED NOT NULL DEFAULT 1,
				created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY   (id),
				KEY           webhook_event (webhook_id, event, created_at),
				KEY           status_date (status, created_at)
			) {$cs};",

		);
	}

	/**
	 * Write the BuddyNext isolation mu-plugin to wp-content/mu-plugins/.
	 *
	 * Skips the write when the file already exists with identical content so
	 * that repeated activations (e.g. during automated upgrades) are cheap.
	 *
	 * @return void
	 */
	public static function install_mu_plugin(): void {
		$dest    = WP_CONTENT_DIR . '/mu-plugins/' . self::MU_PLUGIN_SLUG;
		$content = self::mu_plugin_content();

		// Record the content signature so maybe_refresh_mu_plugin() can detect
		// drift cheaply (no file read) and rewrite after any plugin update that
		// changes the generated source — without relying on a SCHEMA_VERSION bump.
		update_option( self::MU_PLUGIN_SIG_OPTION, md5( $content ), false );

		if ( file_exists( $dest ) && file_get_contents( $dest ) === $content ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return;
		}

		wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins/' );
		file_put_contents( $dest, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Keep the on-disk isolation mu-plugin in sync with the plugin's generated
	 * source. Hooked on admin_init: compares a stored signature of the expected
	 * content (no file read on the steady state) and rewrites only when the
	 * generated mu-plugin changed — e.g. after an update that adds an integration
	 * to the allow-list. This is what guarantees the plugin always lays down the
	 * RIGHT mu-plugin on update, independent of any version bump.
	 *
	 * @return void
	 */
	public static function maybe_refresh_mu_plugin(): void {
		if ( get_option( self::MU_PLUGIN_SIG_OPTION ) === md5( self::mu_plugin_content() ) ) {
			return;
		}
		self::install_mu_plugin();
	}

	/**
	 * Delete the BuddyNext isolation mu-plugin on deactivation.
	 *
	 * @return void
	 */
	public static function remove_mu_plugin(): void {
		$dest = WP_CONTENT_DIR . '/mu-plugins/' . self::MU_PLUGIN_SLUG;

		if ( file_exists( $dest ) ) {
			wp_delete_file( $dest );
		}
	}

	/**
	 * Return the PHP source for the buddynext-isolation mu-plugin.
	 *
	 * Kept as a private method so the content is compiled once and can be
	 * compared byte-for-byte in install_mu_plugin() to avoid unnecessary writes.
	 *
	 * @return string
	 */
	private static function mu_plugin_content(): string {
		// phpcs:disable Squiz.Strings.DoubleQuoteUsage.NotRequired
		return <<<'MUPLUGIN'
<?php
/**
 * Plugin Name: BuddyNext Isolation
 * Description: Strips non-essential plugins on BuddyNext front-end routes to save 20-40 MB per request.
 * Version:     1.0.0
 * Author:      Wbcom Designs
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

// No-op on admin pages and WP-CLI runs — isolation is for front-end only.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * Determine whether the current HTTP request targets a BuddyNext front-end route.
 *
 * Reads the autoloaded buddynext_slug_* options via the options API, so the
 * lookup is served from the single alloptions cache WordPress loads each request
 * (no extra query) and from the object cache on Redis/Memcached sites. A static
 * guard ensures the work runs at most once per request.
 *
 * @return bool
 */
function buddynext_mu_is_bn_request() {
	static $result = null;

	if ( null !== $result ) {
		return $result;
	}

	// Parse the bare path from REQUEST_URI and strip the leading slash.
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw comparison only, never output.
	$path        = ltrim( strtok( $request_uri, '?' ), '/' );

	if ( '' === $path ) {
		$result = false;
		return false;
	}

	// Read the six hub slugs via the options API. They are autoloaded, so this
	// is served from the single alloptions cache WordPress already loads each
	// request (no extra query) and from the object cache on Redis/Memcached
	// sites. The option_active_plugins filter below is registered only AFTER
	// this function returns, so reading options here cannot recurse into it.
	$slug_defaults = array(
		'buddynext_slug_activity'      => 'activity',
		'buddynext_slug_people'        => 'members',
		'buddynext_slug_spaces'        => 'spaces',
		'buddynext_slug_messages'      => 'messages',
		'buddynext_slug_notifications' => 'notifications',
		'buddynext_slug_auth'          => 'login',
	);

	foreach ( $slug_defaults as $option_name => $default_slug ) {
		$slug = trim( (string) get_option( $option_name, $default_slug ) );
		if ( '' === $slug ) {
			$slug = $default_slug;
		}

		// Match the first path segment exactly — not a bare prefix — so a page
		// like /membership/ is not isolated by the 'members' slug.
		if ( $path === $slug || 0 === strpos( $path, $slug . '/' ) ) {
			$result = true;
			return true;
		}
	}

	$result = false;
	return false;
}

if ( buddynext_mu_is_bn_request() ) {
	/**
	 * Strip all non-whitelisted plugins before WordPress loads them.
	 *
	 * The whitelist is filterable so site owners can add cache or security
	 * plugins that must remain active on every request.
	 *
	 * @param string[]|mixed $plugins List of active plugin paths from wp_options.
	 * @return string[] Filtered list.
	 */
	add_filter(
		'option_active_plugins',
		static function ( $plugins ) {
			if ( ! is_array( $plugins ) ) {
				return $plugins;
			}

			// Self-guard: if BuddyNext itself is not active, do nothing. A
			// mu-plugin left behind after BuddyNext is deactivated must never
			// strip plugins on /members/, /spaces/ etc. (it matches those paths
			// by slug and cannot tell BuddyNext is gone) — that would break the
			// site. With BuddyNext inactive the mu-plugin is inert.
			if ( ! in_array( 'buddynext/buddynext.php', $plugins, true ) ) {
				return $plugins;
			}

			// Essentials that must ALWAYS survive — BuddyNext + Pro, operational
			// plugins, AND the full in-house integration family. The family is
			// hard-coded here (not left to the option below) so Portfolio tabs / nav
			// appear even on the very first request, or when a tester's mu-plugin
			// file is stale, or before PluginIsolation has populated the option.
			// Keep in sync with PluginIsolation::CORE_INTEGRATIONS + the Pro
			// buddynext_isolation_plugins filter.
			$essentials = array(
				'buddynext/buddynext.php',
				'buddynext-pro/buddynext-pro.php',
				// Core integrations (Free first-class).
				'wpmediaverse/wpmediaverse.php',
				'wpmediaverse-pro/wpmediaverse-pro.php',
				'jetonomy/jetonomy.php',
				'jetonomy-pro/jetonomy-pro.php',
				'wb-gamification/wb-gamification.php',
				// Pro application-layer integrations (Portfolio panels).
				'wp-career-board/wp-career-board.php',
				'wb-listora/wb-listora.php',
				'learnomy/learnomy.php',
				'learnomy-pro/learnomy-pro.php',
				// Operational.
				'redis-cache/redis-cache.php',
				'query-monitor/query-monitor.php',
			);

			// Plus any dynamic / 3rd-party additions BuddyNext mirrors into the
			// `buddynext_isolation_plugins` option. Read via the options API so it
			// rides the object cache (Redis/Memcached) instead of a raw query. The
			// hard-coded family above is the floor; this merge only adds extras a
			// filter contributed at runtime.
			$stored       = get_option( 'buddynext_isolation_plugins', '' );
			$integrations = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
			if ( ! is_array( $integrations ) ) {
				$integrations = array();
			}

			$whitelist = apply_filters(
				'buddynext_isolation_whitelist',
				array_values( array_unique( array_merge( $essentials, $integrations ) ) )
			);

			return array_values( array_intersect( $plugins, $whitelist ) );
		}
	);
}
MUPLUGIN;
		// phpcs:enable Squiz.Strings.DoubleQuoteUsage.NotRequired
	}

	/**
	 * Create the six BuddyNext hub pages (activity, members, spaces, messages,
	 * notifications, auth/login) if they do not already exist.
	 *
	 * Safe to call on every activation — existing published pages are left
	 * untouched.
	 *
	 * @return void
	 */
	public static function create_hub_pages(): void {
		$hubs = array(
			'activity'      => array(
				'option_slug' => 'buddynext_slug_activity',
				'option_page' => 'buddynext_page_activity',
				'title'       => __( 'Activity', 'buddynext' ),
				'shortcode'   => '[buddynext_activity]',
				'default'     => 'activity',
			),
			'people'        => array(
				'option_slug' => 'buddynext_slug_people',
				'option_page' => 'buddynext_page_people',
				'title'       => __( 'Members', 'buddynext' ),
				'shortcode'   => '[buddynext_people]',
				'default'     => 'members',
			),
			'spaces'        => array(
				'option_slug' => 'buddynext_slug_spaces',
				'option_page' => 'buddynext_page_spaces',
				'title'       => __( 'Spaces', 'buddynext' ),
				'shortcode'   => '[buddynext_spaces]',
				'default'     => 'spaces',
			),
			'messages'      => array(
				'option_slug' => 'buddynext_slug_messages',
				'option_page' => 'buddynext_page_messages',
				'title'       => __( 'Messages', 'buddynext' ),
				'shortcode'   => '[buddynext_messages]',
				'default'     => 'messages',
			),
			'notifications' => array(
				'option_slug' => 'buddynext_slug_notifications',
				'option_page' => 'buddynext_page_notifications',
				'title'       => __( 'Notifications', 'buddynext' ),
				'shortcode'   => '[buddynext_notifications]',
				'default'     => 'notifications',
			),
			'auth'          => array(
				'option_slug' => 'buddynext_slug_auth',
				'option_page' => 'buddynext_page_auth',
				'title'       => __( 'Login', 'buddynext' ),
				'shortcode'   => '[buddynext_auth]',
				'default'     => 'login',
			),
		);

		foreach ( $hubs as $hub ) {
			$existing_id = (int) get_option( $hub['option_page'], 0 );
			if ( $existing_id > 0 && 'publish' === get_post_status( $existing_id ) ) {
				continue;
			}

			$slug = (string) get_option( $hub['option_slug'], $hub['default'] );
			if ( '' === $slug ) {
				$slug = $hub['default'];
			}

			$page_id = wp_insert_post(
				array(
					'post_title'     => $hub['title'],
					'post_name'      => $slug,
					'post_content'   => $hub['shortcode'],
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);

			if ( $page_id > 0 ) {
				update_option( $hub['option_page'], $page_id );
				update_option( $hub['option_slug'], $slug );
			}
		}

		flush_rewrite_rules();
	}
}
