<?php
/**
 * BuddyNext — Login / Register template.
 *
 * Renders a tabbed login + registration card.
 * Login tab uses wp_login_form().
 * Register tab submits to wp-login.php?action=register (WordPress core).
 *
 * Not rendered for already-logged-in users (redirects to home feed).
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

// Logged-in redirect is handled upstream in PageRouter::dispatch_hub_template()
// before any output is sent, so this template never runs for logged-in users.

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

// Member count for social proof.
$member_count_raw = count_users();
$member_count     = $member_count_raw['total_users'] ?? 0;
$member_count_fmt = $member_count >= 1000 ? round( $member_count / 1000, 1 ) . 'k' : (string) $member_count;

$register_url = wp_registration_url();
$login_url    = wp_login_url();

$current_url = ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
$redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_url( wp_unslash( $_GET['redirect_to'] ) ) : \BuddyNext\Core\PageRouter::activity_url(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="bn-auth-wrap"
	data-wp-interactive="buddynext/auth"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'activeTab'     => $active_tab,
			'pwStrength'    => 0,
			'pwStrengthLbl' => '',
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>

	<!-- Brand panel -->
	<div class="bn-auth-brand" aria-hidden="true">
		<div class="bn-auth-logo">
			Buddy<span>Next</span>
		</div>
		<div class="bn-auth-headline">
			<?php esc_html_e( 'Your community,', 'buddynext' ); ?><br>
			<?php esc_html_e( 'your way.', 'buddynext' ); ?>
		</div>
		<div class="bn-auth-sub">
			<?php esc_html_e( 'Connect with people who share your passions. Share ideas, ask questions, grow together.', 'buddynext' ); ?>
		</div>
		<div class="bn-auth-features">
			<div class="bn-auth-feature">
				<div class="bn-auth-feature-icon"><?php buddynext_icon( 'home' ); ?></div>
				<div class="bn-auth-feature-text">
					<strong><?php esc_html_e( 'Spaces', 'buddynext' ); ?></strong>
					&mdash; <?php esc_html_e( 'Topic-focused communities with feeds, forums, and media', 'buddynext' ); ?>
				</div>
			</div>
			<div class="bn-auth-feature">
				<div class="bn-auth-feature-icon"><?php buddynext_icon( 'edit' ); ?></div>
				<div class="bn-auth-feature-text">
					<strong><?php esc_html_e( 'Home Feed', 'buddynext' ); ?></strong>
					&mdash; <?php esc_html_e( 'See posts from people and spaces you follow', 'buddynext' ); ?>
				</div>
			</div>
			<div class="bn-auth-feature">
				<div class="bn-auth-feature-icon"><?php buddynext_icon( 'message-circle' ); ?></div>
				<div class="bn-auth-feature-text">
					<strong><?php esc_html_e( 'Direct Messages', 'buddynext' ); ?></strong>
					&mdash; <?php esc_html_e( 'Private conversations with anyone in the community', 'buddynext' ); ?>
				</div>
			</div>
		</div>
		<?php if ( $member_count > 0 ) : ?>
		<div class="bn-auth-member-count">
			<div class="bn-auth-mc-avatars">
				<div class="bn-auth-mc-av">SR</div>
				<div class="bn-auth-mc-av">AJ</div>
				<div class="bn-auth-mc-av">TN</div>
				<div class="bn-auth-mc-av">LP</div>
			</div>
			<div class="bn-auth-mc-text">
				<?php
				/* translators: %s: formatted member count (e.g. "2.8k") */
				$member_count_msg = __( 'Join %s members already here', 'buddynext' );
				echo wp_kses_post(
					'<strong>' . sprintf( esc_html( $member_count_msg ), esc_html( $member_count_fmt ) ) . '</strong>'
				);
				?>
			</div>
		</div>
		<?php endif; ?>
	</div><!-- /brand panel -->

	<!-- Form panel -->
	<div class="bn-auth-form-panel">
		<div class="bn-auth-form-inner">

			<!-- Tab switcher -->
			<div class="bn-auth-tabs" role="tablist">
				<?php if ( $registration_open ) : ?>
				<a class="bn-auth-tab <?php echo 'register' === $active_tab ? 'active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( 'tab', 'register', $current_url ) ); ?>"
					role="tab"
					aria-selected="<?php echo 'register' === $active_tab ? 'true' : 'false'; ?>"
					data-wp-on--click="actions.setTab"
					data-tab="register">
					<?php esc_html_e( 'Create account', 'buddynext' ); ?>
				</a>
				<?php endif; ?>
				<a class="bn-auth-tab <?php echo 'login' === $active_tab ? 'active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( 'tab', 'login', $current_url ) ); ?>"
					role="tab"
					aria-selected="<?php echo 'login' === $active_tab ? 'true' : 'false'; ?>"
					data-wp-on--click="actions.setTab"
					data-tab="login">
					<?php esc_html_e( 'Sign in', 'buddynext' ); ?>
				</a>
			</div>

			<!-- Register tab -->
			<?php if ( $registration_open ) : ?>
			<div class="bn-auth-tab-content <?php echo 'register' === $active_tab ? 'active' : ''; ?>"
				id="bn-auth-register"
				role="tabpanel">

				<div class="bn-auth-form-title"><?php esc_html_e( 'Join the community', 'buddynext' ); ?></div>
				<div class="bn-auth-form-sub"><?php esc_html_e( 'Free forever. No credit card required.', 'buddynext' ); ?></div>

				<?php if ( $register_error ) : ?>
					<div class="bn-auth-error" role="alert"><?php echo esc_html( $register_error ); ?></div>
				<?php endif; ?>

				<form method="post"
					action="<?php echo esc_url( site_url( 'wp-login.php?action=register', 'login_post' ) ); ?>"
					novalidate>

					<div class="bn-auth-field-row">
						<div class="bn-auth-field">
							<label class="bn-auth-label" for="bn-reg-firstname">
								<?php esc_html_e( 'First Name', 'buddynext' ); ?>
							</label>
							<input class="bn-auth-input"
								type="text"
								id="bn-reg-firstname"
								name="first_name"
								autocomplete="given-name"
								placeholder="<?php esc_attr_e( 'First', 'buddynext' ); ?>"
								required />
						</div>
						<div class="bn-auth-field">
							<label class="bn-auth-label" for="bn-reg-lastname">
								<?php esc_html_e( 'Last Name', 'buddynext' ); ?>
							</label>
							<input class="bn-auth-input"
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
						<input class="bn-auth-input"
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
						<input class="bn-auth-input"
							type="text"
							id="bn-reg-login"
							name="user_login"
							autocomplete="username"
							placeholder="@username"
							required />
					</div>

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-reg-password">
							<?php esc_html_e( 'Password', 'buddynext' ); ?>
						</label>
						<input class="bn-auth-input"
							type="password"
							id="bn-reg-password"
							name="user_pass"
							autocomplete="new-password"
							placeholder="<?php esc_attr_e( 'Choose a strong password', 'buddynext' ); ?>"
							data-wp-on--input="actions.checkPasswordStrength"
							required />
						<div class="bn-auth-strength">
							<div class="bn-auth-strength-bar">
								<div class="bn-auth-strength-fill"
									data-wp-style--width="context.pwStrength + '%'"
									data-wp-style--background="state.strengthColor"></div>
							</div>
							<div class="bn-auth-strength-label"
								data-wp-bind--hidden="!context.pwStrengthLbl"
								data-wp-text="context.pwStrengthLbl"></div>
						</div>
					</div>

					<?php wp_nonce_field( 'bn_register', 'bn_register_nonce' ); ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( \BuddyNext\Core\PageRouter::onboarding_url() ); ?>" />

					<button class="bn-auth-submit" type="submit">
						<?php esc_html_e( 'Create account', 'buddynext' ); ?> &rarr;
					</button>

					<div class="bn-auth-terms">
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: Terms of Service URL, 2: Privacy Policy URL */
								__( 'By signing up you agree to our <a href="%1$s">Terms of Service</a> and <a href="%2$s">Privacy Policy</a>', 'buddynext' ),
								esc_url( get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/terms/' ) ),
								esc_url( get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/privacy/' ) )
							)
						);
						?>
					</div>
					<div class="bn-auth-switch">
						<?php esc_html_e( 'Already have an account?', 'buddynext' ); ?>
						<a href="<?php echo esc_url( add_query_arg( 'tab', 'login', $current_url ) ); ?>">
							<?php esc_html_e( 'Sign in', 'buddynext' ); ?>
						</a>
					</div>
				</form>

			</div><!-- /register tab -->
			<?php endif; ?>

			<!-- Login tab (uses wp_login_form) -->
			<div class="bn-auth-tab-content <?php echo 'login' === $active_tab ? 'active' : ''; ?>"
				id="bn-auth-login"
				role="tabpanel">

				<div class="bn-auth-form-title"><?php esc_html_e( 'Welcome back', 'buddynext' ); ?></div>
				<div class="bn-auth-form-sub"><?php esc_html_e( 'Sign in to your account to continue.', 'buddynext' ); ?></div>

				<?php if ( $login_error ) : ?>
					<div class="bn-auth-error" role="alert"><?php echo esc_html( $login_error ); ?></div>
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

				<div style="text-align:center; margin-top: var(--s2);">
					<a class="bn-auth-forgot"
						href="<?php echo esc_url( wp_lostpassword_url( $redirect_to ) ); ?>">
						<?php esc_html_e( 'Forgot password?', 'buddynext' ); ?>
					</a>
				</div>

				<?php if ( $registration_open ) : ?>
				<div class="bn-auth-switch">
					<?php esc_html_e( "Don't have an account?", 'buddynext' ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'register', $current_url ) ); ?>">
						<?php esc_html_e( 'Create one free', 'buddynext' ); ?>
					</a>
				</div>
				<?php endif; ?>

			</div><!-- /login tab -->

		</div><!-- /form inner -->
	</div><!-- /form panel -->

</div><!-- /bn-auth-wrap -->
