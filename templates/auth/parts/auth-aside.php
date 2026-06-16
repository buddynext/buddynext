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

// Logo priority for a branded panel: BuddyNext's own logo (Settings →
// Appearance) → the theme's custom logo → the BuddyNext mark glyph.
$bn_logo_html = '';
$bn_bn_logo   = (string) get_option( 'buddynext_logo_url', '' );
if ( '' !== trim( $bn_bn_logo ) ) {
	$bn_logo_html = '<img class="bn-auth-aside__logo-img" src="' . esc_url( $bn_bn_logo ) . '" alt="' . esc_attr( $bn_panel_heading ) . '" />';
} else {
	$bn_custom_logo_id = (int) get_theme_mod( 'custom_logo' );
	if ( $bn_custom_logo_id > 0 ) {
		$bn_logo_html = (string) wp_get_attachment_image(
			$bn_custom_logo_id,
			'medium',
			false,
			array(
				'class' => 'bn-auth-aside__logo-img',
				'alt'   => esc_attr( $bn_panel_heading ),
			)
		);
	}
}
?>
<aside class="bn-auth-aside" data-has-image="1"
	style="--bn-auth-aside-image: url('<?php echo esc_url( $bn_panel_image ); ?>');">
	<div class="bn-auth-aside__inner">
		<div class="bn-auth-aside__brand">
			<?php if ( '' !== $bn_logo_html ) : ?>
				<?php echo wp_kses_post( $bn_logo_html ); ?>
			<?php else : ?>
				<span class="bn-auth-aside__mark" aria-hidden="true"><?php buddynext_icon( 'home' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( '' !== trim( $bn_panel_quote ) ) : ?>
			<blockquote class="bn-auth-aside__quote"><?php echo esc_html( $bn_panel_quote ); ?></blockquote>
		<?php endif; ?>

		<div class="bn-auth-aside__foot">
			<h2 class="bn-auth-aside__heading"><?php echo esc_html( $bn_panel_heading ); ?></h2>
			<p class="bn-auth-aside__tagline"><?php echo esc_html( $bn_panel_tagline ); ?></p>
		</div>
	</div>
</aside>
