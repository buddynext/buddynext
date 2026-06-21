<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Password-reset template (v2 design system).
 *
 * Branded password reset, a sub-route of the auth hub (/{auth}/reset/). Two modes:
 *   1. Request:  no valid key -> ask for email/username -> POST /auth/lost-password.
 *   2. Set new:  ?key=...&login=... validated -> new-password form -> POST /auth/reset-password.
 *
 * Security is WordPress core's (retrieve_password / check_password_reset_key /
 * reset_password); this screen only provides the branded UX + Interactivity store.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$rest_root  = esc_url_raw( rest_url( 'buddynext/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );
$login_url  = \BuddyNext\Core\PageRouter::auth_url();

// Resolve mode: a key+login in the URL means we are on the set-new-password
// step. Validate the key with WP core up front so an expired/forged link shows
// a clear error instead of a dead form.
$bn_reset_key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_reset_login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_set_mode    = ( '' !== $bn_reset_key && '' !== $bn_reset_login );
$bn_key_valid   = false;

if ( $bn_set_mode ) {
	$bn_key_check = check_password_reset_key( $bn_reset_key, $bn_reset_login );
	$bn_key_valid = ! is_wp_error( $bn_key_check );
}
?>

<div class="bn-auth-page">
	<div class="bn-auth-shell" data-panel="<?php echo (bool) get_option( 'buddynext_auth_panel_show', true ) ? 'on' : 'off'; ?>">
	<?php buddynext_get_template( 'auth/parts/auth-aside.php', array() ); ?>
	<div class="bn-auth-card"
		data-variant="reset"
		data-wp-interactive="buddynext/auth-reset"
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_interactivity_data_wp_context(
			array(
				'login'       => '',
				'password'    => '',
				'submitting'  => false,
				'done'        => false,
				'error'       => '',
				'notice'      => '',
				'fieldErrors' => array(),
				'restNonce'   => $rest_nonce,
				'restUrl'     => $rest_root,
				'resetKey'    => $bn_reset_key,
				'resetLogin'  => $bn_reset_login,
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		>

		<div class="bn-auth-body">
			<section class="bn-auth-panel" data-active>
				<?php buddynext_get_template( 'auth/parts/auth-form-logo.php', array() ); ?>

				<?php if ( $bn_set_mode && ! $bn_key_valid ) : ?>

					<?php // Invalid / expired link. ?>
					<h1 class="bn-auth-title"><?php esc_html_e( 'This link has expired', 'buddynext' ); ?></h1>
					<p class="bn-auth-sub"><?php esc_html_e( 'Password-reset links are valid for a limited time. Request a new one to continue.', 'buddynext' ); ?></p>
					<a class="bn-btn" data-variant="primary" data-size="lg" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::reset_url() ); ?>">
						<?php esc_html_e( 'Request a new link', 'buddynext' ); ?>
					</a>

				<?php elseif ( $bn_set_mode ) : ?>

					<?php // Step 2 — set a new password. ?>
					<h1 class="bn-auth-title"><?php esc_html_e( 'Choose a new password', 'buddynext' ); ?></h1>
					<p class="bn-auth-sub"><?php esc_html_e( 'Enter a new password for your account.', 'buddynext' ); ?></p>

					<div class="bn-auth-field__msg" role="alert" aria-live="polite"
						data-wp-bind--hidden="!state.error"
						data-wp-text="state.error"></div>

					<form class="bn-auth-form" novalidate data-wp-on--submit="actions.setNewPassword">
						<div class="bn-auth-field">
							<label class="bn-auth-label" for="bn-reset-password">
								<?php esc_html_e( 'New password', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="password"
								id="bn-reset-password"
								name="password"
								autocomplete="new-password"
								placeholder="<?php esc_attr_e( 'Choose a strong password', 'buddynext' ); ?>"
								required
								data-wp-bind--disabled="state.submitting"
								data-wp-on--input="actions.setPassword" />
							<span class="bn-auth-field__msg"
								data-wp-bind--hidden="!state.passwordError"
								data-wp-text="state.passwordError"></span>
						</div>

						<button type="submit" class="bn-btn" data-variant="primary" data-size="lg"
							data-wp-bind--disabled="state.submitting">
							<?php esc_html_e( 'Reset password', 'buddynext' ); ?>
						</button>
					</form>

				<?php else : ?>

					<?php // Step 1 — request a reset link. ?>
					<h1 class="bn-auth-title"><?php esc_html_e( 'Reset your password', 'buddynext' ); ?></h1>
					<p class="bn-auth-sub"><?php esc_html_e( 'Enter your email or username and we will send you a link to set a new password.', 'buddynext' ); ?></p>

					<div class="bn-auth-field__msg" role="alert" aria-live="polite"
						data-wp-bind--hidden="!state.error"
						data-wp-text="state.error"></div>

					<div class="bn-auth-notice" role="status" aria-live="polite"
						data-wp-bind--hidden="!state.done"
						data-wp-text="state.notice"></div>

					<form class="bn-auth-form" novalidate
						data-wp-on--submit="actions.requestReset"
						data-wp-bind--hidden="state.done">
						<div class="bn-auth-field">
							<label class="bn-auth-label" for="bn-reset-login">
								<?php esc_html_e( 'Email or username', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="text"
								id="bn-reset-login"
								name="user_login"
								autocomplete="username"
								placeholder="<?php esc_attr_e( 'you@example.com', 'buddynext' ); ?>"
								required
								data-wp-bind--disabled="state.submitting"
								data-wp-on--input="actions.setLogin" />
						</div>

						<button type="submit" class="bn-btn" data-variant="primary" data-size="lg"
							data-wp-bind--disabled="state.submitting">
							<?php esc_html_e( 'Send reset link', 'buddynext' ); ?>
						</button>
					</form>

				<?php endif; ?>

				<p class="bn-auth-alt">
					<a class="bn-auth-link" href="<?php echo esc_url( $login_url ); ?>">
						<?php esc_html_e( 'Back to sign in', 'buddynext' ); ?>
					</a>
				</p>
			</section>
		</div>
	</div>
	</div>
</div>
