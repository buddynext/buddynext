<?php
/**
 * BuddyNext template part: guest "Join the community" invitation card.
 *
 * The one conversion CTA shown to logged-out visitors on the public discovery and
 * landing surfaces — the explore feed, the members and spaces directories, and a
 * single shared post. It is deliberately NOT shown on an individual member profile
 * (the Follow control there already routes to login) so a guest browsing many
 * profiles is not shown the same card on every one.
 *
 * The caller guards on the guest state; this part only renders the card. Extracted
 * from templates/feed/explore.php so every surface shares one styled component and
 * one copy source instead of re-authoring the markup.
 *
 * @package BuddyNext
 * @since   1.2.0
 *
 * @var string $lede     Optional. One-line invitation copy tailored to the surface.
 *                       Default: the general explore copy.
 * @var string $redirect Optional. URL to return to after login. Default: the current page.
 */

defined( 'ABSPATH' ) || exit;

$bn_gc_lede = ( isset( $lede ) && '' !== (string) $lede )
	? (string) $lede
	: __( "You're browsing as a guest. Create an account to post, follow people, and join spaces.", 'buddynext' );

if ( isset( $redirect ) && '' !== (string) $redirect ) {
	$bn_gc_redirect = (string) $redirect;
} else {
	$bn_gc_permalink = get_permalink();
	$bn_gc_redirect  = $bn_gc_permalink
		? (string) $bn_gc_permalink
		: home_url( user_trailingslashit( (string) ( $GLOBALS['wp']->request ?? '' ) ) );
}
?>
<div class="bn-guest-banner" role="banner">
	<h3><?php esc_html_e( 'Join the community', 'buddynext' ); ?></h3>
	<p><?php echo esc_html( $bn_gc_lede ); ?></p>
	<div class="bn-banner-btns">
		<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::signup_url() ); ?>" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Sign up free', 'buddynext' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( 'redirect_to', $bn_gc_redirect, \BuddyNext\Core\PageRouter::auth_url() ) ); ?>" class="bn-btn" data-variant="ghost"><?php esc_html_e( 'Log in', 'buddynext' ); ?></a>
	</div>
</div>
