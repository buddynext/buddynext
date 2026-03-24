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
<style>
/* ── Design tokens ─────────────────────────────────────────────────────── */
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs: 11px; --text-sm: 13px; --text-base: 15px;
	--text-lg: 17px; --text-xl: 20px; --text-2xl: 24px;
	--leading-body: 1.7;
	--bg: #ffffff; --bg-subtle: #f8f8f7; --bg-hover: #f1f1f0;
	--surface: #ffffff; --border: #e8e8e5; --border-soft: #f1f1ee;
	--text-1: #37352f; --text-2: #787774; --text-3: #aeaca8;
	--brand: #0073aa; --brand-light: #e8f4fb; --brand-hover: #005f8e;
	--green: #059669; --green-bg: #ecfdf5;
	--amber: #d97706; --amber-bg: #fffbeb;
	--red: #dc2626; --red-bg: #fef2f2;
	--s1: 4px; --s2: 8px; --s3: 12px; --s4: 16px; --s5: 20px;
	--s6: 24px; --s8: 32px;
	--radius-sm: 6px; --radius: 10px; --radius-lg: 14px;
}
[data-theme="dark"] {
	--bg: #191919; --bg-subtle: #202020; --bg-hover: #2a2a2a;
	--surface: #252525; --border: #333330; --border-soft: #2c2c2a;
	--text-1: #e8e8e6; --text-2: #9b9b97; --text-3: #6b6b67;
	--brand: #4dabdb; --brand-light: #1a2e3a; --brand-hover: #5fbfe8;
	--green: #34d399; --green-bg: #0d2420;
	--red: #f87171; --red-bg: #2d0f0f;
}

/* ── Auth page ─────────────────────────────────────────────────────────── */
.bn-auth-wrap {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	background: var(--bg-subtle);
	-webkit-font-smoothing: antialiased;
	min-height: 100vh;
	display: grid;
	grid-template-columns: 1fr 1fr;
}

/* Brand panel (left) */
.bn-auth-brand {
	background: linear-gradient(135deg, #0073aa 0%, #1d4ed8 60%, #7c3aed 100%);
	display: flex;
	flex-direction: column;
	justify-content: center;
	padding: 60px 48px;
	color: #fff;
}
.bn-auth-logo {
	font-family: var(--font-display);
	font-size: 28px;
	font-weight: 900;
	margin-bottom: 40px;
	letter-spacing: -1px;
}
.bn-auth-logo span { opacity: 0.7; }
.bn-auth-headline {
	font-family: var(--font-display);
	font-size: 36px;
	font-weight: 800;
	line-height: 1.2;
	margin-bottom: var(--s4);
}
.bn-auth-sub {
	font-size: var(--text-base);
	opacity: 0.85;
	margin-bottom: 40px;
	line-height: var(--leading-body);
}
.bn-auth-features { display: flex; flex-direction: column; gap: var(--s4); }
.bn-auth-feature { display: flex; align-items: flex-start; gap: var(--s3); }
.bn-auth-feature-icon { font-size: 20px; margin-top: 2px; flex-shrink: 0; }
.bn-auth-feature-text { font-size: var(--text-sm); opacity: 0.9; line-height: 1.55; }
.bn-auth-feature-text strong { font-weight: 700; }

.bn-auth-member-count {
	margin-top: 48px;
	display: flex;
	align-items: center;
	gap: var(--s3);
}
.bn-auth-mc-avatars { display: flex; }
.bn-auth-mc-av {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	border: 2px solid rgba(255,255,255,0.8);
	background: rgba(255,255,255,0.3);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 10px;
	font-weight: 700;
	color: #fff;
	margin-left: -8px;
}
.bn-auth-mc-av:first-child { margin-left: 0; }
.bn-auth-mc-text { font-size: var(--text-sm); opacity: 0.9; }

/* Form panel (right) */
.bn-auth-form-panel {
	background: var(--surface);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px var(--s8);
}
.bn-auth-form-inner { width: 100%; max-width: 380px; }

/* Tab toggle */
.bn-auth-tabs {
	display: flex;
	background: var(--bg-hover);
	border-radius: var(--radius);
	padding: 4px;
	margin-bottom: 28px;
}
.bn-auth-tab {
	flex: 1;
	text-align: center;
	padding: 9px;
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	color: var(--text-2);
	text-decoration: none;
	transition: background 0.15s, color 0.15s;
}
.bn-auth-tab.active,
.bn-auth-tab[aria-selected="true"] {
	background: var(--surface);
	color: var(--brand);
	box-shadow: 0 1px 4px rgba(0,0,0,0.1);
}
.bn-auth-tab:not(.active):hover { color: var(--text-1); }

/* Form headings */
.bn-auth-form-title {
	font-family: var(--font-display);
	font-size: 22px;
	font-weight: 800;
	margin-bottom: 4px;
	color: var(--text-1);
}
.bn-auth-form-sub {
	font-size: var(--text-sm);
	color: var(--text-2);
	margin-bottom: var(--s6);
	line-height: 1.5;
}

/* Error/notice banners */
.bn-auth-error {
	background: var(--red-bg);
	color: var(--red);
	border: 1px solid var(--red);
	border-radius: var(--radius-sm);
	padding: var(--s2) var(--s3);
	font-size: var(--text-sm);
	margin-bottom: var(--s4);
}

/* Fields */
.bn-auth-field { margin-bottom: var(--s4); }
.bn-auth-label {
	display: block;
	font-size: var(--text-sm);
	font-weight: 600;
	margin-bottom: 6px;
	color: var(--text-1);
}
.bn-auth-input {
	width: 100%;
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	padding: 10px var(--s3);
	font-size: var(--text-base);
	font-family: var(--font-body);
	color: var(--text-1);
	background: var(--bg);
	transition: border-color 0.15s, box-shadow 0.15s;
}
.bn-auth-input:focus {
	outline: none;
	border-color: var(--brand);
	box-shadow: 0 0 0 3px rgba(0,115,170,0.12);
}
.bn-auth-input::placeholder { color: var(--text-3); }
.bn-auth-field-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: var(--s3);
	margin-bottom: var(--s4);
}
.bn-auth-field-row .bn-auth-field { margin-bottom: 0; }

