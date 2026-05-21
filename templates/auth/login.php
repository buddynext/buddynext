<?php
/**
 * BuddyNext — Login / Register template (v2 design system).
 *
 * Single centered card with brand-gradient hero, tabbed login + register
 * panels, and an optional social-login row. Composes v2 primitives:
 * .bn-btn[data-variant], .bn-input, .bn-tabs/.bn-tab, .bn-progress.
 *
 * Not rendered for already-logged-in users (redirects upstream in
 * PageRouter::dispatch_hub_template()).
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

// Determine active tab from query string (default: login).
$active_tab = ( isset( $_GET['tab'] ) && 'register' === sanitize_key( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	? 'register'
	: 'login';

// Registration allowed?
$registration_open = (bool) get_option( 'users_can_register' );

// Error / redirect messages from WordPress.
$login_error = '';
if ( isset( $_GET['login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$login_param = sanitize_text_field( wp_unslash( $_GET['login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'failed' === $login_param ) {
		$login_error = __( 'Incorrect username or password. Please try again.', 'buddynext' );
	} elseif ( 'invalidcombo' === $login_param ) {
		$login_error = __( 'No account found with that email address.', 'buddynext' );
	}
}

$register_error = '';
if ( isset( $_GET['registration'] ) && 'disabled' === sanitize_key( $_GET['registration'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$register_error = __( 'Registration is currently closed. Please check back later.', 'buddynext' );
}

$current_url = ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
$redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_url( wp_unslash( $_GET['redirect_to'] ) ) : \BuddyNext\Core\PageRouter::activity_url(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

/**
 * Filter — third-party social-login providers.
 *
 * Each entry should be:
 *   array(
 *     'id'    => 'google',
 *     'label' => __( 'Google', 'buddynext' ),
 *     'icon'  => 'globe',           // bn-icon name
 *     'url'   => 'https://…',       // OAuth start URL
 *   )
 *
 * Default is an empty list — the social-login row is hidden when no
 * provider is registered. This keeps the slot ready for OAuth add-ons
 * without shipping dead UI in the core plugin.
 *
 * @param array<int,array<string,string>> $providers Provider list.
 */
$social_providers = (array) apply_filters( 'buddynext_auth_social_providers', array() );

