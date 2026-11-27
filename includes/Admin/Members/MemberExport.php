<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * BuddyNext member export handler.
 *
 * Streams a CSV export of all community members directly to output.
 *
 * @package BuddyNext\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Members;

/**
 * Handles CSV export of all community members.
 */
class MemberExport {

	/**
	 * Members written per query.
	 */
	private const BATCH = 500;

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_export_members', array( $this, 'handle_export' ) );
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

		// sanitize_file_name() defends the header even though the name is composed
		// only from a constant + gmdate today — so no header-injection vector can
		// be introduced by a future edit that folds in a dynamic value.
		$filename = sanitize_file_name( 'buddynext-members-' . gmdate( 'Y-m-d' ) . '.csv' );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked above.
		$this->export_members_csv(
			array(
				'search'      => sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) ),
				'role'        => sanitize_key( wp_unslash( $_POST['role'] ?? '' ) ),
				'member_type' => sanitize_key( wp_unslash( $_POST['member_type'] ?? '' ) ),
				'status'      => sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		exit;
	}

	/**
	 * Stream a CSV export of all members directly to output.
	 *
	 * Pages by ID (keyset: ID > the last one written), 500 at a time, so a late
	 * batch costs the same as the first and memory stays flat at any site size.
	 * Suspension is checked per batch in one query; no id list of every
	 * suspended member is ever loaded.
	 *
	 * Follows the filter the owner had on screen: search, WordPress role, member type and the Active or Suspended tab.
	 *
	 * Columns: ID, Login, Email, Display Name, Registered (site time), Suspended, Member type. Rows go
	 * through Core\Csv::row(), which defuses member-controlled cells such as a
	 * display name of `=HYPERLINK(...)`.
	 *
	 * @param array{search?:string,role?:string,member_type?:string,status?:string} $filters Active list filters.
	 * @return void
	 */
	public function export_members_csv( array $filters = array() ): void {
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			return;
		}

		// Header row.
		\BuddyNext\Core\Csv::row(
			$output,
			array(
				__( 'ID', 'buddynext' ),
				__( 'Login', 'buddynext' ),
				__( 'Email', 'buddynext' ),
				__( 'Display name', 'buddynext' ),
				__( 'Registered', 'buddynext' ),
				__( 'Suspended', 'buddynext' ),
				__( 'Member type', 'buddynext' ),
			)
		);

		$query_args = array(
			'number'      => self::BATCH,
			'fields'      => array( 'ID', 'user_login', 'user_email', 'display_name', 'user_registered' ),
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'count_total' => false,
		);
		if ( '' !== (string) ( $filters['search'] ?? '' ) ) {
			$query_args['search']         = '*' . $filters['search'] . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		if ( '' !== (string) ( $filters['role'] ?? '' ) ) {
			$query_args['role'] = $filters['role'];
		}

		$status     = (string) ( $filters['status'] ?? '' );
		$type_slug  = (string) ( $filters['member_type'] ?? '' );
		$types      = buddynext_service( 'member_types' );
		$moderation = new \BuddyNext\Moderation\ModerationService();
		$after_id   = 0;

		// The keyset condition, added to the batch query only.
		$after = static function ( \WP_User_Query $query ) use ( &$after_id ): void {
			global $wpdb;
			$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d", $after_id );
		};

		while ( true ) {
			add_action( 'pre_user_query', $after );
			try {
				// The Active and Suspended tabs filter through the same seam as the Members list.
				$users = $moderation->within_suspension_status(
					$status,
					static fn() => '' !== $type_slug
						? $types->within_type( $type_slug, static fn() => get_users( $query_args ) )
						: get_users( $query_args )
				);
			} finally {
				remove_action( 'pre_user_query', $after );
			}

			if ( empty( $users ) ) {
				break;
			}

			$ids = array_map( 'intval', wp_list_pluck( $users, 'ID' ) );
			// Prime usermeta cache for this batch, so a downstream meta read costs no extra query.
			update_meta_cache( 'user', $ids );
			$type_names    = $types->names_for_users( $ids );
			$suspended_set = array_flip( $moderation->filter_active_suspended( $ids ) );

			foreach ( $users as $user ) {
				\BuddyNext\Core\Csv::row(
					$output,
					array(
						$user->ID,
						$user->user_login,
						$user->user_email,
						$user->display_name,
						// Stored in UTC; shown in the site's timezone like every other date.
						wp_specialchars_decode( buddynext_date_local( (string) $user->user_registered, 'Y-m-d H:i' ), ENT_QUOTES ),
						isset( $suspended_set[ (int) $user->ID ] ) ? 'yes' : 'no',
						implode( ', ', $type_names[ (int) $user->ID ] ?? array() ),
					)
				);
			}

			$after_id = (int) end( $ids );
			if ( count( $ids ) < self::BATCH ) {
				break;
			}
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
