<?php
/**
 * BuddyNext template part: the shared article card.
 *
 * One card for every surface that lists articles: the feed (and the Space Blog
 * tab) use the 16:9 `cover` variant, the profile Articles tab the square-tile
 * `row` variant. Same text start, title, excerpt and action row with or without
 * an image; a card without an image simply has no media node.
 *
 * Images come from \BuddyNext\Feed\ArticleImage (hard-cropped sizes). The <img>
 * always carries width and height: the image's own when known, else the box's
 * nominal size, which the fixed-ratio box crops to anyway (object-fit: cover).
 *
 * @package BuddyNext
 * @since   1.3.0
 *
 * @var string                     $variant    'cover' (feed / Space cards) or 'row' (list rows).
 * @var string                     $url        The article's address.
 * @var string                     $title      Title ('' shows "(untitled)").
 * @var string                     $excerpt    Short plain-text intro ('' = none).
 * @var array<string, mixed>|null  $image      {url, width, height} or null for a text-only card.
 * @var string                     $date_iso   ISO 8601 date for <time> ('' = no date line).
 * @var string                     $date_label Date as shown.
 * @var string[]                   $writers    Names of the other writers ('' / empty = none).
 * @var string                     $edit_url   Where the viewer edits this article ('' = no Edit).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_ac_variant = isset( $variant ) && 'row' === $variant ? 'row' : 'cover';
$bn_ac_url     = isset( $url ) ? (string) $url : '';
$bn_ac_title   = isset( $title ) ? trim( (string) $title ) : '';
$bn_ac_title   = '' !== $bn_ac_title ? $bn_ac_title : __( '(untitled)', 'buddynext' );
$bn_ac_excerpt = isset( $excerpt ) ? trim( (string) $excerpt ) : '';
$bn_ac_image   = isset( $image ) && is_array( $image ) && '' !== (string) ( $image['url'] ?? '' ) ? $image : null;
$bn_ac_iso     = isset( $date_iso ) ? (string) $date_iso : '';
$bn_ac_date    = isset( $date_label ) ? (string) $date_label : '';
$bn_ac_writers = isset( $writers ) && is_array( $writers ) ? array_values( array_filter( array_map( 'strval', $writers ) ) ) : array();
$bn_ac_edit    = isset( $edit_url ) ? (string) $edit_url : '';

// The box's own size when the image's is unknown (an external image).
$bn_ac_nominal = 'row' === $bn_ac_variant ? array( 192, 192 ) : array( 1200, 675 );
$bn_ac_w       = (int) ( $bn_ac_image['width'] ?? 0 );
$bn_ac_h       = (int) ( $bn_ac_image['height'] ?? 0 );
if ( $bn_ac_w <= 0 || $bn_ac_h <= 0 ) {
	list( $bn_ac_w, $bn_ac_h ) = $bn_ac_nominal;
}
?>
<article class="bn-article-card" data-variant="<?php echo esc_attr( $bn_ac_variant ); ?>"<?php echo null === $bn_ac_image ? ' data-image="none"' : ''; ?>>
	<?php if ( null !== $bn_ac_image ) : ?>
		<a class="bn-article-card__media" href="<?php echo esc_url( $bn_ac_url ); ?>" tabindex="-1" aria-hidden="true">
			<img src="<?php echo esc_url( (string) $bn_ac_image['url'] ); ?>" width="<?php echo (int) $bn_ac_w; ?>" height="<?php echo (int) $bn_ac_h; ?>" alt="" loading="lazy" decoding="async">
		</a>
	<?php endif; ?>

	<div class="bn-article-card__body">
		<?php if ( 'cover' === $bn_ac_variant ) : ?>
			<span class="bn-article-card__kicker"><?php buddynext_icon( 'file-text' ); ?><?php esc_html_e( 'Article', 'buddynext' ); ?></span>
		<?php endif; ?>

		<h3 class="bn-article-card__title"><a href="<?php echo esc_url( $bn_ac_url ); ?>"><?php echo esc_html( $bn_ac_title ); ?></a></h3>

		<?php if ( '' !== $bn_ac_iso || $bn_ac_writers ) : ?>
			<div class="bn-article-card__meta">
				<?php if ( '' !== $bn_ac_iso ) : ?>
					<time datetime="<?php echo esc_attr( $bn_ac_iso ); ?>"><?php echo esc_html( $bn_ac_date ); ?></time>
				<?php endif; ?>
				<?php if ( $bn_ac_writers ) : ?>
					<?php // Every other writer, named as text: the lead author's tab credits co-authors, a co-author's tab credits the lead. ?>
					<span class="bn-badge bn-badge--muted">
						<?php
						$bn_ac_label = implode( ', ', array_slice( $bn_ac_writers, 0, 3 ) );
						if ( count( $bn_ac_writers ) > 3 ) {
							/* translators: %d: number of further items not shown. */
							$bn_ac_label .= ' ' . sprintf( __( '+%d', 'buddynext' ), count( $bn_ac_writers ) - 3 );
						}
						printf(
							/* translators: %s: names of the post's other writers, e.g. "Aisha, Raj". */
							esc_html__( 'Co-written with %s', 'buddynext' ),
							esc_html( $bn_ac_label )
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $bn_ac_excerpt ) : ?>
			<p class="bn-article-card__excerpt"><?php echo esc_html( $bn_ac_excerpt ); ?></p>
		<?php endif; ?>

		<?php if ( 'cover' === $bn_ac_variant ) : ?>
			<a class="bn-article-card__read" href="<?php echo esc_url( $bn_ac_url ); ?>"><?php esc_html_e( 'Read article', 'buddynext' ); ?></a>
		<?php endif; ?>

		<?php if ( '' !== $bn_ac_edit ) : ?>
			<div class="bn-article-card__actions">
				<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( $bn_ac_edit ); ?>">
					<?php esc_html_e( 'Edit', 'buddynext' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
</article>
