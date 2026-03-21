<?php
/**
 * Block template: Search Bar
 *
 * Variables:
 *   string $placeholder Input placeholder text
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$placeholder = $placeholder ?? '';
if ( '' === $placeholder ) {
	$placeholder = __( 'Search…', 'buddynext' );
}
?>
<div class="bn-block-search-bar">
	<form class="bn-search-form" role="search" action="<?php echo esc_url( home_url( '/search/' ) ); ?>" method="get">
		<label for="bn-search-input" class="screen-reader-text"><?php esc_html_e( 'Search', 'buddynext' ); ?></label>
		<div class="bn-search-input-wrap">
			<span class="bn-search-icon" aria-hidden="true">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
					<circle cx="7" cy="7" r="4.5" stroke="currentColor" stroke-width="1.5"/>
					<path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
				</svg>
			</span>
			<input type="search" id="bn-search-input" name="bn_q" class="bn-search-input"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				value="<?php echo esc_attr( get_query_var( 'bn_q', '' ) ); ?>"
				autocomplete="off">
		</div>
	</form>
</div>
