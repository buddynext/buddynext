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
		$handle   = fopen( $tmp_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

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
			<div class="notice <?php echo 'invited' === $notice || 'resent' === $notice ? 'notice-success' : 'notice-error'; ?> is-dismissible">
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
					case 'bad_invite':
						esc_html_e( 'Invalid invite ID.', 'buddynext' );
						break;
					case 'resend_failed':
						esc_html_e( 'Could not resend the invite — it may no longer exist.', 'buddynext' );
						break;
				}
				?>
				</p>
			</div>
		<?php endif; ?>

			<div class="bn-card" style="max-width:540px;margin-bottom:var(--s6);">
				<h3><?php esc_html_e( 'Send Bulk Invitations', 'buddynext' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Upload a CSV file. Each row: email, first_name (first_name is optional). Up to 500 rows per upload.', 'buddynext' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'bn_bulk_invite' ); ?>
					<input type="hidden" name="action" value="bn_bulk_invite">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="bn_invite_csv"><?php esc_html_e( 'CSV File', 'buddynext' ); ?></label>
							</th>
							<td>
								<input type="file" id="bn_invite_csv" name="bn_invite_csv" accept=".csv,text/csv" required>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Send Invitations', 'buddynext' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<h3><?php esc_html_e( 'Pending Invitations', 'buddynext' ); ?></h3>

			<?php if ( empty( $invites ) ) : ?>
				<p><?php esc_html_e( 'No pending invitations.', 'buddynext' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
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
								?>
								<a href="<?php echo esc_url( $resend_url ); ?>"><?php esc_html_e( 'Resend', 'buddynext' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

		</div>
		<?php
	}
}
