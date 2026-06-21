<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for bulk invite endpoints.
 *
 * Routes (all under buddynext/v1):
 *   POST /invites/import-csv  — upload a CSV file and bulk-create invites
 *
 * @package BuddyNext\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Onboarding;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles bulk invite REST endpoints.
 */
class InviteController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/invites/import-csv',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_csv' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);
	}

	/**
	 * Handle a multipart CSV upload and bulk-create invites.
	 *
	 * Expects a file field named "csv_file" in the multipart body.
	 * Returns a JSON summary of the import: { imported, skipped, errors }.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_csv( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();

		if ( empty( $files['csv_file'] ) || UPLOAD_ERR_OK !== (int) $files['csv_file']['error'] ) {
			return new WP_Error(
				'bn_no_file',
				__( 'No CSV file uploaded or upload error occurred.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$file = $files['csv_file'];

		// Validate MIME type. finfo is the reliable server-side check.
		$finfo    = finfo_open( FILEINFO_MIME_TYPE );
		$detected = $finfo ? (string) finfo_file( $finfo, $file['tmp_name'] ) : '';
		if ( $finfo ) {
			finfo_close( $finfo );
		}

		$allowed_mime_types = array(
			'text/csv',
			'text/plain',
			'application/csv',
			'application/vnd.ms-excel',
		);

		if ( '' !== $detected && ! in_array( $detected, $allowed_mime_types, true ) ) {
			return new WP_Error(
				'bn_invalid_file_type',
				__( 'Uploaded file must be a CSV.', 'buddynext' ),
				array( 'status' => 415 )
			);
		}

		$inviter_id = get_current_user_id();
		$svc        = new InviteService();
		$summary    = $svc->import_from_csv( $inviter_id, (string) $file['tmp_name'] );

		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * Permission callback: require a logged-in user with manage_options capability.
	 *
	 * @return true|WP_Error
	 */
	public function require_admin(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to import invites.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
