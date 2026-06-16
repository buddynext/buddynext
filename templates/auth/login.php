<?php
/**
 * BuddyNext — Login template (v2 design system).
 *
 * Centered single-column auth card with brand-gradient hero, sign-in
 * form (email/username + password + Remember-me + Forgot-password +
 * Sign-in primary), optional social SSO row (when third-party providers
 * have registered via the buddynext_auth_social_providers filter), and
 * a "New here?" link to /signup/.
 *
 * Form submit posts to POST /buddynext/v1/auth/login via the
 * buddynext/auth-login Interactivity store. Errors surface inline
 * without a page reload.
 *
 * Already-logged-in users are redirected upstream in
 * PageRouter::dispatch_hub_template().
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

// Only advertise "Create an account" when registration is actually reachable.
// Two independent gates, both enforced by PageRouter/AuthController on /signup/.
// users_can_register (WP core) is the master on/off; when off, /signup/
// redirects to login?registration=disabled. buddynext_reg_mode invite-only has
// no public signup (invitees arrive via their invite link, /signup/?invite=).
// Linking when either gate is closed would send visitors to a dead end.
$registration_open = (bool) get_option( 'users_can_register' )
	&& 'invite' !== (string) get_option( 'buddynext_reg_mode', 'open' );

// Pre-fill from query params (e.g. ?login=failed redirect).
$login_error = '';
if ( isset( $_GET['login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$login_param = sanitize_text_field( wp_unslash( $_GET['login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'failed' === $login_param ) {
		$login_error = __( 'Incorrect username or password. Please try again.', 'buddynext' );
	} elseif ( 'invalidcombo' === $login_param ) {
		$login_error = __( 'No account found with that email address.', 'buddynext' );
	}
}

if ( isset( $_GET['registration'] ) && 'disabled' === sanitize_key( $_GET['registration'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$login_error = __( 'Registration is currently closed. Please sign in with an existing account.', 'buddynext' );
}

// Social sign-in failures (and the approval / takeover-guard notices) are
// passed back here by SocialLogin::bail().
if ( isset( $_GET['bn_social_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$login_error = sanitize_text_field( wp_unslash( (string) $_GET['bn_social_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

$redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_url( wp_unslash( $_GET['redirect_to'] ) ) : \BuddyNext\Core\PageRouter::activity_url(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

/**
 * Filter — third-party social-login providers.
 *
 * @param array<int,array<string,string>> $providers Provider list.
 */
$social_providers = (array) apply_filters( 'buddynext_auth_social_providers', array() );