.bn-auth-forgot {
	font-size: var(--text-xs);
	color: var(--brand);
	cursor: pointer;
	float: right;
	margin-top: 4px;
	text-decoration: none;
}
.bn-auth-forgot:hover { text-decoration: underline; }

/* Password strength */
.bn-auth-strength { margin-top: 6px; }
.bn-auth-strength-bar {
	height: 4px;
	background: var(--border);
	border-radius: 2px;
	overflow: hidden;
}
.bn-auth-strength-fill {
	height: 100%;
	border-radius: 2px;
	background: var(--green);
	width: 0;
	transition: width 0.3s, background 0.3s;
}
.bn-auth-strength-label {
	font-size: var(--text-xs);
	color: var(--green);
	margin-top: 3px;
	font-weight: 600;
}

/* Submit button */
.bn-auth-submit {
	width: 100%;
	background: var(--brand);
	color: #fff;
	padding: 12px;
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 700;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	margin-top: 4px;
	transition: background 0.15s;
}
.bn-auth-submit:hover { background: var(--brand-hover); }

.bn-auth-terms {
	font-size: var(--text-xs);
	color: var(--text-3);
	margin-top: var(--s3);
	text-align: center;
	line-height: 1.5;
}
.bn-auth-terms a { color: var(--brand); text-decoration: none; }
.bn-auth-terms a:hover { text-decoration: underline; }

.bn-auth-switch {
	text-align: center;
	margin-top: var(--s5);
	font-size: var(--text-sm);
	color: var(--text-2);
}
.bn-auth-switch a { color: var(--brand); font-weight: 600; text-decoration: none; }
.bn-auth-switch a:hover { text-decoration: underline; }

/* WP login form overrides (login tab) */
.bn-auth-wp-login-wrap #loginform label { display: none; }
.bn-auth-wp-login-wrap #loginform input[type="text"],
.bn-auth-wp-login-wrap #loginform input[type="password"] {
	width: 100%;
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	padding: 10px var(--s3);
	font-size: var(--text-base);
	font-family: var(--font-body);
	color: var(--text-1);
	background: var(--bg);
	margin-bottom: var(--s4);
	box-sizing: border-box;
}
.bn-auth-wp-login-wrap #loginform input[type="text"]:focus,
.bn-auth-wp-login-wrap #loginform input[type="password"]:focus {
	outline: none;
	border-color: var(--brand);
	box-shadow: 0 0 0 3px rgba(0,115,170,0.12);
}
.bn-auth-wp-login-wrap #loginform .forgetmenot { font-size: var(--text-sm); color: var(--text-2); }
.bn-auth-wp-login-wrap #loginform .submit input[type="submit"] {
	width: 100%;
	background: var(--brand);
	color: #fff;
	padding: 12px;
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 700;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
}
.bn-auth-wp-login-wrap #loginform .submit input[type="submit"]:hover { background: var(--brand-hover); }

/* Tab content hidden by default */
.bn-auth-tab-content { display: none; }
.bn-auth-tab-content.active { display: block; }

/* ── Mobile ── */
@media (max-width: 640px) {
	.bn-auth-wrap { grid-template-columns: 1fr; min-height: auto; }
	.bn-auth-brand { display: none; }
	.bn-auth-form-panel { padding: var(--s6) var(--s4); min-height: 100vh; align-items: flex-start; padding-top: 40px; }
	.bn-auth-field-row { grid-template-columns: 1fr; }
}
</style>

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
