<?php
/**
 * Auth split-panel branding aside.
 *
 * Shared by login, signup, and verify. Plug-and-play: every field falls back to
 * the site's own identity, so a fresh install shows a branded panel with zero
 * configuration, while admins can override heading / tagline / background under
 * BuddyNext → Settings → Registration → "Login & Sign-up Panel".
 *
 * Render nothing (and let the caller render a single, centered column) when the
 * admin turns the panel off.
 *
 * @package BuddyNext\Templates
 */

defined( 'ABSPATH' ) || exit;

if ( ! (bool) get_option( 'buddynext_auth_panel_show', true ) ) {
	return;
}

// Every value resolves through buddynext_auth_panel_value() — the single source
// of product-level defaults shared with the admin Settings UI, so the panel is
// never empty out of the box (plug-and-play) and the two never disagree.
$bn_panel_heading = buddynext_auth_panel_value( 'buddynext_auth_panel_heading' );
$bn_panel_tagline = buddynext_auth_panel_value( 'buddynext_auth_panel_tagline' );
$bn_panel_image   = buddynext_auth_panel_value( 'buddynext_auth_panel_image' );
$bn_panel_quote   = buddynext_auth_panel_value( 'buddynext_auth_panel_quote' );

// No logo here on purpose: the site header already shows the brand, and the form
// side carries the logo (auth-form-logo.php). A third logo on this panel would
// be redundant — the panel leads with the quote + community identity instead.
?>
<aside class="bn-auth-aside" data-has-image="1"
	style="--bn-auth-aside-image: url('<?php echo esc_url( $bn_panel_image ); ?>');">
	<div class="bn-auth-aside__inner">
		<?php if ( '' !== trim( $bn_panel_quote ) ) : ?>
			<blockquote class="bn-auth-aside__quote"><?php echo esc_html( $bn_panel_quote ); ?></blockquote>
		<?php endif; ?>

		<div class="bn-auth-aside__foot">
			<h2 class="bn-auth-aside__heading"><?php echo esc_html( $bn_panel_heading ); ?></h2>
			<p class="bn-auth-aside__tagline"><?php echo esc_html( $bn_panel_tagline ); ?></p>
		</div>
	</div>
</aside>
