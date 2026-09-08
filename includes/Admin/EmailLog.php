<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext Email Log viewer.
 *
 * A read-only backend surface for the bn_email_log table, which EmailSender
 * writes to on every send but which previously had no reader outside the unit
 * tests. Gives the site owner a way to see and debug what mail BuddyNext has
 * actually sent (the third entry point — backend read — for that data store).
 *
 * Reverse-chronological + paginated (big-site safe: ORDER BY the PK, COUNT(*)
 * total, LIMIT/OFFSET window) with type and status filters. Lists who, what
 * type, delivery status (with the failure reason), digest date and when. A
 * proactive admin banner warns when failures pile up in the last 24h.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Settings → Email Log admin tab.
 */
class EmailLog {

	/**
	 * Rows per page.
	 */
	private const PER_PAGE = 30;

	/**
	 * Register the admin tab.
	 *
	 * @return void
	 */
	public function register(): void {
		AdminHub::register_tab(
			'settings',
			'email-log',
			__( 'Email Log', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'subtitle' => __( 'A read-only record of the transactional emails BuddyNext has sent, newest first.', 'buddynext' ),
			)
		);

		add_action( 'admin_notices', array( $this, 'maybe_render_failure_banner' ) );
	}

	/**
	 * Number of failed sends in the last 24 hours.
	 *
	 * @return int
	 */
	private function failed_last_24h(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_log';

		if ( ! $this->table_exists() ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted {$wpdb->prefix} literal; admin-only read.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND sent_at >= %s",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/**
	 * Proactive banner on BuddyNext admin screens when email is failing.
	 *
	 * The whole point of the failed-send tracking is that the owner finds out
	 * WITHOUT having to open the Email Log on a hunch. When failures in the last
	 * 24h cross the threshold (filterable), every BuddyNext admin screen shows a
	 * warning linking to the log. Scoped to BuddyNext screens so it never nags
	 * across unrelated wp-admin pages.
	 *
	 * @return void
	 */
	public function maybe_render_failure_banner(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'buddynext' ) ) {
			return;
		}

		/**
		 * Failed-send count in the last 24h above which the admin banner shows.
		 *
		 * @since 1.2.0
		 *
		 * @param int $threshold Failure count. Default 5.
		 */
		$threshold = (int) apply_filters( 'buddynext_email_failure_alert_threshold', 5 );
		$failed    = $this->failed_last_24h();
		if ( $threshold <= 0 || $failed < $threshold ) {
			return;
		}

		$log_url = add_query_arg(
			array(
				'page'       => 'buddynext-notifications',
				'tab'        => 'email-log',
				'log_status' => 'failed',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'BuddyNext email is failing to send.', 'buddynext' ); ?></strong>
				<?php
				printf(
					/* translators: %s: number of failed emails in the last 24 hours. */
					esc_html__( '%s email(s) failed in the last 24 hours. This usually means the site has no working mailer (SMTP plugin or server MTA). Members are not receiving verification, invite, 2FA or notification email.', 'buddynext' ),
					'<strong>' . esc_html( number_format_i18n( $failed ) ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'Review the email log', 'buddynext' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether the bn_email_log table exists.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Render the Email Log tab.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_log';

		if ( ! $this->table_exists() ) {
			echo '<div class="bn-settings-section"><div class="bn-ss-body"><p>' .
				esc_html__( 'The email log table is not present yet. It is created automatically once the plugin records its first send.', 'buddynext' ) .
				'</p></div></div>';
			return;
		}

		// Filter + page from the query string (read-only listing, nonce not
		// required for a GET-driven, capability-gated view).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter = isset( $_GET['log_type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['log_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = isset( $_GET['log_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['log_status'] ) ) : '';
		if ( ! in_array( $status_filter, array( 'sent', 'failed' ), true ) ) {
			$status_filter = '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = self::PER_PAGE;
		$offset   = ( $paged - 1 ) * $per_page;

		// Build the WHERE from whichever of the two filters are set — a plain
		// prepared clause list so both type and status compose without a
		// combinatorial branch. $table is a trusted {$wpdb->prefix} literal.
		$where = array();
		$args  = array();
		if ( '' !== $type_filter ) {
			$where[] = 'type = %s';
			$args[]  = $type_filter;
		}
		if ( '' !== $status_filter ) {
			$where[] = 'status = %s';
			$args[]  = $status_filter;
		}
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- admin-only debug read, intentionally uncached; WHERE built from a fixed set of prepared clauses.
		$total = $where
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $args ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, type, digest_date, status, error, sent_at FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, $offset ) )
			)
		);

		// Distinct types for the filter chips (small, fixed set of template labels).
		$types = $wpdb->get_col( "SELECT DISTINCT type FROM {$table} ORDER BY type ASC" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Resolve every recipient on the page in one query (no per-row lookup).
		$user_ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) $r->user_id, (array) $rows ) ) ) );
		$user_map = array();
		if ( $user_ids ) {
			foreach ( get_users(
				array(
					'include' => $user_ids,
					'fields'  => array( 'ID', 'display_name', 'user_email' ),
				)
			) as $u ) {
				$user_map[ (int) $u->ID ] = $u;
			}
		}

		$total_pages = (int) max( 1, (int) ceil( $total / $per_page ) );
		$tab_url     = add_query_arg(
			array(
				'page' => 'buddynext-notifications',
				'tab'  => 'email-log',
			),
			admin_url( 'admin.php' )
		);
		// Base URLs that keep the OTHER filter set, so type and status compose
		// instead of clobbering each other.
		$tab_url_status   = '' !== $status_filter ? add_query_arg( 'log_status', $status_filter, $tab_url ) : $tab_url;
		$tab_url_filtered = '' !== $type_filter ? add_query_arg( 'log_type', $type_filter, $tab_url ) : $tab_url;
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Sent email', 'buddynext' ); ?></span>
				<span class="bn-ss-count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<div class="bn-ss-body">

				<?php if ( ! empty( $types ) ) : ?>
					<?php // B5: --wrap lets a long run of event-type chips break to new lines instead of clipping at the card edge. ?>
					<div class="bn-segment bn-segment--wrap" role="group" aria-label="<?php esc_attr_e( 'Filter email log by type', 'buddynext' ); ?>">
						<a href="<?php echo esc_url( remove_query_arg( array( 'log_type', 'paged' ), $tab_url_status ) ); ?>"
							class="bn-segment__item<?php echo '' === $type_filter ? ' is-active' : ''; ?>"
							aria-selected="<?php echo '' === $type_filter ? 'true' : 'false'; ?>">
							<?php esc_html_e( 'All', 'buddynext' ); ?>
						</a>
						<?php foreach ( $types as $t ) : ?>
							<?php $t = (string) $t; ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'log_type' => $t ), remove_query_arg( 'paged', $tab_url_status ) ) ); ?>"
								class="bn-segment__item<?php echo $type_filter === $t ? ' is-active' : ''; ?>"
								aria-selected="<?php echo $type_filter === $t ? 'true' : 'false'; ?>">
								<?php echo esc_html( $t ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php
				// Status filter: All / Sent / Failed. Kept separate from the type
				// chips so an owner can jump straight to failures.
				$status_links = array(
					''       => __( 'All statuses', 'buddynext' ),
					'sent'   => __( 'Sent', 'buddynext' ),
					'failed' => __( 'Failed', 'buddynext' ),
				);
				?>
				<div class="bn-segment" role="group" aria-label="<?php esc_attr_e( 'Filter email log by status', 'buddynext' ); ?>">
					<?php foreach ( $status_links as $s_key => $s_label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'log_status' => '' !== $s_key ? $s_key : false ), remove_query_arg( 'paged', $tab_url_filtered ) ) ); ?>"
							class="bn-segment__item<?php echo $status_filter === $s_key ? ' is-active' : ''; ?>"
							aria-selected="<?php echo $status_filter === $s_key ? 'true' : 'false'; ?>">
							<?php echo esc_html( $s_label ); ?>
						</a>
					<?php endforeach; ?>
				</div>

				<?php if ( empty( $rows ) ) : ?>
					<div class="bn-empty">
						<p class="bn-empty__title"><?php esc_html_e( 'No sent email recorded for this filter yet', 'buddynext' ); ?></p>
					</div>
				<?php else : ?>
					<table class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Recipient', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Type', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Digest date', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Sent', 'buddynext' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $rows as $row ) :
							$uid       = (int) $row->user_id;
							$user      = $user_map[ $uid ] ?? null;
							$sent_disp = buddynext_date_local( (string) $row->sent_at, 'M j, Y g:i a' );
							?>
							<tr>
								<td>
									<?php if ( $user ) : ?>
										<strong><?php echo esc_html( (string) $user->display_name ); ?></strong>
										<span class="bn-text-muted">&lt;<?php echo esc_html( (string) $user->user_email ); ?>&gt;</span>
									<?php elseif ( $uid > 0 ) : ?>
										<?php
										/* translators: %d: user ID of a recipient whose account no longer exists. */
										echo esc_html( sprintf( __( 'Deleted user #%d', 'buddynext' ), $uid ) );
										?>
									<?php else : ?>
										<span class="bn-text-muted"><?php esc_html_e( 'Guest / unknown', 'buddynext' ); ?></span>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( (string) $row->type ); ?></code></td>
								<td>
									<?php $is_failed = 'failed' === (string) $row->status; ?>
									<span class="bn-badge" data-tone="<?php echo $is_failed ? 'danger' : 'success'; ?>">
										<?php echo esc_html( $is_failed ? __( 'Failed', 'buddynext' ) : __( 'Sent', 'buddynext' ) ); ?>
									</span>
									<?php if ( $is_failed && '' !== (string) $row->error ) : ?>
										<div class="bn-text-muted bn-email-log__error"><?php echo esc_html( (string) $row->error ); ?></div>
									<?php endif; ?>
								</td>
								<td><?php echo $row->digest_date ? esc_html( (string) $row->digest_date ) : esc_html( '—' ); ?></td>
								<td><?php echo '' !== $sent_disp ? $sent_disp : esc_html( '—' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_date_local() returns esc_html()'d output. ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php
					AdminPageBase::render_pagination(
						$paged,
						$total_pages,
						$total,
						$per_page,
						static function ( int $p ) use ( $tab_url, $type_filter, $status_filter ): string {
							return add_query_arg(
								array(
									'log_type'   => '' !== $type_filter ? $type_filter : false,
									'log_status' => '' !== $status_filter ? $status_filter : false,
									'paged'      => $p > 1 ? $p : false,
								),
								$tab_url
							);
						},
						__( 'Email log pagination', 'buddynext' )
					);
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
