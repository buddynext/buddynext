<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin tab: Bulk Invites.
 *
 * Provides the "Invites" tab under Members admin. Admins can:
 *   - Upload a CSV of email addresses to send bulk invitations.
 *   - View all pending invites with sent date and status.
 *   - Resend a specific invite (regenerates token + resends email).
 *
 * @package BuddyNext\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Members;

use BuddyNext\Onboarding\InviteService;

/**
 * Renders and processes the Bulk Invites admin tab.
 */
class InviteManager {

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register WordPress hooks for form submission handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_bulk_invite', array( $this, 'handle_bulk_invite' ) );
		add_action( 'admin_post_bn_resend_invite', array( $this, 'handle_resend_invite' ) );
		add_action( 'admin_post_bn_single_invite', array( $this, 'handle_single_invite' ) );
		add_action( 'admin_post_bn_revoke_invite', array( $this, 'handle_revoke_invite' ) );
	}

	// ── Form handlers ─────────────────────────────────────────────────────────

	/**
	 * Handle the CSV bulk invite form submission.
	 *
	 * Expects a file upload under the 'bn_invite_csv' key. Each CSV line
	 * should be: email[,first_name]. Lines exceeding 500 are silently ignored.
	 * Duplicate or already-pending emails that exist in bn_invites are skipped.
	 *
	 * @return void
	 */
	public function handle_bulk_invite(): void {
		check_admin_referer( 'bn_bulk_invite' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to send invites.', 'buddynext' ) );
		}

		$redirect = admin_url( 'admin.php?page=buddynext-members&tab=invites' );

		if ( empty( $_FILES['bn_invite_csv']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'bn_notice', 'no_file', $redirect ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tmp_path = (string) $_FILES['bn_invite_csv']['tmp_name'];

		// Validate MIME type from the file's own bytes — finfo is the reliable
		// server-side check. Mirrors the REST path (InviteController) so both
		// upload entry points enforce the same allow-list.
		$finfo    = finfo_open( FILEINFO_MIME_TYPE );
		$detected = $finfo ? (string) finfo_file( $finfo, $tmp_path ) : '';
		if ( $finfo ) {
			finfo_close( $finfo );
		}
		$allowed_mime_types = array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' );
		if ( '' !== $detected && ! in_array( $detected, $allowed_mime_types, true ) ) {
			wp_safe_redirect( add_query_arg( 'bn_notice', 'bad_type', $redirect ) );
			exit;
		}

		$handle = fopen( $tmp_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			wp_safe_redirect( add_query_arg( 'bn_notice', 'bad_file', $redirect ) );
			exit;
		}

		$service = new InviteService();
		$sent    = 0;
		$limit   = 500;

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $line = fgetcsv( $handle ) ) && $sent < $limit ) {
			$email = sanitize_email( trim( (string) ( $line[0] ?? '' ) ) );
			if ( '' === $email ) {
				continue;
			}
			$first_name = sanitize_text_field( trim( (string) ( $line[1] ?? '' ) ) );
			$invite_id  = $service->create( $email, $first_name );
			if ( $invite_id > 0 ) {
				++$sent;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		wp_safe_redirect(
			add_query_arg(
				array(
					'bn_notice' => 'invited',
					'bn_sent'   => $sent,
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Handle a resend invite request.
	 *
	 * @return void
	 */
	public function handle_resend_invite(): void {
		$invite_id = absint( $_REQUEST['invite_id'] ?? 0 );
		check_admin_referer( 'bn_resend_invite_' . $invite_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to resend invites.', 'buddynext' ) );
		}

		$redirect = admin_url( 'admin.php?page=buddynext-members&tab=invites' );

		if ( 0 === $invite_id ) {
			wp_safe_redirect( add_query_arg( 'bn_notice', 'bad_invite', $redirect ) );
			exit;
		}

		$service = new InviteService();
		$ok      = $service->resend( $invite_id );

		$notice = $ok ? 'resent' : 'resend_failed';
		wp_safe_redirect( add_query_arg( 'bn_notice', $notice, $redirect ) );
		exit;
	}

	/**
	 * Handle a single-email invite form submission.
	 *
	 * @return void
	 */
	public function handle_single_invite(): void {
		check_admin_referer( 'bn_single_invite' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to send invites.', 'buddynext' ) );
		}

		$redirect = admin_url( 'admin.php?page=buddynext-members&tab=invites' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$email = sanitize_email( wp_unslash( $_POST['bn_invite_email'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$first = sanitize_text_field( wp_unslash( $_POST['bn_invite_first_name'] ?? '' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'bn_notice', 'bad_email', $redirect ) );
			exit;
		}

		$ok = ( new InviteService() )->create( $email, $first ) > 0;
		wp_safe_redirect( add_query_arg( 'bn_notice', $ok ? 'invited_one' : 'invite_dupe', $redirect ) );
		exit;
	}

	/**
	 * Handle a revoke-invite request (deletes a pending invite).
	 *
	 * @return void
	 */
	public function handle_revoke_invite(): void {
		$invite_id = absint( $_REQUEST['invite_id'] ?? 0 );
		check_admin_referer( 'bn_revoke_invite_' . $invite_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to revoke invites.', 'buddynext' ) );
		}

		$redirect = admin_url( 'admin.php?page=buddynext-members&tab=invites' );

		if ( 0 === $invite_id ) {
			wp_safe_redirect( add_query_arg( 'bn_notice', 'bad_invite', $redirect ) );
			exit;
		}

		$ok = ( new InviteService() )->revoke( $invite_id );
		wp_safe_redirect( add_query_arg( 'bn_notice', $ok ? 'revoked' : 'revoke_failed', $redirect ) );
		exit;
	}

	// ── Tab renderer ──────────────────────────────────────────────────────────

	/**
	 * Render the Invites tab: notice bar, CSV upload form, pending invites table.
	 *
	 * @return void
	 */
	public function render_invites_tab(): void {
		$service = new InviteService();
		$invites = $service->get_pending();
		$tab_url = admin_url( 'admin.php?page=buddynext-members&tab=invites' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( wp_unslash( $_GET['bn_notice'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sent = absint( wp_unslash( $_GET['bn_sent'] ?? 0 ) );

		?>
		<div class="bn-admin-section">

		<?php if ( '' !== $notice ) : ?>
			<div class="notice <?php echo in_array( $notice, array( 'invited', 'resent', 'invited_one', 'revoked' ), true ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
				<p>
				<?php
				switch ( $notice ) {
					case 'invited':
						printf(
							/* translators: %d: number of invites sent */
							esc_html__( '%d invitation(s) sent successfully.', 'buddynext' ),
							(int) $sent
						);
						break;
					case 'resent':
						esc_html_e( 'Invitation resent successfully.', 'buddynext' );
						break;
					case 'no_file':
						esc_html_e( 'Please upload a CSV file.', 'buddynext' );
						break;
					case 'bad_file':
						esc_html_e( 'Could not read the uploaded file.', 'buddynext' );
						break;
					case 'bad_type':
						esc_html_e( 'The uploaded file must be a CSV.', 'buddynext' );
						break;
					case 'bad_invite':
						esc_html_e( 'Invalid invite ID.', 'buddynext' );
						break;
					case 'resend_failed':
						esc_html_e( 'Could not resend the invite — it may no longer exist.', 'buddynext' );
						break;
					case 'invited_one':
						esc_html_e( 'Invitation sent.', 'buddynext' );
						break;
					case 'invite_dupe':
						esc_html_e( 'That email already has a pending invite or an account.', 'buddynext' );
						break;
					case 'bad_email':
						esc_html_e( 'Please enter a valid email address.', 'buddynext' );
						break;
					case 'revoked':
						esc_html_e( 'Invitation revoked.', 'buddynext' );
						break;
					case 'revoke_failed':
						esc_html_e( 'Could not revoke the invite — it may no longer exist.', 'buddynext' );
						break;
				}
				?>
				</p>
			</div>
		<?php endif; ?>

			<div class="bn-settings-section" style="max-width:620px;">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Invite a Member', 'buddynext' ); ?></span>
				</div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc"><?php esc_html_e( 'Send a single invitation by email.', 'buddynext' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'bn_single_invite' ); ?>
						<input type="hidden" name="action" value="bn_single_invite">
						<div class="bn-field">
							<label for="bn_invite_email"><?php esc_html_e( 'Email', 'buddynext' ); ?></label>
							<input type="email" id="bn_invite_email" name="bn_invite_email" class="bn-text-input regular-text" required>
						</div>
						<div class="bn-field">
							<label for="bn_invite_first_name"><?php esc_html_e( 'First name', 'buddynext' ); ?></label>
							<input type="text" id="bn_invite_first_name" name="bn_invite_first_name" class="bn-text-input regular-text">
						</div>
						<?php submit_button( __( 'Send Invitation', 'buddynext' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<div class="bn-settings-section" style="max-width:620px;">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Send Bulk Invitations', 'buddynext' ); ?></span>
				</div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc"><?php esc_html_e( 'Upload a CSV file. Each row: email, first_name (first_name is optional). Up to 500 rows per upload.', 'buddynext' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'bn_bulk_invite' ); ?>
						<input type="hidden" name="action" value="bn_bulk_invite">
						<div class="bn-field">
							<label for="bn_invite_csv"><?php esc_html_e( 'CSV File', 'buddynext' ); ?></label>
							<input type="file" id="bn_invite_csv" name="bn_invite_csv" accept=".csv,text/csv" required>
						</div>
						<?php submit_button( __( 'Send Invitations', 'buddynext' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<div class="bn-settings-section">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Pending Invitations', 'buddynext' ); ?></span>
					<?php if ( ! empty( $invites ) ) : ?>
						<span class="bn-ss-count"><?php echo esc_html( (string) count( $invites ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="bn-ss-body">
				<?php if ( empty( $invites ) ) : ?>
					<p><?php esc_html_e( 'No pending invitations.', 'buddynext' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Email', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'First Name', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Sent', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Expires', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
							</tr>
						</thead>
						<tbody>
					<?php foreach ( $invites as $invite ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $invite['email'] ); ?></td>
							<td><?php echo esc_html( (string) ( $invite['first_name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $invite['created_at'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $invite['expires_at'] ?? '' ) ); ?></td>
							<td>
								<?php
								$invite_id  = (int) ( $invite['id'] ?? 0 );
								$resend_url = wp_nonce_url(
									add_query_arg(
										array(
											'action'    => 'bn_resend_invite',
											'invite_id' => $invite_id,
										),
										admin_url( 'admin-post.php' )
									),
									'bn_resend_invite_' . $invite_id
								);
								$revoke_url = wp_nonce_url(
									add_query_arg(
										array(
											'action'    => 'bn_revoke_invite',
											'invite_id' => $invite_id,
										),
										admin_url( 'admin-post.php' )
									),
									'bn_revoke_invite_' . $invite_id
								);
								?>
								<a href="<?php echo esc_url( $resend_url ); ?>"><?php esc_html_e( 'Resend', 'buddynext' ); ?></a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( $revoke_url ); ?>" class="bn-text-danger" data-bn-confirm="<?php esc_attr_e( 'Revoke this invitation? The link will stop working.', 'buddynext' ); ?>" data-bn-confirm-tone="danger"><?php esc_html_e( 'Revoke', 'buddynext' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
				</div><!-- .bn-ss-body -->
			</div><!-- .bn-settings-section -->

		</div><!-- .bn-admin-section -->
		<?php
	}
}
