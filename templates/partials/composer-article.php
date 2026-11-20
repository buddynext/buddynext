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
 *   array  $summary   ArticleProvider::composer_summary(): drafts, articles, draft.
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
$bn_art_summary  = isset( $summary ) && is_array( $summary ) ? $summary : array();
$bn_art_id       = isset( $id_prefix ) ? (string) $id_prefix : wp_unique_id( 'bn-composer-switch-' );
$bn_art_new_url  = (string) ( $bn_art_provider['new_url'] ?? '' );
if ( '' === $bn_art_new_url ) {
	return;
}

$bn_art_title_arg = '' !== (string) ( $bn_art_provider['title_arg'] ?? '' ) ? (string) $bn_art_provider['title_arg'] : 'bn_title';
$bn_art_draft     = is_array( $bn_art_summary['draft'] ?? null ) ? $bn_art_summary['draft'] : null;

// A GET form replaces the action's query string with its fields, so the editor
// address's own args (a plain-permalink ?page_id=) travel as hidden fields.
$bn_art_action = (string) strtok( $bn_art_new_url, '?' );
$bn_art_hidden = array();
wp_parse_str( (string) wp_parse_url( $bn_art_new_url, PHP_URL_QUERY ), $bn_art_hidden );
unset( $bn_art_hidden[ $bn_art_title_arg ] );

$bn_art_links = array(
	array(
		'url'   => (string) ( $bn_art_provider['drafts_url'] ?? '' ),
		'label' => __( 'My drafts', 'buddynext' ),
		'count' => (int) ( $bn_art_summary['drafts'] ?? 0 ),
	),
	array(
		'url'   => (string) ( $bn_art_provider['my_articles_url'] ?? '' ),
		'label' => __( 'My articles', 'buddynext' ),
		'count' => (int) ( $bn_art_summary['articles'] ?? 0 ),
	),
);
?>
<div class="bn-composer-article">
	<p class="bn-composer-article__lede"><?php esc_html_e( 'Write something longer, with a cover image and headings. It appears in the feed as an article card when you publish.', 'buddynext' ); ?></p>

	<?php if ( null !== $bn_art_draft && '' !== (string) $bn_art_draft['edit_url'] ) : ?>
		<div class="bn-composer-article__resume">
			<div class="bn-composer-article__resume-text">
				<strong class="bn-composer-article__resume-title">
					<?php
					printf(
						/* translators: %s: the draft's title. */
						esc_html__( 'Continue: %s', 'buddynext' ),
						esc_html( '' !== trim( (string) $bn_art_draft['title'] ) ? (string) $bn_art_draft['title'] : __( 'Untitled draft', 'buddynext' ) )
					);
					?>
				</strong>
				<span class="bn-composer-article__resume-meta">
					<?php
					printf(
						/* translators: %s: how long ago the draft was edited, e.g. "2h ago". */
						esc_html__( 'Draft, edited %s', 'buddynext' ),
						buddynext_time_ago( (string) $bn_art_draft['modified_gmt'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_time_ago() returns an escaped label.
					);
					?>
				</span>
			</div>
			<a class="bn-btn bn-composer-article__resume-action" data-variant="secondary" data-bn-full-load href="<?php echo esc_url( (string) $bn_art_draft['edit_url'] ); ?>"><?php esc_html_e( 'Continue writing', 'buddynext' ); ?></a>
		</div>
	<?php endif; ?>

	<form class="bn-composer-article__form" method="get" action="<?php echo esc_url( $bn_art_action ); ?>">
		<?php foreach ( $bn_art_hidden as $bn_art_key => $bn_art_value ) : ?>
			<?php if ( is_scalar( $bn_art_value ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $bn_art_key ); ?>" value="<?php echo esc_attr( (string) $bn_art_value ); ?>">
			<?php endif; ?>
		<?php endforeach; ?>

		<label class="bn-composer-article__label" for="<?php echo esc_attr( $bn_art_id ); ?>-title">
			<?php
			echo esc_html(
				null !== $bn_art_draft
					? __( 'Or start a new article: title (optional)', 'buddynext' )
					: __( 'Title (optional)', 'buddynext' )
			);
			?>
		</label>
		<input class="bn-input bn-composer-article__title"
			type="text"
			id="<?php echo esc_attr( $bn_art_id ); ?>-title"
			name="<?php echo esc_attr( $bn_art_title_arg ); ?>"
			maxlength="200"
			autocomplete="off"
			placeholder="<?php esc_attr_e( 'Give it a title', 'buddynext' ); ?>">

		<div class="bn-composer-article__bar">
			<ul class="bn-composer-article__links">
				<?php foreach ( $bn_art_links as $bn_art_link ) : ?>
					<?php if ( '' !== $bn_art_link['url'] ) : ?>
						<li>
							<a class="bn-composer-article__link" data-bn-full-load href="<?php echo esc_url( $bn_art_link['url'] ); ?>">
								<?php echo esc_html( $bn_art_link['label'] ); ?>
								<span class="bn-composer-article__count"><?php echo esc_html( number_format_i18n( $bn_art_link['count'] ) ); ?></span>
							</a>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<button type="submit" class="bn-btn bn-composer-article__start" data-variant="primary"><?php esc_html_e( 'Start writing', 'buddynext' ); ?></button>
		</div>
	</form>
</div>
