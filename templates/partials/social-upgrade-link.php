<?php
/**
 * Partial: the way forward when a member's plan withholds following or connecting.
 *
 * Renders nothing without a URL: see PrivacyService::social_state_for().
 *
 * @package BuddyNext
 *
 * @var string $url   Upgrade URL.
 * @var string $label Button label.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $url ) ) {
	return;
}
?>
<a class="bn-btn bn-social-upgrade" data-variant="primary" data-size="sm" href="<?php echo esc_url( (string) $url ); ?>"><?php echo esc_html( (string) ( $label ?? '' ) ); ?></a>
