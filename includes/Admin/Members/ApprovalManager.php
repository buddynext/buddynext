<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin tab: Pending Approvals.
 *
 * Provides the "Pending" tab under Members admin, shown when registration mode
 * is `approval`. Lists accounts created with the `bn_pending_approval` flag
 * (set by AuthController::register() in approval mode, which also blocks their
 * login via the wp_authenticate_user gate). Admins can:
 *   - Approve a member  → clears the flag, fires `buddynext_member_approved`.
 *   - Reject a member   → deletes the pending account.
 *
 * @package BuddyNext\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Members;

/**
 * Renders and processes the Pending Approvals admin tab.
 */
class ApprovalManager {

	private const META = 'bn_pending_approval';

	/**
	 * Register form-submission handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_approve_member', array( $this, 'handle_approve' ) );
		add_action( 'admin_post_bn_reject_member', array( $this, 'handle_reject' ) );
	}

	/**
	 * Users awaiting approval.
	 *
	 * @return \WP_User[]
	 */
	private function pending_users(): array {
		return get_users(
			array(
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1',         // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'registered',
				'order'      => 'DESC',
				'number'     => 200,
			)
		);
	}

	/**
	 * Approve a pending member: clear the flag so they can sign in.
	 *
	 * @return void
	 */
	public function handle_approve(): void {
		$user_id = absint( $_REQUEST['user_id'] ?? 0 );
		check_admin_referer( 'bn_approve_member_' . $user_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to approve members.', 'buddynext' ) );
		}

		$redirect = admin_url( 'admin.php?page=buddynext-members&tab=pending' );

		if ( 0 === $user_id || ! get_userdata( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'bn_notice', 'bad_user', $redirect ) );
			exit;
		}

		delete_user_meta( $user_id, self::META );

		/**
		 * Fires when an administrator approves a pending registration.
		 *
		 * @param int $user_id Approved user ID.
		 */
		do_action( 'buddynext_member_approved', $user_id );

		wp_safe_redirect( add_query_arg( 'bn_notice', 'approved', $redirect ) );
		exit;
	}

	/**
	 * Reject a pending member: delete the account.
	 *
	 * @return void
	 */
	public function handle_reject(): void {
		$user_id = absint( $_REQUEST['user_id'] ?? 0 );
		check_admin_referer( 'bn_reject_member_' . $user_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reject members.', 'buddynext' ) );
		}

		$redirect = admin_url( 'admin.php?page=buddynext-members&tab=pending' );

		// Only delete accounts that are actually pending approval — never a live member.
		if ( 0 === $user_id || ! get_user_meta( $user_id, self::META, true ) ) {
			wp_safe_redirect( add_query_arg( 'bn_notice', 'bad_user', $redirect ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );

		/**
		 * Fires when an administrator rejects (deletes) a pending registration.
		 *
		 * @param int $user_id Rejected user ID.
		 */
		do_action( 'buddynext_member_rejected', $user_id );

		wp_safe_redirect( add_query_arg( 'bn_notice', 'rejected', $redirect ) );
		exit;
	}

	/**
	 * Render the Pending Approvals tab.
	 *
	 * @return void
	 */
	public function render_pending_tab(): void {
		$pending = $this->pending_users();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( wp_unslash( $_GET['bn_notice'] ?? '' ) );
		?>
		<div class="bn-admin-section">

		<?php if ( '' !== $notice ) : ?>
			<div class="notice <?php echo in_array( $notice, array( 'approved', 'rejected' ), true ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
				<p>
				<?php
				switch ( $notice ) {
					case 'approved':
						esc_html_e( 'Member approved — they can now sign in.', 'buddynext' );
						break;
					case 'rejected':
						esc_html_e( 'Pending member rejected and removed.', 'buddynext' );
						break;
					case 'bad_user':
						esc_html_e( 'That account is not awaiting approval.', 'buddynext' );
						break;
				}
				?>
				</p>
			</div>
		<?php endif; ?>

			<h3><?php esc_html_e( 'Pending Approvals', 'buddynext' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Accounts awaiting approval cannot sign in until approved. Shown only while registration mode is set to “Approval”.', 'buddynext' ); ?>
			</p>

			<?php if ( empty( $pending ) ) : ?>
				<p><?php esc_html_e( 'No members awaiting approval.', 'buddynext' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Member', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Email', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Registered', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $pending as $user ) : ?>
						<?php
						$approve_url = wp_nonce_url(
							add_query_arg(
								array(
									'action'  => 'bn_approve_member',
									'user_id' => $user->ID,
								),
								admin_url( 'admin-post.php' )
							),
							'bn_approve_member_' . $user->ID
						);
						$reject_url  = wp_nonce_url(
							add_query_arg(
								array(
									'action'  => 'bn_reject_member',
									'user_id' => $user->ID,
								),
								admin_url( 'admin-post.php' )
							),
							'bn_reject_member_' . $user->ID
						);
						?>
						<tr>
							<td><?php echo esc_html( $user->display_name ); ?></td>
							<td><?php echo esc_html( $user->user_email ); ?></td>
							<td><?php echo esc_html( $user->user_registered ); ?></td>
							<td>
								<a href="<?php echo esc_url( $approve_url ); ?>"><?php esc_html_e( 'Approve', 'buddynext' ); ?></a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( $reject_url ); ?>" class="bn-text-danger" onclick="return confirm('<?php echo esc_js( __( 'Reject and permanently delete this pending account?', 'buddynext' ) ); ?>');"><?php esc_html_e( 'Reject', 'buddynext' ); ?></a>
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
