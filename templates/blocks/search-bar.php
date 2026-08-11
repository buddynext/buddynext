<?php
/**
 * Block template: Search Bar (v2 design system).
 *
 * Inline search form with leading icon and a v2 .bn-input. Icon comes from
 * the BuddyNext SVG registry via buddynext_icon().
 *
 * Variables:
 *   string $placeholder Input placeholder text.
 *   string $search_in   Which results tab to open: all | members | spaces | posts.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$placeholder = $placeholder ?? '';
if ( '' === $placeholder ) {
	$placeholder = __( 'Search…', 'buddynext' );
}

$bn_sb_scope = isset( $search_in ) ? (string) $search_in : 'all';

// The label needs a `for` and the input needs an id, and this block is
// deliberately placeable more than once - a theme author can put one in the
// header and another in a footer widget. A hardcoded id made the second block
// on a page a duplicate, which is invalid HTML and, more practically, means
// clicking the second label focuses the FIRST input.
$bn_sb_id = wp_unique_id( 'bn-search-input-' );
?>
<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes its own output.
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'bn-block-search-bar' ) ); ?>>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
	<form
		class="bn-search-form"
		role="search"
		action="<?php echo esc_url( \BuddyNext\Core\PageRouter::search_url() ); ?>"
		method="get"
	>
		<label for="<?php echo esc_attr( $bn_sb_id ); ?>" class="screen-reader-text">
			<?php esc_html_e( 'Search', 'buddynext' ); ?>
		</label>
		<?php if ( 'all' !== $bn_sb_scope ) : ?>
			<?php
			// Submitted as the results page's own `type` tab. Omitted for "all"
			// because that IS the results page's default - carrying ?type=all
			// would only make the URL longer and the bookmark less portable.
			?>
			<input type="hidden" name="type" value="<?php echo esc_attr( $bn_sb_scope ); ?>">
		<?php endif; ?>
		<div class="bn-search-input-wrap">
			<span class="bn-search-icon" aria-hidden="true">
				<?php buddynext_icon( 'search' ); ?>
			</span>
			<input
				type="search"
				id="<?php echo esc_attr( $bn_sb_id ); ?>"
				name="q"
				class="bn-input bn-search-input"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				value="<?php echo isset( $_GET['q'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>"
				autocomplete="off"
			>
		</div>
	</form>
</div>
