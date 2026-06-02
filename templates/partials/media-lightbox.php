<?php
/**
 * BuddyNext media lightbox shell.
 *
 * Printed once in the footer on BuddyNext front-end pages. The BN lightbox JS
 * (assets/js/media/lightbox.js) populates `[data-bn-lb-stage]` and toggles
 * visibility when a media tile is clicked. BN-native UX (no WPMediaVerse).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="bn-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Media viewer', 'buddynext' ); ?>" hidden>
	<button type="button" class="bn-lightbox__backdrop" data-bn-lb-close aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"></button>
	<div class="bn-lightbox__dialog">
		<button type="button" class="bn-lightbox__close" data-bn-lb-close aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
		<button type="button" class="bn-lightbox__nav bn-lightbox__nav--prev" data-bn-lb-prev aria-label="<?php esc_attr_e( 'Previous', 'buddynext' ); ?>"><?php buddynext_icon( 'chevron-left' ); ?></button>
		<div class="bn-lightbox__stage" data-bn-lb-stage></div>
		<button type="button" class="bn-lightbox__nav bn-lightbox__nav--next" data-bn-lb-next aria-label="<?php esc_attr_e( 'Next', 'buddynext' ); ?>"><?php buddynext_icon( 'chevron-right' ); ?></button>
	</div>
</div>
