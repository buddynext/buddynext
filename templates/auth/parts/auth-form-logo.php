<?php
/**
 * Auth form-side logo.
 *
 * OFF BY DEFAULT, because on a normal site it is the THIRD copy of the same logo on
 * screen. The theme header already carries the mark at the top of the page — on desktop
 * and on mobile — and the auth hero panel carries the wordmark and tagline again. A
 * fourth reminder of where you are, stacked directly on top of the fields you came to
 * fill in, is not branding; it is delay. On a phone it is worse: the hero and this logo
 * together push the password field and the submit button below the fold before the member
 * has typed anything.
 *
 * It is a filter and not a deletion because the assumption above can be false: a site that
 * hides the theme header on auth screens (a deliberately distraction-free sign-in) has no
 * other brand mark, and a signup page that never says whose community you are joining is a
 * trust problem. Those sites switch it back on:
 *
 *     add_filter( 'buddynext_auth_show_form_logo', '__return_true' );
 *
 * Renders nothing when no logo is configured, regardless.
 *
 * @package BuddyNext\Templates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether to repeat the logo inside the auth card.
 *
 * Default false: the theme header already shows it. Turn on for a header-less,
 * distraction-free auth screen, where this would be the only brand mark.
 *
 * @since 1.0.8
 *
 * @param bool $show Whether to render the logo above the auth form.
 */
if ( ! apply_filters( 'buddynext_auth_show_form_logo', false ) ) {
	return;
}

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
