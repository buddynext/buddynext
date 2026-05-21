<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Email verification page (v2 design system).
 *
 * Renders three states:
 *   success  — user arrived via ?bn_verified=1 (token processed by VerificationListener)
 *   error    — user arrived via ?bn_verified=0 (invalid or expired token)
 *   pending  — logged-in unverified user waiting to click their link
 *
 * Composes v2 primitives: .bn-btn[data-variant], .bn-badge[data-tone],
 * .bn-progress[data-indeterminate]. Resend logic lives in the
 * bn-auth-verify classic script enqueued below.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

// Redirect already-verified or logged-out-but-not-verifying users to home.
$bn_verify_current_user = get_current_user_id();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_verified_param = isset( $_GET['bn_verified'] ) ? sanitize_key( $_GET['bn_verified'] ) : '';

// Determine state.
if ( '1' === $bn_verified_param ) {
	$bn_verify_state = 'success';
} elseif ( '0' === $bn_verified_param ) {
	$bn_verify_state = 'error';
} elseif ( $bn_verify_current_user > 0 ) {
	$bn_already_verified = (bool) get_user_meta( $bn_verify_current_user, 'buddynext_email_verified', true );
	if ( $bn_already_verified ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
	$bn_verify_state = 'pending';
} else {
	// Guest with no verification context — redirect to login.
	wp_safe_redirect( \BuddyNext\Core\PageRouter::auth_url() );
	exit;
}

// User data for personalisation (best-effort — may be null).
$bn_verify_user  = $bn_verify_current_user > 0 ? get_userdata( $bn_verify_current_user ) : false;
$bn_verify_name  = ( $bn_verify_user && '' !== $bn_verify_user->display_name )
	? $bn_verify_user->display_name
	: '';
$bn_verify_email = $bn_verify_user ? $bn_verify_user->user_email : '';

// Feed URL for post-verification redirect.
$bn_feed_url = \BuddyNext\Core\PageRouter::activity_url();

// REST endpoint for resend requests.
$bn_resend_url   = rest_url( 'buddynext/v1/auth/verify/resend' );
$bn_resend_nonce = wp_create_nonce( 'wp_rest' );

// Enqueue the auth CSS bundle (verify.php is rendered outside the hub
// path so PageRouter::enqueue_hub_assets() does not fire here) and the
// dedicated classic resend script — only when an interactive control
// is present.
wp_enqueue_style( 'bn-auth' );
if ( $bn_verify_current_user > 0 && 'success' !== $bn_verify_state ) {
	wp_enqueue_script( 'bn-auth-verify' );
}

get_header();
?>

<div class="bn-verify-page">
	<div class="bn-verify-card">

		<?php if ( 'success' === $bn_verify_state ) : ?>

			<div class="bn-verify-body">
				<div class="bn-verify-icon" data-tone="success" aria-hidden="true">
					<?php buddynext_icon( 'check-circle' ); ?>
				</div>
				<div class="bn-verify-badge-row">
					<span class="bn-badge" data-tone="success">
						<?php esc_html_e( 'Verified', 'buddynext' ); ?>
					</span>
				</div>
				<h1 class="bn-auth-title"><?php esc_html_e( 'Your email is verified', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub">
					<?php esc_html_e( "You're all set to start using the community.", 'buddynext' ); ?>
				</p>
				<div class="bn-verify-actions">
					<a class="bn-btn" data-variant="primary" data-size="lg"
						href="<?php echo esc_url( $bn_feed_url ); ?>">
						<?php esc_html_e( 'Continue to your feed', 'buddynext' ); ?>
						<?php buddynext_icon( 'arrow-right' ); ?>
					</a>
				</div>
			</div>

		<?php elseif ( 'error' === $bn_verify_state ) : ?>

			<div class="bn-verify-body">
				<div class="bn-verify-icon" data-tone="danger" aria-hidden="true">
					<?php buddynext_icon( 'alert-triangle' ); ?>
				</div>
				<div class="bn-verify-badge-row">
					<span class="bn-badge" data-tone="danger">
						<?php esc_html_e( 'Link expired', 'buddynext' ); ?>
					</span>
				</div>
				<h1 class="bn-auth-title"><?php esc_html_e( 'Verification link invalid', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub">
					<?php esc_html_e( 'This verification link has expired or is no longer valid. Request a new link below.', 'buddynext' ); ?>
				</p>

				<?php if ( $bn_verify_current_user > 0 ) : ?>
					<div id="bn-verify-feedback" class="bn-badge" role="status" aria-live="polite" hidden></div>
					<div class="bn-verify-actions">
						<button
							id="bn-resend-btn"
							type="button"
							class="bn-btn"
							data-variant="primary"
							data-size="lg"
							data-url="<?php echo esc_url( $bn_resend_url ); ?>"
							data-nonce="<?php echo esc_attr( $bn_resend_nonce ); ?>"
							data-label-sending="<?php esc_attr_e( 'Sending…', 'buddynext' ); ?>"
							data-label-ready="<?php esc_attr_e( 'Get a new code', 'buddynext' ); ?>"
							data-msg-sent="<?php esc_attr_e( 'Verification email sent. Check your inbox.', 'buddynext' ); ?>"
							data-msg-error="<?php esc_attr_e( 'Something went wrong. Please try again.', 'buddynext' ); ?>">
							<?php buddynext_icon( 'mail' ); ?>
							<span class="bn-resend-label"><?php esc_html_e( 'Get a new code', 'buddynext' ); ?></span>
						</button>
						<a class="bn-btn" data-variant="ghost" data-size="lg"
							href="<?php echo esc_url( \BuddyNext\Core\PageRouter::auth_url() ); ?>">
							<?php esc_html_e( 'Back to sign in', 'buddynext' ); ?>
						</a>
					</div>
				<?php else : ?>
					<div class="bn-verify-actions">
						<a class="bn-btn" data-variant="primary" data-size="lg"
							href="<?php echo esc_url( \BuddyNext\Core\PageRouter::auth_url() ); ?>">
							<?php esc_html_e( 'Back to sign in', 'buddynext' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>

		<?php else : ?>

			<div class="bn-verify-body">
				<div class="bn-verify-icon" data-tone="info" aria-hidden="true">
					<?php buddynext_icon( 'mail' ); ?>
				</div>
				<div class="bn-verify-badge-row">
					<span class="bn-badge" data-tone="info">
						<?php esc_html_e( 'Waiting for confirmation', 'buddynext' ); ?>
					</span>
				</div>
				<h1 class="bn-auth-title"><?php esc_html_e( 'Check your email', 'buddynext' ); ?></h1>

				<div class="bn-verify-progress" role="status" aria-live="polite">
					<div class="bn-progress"
						data-indeterminate
						aria-label="<?php esc_attr_e( "We're verifying your email", 'buddynext' ); ?>">
						<div class="bn-progress__fill"></div>
					</div>
				</div>

				<p class="bn-auth-sub">
					<?php
					if ( '' !== $bn_verify_name ) {
						printf(
							/* translators: %s: user display name */
							esc_html__( "Hi %s, we've sent a verification link to:", 'buddynext' ),
							esc_html( $bn_verify_name )
						);
					} else {
						esc_html_e( "We've sent a verification link to:", 'buddynext' );
					}
					?>
				</p>

				<?php if ( '' !== $bn_verify_email ) : ?>
					<div class="bn-verify-email-chip">
						<?php buddynext_icon( 'mail' ); ?>
						<?php echo esc_html( $bn_verify_email ); ?>
					</div>
				<?php endif; ?>

				<p class="bn-auth-sub">
					<?php esc_html_e( 'Click the link in the email to verify your address. The link expires in 24 hours.', 'buddynext' ); ?>
				</p>

				<div id="bn-verify-feedback" class="bn-badge" role="status" aria-live="polite" hidden></div>
				<div class="bn-verify-actions">
					<button
						id="bn-resend-btn"
						type="button"
						class="bn-btn"
						data-variant="ghost"
						data-size="lg"
						data-url="<?php echo esc_url( $bn_resend_url ); ?>"
						data-nonce="<?php echo esc_attr( $bn_resend_nonce ); ?>"
						data-label-sending="<?php esc_attr_e( 'Sending…', 'buddynext' ); ?>"
						data-label-ready="<?php esc_attr_e( "Didn't receive it? Resend", 'buddynext' ); ?>"
						data-msg-sent="<?php esc_attr_e( 'Verification email sent. Check your inbox.', 'buddynext' ); ?>"
						data-msg-error="<?php esc_attr_e( 'Something went wrong. Please try again.', 'buddynext' ); ?>">
						<?php buddynext_icon( 'mail' ); ?>
						<span class="bn-resend-label"><?php esc_html_e( "Didn't receive it? Resend", 'buddynext' ); ?></span>
					</button>
				</div>
			</div>

		<?php endif; ?>

		<div class="bn-verify-footer">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: support email link */
					esc_html__( 'Having trouble? %s', 'buddynext' ),
					'<a href="mailto:' . esc_attr( (string) get_option( 'admin_email' ) ) . '">' . esc_html__( 'Contact support', 'buddynext' ) . '</a>'
				),
				array(
					'a' => array( 'href' => array() ),
				)
			);
			?>
		</div>

	</div>
</div>

<?php get_footer(); ?>
