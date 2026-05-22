<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Auth shell.
 *
 * Slim, centered single-column shell for auth surfaces (login, signup,
 * verify-email, reset-password). Renders between the active theme's
 * get_header() and get_footer() like the standard hub-shell, but skips
 * the rail / main / sidebar split — auth surfaces should look like a
 * focused identity step, not a community feed.
 *
 * Context variables (supplied by PageRouter or direct render):
 *   $inner_template string  Relative template path under templates/, e.g. 'auth/login.php'.
 *   $hub            string  Hub slug, used for body-class context.
 *   $context        array   Forwarded to the inner template.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! isset( $inner_template ) || '' === (string) $inner_template ) {
	return;
}
if ( ! isset( $hub ) ) {
	$hub = (string) get_query_var( 'bn_hub', '' );
}
if ( ! isset( $context ) || ! is_array( $context ) ) {
	$context = array();
}

ob_start();
buddynext_get_template( (string) $inner_template, $context );
$bn_auth_html = (string) ob_get_clean();
?>
<div class="bn-app bn-app--auth" id="bn-app" data-bn-hub="<?php echo esc_attr( $hub ); ?>">
	<main class="bn-auth" id="bn-main-content" tabindex="-1">
		<?php
		// Trusted: buffered output from buddynext_get_template().
		echo $bn_auth_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</main>
</div>
