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

		$this->export_members_csv();
		exit;
	}

	/**
	 * Stream a CSV export of all members directly to output.
	 *
	 * Fetches users in batches of 500 to avoid out-of-memory errors on large
	 * sites. Suspension status is pre-fetched in a single query per batch so
	 * there are no per-row DB round-trips.
	 *
	 * Columns: ID, Login, Email, Display Name, Registered, Suspended.
	 *
	 * @return void
	 */
	public function export_members_csv(): void {
		global $wpdb;

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			return;
		}

		// Header row.
		fputcsv( $output, array( 'ID', 'Login', 'Email', 'Display Name', 'Registered', 'Suspended' ) );

		// Pre-fetch ALL currently suspended user IDs in one query so the per-batch
		// lookup below is an O(1) array_key_exists check with no extra queries.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$suspended_ids = array_flip(
			(array) $wpdb->get_col(
				"SELECT DISTINCT user_id FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$offset     = 0;
		$batch_size = 500;

		while ( true ) {
			$users = get_users(
				array(
					'number'  => $batch_size,
					'offset'  => $offset,
					'fields'  => array( 'ID', 'user_login', 'user_email', 'display_name', 'user_registered' ),
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			if ( empty( $users ) ) {
				break;
			}

			// Prime usermeta cache for this batch — prevents extra queries if any
			// downstream hook reads meta during or after this loop.
			update_meta_cache( 'user', wp_list_pluck( $users, 'ID' ) );

			foreach ( $users as $user ) {
				$suspended = isset( $suspended_ids[ $user->ID ] ) ? 'yes' : 'no';
				fputcsv(
					$output,
					array(
						$user->ID,
						$user->user_login,
						$user->user_email,
						$user->display_name,
						$user->user_registered,
						$suspended,
					)
				);
			}

			$offset += $batch_size;
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
