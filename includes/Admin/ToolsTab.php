<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Settings → Tools.
 *
 * Site-maintenance utilities every community plugin is expected to ship:
 *
 *   Repair counters — recompute the denormalised counts (space members, member
 *                     follow counts, post reactions/comments) that can drift
 *                     after bulk imports, crashes, or manual DB edits.
 *   Flush caches    — clear the BuddyNext object-cache group.
 *   Export / Import — download every BuddyNext option as JSON and restore it on
 *                     another site (staging → production), whitelisted to known
 *                     keys so an import can't inject arbitrary options.
 *   Demo data       — seed / remove the demo community (shared engine; the
 *                     buttons post to DemoAdmin's handlers).
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use BuddyNext\Core\CacheService;
use BuddyNext\Core\CounterService;
use BuddyNext\Core\CronScheduler;
use BuddyNext\Demo\DemoAdmin;

/**
 * Renders the Tools tab and handles its maintenance actions.
 */
class ToolsTab {

	/**
	 * Register hooks + the Tools tab.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_tools_recount', array( $this, 'handle_recount' ) );
		add_action( 'admin_post_bn_tools_flush_cache', array( $this, 'handle_flush_cache' ) );
		add_action( 'admin_post_bn_tools_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_bn_tools_import', array( $this, 'handle_import' ) );

		AdminHub::register_tab(
			'settings',
			'tools',
			__( 'Tools', 'buddynext' ),
			array( $this, 'render_page' ),
			array( 'group' => __( 'Advanced', 'buddynext' ) )
		);
	}

	/**
	 * Render the Tools tab.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$done = isset( $_GET['bn_tools'] ) ? sanitize_key( wp_unslash( (string) $_GET['bn_tools'] ) ) : '';
		if ( '' !== $done ) {
			$msg = $this->result_message( $done );
			if ( '' !== $msg ) {
				$is_error = in_array( $done, array( 'import_failed', 'import_empty' ), true );
				printf(
					'<div class="notice %s is-dismissible"><p>%s</p></div>',
					$is_error ? 'notice-error' : 'notice-success',
					esc_html( $msg )
				);
			}
		}

		$this->render_background_tasks_section();
		$this->render_repair_section();
		$this->render_cache_section();
		$this->render_export_import_section();

		// Demo data lives here too — same engine, rendered by its own class.
		( new DemoAdmin() )->render_section();
	}

	// ── Sections ────────────────────────────────────────────────────────────

	/**
	 * Background tasks (Action Scheduler) health + system-cron guidance.
	 *
	 * Background jobs run automatically on a normal install. This surfaces a
	 * clear, actionable note ONLY when the site has WP-Cron disabled and tasks
	 * are piling up overdue — the one case where the admin must add a server
	 * cron for digests, cleanups, scheduled posts, and emails to keep running.
	 *
	 * @return void
	 */
	private function render_background_tasks_section(): void {
		$health   = CronScheduler::health();
		$cron_url = site_url( 'wp-cron.php?doing_wp_cron' );
		$command  = sprintf( "*/5 * * * * wget -q -O - '%s' >/dev/null 2>&1", $cron_url );
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Background tasks', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php esc_html_e( 'Digests, cleanups, scheduled posts, and emails run on Action Scheduler in the background. On a normal site these run automatically — no setup needed.', 'buddynext' ); ?>
				</p>

				<?php if ( $health['stalled'] ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<strong><?php esc_html_e( 'Background tasks are not running.', 'buddynext' ); ?></strong>
							<?php
							printf(
								/* translators: %d: number of overdue scheduled tasks. */
								esc_html__( 'WordPress cron is disabled on this site (DISABLE_WP_CRON) and %d scheduled task(s) are overdue. Add a server-level cron job so the queue is processed:', 'buddynext' ),
								(int) $health['overdue']
							);
							?>
						</p>
						<p><code><?php echo esc_html( $command ); ?></code></p>
						<p class="description">
							<?php esc_html_e( 'Run that on your server (or ask your host) to fire WordPress cron every 5 minutes. This is a server change, not a plugin setting — BuddyNext never disables WordPress cron for you.', 'buddynext' ); ?>
						</p>
					</div>
				<?php elseif ( $health['wp_cron_disabled'] ) : ?>
					<p class="bn-av-section-desc">
						<?php esc_html_e( 'WordPress cron is disabled on this site. Background tasks are keeping up, which means a system cron is already driving them. No action needed.', 'buddynext' ); ?>
					</p>
				<?php else : ?>
					<p class="bn-av-section-desc">
						<?php
						if ( $health['overdue'] > 0 ) {
							printf(
								/* translators: %d: number of tasks waiting to run. */
								esc_html__( 'Status: running automatically. %d task(s) are waiting and will process on the next site activity.', 'buddynext' ),
								(int) $health['overdue']
							);
						} else {
							esc_html_e( 'Status: running automatically. No overdue tasks.', 'buddynext' );
						}
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Repair / recount counters.
	 *
	 * @return void
	 */
	private function render_repair_section(): void {
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Repair counters', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php esc_html_e( 'Recompute cached counts that can drift after imports or manual database changes. Safe to run any time; may take a moment on large communities.', 'buddynext' ); ?>
				</p>
				<div class="bn-mod-actions">
					<?php
					$this->recount_button( 'space_members', __( 'Recount space members', 'buddynext' ) );
					$this->recount_button( 'follow_counts', __( 'Recount follow counts', 'buddynext' ) );
					$this->recount_button( 'post_engagement', __( 'Recount post reactions & comments', 'buddynext' ) );
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Cache flush.
	 *
	 * @return void
	 */
	private function render_cache_section(): void {
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Caches', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php esc_html_e( 'Clear the BuddyNext object-cache group. Use after changing settings if a stale value persists.', 'buddynext' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bn_tools_flush_cache">
					<?php wp_nonce_field( 'bn_tools_flush_cache' ); ?>
					<button type="submit" class="bn-btn" data-variant="secondary"><?php esc_html_e( 'Flush BuddyNext cache', 'buddynext' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings export + import.
	 *
	 * @return void
	 */
	private function render_export_import_section(): void {
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Export / Import settings', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php esc_html_e( 'Download all BuddyNext settings as a JSON file, or restore them from a previous export. Only BuddyNext option keys are touched.', 'buddynext' ); ?>
				</p>
				<div class="bn-mod-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_tools_export">
						<?php wp_nonce_field( 'bn_tools_export' ); ?>
						<button type="submit" class="bn-btn" data-variant="secondary"><?php esc_html_e( 'Export settings', 'buddynext' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="bn_tools_import">
						<?php wp_nonce_field( 'bn_tools_import' ); ?>
						<input type="file" name="bn_settings_file" accept="application/json,.json" required>
						<button type="submit" class="bn-btn" data-variant="secondary"><?php esc_html_e( 'Import settings', 'buddynext' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	/**
	 * Recompute a family of counters.
	 *
	 * @return void
	 */
	public function handle_recount(): void {
		$this->guard( 'bn_tools_recount' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard() via check_admin_referer().
		$what    = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( (string) $_POST['what'] ) ) : '';
		$counter = new CounterService();
		global $wpdb;

		switch ( $what ) {
			case 'space_members':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bn_spaces" ) as $id ) {
					$counter->recount_space_members( (int) $id );
				}
				break;
			case 'follow_counts':
				foreach ( (array) get_users( array( 'fields' => 'ID' ) ) as $id ) {
					$counter->recount_follow_counts( (int) $id );
				}
				break;
			case 'post_engagement':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bn_posts" ) as $id ) {
					$counter->recount_post_reactions( (int) $id );
					$counter->recount_post_comments( (int) $id );
				}
				break;
		}

		$this->redirect_back( 'recounted' );
	}

	/**
	 * Flush the BuddyNext cache group.
	 *
	 * @return void
	 */
	public function handle_flush_cache(): void {
		$this->guard( 'bn_tools_flush_cache' );
		( new CacheService() )->forget_group();
		$this->redirect_back( 'flushed' );
	}

	/**
	 * Stream a JSON export of every BuddyNext option.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		$this->guard( 'bn_tools_export' );

		$payload = array(
			'plugin'      => 'buddynext',
			'version'     => defined( 'BUDDYNEXT_VERSION' ) ? BUDDYNEXT_VERSION : '',
			'exported_at' => gmdate( 'c' ),
			'options'     => $this->collect_options(),
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="buddynext-settings.json"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Import settings from an uploaded JSON export.
	 *
	 * Only keys that already exist as BuddyNext options are written, so a
	 * tampered file cannot inject arbitrary options.
	 *
	 * @return void
	 */
	public function handle_import(): void {
		$this->guard( 'bn_tools_import' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- Nonce verified in guard() via check_admin_referer(); tmp_name read as-is for finfo/upload checks.
		$tmp = isset( $_FILES['bn_settings_file']['tmp_name'] ) ? (string) $_FILES['bn_settings_file']['tmp_name'] : '';
		$err = isset( $_FILES['bn_settings_file']['error'] ) ? (int) $_FILES['bn_settings_file']['error'] : UPLOAD_ERR_NO_FILE;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing

		if ( UPLOAD_ERR_OK !== $err || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$this->redirect_back( 'import_failed' );
		}

		// Validate the upload's MIME type before reading it. finfo inspects the
		// file's own bytes, so a renamed binary cannot pass as a settings export
		// (the browser-sent type is never trusted). The JSON structure is still
		// validated below — this is defence in depth, matching the CSV-invite
		// import guard in InviteController.
		$finfo    = finfo_open( FILEINFO_MIME_TYPE );
		$detected = $finfo ? (string) finfo_file( $finfo, $tmp ) : '';
		if ( $finfo ) {
			finfo_close( $finfo );
		}
		$allowed_mime = array( 'application/json', 'text/json', 'text/plain' );
		if ( '' !== $detected && ! in_array( $detected, $allowed_mime, true ) ) {
			$this->redirect_back( 'import_failed' );
		}

		$raw  = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['options'] ) || ! is_array( $data['options'] ) ) {
			$this->redirect_back( 'import_failed' );
		}

		// Whitelist: only overwrite keys that already exist (known BN options).
		$known   = $this->collect_options();
		$applied = 0;
		foreach ( $data['options'] as $key => $value ) {
			$key = (string) $key;
			if ( array_key_exists( $key, $known ) ) {
				update_option( $key, $value );
				++$applied;
			}
		}

		( new CacheService() )->forget_group();
		$this->redirect_back( $applied > 0 ? 'imported' : 'import_empty' );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Every BuddyNext option (prefix buddynext_ or bn_) as key => value.
	 *
	 * @return array<string,mixed>
	 */
	private function collect_options(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE 'buddynext\_%' OR option_name LIKE 'bn\_%'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Never export the demo manifest or transient-ish internal bookkeeping.
		$skip = array( 'bn_demo_manifest' );

		$out = array();
		foreach ( (array) $names as $name ) {
			$name = (string) $name;
			if ( in_array( $name, $skip, true ) || $this->is_sensitive_option( $name ) ) {
				continue;
			}
			$out[ $name ] = get_option( $name );
		}
		return $out;
	}

	/**
	 * Whether an option holds a secret that must never be written to a settings
	 * export (license keys, webhook/Stripe secrets, API keys, push credentials).
	 * Pattern-based so new secret-bearing options are covered automatically.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function is_sensitive_option( string $name ): bool {
		$needles   = array( 'secret', 'api_key', 'apikey', 'license_key', 'private_key', 'access_token', 'service_account', 'password', '_salt' );
		$sensitive = false;
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $name, $needle ) ) {
				$sensitive = true;
				break;
			}
		}

		/**
		 * Filter whether a BuddyNext option is treated as sensitive (excluded from
		 * the settings export).
		 *
		 * @param bool   $sensitive Whether the option is sensitive.
		 * @param string $name      Option name.
		 */
		return (bool) apply_filters( 'buddynext_export_option_is_sensitive', $sensitive, $name );
	}

	/**
	 * Render a single recount button form.
	 *
	 * @param string $what  Recount family key.
	 * @param string $label Button label.
	 * @return void
	 */
	private function recount_button( string $what, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-mod-action-form">
			<input type="hidden" name="action" value="bn_tools_recount">
			<input type="hidden" name="what" value="<?php echo esc_attr( $what ); ?>">
			<?php wp_nonce_field( 'bn_tools_recount' ); ?>
			<button type="submit" class="bn-btn" data-variant="secondary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Capability + nonce guard for handlers.
	 *
	 * @param string $action Nonce/action name.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the Tools tab with a result flag.
	 *
	 * @param string $result Result slug.
	 * @return void
	 */
	private function redirect_back( string $result ): void {
		// Use the canonical Hub URL builder (origin section 'settings', slug
		// 'tools') so the redirect honours the IA placement map. Hardcoding
		// page=buddynext sent the user to General Settings whenever the Tools tab
		// was relocated to another section's page.
		wp_safe_redirect(
			AdminHub::tab_url( 'settings', 'tools', array( 'bn_tools' => $result ) )
		);
		exit;
	}

	/**
	 * Map a result flag to a human message.
	 *
	 * @param string $result Result slug.
	 * @return string
	 */
	private function result_message( string $result ): string {
		switch ( $result ) {
			case 'recounted':
				return __( 'Counters recomputed.', 'buddynext' );
			case 'flushed':
				return __( 'Cache flushed.', 'buddynext' );
			case 'imported':
				return __( 'Settings imported.', 'buddynext' );
			case 'import_empty':
				return __( 'Import file had no recognised BuddyNext settings.', 'buddynext' );
			case 'import_failed':
				return __( 'Could not read that import file.', 'buddynext' );
			default:
				return '';
		}
	}
}
