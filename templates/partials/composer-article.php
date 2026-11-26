<?php
/**
 * BuddyNext: the composer's Article panel.
 *
 * Rendered by partials/composer.php inside the Article tab panel, only for a
 * member whose article provider lets them write. A plain GET form to the
 * provider's editor, so Start writing works without JavaScript: the title goes
 * in the provider's own `title_arg`, or in BuddyNext's `bn_title` when the
 * editor reads none (the write hub then fills Member Blog's title field).
 *
 * Variables:
 *   array  $provider  Normalised provider (ArticleProvider::for_user()).
 *   string $id_prefix Unique id prefix for the panel's fields.
 *
 * Overridable: copy to {theme}/buddynext/partials/composer-article.php.
 *
 * @package BuddyNext
 * @since   1.3.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_art_provider = isset( $provider ) && is_array( $provider ) ? $provider : array();
$bn_art_id       = isset( $id_prefix ) ? (string) $id_prefix : wp_unique_id( 'bn-composer-switch-' );
$bn_art_new_url  = (string) ( $bn_art_provider['new_url'] ?? '' );
if ( '' === $bn_art_new_url ) {
	return;
}

$bn_art_title_arg = '' !== (string) ( $bn_art_provider['title_arg'] ?? '' ) ? (string) $bn_art_provider['title_arg'] : 'bn_title';

// A GET form replaces the action's query string with its fields, so the editor
// address's own args (a plain-permalink ?page_id=) travel as hidden fields.
$bn_art_action = (string) strtok( $bn_art_new_url, '?' );
$bn_art_hidden = array();
wp_parse_str( (string) wp_parse_url( $bn_art_new_url, PHP_URL_QUERY ), $bn_art_hidden );
unset( $bn_art_hidden[ $bn_art_title_arg ] );
?>
<div class="bn-composer-article">
	<p class="bn-composer-article__lede"><?php esc_html_e( 'Write something longer, with a cover image and headings. It appears in the feed as an article card when you publish.', 'buddynext' ); ?></p>

	<?php // One row, the same height as the Post panel, so the feed below does not jump when the tabs switch. ?>
	<form class="bn-composer-article__form" method="get" action="<?php echo esc_url( $bn_art_action ); ?>">
		<?php foreach ( $bn_art_hidden as $bn_art_key => $bn_art_value ) : ?>
			<?php if ( is_scalar( $bn_art_value ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $bn_art_key ); ?>" value="<?php echo esc_attr( (string) $bn_art_value ); ?>">
			<?php endif; ?>
		<?php endforeach; ?>

		<label class="bn-sr-only" for="<?php echo esc_attr( $bn_art_id ); ?>-title"><?php esc_html_e( 'Title (optional)', 'buddynext' ); ?></label>
		<div class="bn-composer-article__row">
			<input class="bn-input bn-composer-article__title"
				type="text"
				id="<?php echo esc_attr( $bn_art_id ); ?>-title"
				name="<?php echo esc_attr( $bn_art_title_arg ); ?>"
				maxlength="200"
				autocomplete="off"
				placeholder="<?php esc_attr_e( 'Title (optional)', 'buddynext' ); ?>">
			<button type="submit" class="bn-btn bn-composer-article__start" data-variant="primary"><?php esc_html_e( 'Start writing', 'buddynext' ); ?></button>
		</div>
	</form>
</div>
