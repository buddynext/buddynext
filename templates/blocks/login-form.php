<?php
/**
 * Block template: Login Form (v2 design system).
 *
 * Variables:
 *   string $redirect_url URL to redirect to after successful login ('' = home)
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() ) {
	return;
}

$redirect_url = $redirect_url ?? '';
if ( ! $redirect_url ) {
	$redirect_url = home_url( '/' );
}
?>
<div class="bn-block-login-form">
	<h3 class="bn-block-heading"><?php esc_html_e( 'Sign in', 'buddynext' ); ?></h3>

	<form class="bn-auth-form" id="bn-login-form"
		method="post"
		action="<?php echo esc_url( wp_login_url( $redirect_url ) ); ?>">
		<?php wp_nonce_field( 'buddynext_login', '_wpnonce' ); ?>
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_url ); ?>">

		<div class="bn-auth-field">
			<label for="bn-login-user" class="bn-auth-label">
				<?php esc_html_e( 'Username or email', 'buddynext' ); ?>
			</label>
			<input type="text"
				id="bn-login-user"
				name="log"
				class="bn-input"
				required
				autocomplete="username">
		</div>

		<div class="bn-auth-field">
			<label for="bn-login-pass" class="bn-auth-label">
				<?php esc_html_e( 'Password', 'buddynext' ); ?>
			</label>
			<input type="password"
				id="bn-login-pass"
				name="pwd"
				class="bn-input"
				required
				autocomplete="current-password">
		</div>

		<div class="bn-block-form-row">
			<label>
				<input type="checkbox" name="rememberme" value="forever">
				<?php esc_html_e( 'Remember me', 'buddynext' ); ?>
			</label>
			<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">
				<?php esc_html_e( 'Forgot password?', 'buddynext' ); ?>
			</a>
		</div>

		<button type="submit" class="bn-btn" data-variant="primary" data-size="lg" data-full>
			<?php esc_html_e( 'Sign in', 'buddynext' ); ?>
		</button>

		<p class="bn-auth-foot">
			<?php esc_html_e( "Don't have an account?", 'buddynext' ); ?>
			<a href="<?php echo esc_url( wp_registration_url() ); ?>">
				<?php esc_html_e( 'Create one', 'buddynext' ); ?>
			</a>
		</p>
	</form>
</div>