$rest_root  = esc_url_raw( rest_url( 'buddynext/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );
$signup_url = home_url( '/' . (string) get_option( 'buddynext_slug_signup', 'signup' ) . '/' );
?>
<div class="bn-auth-page">
	<div class="bn-auth-shell" data-panel="<?php echo (bool) get_option( 'buddynext_auth_panel_show', true ) ? 'on' : 'off'; ?>">
	<?php buddynext_get_template( 'auth/parts/auth-aside.php', array() ); ?>
	<div class="bn-auth-card"
		data-variant="login"
		data-wp-class--bn-2fa-active="state.twofaStep"
		data-wp-interactive="buddynext/auth-login"
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_interactivity_data_wp_context(
			array(
				'user'       => '',
				'password'   => '',
				'remember'   => false,
				'submitting' => false,
				'error'      => $login_error,
				'restNonce'  => $rest_nonce,
				'restUrl'    => $rest_root,
				'redirectTo' => esc_url_raw( $redirect_to ),
				'twofaStep'  => false,
				'twofaToken' => '',
				'twofaCode'  => '',
				'twofaError' => '',
				'emailHint'  => '',
				'emailSent'  => false,
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		>

		<div class="bn-auth-body">
			<section class="bn-auth-panel" data-active>
				<?php buddynext_get_template( 'auth/parts/auth-form-logo.php', array() ); ?>
				<h1 class="bn-auth-title"><?php esc_html_e( 'Welcome back', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub"><?php esc_html_e( 'Sign in to your account to continue.', 'buddynext' ); ?></p>

				<div class="bn-auth-field__msg" role="alert" aria-live="polite"
					data-wp-bind--hidden="!state.error"
					data-wp-text="state.error"></div>

				<form class="bn-auth-form"
					novalidate
					data-wp-on--submit="actions.submitLogin">

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-login-user">
							<?php esc_html_e( 'Email or username', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="text"
							id="bn-login-user"
							name="user"
							autocomplete="username"
							required
							data-wp-bind--disabled="state.submitting"
							data-wp-on--input="actions.setUser" />
					</div>

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-login-password">
							<?php esc_html_e( 'Password', 'buddynext' ); ?>
						</label>
						<div class="bn-auth-pw">
							<input class="bn-input bn-auth-pw__input"
								type="password"
								id="bn-login-password"
								name="password"
								autocomplete="current-password"
								required
								data-wp-bind--disabled="state.submitting"
								data-wp-on--input="actions.setPassword" />
							<button type="button" class="bn-auth-pw__toggle"
								data-bn-pw-toggle
								aria-controls="bn-login-password"
								aria-pressed="false"
								aria-label="<?php esc_attr_e( 'Show password', 'buddynext' ); ?>"
								data-show-label="<?php esc_attr_e( 'Show', 'buddynext' ); ?>"
								data-hide-label="<?php esc_attr_e( 'Hide', 'buddynext' ); ?>"
								data-show-aria="<?php esc_attr_e( 'Show password', 'buddynext' ); ?>"
								data-hide-aria="<?php esc_attr_e( 'Hide password', 'buddynext' ); ?>"><?php esc_html_e( 'Show', 'buddynext' ); ?></button>
						</div>
					</div>

					<div class="bn-auth-field bn-auth-field--check">
						<label class="bn-auth-check">
							<input type="checkbox"
								name="remember"
								data-wp-bind--checked="context.remember"
								data-wp-bind--disabled="state.submitting"
								data-wp-on--change="actions.toggleRemember" />
							<span><?php esc_html_e( 'Remember me', 'buddynext' ); ?></span>
						</label>
						<a class="bn-auth-link" href="<?php echo esc_url( wp_lostpassword_url( $redirect_to ) ); ?>">
							<?php esc_html_e( 'Forgot password?', 'buddynext' ); ?>
						</a>
					</div>

					<button class="bn-btn"
						data-variant="primary"
						data-size="lg"
						data-full
						type="submit"
						data-wp-bind--disabled="state.submitDisabled">
						<span data-wp-bind--hidden="state.submitting"><?php esc_html_e( 'Sign in', 'buddynext' ); ?></span>
						<span data-wp-bind--hidden="!state.submitting"><?php esc_html_e( 'Signing in...', 'buddynext' ); ?></span>
						<?php buddynext_icon( 'arrow-right' ); ?>
					</button>
				</form>

				<?php if ( ! empty( $social_providers ) ) : ?>
					<div class="bn-auth-divider"><?php esc_html_e( 'or continue with', 'buddynext' ); ?></div>
					<div class="bn-auth-social">
						<?php
						foreach ( $social_providers as $provider ) :
							$pid    = isset( $provider['id'] ) ? sanitize_key( $provider['id'] ) : '';
							$plabel = isset( $provider['label'] ) ? (string) $provider['label'] : '';
							$picon  = isset( $provider['icon'] ) ? sanitize_key( $provider['icon'] ) : 'globe';
							$purl   = isset( $provider['url'] ) ? esc_url_raw( $provider['url'] ) : '';
							if ( '' === $pid || '' === $purl ) {
								continue;
							}
							?>
							<a class="bn-btn" data-variant="secondary" data-size="lg"
								href="<?php echo esc_url( $purl ); ?>"
								aria-label="
								<?php
								/* translators: %s: provider name (e.g. Google) */
								echo esc_attr( sprintf( __( 'Continue with %s', 'buddynext' ), $plabel ) );
								?>
								">
								<?php buddynext_icon( $picon ); ?>
								<span><?php echo esc_html( $plabel ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( $registration_open ) : ?>
					<div class="bn-auth-foot">
						<?php esc_html_e( 'New here?', 'buddynext' ); ?>
						<a href="<?php echo esc_url( $signup_url ); ?>">
							<?php esc_html_e( 'Create an account', 'buddynext' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</section>

			<!-- Two-step verification panel (shown only after a 2FA-enabled password check) -->
			<section class="bn-auth-panel bn-2fa-loginpanel">
				<h1 class="bn-auth-title"><?php esc_html_e( 'Two-step verification', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub"><?php esc_html_e( 'Enter the 6-digit code from your authenticator app.', 'buddynext' ); ?></p>

				<div class="bn-auth-field__msg" role="alert" aria-live="polite"
					data-wp-bind--hidden="!state.twofaError"
					data-wp-text="state.twofaError"></div>

				<form class="bn-auth-form"
					novalidate
					data-wp-on--submit="actions.submitTwoFactor">

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-login-2fa-code">
							<?php esc_html_e( 'Verification code', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="text"
							id="bn-login-2fa-code"
							name="code"
							inputmode="numeric"
							autocomplete="one-time-code"
							maxlength="9"
							placeholder="123456"
							required
							data-wp-bind--disabled="state.submitting"
							data-wp-on--input="actions.setTwofaCode" />
						<span class="bn-auth-hint">
							<?php esc_html_e( 'Lost your device? Enter one of your backup codes instead.', 'buddynext' ); ?>
						</span>
					</div>

					<button class="bn-btn"
						data-variant="primary"
						data-size="lg"
						data-full
						type="submit"
						data-wp-bind--disabled="state.twofaDisabled">
						<span data-wp-bind--hidden="state.submitting"><?php esc_html_e( 'Verify and sign in', 'buddynext' ); ?></span>
						<span data-wp-bind--hidden="!state.submitting"><?php esc_html_e( 'Verifying...', 'buddynext' ); ?></span>
						<?php buddynext_icon( 'arrow-right' ); ?>
					</button>
				</form>

				<div class="bn-auth-foot">
					<button type="button" class="bn-auth-linkbtn"
						data-wp-bind--disabled="state.emailSent"
						data-wp-on--click="actions.sendEmailCode">
						<span data-wp-bind--hidden="state.emailSent"><?php esc_html_e( 'Email me a code instead', 'buddynext' ); ?></span>
						<span data-wp-bind--hidden="!state.emailSent" data-wp-text="state.emailHintText"></span>
					</button>
				</div>
			</section>
		</div>
	</div>
	</div>
</div>
