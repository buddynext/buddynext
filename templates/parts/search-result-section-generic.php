<?php
/**
 * BuddyNext template part: search-result-section-generic.
 *
 * Renders a result section for any indexed object type that has no bespoke
 * section part — e.g. `job` (Career Board) or `listing` (Listora). The set of
 * types is discovered dynamically by templates/search/results.php from
 * SearchService::available_types(), so a new addon that indexes its own
 * object_type gets a search tab + section automatically.
 *
 * Each row links to the object's permalink with a highlighted snippet. Raw
 * index rows (object_id / title / content) are passed in; the only per-item
 * query is get_permalink(), which WordPress caches.
 *
 * Used by: templates/search/results.php.
 *
 * @package BuddyNext
 *
 * @var array    $items        Raw index rows (object_id, title, content). Default [].
 * @var string   $type         Object-type slug (e.g. 'job'). Required.
 * @var string   $label        Human, pluralised section label (e.g. 'Jobs'). Required.
 * @var string   $query        Current search query. Default ''.
 * @var string   $active_type  Active type tab — drives "See all" visibility. Default 'all'.
 * @var int      $total_count  Total matches for this type. Default 0.
 * @var callable $highlight_fn Optional snippet highlighter ( string $text, string $query ): string.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_items  = isset( $items ) ? (array) $items : array();
$bn_type   = isset( $type ) ? sanitize_key( (string) $type ) : '';
$bn_label  = isset( $label ) && '' !== (string) $label ? (string) $label : ucfirst( $bn_type );
$bn_query  = isset( $query ) ? (string) $query : '';
$bn_active = isset( $active_type ) ? (string) $active_type : 'all';
$bn_total  = isset( $total_count ) ? (int) $total_count : 0;
$bn_hl     = ( isset( $highlight_fn ) && is_callable( $highlight_fn ) ) ? $highlight_fn : null;

if ( empty( $bn_items ) || '' === $bn_type ) {
	return;
}

$bn_section_id = 'bn-search-section-' . $bn_type;
$bn_item_badge = ucfirst( str_replace( array( '-', '_' ), ' ', $bn_type ) );
?>
<section class="bn-search-section bn-search-section--<?php echo esc_attr( $bn_type ); ?>" aria-labelledby="<?php echo esc_attr( $bn_section_id ); ?>">
	<header class="bn-search-section__header">
		<h2 id="<?php echo esc_attr( $bn_section_id ); ?>" class="bn-search-section__title">
			<?php echo esc_html( $bn_label ); ?>
		</h2>
		<span class="bn-search-section__count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %1$d shown, %2$d total. */
					__( '%1$d of %2$d', 'buddynext' ),
					count( $bn_items ),
					$bn_total
				)
			);
			?>
		</span>
		<?php if ( 'all' === $bn_active && $bn_total > 0 ) : ?>
			<a class="bn-search-section__seeall"
				href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'q'    => $bn_query,
							'type' => $bn_type,
						)
					)
				);
				?>
						">
				<?php esc_html_e( 'See all', 'buddynext' ); ?>
				<span aria-hidden="true"><?php buddynext_icon( 'arrow-right' ); ?></span>
			</a>
		<?php endif; ?>
	</header>

	<div class="bn-search-results__list">
		<?php
		foreach ( $bn_items as $bn_item ) :
			$bn_oid = (int) ( $bn_item['object_id'] ?? 0 );
			if ( $bn_oid <= 0 ) {
				continue;
			}
			$bn_title = (string) ( $bn_item['title'] ?? '' );
			if ( '' === $bn_title ) {
				$bn_title = (string) get_the_title( $bn_oid );
			}
			if ( '' === $bn_title ) {
				$bn_title = sprintf( /* translators: %d: object id. */ __( 'Item #%d', 'buddynext' ), $bn_oid );
			}
			$bn_body = (string) ( $bn_item['content'] ?? '' );
			$bn_url  = (string) get_permalink( $bn_oid );
			$bn_snip = ( null !== $bn_hl )
				? (string) $bn_hl( '' !== $bn_body ? $bn_body : $bn_title, $bn_query )
				: esc_html( $bn_body );
			?>
			<article class="bn-card bn-search-row bn-search-row--<?php echo esc_attr( $bn_type ); ?>">
				<?php if ( '' !== $bn_url ) : ?>
					<a class="bn-search-row__link" href="<?php echo esc_url( $bn_url ); ?>">
				<?php else : ?>
					<div class="bn-search-row__link">
				<?php endif; ?>
					<span class="bn-search-row__info">
						<span class="bn-search-row__title">
							<?php echo esc_html( $bn_title ); ?>
							<span class="bn-badge" data-tone="accent"><?php echo esc_html( $bn_item_badge ); ?></span>
						</span>
						<?php if ( '' !== trim( wp_strip_all_tags( $bn_snip ) ) ) : ?>
							<span class="bn-search-row__meta">
								<?php echo wp_kses( $bn_snip, array( 'mark' => array() ) ); ?>
							</span>
						<?php endif; ?>
					</span>
				<?php if ( '' !== $bn_url ) : ?>
					</a>
				<?php else : ?>
					</div>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
</section>
