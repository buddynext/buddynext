<?php
/**
 * Block template: Registration Form
 *
 * Variables:
 *   string $redirect_url URL to redirect to after successful registration ('' = home)
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

$nonce = wp_create_nonce( 'buddynext_register' );
?>
<div class="bn-block-registration-form">
	<h3 class="bn-block-heading"><?php esc_html_e( 'Create an account', 'buddynext' ); ?></h3>
	<form class="bn-form" id="bn-registration-form"
		data-action="buddynext/v1/auth/register"
		data-nonce="<?php echo esc_attr( $nonce ); ?>"
		data-redirect="<?php echo esc_url( $redirect_url ); ?>">
		<div class="bn-form__group">
			<label for="bn-reg-username" class="bn-form__label"><?php esc_html_e( 'Username', 'buddynext' ); ?></label>
			<input type="text" id="bn-reg-username" name="user_login" class="bn-form__input" required autocomplete="username">
		</div>
		<div class="bn-form__group">
			<label for="bn-reg-email" class="bn-form__label"><?php esc_html_e( 'Email address', 'buddynext' ); ?></label>
			<input type="email" id="bn-reg-email" name="user_email" class="bn-form__input" required autocomplete="email">
		</div>
		<div class="bn-form__group">
			<label for="bn-reg-password" class="bn-form__label"><?php esc_html_e( 'Password', 'buddynext' ); ?></label>
			<input type="password" id="bn-reg-password" name="user_pass" class="bn-form__input" required autocomplete="new-password" minlength="8">
		</div>
		<div class="bn-form__actions">
			<button type="submit" class="bn-btn bn-btn--primary bn-btn--full">
				<?php esc_html_e( 'Create account', 'buddynext' ); ?>
			</button>
		</div>
		<p class="bn-form__footer">
			<?php esc_html_e( 'Already have an account?', 'buddynext' ); ?>
			<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Sign in', 'buddynext' ); ?></a>
		</p>
	</form>
</div>
