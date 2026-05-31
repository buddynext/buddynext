<?php
/**
 * Block template: Search Bar (v2 design system).
 *
 * Inline search form with leading icon and a v2 .bn-input. Icon comes from
 * the BuddyNext SVG registry via buddynext_icon().
 *
 * Variables:
 *   string $placeholder Input placeholder text.
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
	<form
		class="bn-search-form"
		role="search"
		action="<?php echo esc_url( \BuddyNext\Core\PageRouter::search_url() ); ?>"
		method="get"
	>
		<label for="bn-search-input" class="screen-reader-text">
			<?php esc_html_e( 'Search', 'buddynext' ); ?>
		</label>
		<div class="bn-search-input-wrap">
			<span class="bn-search-icon" aria-hidden="true">
				<?php buddynext_icon( 'search' ); ?>
			</span>
			<input
				type="search"
				id="bn-search-input"
				name="q"
				class="bn-input bn-search-input"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				value="<?php echo isset( $_GET['q'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>"
				autocomplete="off"
			>
		</div>
	</form>
</div>
