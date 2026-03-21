<?php
/**
 * BuddyNext admin spaces panel.
 *
 * Provides a submenu page under the BuddyNext top-level menu for
 * listing, searching, and deleting community spaces.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Admin panel for managing BuddyNext community spaces.
 */
class Spaces extends AdminPageBase {

	/**
	 * Default items per page for the spaces listing.
	 */
	private const DEFAULT_PER_PAGE = 20;

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_post_bn_delete_space', array( $this, 'handle_delete' ) );
	}

	/**
	 * Add the Spaces submenu under the BuddyNext top-level menu.
	 *
	 * @return void
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'buddynext',
			__( 'Spaces', 'buddynext' ),
			__( 'Spaces', 'buddynext' ),
			'manage_options',
			'buddynext-spaces',
			array( $this, 'render_page' )
		);
	}

	// ── AdminPageBase interface ────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Community Spaces', 'buddynext' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return __( 'Manage all spaces, categories, and integrations', 'buddynext' );
	}

	/**
	 * Render the spaces admin page content.
	 *
	 * @return void
	 */
	protected function render_content(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page   = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$type   = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$data   = $this->list_spaces(
			array(
				'page'   => $page,
				'search' => $search,
				'type'   => $type,
			)
		);
		$spaces = $data['spaces'];
		$total  = $data['total'];
		$pages  = $data['pages'];
		$counts = $this->get_type_counts();

		$base_url = admin_url( 'admin.php?page=buddynext-spaces' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['deleted'] ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Space deleted successfully.', 'buddynext' ); ?></p>
			</div>
			<?php
		}
		?>

		<div class="bn-stats-row">
			<div class="bn-stat-card">
				<div class="bn-stat-label"><?php esc_html_e( 'Total Spaces', 'buddynext' ); ?></div>
				<div class="bn-stat-val"><?php echo esc_html( (string) $counts['total'] ); ?></div>
			</div>
			<div class="bn-stat-card">
				<div class="bn-stat-label"><?php esc_html_e( 'Open', 'buddynext' ); ?></div>
				<div class="bn-stat-val"><?php echo esc_html( (string) $counts['open'] ); ?></div>
			</div>
			<div class="bn-stat-card">
				<div class="bn-stat-label"><?php esc_html_e( 'Private', 'buddynext' ); ?></div>
				<div class="bn-stat-val"><?php echo esc_html( (string) $counts['private'] ); ?></div>
			</div>
			<div class="bn-stat-card">
				<div class="bn-stat-label"><?php esc_html_e( 'Secret', 'buddynext' ); ?></div>
				<div class="bn-stat-val"><?php echo esc_html( (string) $counts['secret'] ); ?></div>
			</div>
		</div>

		<div class="bn-data-table">
			<div class="bn-table-header">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="buddynext-spaces">
					<?php if ( '' !== $type ) : ?>
						<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
					<?php endif; ?>
					<input type="search"
							class="bn-table-search"
							name="s"
							value="<?php echo esc_attr( $search ); ?>"
							placeholder="<?php esc_attr_e( 'Search spaces…', 'buddynext' ); ?>">
				</form>

				<div class="bn-table-filter-links">
					<a href="<?php echo esc_url( add_query_arg( 's', $search, $base_url ) ); ?>"
						class="<?php echo '' === $type ? 'current' : ''; ?>">
						<?php esc_html_e( 'All', 'buddynext' ); ?>
						<span class="count">(<?php echo esc_html( (string) $counts['total'] ); ?>)</span>
					</a>
					<?php
					$type_labels = array(
						'open'    => __( 'Open', 'buddynext' ),
						'private' => __( 'Private', 'buddynext' ),
						'secret'  => __( 'Secret', 'buddynext' ),
					);
					foreach ( $type_labels as $t_slug => $t_label ) :
						?>
						<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'type' => $t_slug,
									's'    => $search,
								),
								$base_url
							)
						);
						?>
									"
							class="<?php echo $type === $t_slug ? 'current' : ''; ?>">
							<?php echo esc_html( $t_label ); ?>
							<span class="count">(<?php echo esc_html( (string) ( $counts[ $t_slug ] ?? 0 ) ); ?>)</span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<table class="bn-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Space', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Type', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Members', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Created', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $spaces ) ) : ?>
						<tr>
							<td colspan="5">
								<p class="description"><?php esc_html_e( 'No spaces found.', 'buddynext' ); ?></p>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $spaces as $space ) : ?>
							<?php
							$owner    = get_userdata( $space['owner_id'] );
							$created  = mysql2date( (string) get_option( 'date_format' ), (string) $space['created_at'] );
							$type_key = sanitize_key( (string) $space['type'] );

							$badge_class_map = array(
								'open'    => 'bn-badge-active',
								'private' => 'bn-badge-private',
								'secret'  => 'bn-badge-secret',
							);
							$badge_class     = $badge_class_map[ $type_key ] ?? 'bn-badge-secret';
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( (string) $space['name'] ); ?></strong>
									<?php if ( $owner ) : ?>
										<br>
										<span class="bn-row-info">
											<?php
											printf(
												/* translators: %s: owner username */
												esc_html__( 'Owner: %s', 'buddynext' ),
												esc_html( $owner->user_login )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<span class="bn-row-badge <?php echo esc_attr( $badge_class ); ?>">
										<?php echo esc_html( ucfirst( $type_key ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( (string) $space['member_count'] ); ?></td>
								<td><?php echo esc_html( (string) $created ); ?></td>
								<td>
									<form method="post"
											action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
											onsubmit="return confirm( '<?php esc_attr_e( 'Delete this space? This cannot be undone.', 'buddynext' ); ?>' );">
										<?php wp_nonce_field( 'bn_delete_space' ); ?>
										<input type="hidden" name="action" value="bn_delete_space">
										<input type="hidden" name="space_id" value="<?php echo esc_attr( (string) $space['id'] ); ?>">
										<button type="submit" class="bn-btn bn-btn-danger">
											<?php esc_html_e( 'Delete', 'buddynext' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="bn-pagination">
					<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
						<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'paged' => $i,
									's'     => $search,
									'type'  => $type,
								),
								$base_url
							)
						);
						?>
									"
							class="bn-page-link<?php echo $i === $page ? ' current' : ''; ?>">
							<?php echo esc_html( (string) $i ); ?>
						</a>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Query ──────────────────────────────────────────────────────────────────

	/**
	 * Return a paginated list of spaces.
	 *
	 * Accepted args:
	 *   'page'     int    Current page (1-based). Default 1.
	 *   'per_page' int    Items per page. Default 20.
	 *   'search'   string Optional name search string.
	 *   'type'     string Optional type filter: 'open' | 'private' | 'secret'.
	 *   'orderby'  string Column to order by. Default 'created_at'.
	 *   'order'    string 'ASC' | 'DESC'. Default 'DESC'.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{ spaces: array<int, array<string, mixed>>, total: int, pages: int }
	 */
	public function list_spaces( array $args = array() ): array {
		global $wpdb;

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) );
		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$type     = sanitize_key( (string) ( $args['type'] ?? '' ) );
		$orderby  = sanitize_key( (string) ( $args['orderby'] ?? 'created_at' ) );
		$order    = strtoupper( sanitize_text_field( (string) ( $args['order'] ?? 'DESC' ) ) );
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$offset = ( $page - 1 ) * $per_page;
		$table  = $wpdb->prefix . 'bn_spaces';

		$allowed_columns = array( 'id', 'name', 'member_count', 'created_at', 'type' );
		if ( ! in_array( $orderby, $allowed_columns, true ) ) {
			$orderby = 'created_at';
		}

		$allowed_types = array( 'open', 'private', 'secret' );

		// Build WHERE conditions.
		$conditions = array();
		$params     = array();

		if ( '' !== $search ) {
			$conditions[] = 'name LIKE %s';
			$params[]     = '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( '' !== $type && in_array( $type, $allowed_types, true ) ) {
			$conditions[] = 'type = %s';
			$params[]     = $type;
		}

		if ( ! empty( $conditions ) ) {
			$where = 'WHERE ' . implode( ' AND ', $conditions );
			// Placeholders live in $where (dynamic) — static analysis cannot count them, false positives below.
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$params
				)
			);
			$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
					"SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( $params, array( $per_page, $offset ) )
				)
			);
		} else {
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$table}" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$per_page,
					$offset
				)
			);
		}

		$spaces = array();
		foreach ( (array) $rows as $row ) {
			$spaces[] = array(
				'id'           => (int) $row->id,
				'name'         => $row->name,
				'owner_id'     => (int) $row->owner_id,
				'member_count' => (int) $row->member_count,
				'type'         => $row->type,
				'created_at'   => $row->created_at,
			);
		}

		$pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		return compact( 'spaces', 'total', 'pages' );
	}

	/**
	 * Return the total number of spaces in bn_spaces.
	 *
	 * @return int
	 */
	public function get_space_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	// ── Write ──────────────────────────────────────────────────────────────────

	/**
	 * Permanently delete a space and all its associated data.
	 *
	 * Fires buddynext_space_deleted after the row is removed.
	 *
	 * @param int $space_id bn_spaces.id.
	 * @return void
	 */
	public function delete_space( int $space_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_spaces',
			array( 'id' => $space_id ),
			array( '%d' )
		);

		// Remove member associations.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}bn_space_members'" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prefix . 'bn_space_members',
				array( 'space_id' => $space_id ),
				array( '%d' )
			);
		}

		/**
		 * Fires after a space is deleted.
		 *
		 * @param int $space_id The deleted space ID.
		 */
		do_action( 'buddynext_space_deleted', $space_id );
	}

	// ── Admin-post handlers ────────────────────────────────────────────────────

	/**
	 * Handle admin_post_bn_delete_space form submission.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_delete_space' );

		$space_id = absint( wp_unslash( $_POST['space_id'] ?? 0 ) );
		if ( $space_id > 0 ) {
			$this->delete_space( $space_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'buddynext-spaces',
					'deleted'  => '1',
					'space_id' => $space_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Return space counts grouped by type.
	 *
	 * @return array{ total: int, open: int, private: int, secret: int }
	 */
	private function get_type_counts(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'bn_spaces';
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT type, COUNT(*) AS cnt FROM {$table} GROUP BY type", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = array(
			'total'   => 0,
			'open'    => 0,
			'private' => 0,
			'secret'  => 0,
		);

		foreach ( (array) $rows as $row ) {
			$t   = sanitize_key( (string) ( $row['type'] ?? '' ) );
			$cnt = (int) ( $row['cnt'] ?? 0 );
			if ( array_key_exists( $t, $counts ) ) {
				$counts[ $t ] = $cnt;
			}
			$counts['total'] += $cnt;
		}

		return $counts;
	}
}
