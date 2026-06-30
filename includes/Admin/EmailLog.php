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
 * total, LIMIT/OFFSET window) with a type filter. The schema stores only
 * user_id, type, digest_date and sent_at — there is no subject/body/status
 * column to show, so this lists who, what type, when.
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
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = self::PER_PAGE;
		$offset   = ( $paged - 1 ) * $per_page;

		// Branch on the filter so each prepared query carries its placeholders
		// literally — the visible %s/%d keep the static analyser correct and the
		// SQL obvious. $table is a trusted {$wpdb->prefix} literal.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- admin-only debug read, intentionally uncached.
		if ( '' !== $type_filter ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s", $type_filter ) );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, type, digest_date, sent_at FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$type_filter,
					$per_page,
					$offset
				)
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, type, digest_date, sent_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		}

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
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Sent email', 'buddynext' ); ?></span>
				<span class="bn-ss-count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<div class="bn-ss-body">

				<?php if ( ! empty( $types ) ) : ?>
					<div class="bn-segment" role="group" aria-label="<?php esc_attr_e( 'Filter email log by type', 'buddynext' ); ?>">
						<a href="<?php echo esc_url( remove_query_arg( array( 'log_type', 'paged' ), $tab_url ) ); ?>"
							class="bn-segment__item<?php echo '' === $type_filter ? ' is-active' : ''; ?>"
							aria-selected="<?php echo '' === $type_filter ? 'true' : 'false'; ?>">
							<?php esc_html_e( 'All', 'buddynext' ); ?>
						</a>
						<?php foreach ( $types as $t ) : ?>
							<?php $t = (string) $t; ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'log_type' => $t ), remove_query_arg( 'paged', $tab_url ) ) ); ?>"
								class="bn-segment__item<?php echo $type_filter === $t ? ' is-active' : ''; ?>"
								aria-selected="<?php echo $type_filter === $t ? 'true' : 'false'; ?>">
								<?php echo esc_html( $t ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( empty( $rows ) ) : ?>
					<p><?php esc_html_e( 'No sent email recorded for this filter yet.', 'buddynext' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Recipient', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Type', 'buddynext' ); ?></th>
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
						static function ( int $p ) use ( $tab_url, $type_filter ): string {
							return add_query_arg(
								array(
									'log_type' => '' !== $type_filter ? $type_filter : false,
									'paged'    => $p > 1 ? $p : false,
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
