<?php
/**
 * Auth form-side logo.
 *
 * Renders the community's logo above the login / signup form title for a
 * branded experience. Uses BuddyNext's own logo (Settings → Appearance), then
 * the theme's custom logo. Renders nothing when neither is set.
 *
 * @package BuddyNext\Templates
 */

defined( 'ABSPATH' ) || exit;

$bn_form_logo = (string) get_option( 'buddynext_logo_url', '' );
if ( '' === trim( $bn_form_logo ) ) {
	$bn_form_logo_id = (int) get_theme_mod( 'custom_logo' );
	if ( $bn_form_logo_id > 0 ) {
		$bn_form_logo = (string) wp_get_attachment_image_url( $bn_form_logo_id, 'medium' );
	}
}

if ( '' === trim( $bn_form_logo ) ) {
	return;
}
?>
<div class="bn-auth-formlogo">
	<img src="<?php echo esc_url( $bn_form_logo ); ?>" alt="<?php echo esc_attr( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" />
</div>