$register_card_variant = 'register' === $active_tab ? 'register' : 'login';
?>
<div class="bn-auth-page">
	<div class="bn-auth-card"
		data-variant="<?php echo esc_attr( $register_card_variant ); ?>"
		data-wp-interactive="buddynext/auth"
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_interactivity_data_wp_context(
			array(
				'tab'              => $active_tab,
				'passwordStrength' => 0,
				'strengthLabel'    => '',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		>

		<!-- Brand gradient hero -->
		<div class="bn-auth-hero" aria-hidden="true">
			<span class="bn-auth-hero__logo"><?php buddynext_icon( 'home' ); ?></span>
			<span class="bn-auth-hero__wordmark">Buddy<span>Next</span></span>
		</div>

		<div class="bn-auth-body">

			<!-- Tab switcher (Sign in / Create account) -->
			<?php if ( $registration_open ) : ?>
			<div class="bn-tabs bn-auth-tabs" role="tablist" data-density="compact">
				<a class="bn-tab"
					href="<?php echo esc_url( add_query_arg( 'tab', 'login', $current_url ) ); ?>"
					role="tab"
					id="bn-auth-tab-login"
					aria-controls="bn-auth-panel-login"
					aria-selected="<?php echo 'login' === $active_tab ? 'true' : 'false'; ?>"
					data-wp-on--click="actions.setTab"
					data-tab="login">
					<?php esc_html_e( 'Sign in', 'buddynext' ); ?>
				</a>
				<a class="bn-tab"
					href="<?php echo esc_url( add_query_arg( 'tab', 'register', $current_url ) ); ?>"
					role="tab"
					id="bn-auth-tab-register"
					aria-controls="bn-auth-panel-register"
					aria-selected="<?php echo 'register' === $active_tab ? 'true' : 'false'; ?>"
					data-wp-on--click="actions.setTab"
					data-tab="register">
					<?php esc_html_e( 'Create account', 'buddynext' ); ?>
				</a>
			</div>
			<?php endif; ?>

			<!-- Login panel -->
			<section class="bn-auth-panel"
				id="bn-auth-panel-login"
				role="tabpanel"
				aria-labelledby="bn-auth-tab-login"
				<?php echo 'login' === $active_tab ? 'data-active' : ''; ?>>

				<h1 class="bn-auth-title"><?php esc_html_e( 'Welcome back', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub"><?php esc_html_e( 'Sign in to your account to continue.', 'buddynext' ); ?></p>

				<?php if ( '' !== $login_error ) : ?>
					<div class="bn-auth-field__msg" role="alert" aria-live="polite">
						<?php echo esc_html( $login_error ); ?>
					</div>
				<?php endif; ?>

				<div class="bn-auth-wp-login-wrap">
					<?php
					wp_login_form(
						array(
							'echo'           => true,
							'redirect'       => esc_url( $redirect_to ),
							'label_username' => __( 'Username or Email Address', 'buddynext' ),
							'label_password' => __( 'Password', 'buddynext' ),
							'label_remember' => __( 'Remember me', 'buddynext' ),
							'label_log_in'   => __( 'Sign in', 'buddynext' ),
							'id_username'    => 'bn-login-username',
							'id_password'    => 'bn-login-password',
							'id_remember'    => 'bn-login-remember',
							'id_submit'      => 'bn-login-submit',
							'remember'       => true,
							'value_username' => '',
							'value_remember' => false,
						)
					);
					?>
				</div>

				<div class="bn-auth-foot">
					<a href="<?php echo esc_url( wp_lostpassword_url( $redirect_to ) ); ?>">
						<?php esc_html_e( 'Forgot password?', 'buddynext' ); ?>
					</a>
				</div>

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
					<?php esc_html_e( "Don't have an account?", 'buddynext' ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'register', $current_url ) ); ?>">
						<?php esc_html_e( 'Create one free', 'buddynext' ); ?>
					</a>
				</div>
				<?php endif; ?>

			</section>

			<!-- Register panel -->
			<?php if ( $registration_open ) : ?>
			<section class="bn-auth-panel"
				id="bn-auth-panel-register"
				role="tabpanel"
				aria-labelledby="bn-auth-tab-register"
				<?php echo 'register' === $active_tab ? 'data-active' : ''; ?>>

				<h1 class="bn-auth-title"><?php esc_html_e( 'Join the community', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub"><?php esc_html_e( 'Free forever. No credit card required.', 'buddynext' ); ?></p>

				<?php if ( '' !== $register_error ) : ?>
					<div class="bn-auth-field__msg" role="alert" aria-live="polite">
						<?php echo esc_html( $register_error ); ?>
					</div>
				<?php endif; ?>

				<form class="bn-auth-form"
					method="post"
					action="<?php echo esc_url( site_url( 'wp-login.php?action=register', 'login_post' ) ); ?>"
					novalidate>

					<div class="bn-auth-field__row">
						<div class="bn-auth-field">
							<label class="bn-auth-label" for="bn-reg-firstname">
								<?php esc_html_e( 'First name', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="text"
								id="bn-reg-firstname"
								name="first_name"
								autocomplete="given-name"
								placeholder="<?php esc_attr_e( 'First', 'buddynext' ); ?>"
								required />
						</div>
						<div class="bn-auth-field">
							<label class="bn-auth-label" for="bn-reg-lastname">
								<?php esc_html_e( 'Last name', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="text"
								id="bn-reg-lastname"
								name="last_name"
								autocomplete="family-name"
								placeholder="<?php esc_attr_e( 'Last', 'buddynext' ); ?>" />
						</div>
					</div>

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-reg-email">
							<?php esc_html_e( 'Email', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="email"
							id="bn-reg-email"
							name="user_email"
							autocomplete="email"
							placeholder="you@example.com"
							required />
					</div>

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-reg-login">
							<?php esc_html_e( 'Username', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="text"
							id="bn-reg-login"
							name="user_login"
							autocomplete="username"
							placeholder="@username"
							aria-describedby="bn-reg-login-hint"
							required />
						<span class="bn-auth-hint" id="bn-reg-login-hint">
							<?php esc_html_e( '3–24 characters: letters, numbers, underscore.', 'buddynext' ); ?>
						</span>
					</div>

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-reg-password">
							<?php esc_html_e( 'Password', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="password"
							id="bn-reg-password"
							name="user_pass"
							autocomplete="new-password"
							placeholder="<?php esc_attr_e( 'Choose a strong password', 'buddynext' ); ?>"
							aria-describedby="bn-reg-password-meter"
							data-wp-on--input="actions.checkPasswordStrength"
							required />
						<div class="bn-auth-strength" id="bn-reg-password-meter" aria-live="polite">
							<div class="bn-progress"
								role="progressbar"
								aria-valuemin="0"
								aria-valuemax="4"
								data-wp-bind--aria-valuenow="context.passwordStrength">
								<div class="bn-progress__fill"
									data-wp-style--width="state.strengthWidth"></div>
							</div>
							<span class="bn-auth-strength__label"
								data-wp-text="context.strengthLabel"></span>
						</div>
					</div>

					<?php wp_nonce_field( 'bn_register', 'bn_register_nonce' ); ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( \BuddyNext\Core\PageRouter::onboarding_url() ); ?>" />

					<button class="bn-btn" data-variant="primary" data-size="lg" data-full type="submit">
						<?php esc_html_e( 'Create account', 'buddynext' ); ?>
						<?php buddynext_icon( 'arrow-right' ); ?>
					</button>
				</form>

				<?php if ( ! empty( $social_providers ) ) : ?>
				<div class="bn-auth-divider"><?php esc_html_e( 'or sign up with', 'buddynext' ); ?></div>
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
								/* translators: %s: provider name */
								echo esc_attr( sprintf( __( 'Sign up with %s', 'buddynext' ), $plabel ) );
							?>
							">
							<?php buddynext_icon( $picon ); ?>
							<span><?php echo esc_html( $plabel ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<p class="bn-auth-foot bn-auth-foot--terms">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: Terms of Service URL, 2: Privacy Policy URL */
							__( 'By signing up you agree to our <a href="%1$s">Terms of Service</a> and <a href="%2$s">Privacy Policy</a>.', 'buddynext' ),
							esc_url( get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/terms/' ) ),
							esc_url( get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/privacy/' ) )
						)
					);
					?>
				</p>

				<div class="bn-auth-foot">
					<?php esc_html_e( 'Already have an account?', 'buddynext' ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'login', $current_url ) ); ?>">
						<?php esc_html_e( 'Sign in', 'buddynext' ); ?>
					</a>
				</div>

			</section>
			<?php endif; ?>

		</div>
	</div>
</div>
