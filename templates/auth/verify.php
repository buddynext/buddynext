<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Email verification template (v2 design system).
 *
 * Renders three states driven by query params:
 *   success — ?bn_verified=1 (token processed by VerificationListener).
 *   error   — ?bn_verified=0 (token invalid or expired).
 *   pending — default (logged-in unverified user waiting on inbox click).
 *
 * Email-change flow piggybacks on the same template:
 *   ?bn_email_changed=1 — confirmation token swapped the address.
 *   ?bn_email_changed=0 — confirmation token was stale or already used.
 *
 * Optional ?email=foo@bar query arg is shown in the pending state to
 * confirm which inbox we sent the link to. Resend + request-new-link
 * actions live in the buddynext/auth-verify Interactivity store.
 *
 * Rendered inside the auth-shell — does NOT call get_header()/get_footer().
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

$bn_verify_current_user = get_current_user_id();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_verified_param = isset( $_GET['bn_verified'] ) ? sanitize_key( $_GET['bn_verified'] ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_email_changed_param = isset( $_GET['bn_email_changed'] ) ? sanitize_key( $_GET['bn_email_changed'] ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_email_hint = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( (string) $_GET['email'] ) ) : '';

// Resolve state.
// Email-change confirmations take precedence — they only fire on click of a
// token-bearing link, so the bn_email_changed param is always meaningful.
if ( '1' === $bn_email_changed_param ) {
	$bn_verify_state = 'email_changed';
} elseif ( '0' === $bn_email_changed_param ) {
	$bn_verify_state = 'email_change_failed';
} elseif ( '1' === $bn_verified_param ) {
	$bn_verify_state = 'success';
} elseif ( '0' === $bn_verified_param ) {
	$bn_verify_state = 'error';
} elseif ( $bn_verify_current_user > 0 ) {
	$bn_already_verified = (bool) get_user_meta( $bn_verify_current_user, 'buddynext_email_verified', true );
	if ( $bn_already_verified ) {
		wp_safe_redirect( \BuddyNext\Core\PageRouter::activity_url() );
		exit;
	}
	$bn_verify_state = 'pending';
} elseif ( '' !== $bn_email_hint ) {
	// Guest who just signed up — they have an email hint but no session.
	$bn_verify_state = 'pending';
} else {
	wp_safe_redirect( \BuddyNext\Core\PageRouter::auth_url() );
	exit;
}

$bn_verify_user  = $bn_verify_current_user > 0 ? get_userdata( $bn_verify_current_user ) : false;
$bn_verify_name  = ( $bn_verify_user && '' !== $bn_verify_user->display_name )
	? $bn_verify_user->display_name
	: '';
$bn_verify_email = '';
if ( $bn_verify_user ) {
	$bn_verify_email = $bn_verify_user->user_email;
} elseif ( '' !== $bn_email_hint ) {
	$bn_verify_email = $bn_email_hint;
}

$bn_feed_url       = \BuddyNext\Core\PageRouter::activity_url();
$bn_onboarding_url = \BuddyNext\Core\PageRouter::onboarding_url();
$bn_auth_url       = \BuddyNext\Core\PageRouter::auth_url();
$bn_rest_root      = esc_url_raw( rest_url( 'buddynext/v1/' ) );
$bn_resend_nonce   = wp_create_nonce( 'wp_rest' );

// Ensure the auth bundle CSS is enqueued — verify.php may render via
// the hub bundle path which auto-enqueues bn-auth, but defending against
// the direct-render edge case where dispatch_hub_template bypasses the
// switch (unit-test render path).
wp_enqueue_style( 'bn-auth' );
?>

<div class="bn-verify-page"
	data-wp-interactive="buddynext/auth-verify"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'state'     => $bn_verify_state,
			'email'     => $bn_verify_email,
			'sending'   => false,
			'feedback'  => '',
			'tone'      => '',
			'restNonce' => $bn_resend_nonce,
			'restUrl'   => $bn_rest_root,
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>
	<div class="bn-verify-card">

		<?php if ( 'email_changed' === $bn_verify_state ) : ?>

			<div class="bn-verify-body">
				<div class="bn-verify-icon" data-tone="success" aria-hidden="true">
					<?php buddynext_icon( 'check-circle' ); ?>
				</div>
				<div class="bn-verify-badge-row">
					<span class="bn-badge" data-tone="success">
						<?php esc_html_e( 'Address updated', 'buddynext' ); ?>
					</span>
				</div>
				<h1 class="bn-auth-title"><?php esc_html_e( 'Your new email is active', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub">
					<?php esc_html_e( 'Future notifications and sign-in flows will use your updated address.', 'buddynext' ); ?>
				</p>
				<div class="bn-verify-actions">
					<a class="bn-btn" data-variant="primary" data-size="lg"
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::activity_url() ); ?>">
						<?php esc_html_e( 'Back to feed', 'buddynext' ); ?>
						<?php buddynext_icon( 'arrow-right' ); ?>
					</a>
				</div>
			</div>

		<?php elseif ( 'email_change_failed' === $bn_verify_state ) : ?>

			<div class="bn-verify-body">
				<div class="bn-verify-icon" data-tone="danger" aria-hidden="true">
					<?php buddynext_icon( 'alert-triangle' ); ?>
				</div>
				<div class="bn-verify-badge-row">
					<span class="bn-badge" data-tone="danger">
						<?php esc_html_e( 'Link expired', 'buddynext' ); ?>
					</span>
				</div>
				<h1 class="bn-auth-title"><?php esc_html_e( 'We could not confirm the email change', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub">
					<?php esc_html_e( 'Email-change links expire after 24 hours. Request a fresh confirmation from your profile settings.', 'buddynext' ); ?>
				</p>
				<div class="bn-verify-actions">
					<a class="bn-btn" data-variant="primary" data-size="lg"
						href="<?php echo esc_url( home_url( '/members/' . wp_get_current_user()->user_login . '/edit/' ) ); ?>">
						<?php esc_html_e( 'Open profile settings', 'buddynext' ); ?>
					</a>
				</div>
			</div>

		<?php elseif ( 'success' === $bn_verify_state ) : ?>

			<div class="bn-verify-body">
				<div class="bn-verify-icon" data-tone="success" aria-hidden="true">
					<?php buddynext_icon( 'check-circle' ); ?>
				</div>
				<div class="bn-verify-badge-row">
					<span class="bn-badge" data-tone="success">
						<?php esc_html_e( 'Verified', 'buddynext' ); ?>
					</span>
				</div>
				<h1 class="bn-auth-title"><?php esc_html_e( 'Email verified — welcome!', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub">
					<?php esc_html_e( "You're all set. Let's finish setting up your profile.", 'buddynext' ); ?>
				</p>
				<div class="bn-verify-actions">
					<a class="bn-btn" data-variant="primary" data-size="lg"
						href="<?php echo esc_url( $bn_onboarding_url ); ?>">
						<?php esc_html_e( 'Continue', 'buddynext' ); ?>
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
				<h1 class="bn-auth-title"><?php esc_html_e( 'This link expired or is invalid', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub">
					<?php esc_html_e( 'Verification links expire after 24 hours. Request a fresh link below.', 'buddynext' ); ?>
				</p>

				<div class="bn-badge bn-verify-feedback" role="status" aria-live="polite"
					data-wp-bind--hidden="!state.feedback"
					data-wp-bind--data-tone="context.tone"
					data-wp-text="state.feedback"></div>

				<?php if ( $bn_verify_current_user > 0 ) : ?>
					<div class="bn-verify-actions">
						<button type="button" class="bn-btn"
							data-variant="primary"
							data-size="lg"
							data-wp-bind--disabled="state.sending"
							data-wp-on--click="actions.requestNewLink">
							<?php buddynext_icon( 'mail' ); ?>
							<span data-wp-bind--hidden="state.sending">
								<?php esc_html_e( 'Request a new link', 'buddynext' ); ?>
							</span>
							<span data-wp-bind--hidden="!state.sending">
								<?php esc_html_e( 'Sending...', 'buddynext' ); ?>
							</span>
						</button>
						<a class="bn-btn" data-variant="ghost" data-size="lg"
							href="<?php echo esc_url( $bn_auth_url ); ?>">
							<?php esc_html_e( 'Back to sign in', 'buddynext' ); ?>
						</a>
					</div>
				<?php else : ?>
					<div class="bn-verify-actions">
						<a class="bn-btn" data-variant="primary" data-size="lg"
							href="<?php echo esc_url( $bn_auth_url ); ?>">
							<?php esc_html_e( 'Back to sign in', 'buddynext' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>

		<?php else : // pending. ?>

			<div class="bn-verify-body">
				<div class="bn-verify-icon" data-tone="info" aria-hidden="true">
					<?php buddynext_icon( 'mail' ); ?>
				</div>
				<div class="bn-verify-badge-row">
					<span class="bn-badge" data-tone="info">
						<?php esc_html_e( 'Waiting for confirmation', 'buddynext' ); ?>
					</span>
				</div>
				<h1 class="bn-auth-title"><?php esc_html_e( 'Check your inbox', 'buddynext' ); ?></h1>

				<div class="bn-verify-progress" role="status" aria-live="polite">
					<div class="bn-progress"
						data-indeterminate
						aria-label="<?php esc_attr_e( 'Waiting for verification', 'buddynext' ); ?>">
						<div class="bn-progress__fill"></div>
					</div>
				</div>

				<p class="bn-auth-sub">
					<?php
					if ( '' !== $bn_verify_name ) {
						printf(
							/* translators: %s: user display name */
							esc_html__( 'Hi %s, we sent a verification link to:', 'buddynext' ),
							esc_html( $bn_verify_name )
						);
					} else {
						esc_html_e( 'We sent a verification link to:', 'buddynext' );
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

				<div class="bn-badge bn-verify-feedback" role="status" aria-live="polite"
					data-wp-bind--hidden="!state.feedback"
					data-wp-bind--data-tone="context.tone"
					data-wp-text="state.feedback"></div>

				<?php if ( $bn_verify_current_user > 0 ) : ?>
					<div class="bn-verify-actions">
						<button type="button" class="bn-btn"
							data-variant="ghost"
							data-size="lg"
							data-wp-bind--disabled="state.sending"
							data-wp-on--click="actions.resendEmail">
							<?php buddynext_icon( 'mail' ); ?>
							<span data-wp-bind--hidden="state.sending">
								<?php esc_html_e( "Didn't receive it? Resend", 'buddynext' ); ?>
							</span>
							<span data-wp-bind--hidden="!state.sending">
								<?php esc_html_e( 'Sending...', 'buddynext' ); ?>
							</span>
						</button>
					</div>
				<?php endif; ?>
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
