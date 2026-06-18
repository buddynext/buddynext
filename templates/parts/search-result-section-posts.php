<?php
/**
 * BuddyNext template part: search-result-section-posts.
 *
 * Renders the Posts result section on the search results page: section
 * header (title, "shown / total" count, optional "See all" link) followed by
 * a list of `.bn-search-row--post` cards. Each card carries the author byline,
 * age, the highlighted snippet, and the reaction / comment / share stats.
 *
 * Presentation only: each row is a pre-enriched array from
 * SearchService::enrich_results( …, 'post' ) — author_name, author_initials,
 * age, reactions, comments, shares, snippet_source (raw text the highlight
 * helper marks) — so the part runs no queries.
 *
 * The composer supplies the highlight helper via `highlight_fn`; the part
 * falls back to escaped plain text when it is not callable.
 *
 * Used by: templates/search/results.php.
 *
 * @package BuddyNext
 *
 * @var array    $posts        Optional. Enriched post rows. Default [].
 * @var int      $viewer_id    Optional. Currently-viewing user ID. Default 0.
 * @var string   $query        Optional. Current search query. Default ''.
 * @var string   $active_type  Optional. Active type tab — drives "See all" visibility. Default 'all'.
 * @var int      $total_count  Optional. Total posts matching the query. Default 0.
 * @var callable $highlight_fn Optional. fn( string $text, string $query ): string returning safe HTML. Default null.
 * @var array    $classes      Optional. Extra CSS classes appended to `.bn-search-section`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_search_result_section_posts_before', $args )
 *   - do_action( 'buddynext_part_search_result_section_posts_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_search_result_section_posts_args',    array $args )
 *   - apply_filters( 'buddynext_part_search_result_section_posts_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'posts'        => isset( $posts ) ? (array) $posts : array(),
	'viewer_id'    => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'query'        => isset( $query ) ? (string) $query : '',
	'active_type'  => isset( $active_type ) ? (string) $active_type : 'all',
	'total_count'  => isset( $total_count ) ? (int) $total_count : 0,
	'highlight_fn' => isset( $highlight_fn ) && is_callable( $highlight_fn ) ? $highlight_fn : null,
	'classes'      => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_search_result_section_posts_args', $args );

if ( empty( $args['posts'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-search-section' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_search_result_section_posts_classes', $bn_classes, $args );
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

$bn_posts        = (array) $args['posts'];
$bn_query        = (string) $args['query'];
$bn_active_type  = (string) $args['active_type'];
$bn_total        = (int) $args['total_count'];
$bn_highlight_fn = $args['highlight_fn'];

do_action( 'buddynext_part_search_result_section_posts_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-labelledby="bn-search-section-posts">
	<header class="bn-search-section__header">
		<h2 id="bn-search-section-posts" class="bn-search-section__title">
			<?php esc_html_e( 'Posts', 'buddynext' ); ?>
		</h2>
		<span class="bn-search-section__count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %1$d shown, %2$d total. */
					__( '%1$d of %2$d', 'buddynext' ),
					count( $bn_posts ),
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
							'type' => 'posts',
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
		foreach ( $bn_posts as $post_item ) :
			$author_name  = (string) ( $post_item['author_name'] ?? '' );
			$author_inits = (string) ( $post_item['author_initials'] ?? '' );
			$post_age     = (string) ( $post_item['age'] ?? '' );
			$reactions    = (int) ( $post_item['reactions'] ?? 0 );
			$comments_c   = (int) ( $post_item['comments'] ?? 0 );
			$shares_c     = (int) ( $post_item['shares'] ?? 0 );
			$snippet_src  = (string) ( $post_item['snippet_source'] ?? '' );
			$snippet_html = null !== $bn_highlight_fn
				? (string) call_user_func( $bn_highlight_fn, $snippet_src, $bn_query )
				: esc_html( $snippet_src );
			?>
			<article class="bn-card bn-search-row bn-search-row--post" data-interactive>
				<header class="bn-search-row__head">
					<span class="bn-avatar" data-size="sm" aria-hidden="true">
						<?php echo esc_html( $author_inits ); ?>
					</span>
					<span class="bn-search-row__author"><?php echo esc_html( $author_name ); ?></span>
					<?php if ( '' !== $post_age ) : ?>
						<span class="bn-search-row__time">&middot; <?php echo $post_age; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_time_ago() returns esc_html()'d output. ?></span>
					<?php endif; ?>
					<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Post', 'buddynext' ); ?></span>
				</header>
				<div class="bn-search-row__text">
					<?php echo $snippet_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- highlight_fn returns safe HTML. ?>
				</div>
				<?php if ( $reactions || $comments_c || $shares_c ) : ?>
					<footer class="bn-search-row__stats">
						<span class="bn-search-row__stat">
							<span aria-hidden="true"><?php buddynext_icon( 'heart' ); ?></span>
							<?php echo esc_html( (string) $reactions ); ?>
						</span>
						<span class="bn-search-row__stat">
							<span aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
							<?php echo esc_html( (string) $comments_c ); ?>
						</span>
						<span class="bn-search-row__stat">
							<span aria-hidden="true"><?php buddynext_icon( 'share' ); ?></span>
							<?php echo esc_html( (string) $shares_c ); ?>
						</span>
					</footer>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
</section>
<?php
do_action( 'buddynext_part_search_result_section_posts_after', $args );
