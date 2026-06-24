<?php
/**
 * BuddyNext template part: search-result-section-hashtags.
 *
 * Renders the Hashtags result section on the search results page: section
 * header (title, "shown / total" count, optional "See all" link) followed by
 * a list of `.bn-search-row--hashtag` cards. Each card carries the slug, the
 * Hashtag badge, and the post count.
 *
 * Used by: templates/search/results.php.
 *
 * @package BuddyNext
 *
 * @var array  $hashtags    Optional. Hashtag rows (each exposes `slug`, `post_count`). Default [].
 * @var string $query       Optional. Current search query. Default ''.
 * @var string $active_type Optional. Active type tab — drives "See all" visibility. Default 'all'.
 * @var int    $total_count Optional. Total hashtags matching the query. Default 0.
 * @var array  $classes     Optional. Extra CSS classes appended to `.bn-search-section`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_search_result_section_hashtags_before', $args )
 *   - do_action( 'buddynext_part_search_result_section_hashtags_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_search_result_section_hashtags_args',    array $args )
 *   - apply_filters( 'buddynext_part_search_result_section_hashtags_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'hashtags'    => isset( $hashtags ) ? (array) $hashtags : array(),
	'query'       => isset( $query ) ? (string) $query : '',
	'active_type' => isset( $active_type ) ? (string) $active_type : 'all',
	'total_count' => isset( $total_count ) ? (int) $total_count : 0,
	'classes'     => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_search_result_section_hashtags_args', $args );

if ( empty( $args['hashtags'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-search-section' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_search_result_section_hashtags_classes', $bn_classes, $args );
$bn_class   = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

$bn_hashtags    = (array) $args['hashtags'];
$bn_query       = (string) $args['query'];
$bn_active_type = (string) $args['active_type'];
$bn_total       = (int) $args['total_count'];

do_action( 'buddynext_part_search_result_section_hashtags_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-labelledby="bn-search-section-hashtags">
	<header class="bn-search-section__header">
		<h2 id="bn-search-section-hashtags" class="bn-search-section__title">
			<?php esc_html_e( 'Hashtags', 'buddynext' ); ?>
		</h2>
		<span class="bn-search-section__count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %1$d shown, %2$d total. */
					__( '%1$d of %2$d', 'buddynext' ),
					count( $bn_hashtags ),
					$bn_total
				)
			);
			?>
		</span>
		<?php if ( 'all' === $bn_active_type && $bn_total > 0 ) : ?>
			<a class="bn-search-section__seeall"
				href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'q'    => $bn_query,
							'type' => 'hashtags',
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
		<?php foreach ( $bn_hashtags as $ht_row ) : ?>
			<?php
			$ht_slug = (string) $ht_row['slug'];
			$ht_url  = '';
			if ( class_exists( '\\BuddyNext\\Core\\PageRouter' ) ) {
				$ht_url = (string) \BuddyNext\Core\PageRouter::hashtag_feed_url( $ht_slug );
			}
			$ht_count = (int) $ht_row['post_count'];
			?>
			<article class="bn-card bn-search-row bn-search-row--hashtag" data-interactive>
				<a class="bn-search-row__link" href="<?php echo esc_url( $ht_url ); ?>">
					<span class="bn-avatar" data-size="md" aria-hidden="true">
						<?php buddynext_icon( 'hash' ); ?>
					</span>
					<span class="bn-search-row__info">
						<span class="bn-search-row__title">
							#<?php echo esc_html( $ht_slug ); ?>
							<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Hashtag', 'buddynext' ); ?></span>
						</span>
						<span class="bn-search-row__meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d = post count for hashtag. */
									_n( '%d post', '%d posts', $ht_count, 'buddynext' ),
									$ht_count
								)
							);
							?>
						</span>
					</span>
				</a>
			</article>
		<?php endforeach; ?>
	</div>
</section>
<?php
do_action( 'buddynext_part_search_result_section_hashtags_after', $args );
