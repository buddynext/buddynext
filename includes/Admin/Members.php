<?php
/**
 * BuddyNext admin members panel.
 *
 * Provides a submenu page under the BuddyNext top-level menu for
 * listing, suspending, unsuspending, and exporting community members.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Admin panel for managing BuddyNext community members.
 */
class Members extends AdminPageBase {

	/**
	 * Usermeta key that marks a member as suspended.
	 */
	private const META_SUSPENDED = 'bn_suspended';

	/**
	 * Default items per page for member listing.
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
		add_action( 'admin_post_bn_suspend_member',   array( $this, 'handle_suspend' ) );
		add_action( 'admin_post_bn_unsuspend_member', array( $this, 'handle_unsuspend' ) );
		add_action( 'admin_post_bn_export_members',   array( $this, 'handle_export' ) );
	}

	/**
	 * Add the Members submenu under the BuddyNext top-level menu.
	 *
	 * @return void
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'buddynext',
			__( 'Members', 'buddynext' ),
			__( 'Members', 'buddynext' ),
			'manage_options',
			'buddynext-members',
			array( $this, 'render_page' )
		);
	}

	// ── Query ──────────────────────────────────────────────────────────────────

	/**
	 * Return a paginated list of members.
	 *
	 * Accepted args:
	 *   'page'     int    Current page number (1-based). Default 1.
	 *   'per_page' int    Items per page. Default 20.
	 *   'search'   string Optional search string matched against login/email.
	 *   'status'   string 'active' | 'suspended' | 'all'. Default 'all'.
	 *   'orderby'  string User field to order by. Default 'registered'.
	 *   'order'    string 'ASC' | 'DESC'. Default 'DESC'.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{ members: array<int, array<string, mixed>>, total: int, pages: int }
	 */
	public function list_members( array $args = array() ): array {
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) );
		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$status   = sanitize_key( (string) ( $args['status'] ?? 'all' ) );
		$orderby  = sanitize_key( (string) ( $args['orderby'] ?? 'registered' ) );
		$order    = strtoupper( sanitize_text_field( (string) ( $args['order'] ?? 'DESC' ) ) );
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$query_args = array(
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => $orderby,
			'order'   => $order,
			'fields'  => 'all',
		);

		if ( '' !== $search ) {
			$query_args['search']         = '*' . $search . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		if ( 'suspended' === $status ) {
			$query_args['meta_key']   = self::META_SUSPENDED; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query_args['meta_value'] = '1'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$user_query = new \WP_User_Query( $query_args );

		$members = array();
		foreach ( $user_query->get_results() as $user ) {
			$members[] = array(
				'id'         => $user->ID,
				'login'      => $user->user_login,
				'email'      => $user->user_email,
				'display'    => $user->display_name,
				'registered' => $user->user_registered,
				'suspended'  => (bool) get_user_meta( $user->ID, self::META_SUSPENDED, true ),
			);
		}

		$total = (int) $user_query->get_total();
		$pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		return compact( 'members', 'total', 'pages' );
	}

	/**
	 * Return the total number of registered users.
	 *
	 * @return int
	 */
	public function get_member_count(): int {
		$counts = count_users();
		return (int) ( $counts['total_users'] ?? 0 );
	}

	// ── Moderation ─────────────────────────────────────────────────────────────

	/**
	 * Suspend a community member.
	 *
	 * Sets the bn_suspended usermeta flag and fires buddynext_member_suspended.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function suspend_member( int $user_id ): void {
		update_user_meta( $user_id, self::META_SUSPENDED, '1' );

		/**
		 * Fires after a member is suspended.
		 *
		 * @param int $user_id  WordPress user ID of the suspended member.
		 * @param int $actor_id Admin user ID performing the suspension.
		 */
		do_action( 'buddynext_member_suspended', $user_id, get_current_user_id() );
	}

	/**
	 * Lift the suspension for a community member.
	 *
	 * Removes the bn_suspended flag and fires buddynext_member_unsuspended.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function unsuspend_member( int $user_id ): void {
		delete_user_meta( $user_id, self::META_SUSPENDED );

		/**
		 * Fires after a member suspension is lifted.
		 *
		 * @param int $user_id  WordPress user ID of the unsuspended member.
		 * @param int $actor_id Admin user ID performing the lift.
		 */
		do_action( 'buddynext_member_unsuspended', $user_id, get_current_user_id() );
	}

	// ── Admin-post handlers ────────────────────────────────────────────────────

	/**
	 * Handle admin_post_bn_suspend_member form submission.
	 *
	 * @return void
	 */
	public function handle_suspend(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_suspend_member' );

		$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		if ( $user_id > 0 ) {
			$this->suspend_member( $user_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'buddynext-members',
					'action'  => 'suspended',
					'user_id' => $user_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin_post_bn_unsuspend_member form submission.
	 *
	 * @return void
	 */
	public function handle_unsuspend(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_unsuspend_member' );

		$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		if ( $user_id > 0 ) {
			$this->unsuspend_member( $user_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'buddynext-members',
					'action'  => 'unsuspended',
					'user_id' => $user_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin_post_bn_export_members form submission.
	 *
	 * Sends a CSV file download of all members.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_export_members' );

		$csv      = $this->export_members_csv();
		$filename = 'buddynext-members-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $csv;
		exit;
	}

	// ── Export ─────────────────────────────────────────────────────────────────

	/**
	 * Build and return a CSV export of all members.
	 *
	 * Columns: ID, Login, Email, Registered, Suspended.
	 *
	 * @return string CSV string with header row.
	 */
	public function export_members_csv(): string {
		$users = get_users(
			array(
				'fields' => 'all',
				'number' => 0,
			)
		);

		$lines   = array();
		$lines[] = 'ID,Login,Email,Registered,Suspended';

		foreach ( $users as $user ) {
			$suspended = get_user_meta( $user->ID, self::META_SUSPENDED, true ) ? 'yes' : 'no';
			$lines[]   = implode(
				',',
				array(
					$user->ID,
					'"' . esc_html( $user->user_login ) . '"',
					'"' . esc_html( $user->user_email ) . '"',
					$user->user_registered,
					$suspended,
				)
			);
		}

		return implode( "\n", $lines );
	}

	// ── AdminPageBase interface ────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Members', 'buddynext' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return __( 'Manage your community members', 'buddynext' );
	}

	/**
	 * Render the members page: stats cards, search/filter, member table, pagination.
	 *
	 * @return void
	 */
	protected function render_content(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) );
		if ( ! in_array( $status, array( 'all', 'active', 'suspended' ), true ) ) {
			$status = 'all';
		}

		$data      = $this->list_members(
			array(
				'page'   => $page,
				'search' => $search,
				'status' => $status,
			)
		);
		$total     = $data['total'];
		$members   = $data['members'];
		$pages     = $data['pages'];

		// Suspended count for stat card.
		$susp_data      = $this->list_members( array( 'status' => 'suspended', 'per_page' => 1 ) );
		$suspended_count = $susp_data['total'];
		$active_count    = max( 0, $this->get_member_count() - $suspended_count );

		// Action feedback notices.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
		if ( 'suspended' === $action ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Member suspended.', 'buddynext' ) . '</p></div>';
		} elseif ( 'unsuspended' === $action ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Member unsuspended.', 'buddynext' ) . '</p></div>';
		}

		// Stats row.
		?>
		<div class="bn-stats-row">
			<div class="bn-stat-card">
				<div class="bn-stat-val"><?php echo esc_html( (string) $this->get_member_count() ); ?></div>
				<div class="bn-stat-label"><?php esc_html_e( 'Total members', 'buddynext' ); ?></div>
			</div>
			<div class="bn-stat-card">
				<div class="bn-stat-val"><?php echo esc_html( (string) $active_count ); ?></div>
				<div class="bn-stat-label"><?php esc_html_e( 'Active', 'buddynext' ); ?></div>
			</div>
			<div class="bn-stat-card">
				<div class="bn-stat-val"><?php echo esc_html( (string) $suspended_count ); ?></div>
				<div class="bn-stat-label"><?php esc_html_e( 'Suspended', 'buddynext' ); ?></div>
			</div>
		</div>

		<?php
		// Export form (inline, separate from member actions).
		$export_url = admin_url( 'admin-post.php' );
		?>

		<div class="bn-data-table">
			<div class="bn-table-header">
				<!-- Search form -->
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<input type="hidden" name="page" value="buddynext-members">
					<?php if ( 'all' !== $status ) : ?>
						<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
					<?php endif; ?>
					<input type="search"
					       name="s"
					       class="bn-table-search"
					       placeholder="<?php esc_attr_e( 'Search members…', 'buddynext' ); ?>"
					       value="<?php echo esc_attr( $search ); ?>">
					<?php submit_button( __( 'Search', 'buddynext' ), 'secondary', '', false ); ?>
				</form>

				<!-- Status filter links -->
				<div class="bn-toolbar">
					<?php
					$base      = admin_url( 'admin.php?page=buddynext-members' );
					$s_all     = '' !== $search ? add_query_arg( 's', rawurlencode( $search ), $base ) : $base;
					$s_active  = add_query_arg( 'status', 'active', $s_all );
					$s_susp    = add_query_arg( 'status', 'suspended', $s_all );
					$filter_links = array(
						'all'       => array( 'url' => $s_all,    'label' => __( 'All', 'buddynext' ) ),
						'active'    => array( 'url' => $s_active, 'label' => __( 'Active', 'buddynext' ) ),
						'suspended' => array( 'url' => $s_susp,   'label' => __( 'Suspended', 'buddynext' ) ),
					);
					foreach ( $filter_links as $key => $link ) :
						$cls = ( $status === $key ) ? 'bn-btn active' : 'bn-btn';
						echo '<a href="' . esc_url( $link['url'] ) . '" class="' . esc_attr( $cls ) . '" style="' . ( $status === $key ? 'background:#0073aa;color:#fff;border-color:#0073aa;' : '' ) . '">' . esc_html( $link['label'] ) . '</a>';
					endforeach;
					?>

					<!-- Export -->
					<form method="post" action="<?php echo esc_url( $export_url ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="bn_export_members">
						<?php wp_nonce_field( 'bn_export_members' ); ?>
						<button type="submit" class="bn-btn"><?php esc_html_e( 'Export CSV', 'buddynext' ); ?></button>
					</form>
				</div>
			</div>

			<?php if ( empty( $members ) ) : ?>
				<p style="padding:20px 18px;color:#6b7280;margin:0;"><?php esc_html_e( 'No members found.', 'buddynext' ); ?></p>
			<?php else : ?>
			<table class="bn-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Member', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Email', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Registered', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $members as $member ) : ?>
					<tr>
						<td>
							<?php echo get_avatar( $member['id'], 28, '', '', array( 'style' => 'border-radius:50%;vertical-align:middle;margin-right:8px;' ) ); ?>
							<strong><?php echo esc_html( $member['display'] ); ?></strong>
							<span style="color:#9ca3af;font-size:11px;margin-left:4px;"><?php echo esc_html( $member['login'] ); ?></span>
						</td>
						<td><?php echo esc_html( $member['email'] ); ?></td>
						<td><?php echo esc_html( gmdate( 'M j, Y', strtotime( $member['registered'] ) ) ); ?></td>
						<td>
							<?php if ( $member['suspended'] ) : ?>
								<span class="bn-badge bn-badge-suspended"><?php esc_html_e( 'Suspended', 'buddynext' ); ?></span>
							<?php else : ?>
								<span class="bn-badge bn-badge-active"><?php esc_html_e( 'Active', 'buddynext' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $member['suspended'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<input type="hidden" name="action" value="bn_unsuspend_member">
									<input type="hidden" name="user_id" value="<?php echo absint( $member['id'] ); ?>">
									<?php wp_nonce_field( 'bn_unsuspend_member' ); ?>
									<button type="submit" class="bn-btn"><?php esc_html_e( 'Unsuspend', 'buddynext' ); ?></button>
								</form>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<input type="hidden" name="action" value="bn_suspend_member">
									<input type="hidden" name="user_id" value="<?php echo absint( $member['id'] ); ?>">
									<?php wp_nonce_field( 'bn_suspend_member' ); ?>
									<button type="submit" class="bn-btn bn-btn-danger"><?php esc_html_e( 'Suspend', 'buddynext' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php if ( $pages > 1 ) : ?>
			<div class="bn-pagination">
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<?php
					$paged_url = add_query_arg(
						array_filter(
							array(
								'page'   => 'buddynext-members',
								'paged'  => $i > 1 ? $i : false,
								's'      => '' !== $search ? $search : false,
								'status' => 'all' !== $status ? $status : false,
							)
						),
						admin_url( 'admin.php' )
					);
					?>
					<a href="<?php echo esc_url( $paged_url ); ?>"
					   class="bn-page-link<?php echo $i === $page ? ' current' : ''; ?>">
						<?php echo esc_html( (string) $i ); ?>
					</a>
				<?php endfor; ?>
			</div>
			<?php endif; ?>

		</div><!-- .bn-data-table -->
		<?php
	}
}
