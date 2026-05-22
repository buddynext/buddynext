<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Signup template (v2 design system).
 *
 * Dedicated signup surface — posts to POST /buddynext/v1/auth/register.
 * Inline validation per field. Password strength meter. On success the
 * server returns the redirect target (verify-email page when email
 * verification is enabled, otherwise the onboarding wizard).
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! (bool) get_option( 'users_can_register' ) ) {
	// Registration closed — bounce to login with a notice.
	wp_safe_redirect( add_query_arg( 'registration', 'disabled', \BuddyNext\Core\PageRouter::auth_url() ) );
	exit;
}

$rest_root   = esc_url_raw( rest_url( 'buddynext/v1/' ) );
$rest_nonce  = wp_create_nonce( 'wp_rest' );
$login_url   = \BuddyNext\Core\PageRouter::auth_url();
$terms_url   = get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/terms/' );
$privacy_url = get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/privacy/' );
?>

<div class="bn-auth-page">
	<div class="bn-auth-card"
		data-variant="register"
		data-wp-interactive="buddynext/auth-signup"
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_interactivity_data_wp_context(
			array(
				'email'            => '',
				'userLogin'        => '',
				'password'         => '',
				'termsAgreed'      => false,
				'passwordStrength' => 0,
				'strengthLabel'    => '',
				'submitting'       => false,
				'error'            => '',
				'fieldErrors'      => array(),
				'restNonce'        => $rest_nonce,
				'restUrl'          => $rest_root,
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		>

		<div class="bn-auth-hero" aria-hidden="true">
			<span class="bn-auth-hero__logo"><?php buddynext_icon( 'home' ); ?></span>
			<span class="bn-auth-hero__wordmark">Buddy<span>Next</span></span>
		</div>

		<div class="bn-auth-body">
			<section class="bn-auth-panel" data-active>
				<h1 class="bn-auth-title"><?php esc_html_e( 'Join the community', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub"><?php esc_html_e( 'Free forever. No credit card required.', 'buddynext' ); ?></p>

				<div class="bn-auth-field__msg" role="alert" aria-live="polite"
					data-wp-bind--hidden="!state.error"
					data-wp-text="state.error"></div>

				<form class="bn-auth-form"
					novalidate
					data-wp-on--submit="actions.submitSignup">

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-signup-email">
							<?php esc_html_e( 'Email', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="email"
							id="bn-signup-email"
							name="email"
							autocomplete="email"
							placeholder="you@example.com"
							required
							data-wp-bind--disabled="state.submitting"
							data-wp-bind--aria-invalid="state.emailInvalid"
							data-wp-on--input="actions.setEmail" />
						<span class="bn-auth-field__msg"
							data-wp-bind--hidden="!state.emailError"
							data-wp-text="state.emailError"></span>
					</div>

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-signup-username">
							<?php esc_html_e( 'Username', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="text"
							id="bn-signup-username"
							name="user_login"
							autocomplete="username"
							placeholder="@username"
							aria-describedby="bn-signup-username-hint"
							required
							data-wp-bind--disabled="state.submitting"
							data-wp-bind--aria-invalid="state.usernameInvalid"
							data-wp-on--input="actions.setUserLogin" />
						<span class="bn-auth-hint" id="bn-signup-username-hint">
							<?php esc_html_e( '3–24 characters: letters, numbers, underscore.', 'buddynext' ); ?>
						</span>
						<span class="bn-auth-field__msg"
							data-wp-bind--hidden="!state.usernameError"
							data-wp-text="state.usernameError"></span>
					</div>

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-signup-password">
							<?php esc_html_e( 'Password', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="password"
							id="bn-signup-password"
							name="password"
							autocomplete="new-password"
							placeholder="<?php esc_attr_e( 'Choose a strong password', 'buddynext' ); ?>"
							aria-describedby="bn-signup-password-meter"
							required
							data-wp-bind--disabled="state.submitting"
							data-wp-bind--aria-invalid="state.passwordInvalid"
							data-wp-on--input="actions.setPassword" />
						<div class="bn-auth-strength" id="bn-signup-password-meter" aria-live="polite">
							<div class="bn-progress"
								role="progressbar"
								aria-valuemin="0"
								aria-valuemax="4"
								data-wp-bind--aria-valuenow="context.passwordStrength">
								<div class="bn-progress__fill"
									data-wp-style--width="state.strengthWidth"></div>
							</div>
							<span class="bn-auth-strength__label"
								data-wp-text="state.strengthLabelText"></span>
						</div>
						<span class="bn-auth-field__msg"
							data-wp-bind--hidden="!state.passwordError"
							data-wp-text="state.passwordError"></span>
					</div>

					<div class="bn-auth-field bn-auth-field--check">
						<label class="bn-auth-check">
							<input type="checkbox"
								name="terms_agreed"
								data-wp-bind--checked="context.termsAgreed"
								data-wp-bind--disabled="state.submitting"
								data-wp-on--change="actions.toggleTerms" />
							<span>
								<?php
								echo wp_kses(
									sprintf(
										/* translators: 1: Terms URL, 2: Privacy URL */
										__( 'I agree to the <a href="%1$s" target="_blank" rel="noopener">Terms of Service</a> and <a href="%2$s" target="_blank" rel="noopener">Privacy Policy</a>.', 'buddynext' ),
										esc_url( $terms_url ),
										esc_url( $privacy_url )
									),
									array(
										'a' => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
									)
								);
								?>
							</span>
						</label>
						<span class="bn-auth-field__msg"
							data-wp-bind--hidden="!state.termsError"
							data-wp-text="state.termsError"></span>
					</div>

					<button class="bn-btn"
						data-variant="primary"
						data-size="lg"
						data-full
						type="submit"
						data-wp-bind--disabled="state.submitDisabled">
						<span data-wp-bind--hidden="state.submitting"><?php esc_html_e( 'Create account', 'buddynext' ); ?></span>
						<span data-wp-bind--hidden="!state.submitting"><?php esc_html_e( 'Creating account...', 'buddynext' ); ?></span>
						<?php buddynext_icon( 'arrow-right' ); ?>
					</button>
				</form>

				<div class="bn-auth-foot">
					<?php esc_html_e( 'Already have an account?', 'buddynext' ); ?>
					<a href="<?php echo esc_url( $login_url ); ?>">
						<?php esc_html_e( 'Sign in', 'buddynext' ); ?>
					</a>
				</div>
			</section>
		</div>
	</div>
</div>
