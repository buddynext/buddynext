<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Account-lifecycle emails for the admin-approval registration flow.
 *
 * The registration hooks fired but had no listeners, so neither the new member
 * nor the administrator was ever notified:
 *   - buddynext_registration_pending — fired when approval-mode registration
 *     creates a held account (AuthController + SocialLogin). Emails the new
 *     member ("awaiting approval") and the site admin ("review needed").
 *   - buddynext_member_approved       — fired by ApprovalManager / AuthController
 *     when an admin approves the account. Emails the member ("you can sign in").
 *   - buddynext_member_rejected       — fired by ApprovalManager on rejection.
 *     Emails the member ("not approved").
 *
 * These are transactional account emails (not preference-gated notifications),
 * so they route through EmailSender::send_with_identity() directly — carrying
 * the same From identity (Settings → Email) as every other BuddyNext email.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use BuddyNext\Notifications\EmailSender;
use BuddyNext\Core\PageRouter;

/**
 * Sends the admin-approval registration lifecycle emails.
 */
class RegistrationEmailListener {

	/**
	 * Hook the registration lifecycle actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'buddynext_registration_pending', array( $this, 'on_registration_pending' ), 10, 2 );
		add_action( 'buddynext_member_approved', array( $this, 'on_member_approved' ) );
		add_action( 'buddynext_member_rejected', array( $this, 'on_member_rejected' ) );
	}

	/**
	 * Email the new member + the site admin when a held registration is created.
	 *
	 * @param int    $user_id New (pending) user ID.
	 * @param string $email   Registered email address.
	 * @return void
	 */
	public function on_registration_pending( int $user_id, string $email ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$site  = $this->site_name();
		$email = '' !== $email ? sanitize_email( $email ) : (string) $user->user_email;

		// 1) The new member — confirm receipt, set the "awaiting approval" expectation.
		$member_subject = sprintf(
			/* translators: %s: site name */
			__( 'Your registration at %s is awaiting approval', 'buddynext' ),
			$site
		);
		$member_body = sprintf(
			/* translators: 1: display name, 2: site name */
			__( 'Hi %1$s, thanks for registering at %2$s. An administrator needs to review your account before you can sign in — we will email you as soon as it is approved.', 'buddynext' ),
			$user->display_name,
			$site
		);
		$this->mail( $email, $member_subject, $member_body );

		// 2) The site admin — prompt a review.
		$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
		if ( '' !== $admin_email ) {
			$admin_subject = sprintf(
				/* translators: %s: site name */
				__( 'New registration awaiting approval — %s', 'buddynext' ),
				$site
			);
			$admin_body = sprintf(
				/* translators: 1: username, 2: email, 3: review URL */
				__( '%1$s (%2$s) has registered and is awaiting your approval. Review pending members here: %3$s', 'buddynext' ),
				$user->user_login,
				$email,
				admin_url( 'admin.php?page=buddynext-members&tab=invites' )
			);
			$this->mail( $admin_email, $admin_subject, $admin_body );
		}
	}

	/**
	 * Email the member that their account was approved.
	 *
	 * @param int $user_id Approved user ID.
	 * @return void
	 */
	public function on_member_approved( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$site    = $this->site_name();
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Your account at %s is approved', 'buddynext' ),
			$site
		);
		$body = sprintf(
			/* translators: 1: display name, 2: site name, 3: sign-in URL */
			__( 'Good news, %1$s — your account at %2$s has been approved. You can now sign in: %3$s', 'buddynext' ),
			$user->display_name,
			$site,
			PageRouter::auth_url()
		);
		$this->mail( (string) $user->user_email, $subject, $body );
	}

	/**
	 * Email the member that their registration was not approved.
	 *
	 * @param int $user_id Rejected user ID.
	 * @return void
	 */
	public function on_member_rejected( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$site    = $this->site_name();
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Your registration at %s', 'buddynext' ),
			$site
		);
		$body = sprintf(
			/* translators: 1: display name, 2: site name */
			__( 'Hi %1$s, thank you for your interest in %2$s. Unfortunately your registration was not approved at this time.', 'buddynext' ),
			$user->display_name,
			$site
		);
		$this->mail( (string) $user->user_email, $subject, $body );
	}

	/**
	 * Send a transactional HTML email with the BuddyNext From identity.
	 *
	 * @param string $to      Recipient address.
	 * @param string $subject Subject line.
	 * @param string $body    Plain message (escaped + wrapped in minimal HTML).
	 * @return void
	 */
	private function mail( string $to, string $subject, string $body ): void {
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}
		EmailSender::send_with_identity(
			$to,
			$subject,
			EmailSender::brand_wrap( '<p>' . esc_html( $body ) . '</p>', $subject ),
			EmailSender::build_identity_headers()
		);
	}

	/**
	 * Resolve the site name for email copy.
	 *
	 * @return string
	 */
	private function site_name(): string {
		$name = (string) get_bloginfo( 'name' );
		return '' !== $name ? $name : __( 'our community', 'buddynext' );
	}
}
