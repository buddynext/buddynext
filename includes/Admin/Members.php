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
class Members {

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
		 * @param int $user_id WordPress user ID of the suspended member.
		 */
		do_action( 'buddynext_member_suspended', $user_id );
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
		 * @param int $user_id WordPress user ID of the unsuspended member.
		 */
		do_action( 'buddynext_member_unsuspended', $user_id );
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

	// ── Render ─────────────────────────────────────────────────────────────────

	/**
	 * Render the members admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'buddynext' ) );
		}

		$page   = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data   = $this->list_members(
			array(
				'page'   => $page,
				'search' => $search,
				'status' => $status,
			)
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BuddyNext Members', 'buddynext' ) . '</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: member count */
				__( 'Total members: %d', 'buddynext' ),
				$data['total']
			)
		) . '</p>';
		echo '</div>';
	}
}
