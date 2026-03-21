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
class Spaces {

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

	// ── Query ──────────────────────────────────────────────────────────────────

	/**
	 * Return a paginated list of spaces.
	 *
	 * Accepted args:
	 *   'page'     int    Current page (1-based). Default 1.
	 *   'per_page' int    Items per page. Default 20.
	 *   'search'   string Optional name search string.
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
		$orderby  = sanitize_key( (string) ( $args['orderby'] ?? 'created_at' ) );
		$order    = strtoupper( sanitize_text_field( (string) ( $args['order'] ?? 'DESC' ) ) );
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$offset = ( $page - 1 ) * $per_page;
		$table  = $wpdb->prefix . 'bn_spaces';

		$allowed_columns = array( 'id', 'name', 'member_count', 'created_at', 'status' );
		if ( ! in_array( $orderby, $allowed_columns, true ) ) {
			$orderby = 'created_at';
		}

		if ( '' !== $search ) {
			$where = $wpdb->prepare( 'WHERE name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$table} {$where}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$per_page,
					$offset
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
				'creator_id'   => (int) $row->creator_id,
				'member_count' => (int) $row->member_count,
				'status'       => $row->status,
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

	// ── Render ─────────────────────────────────────────────────────────────────

	/**
	 * Render the spaces admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'buddynext' ) );
		}

		$page   = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data   = $this->list_spaces(
			array(
				'page'   => $page,
				'search' => $search,
			)
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BuddyNext Spaces', 'buddynext' ) . '</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: space count */
				__( 'Total spaces: %d', 'buddynext' ),
				$data['total']
			)
		) . '</p>';
		echo '</div>';
	}
}
