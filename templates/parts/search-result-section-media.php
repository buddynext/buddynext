<?php
/**
 * BuddyNext template part: search-result-section-media.
 *
 * Renders the Media result section on the search results page: section
 * header (title, "shown / total" count, optional "See all" link) followed by
 * a list of `.bn-search-row--media` cards. Each card carries the author
 * byline + Media badge and a highlighted text snippet.
 *
 * The composer supplies the highlight helper via `highlight_fn`; the part
 * falls back to escaped plain text when it is not callable.
 *
 * Used by: templates/search/results.php.
 *
 * @package BuddyNext
 *
 * @var array    $media        Optional. Media rows (each exposes `object_id`, `content`, `author_id`). Default [].
 * @var int      $viewer_id    Optional. Currently-viewing user ID. Default 0.
 * @var string   $query        Optional. Current search query. Default ''.
 * @var string   $active_type  Optional. Active type tab — drives "See all" visibility. Default 'all'.
 * @var int      $total_count  Optional. Total media matches. Default 0.
 * @var callable $highlight_fn Optional. fn( string $text, string $query ): string returning safe HTML. Default null.
 * @var array    $classes      Optional. Extra CSS classes appended to `.bn-search-section`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_search_result_section_media_before', $args )
 *   - do_action( 'buddynext_part_search_result_section_media_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_search_result_section_media_args',    array $args )
 *   - apply_filters( 'buddynext_part_search_result_section_media_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'media'        => isset( $media ) ? (array) $media : array(),
	'viewer_id'    => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'query'        => isset( $query ) ? (string) $query : '',
	'active_type'  => isset( $active_type ) ? (string) $active_type : 'all',
	'total_count'  => isset( $total_count ) ? (int) $total_count : 0,
	'highlight_fn' => isset( $highlight_fn ) && is_callable( $highlight_fn ) ? $highlight_fn : null,
	'classes'      => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_search_result_section_media_args', $args );

if ( empty( $args['media'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-search-section' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_search_result_section_media_classes', $bn_classes, $args );
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

$bn_media        = (array) $args['media'];
$bn_query        = (string) $args['query'];
$bn_active_type  = (string) $args['active_type'];
$bn_total        = (int) $args['total_count'];
$bn_highlight_fn = $args['highlight_fn'];

$bn_initials_fn = static function ( string $name ): string {
	$name = trim( $name );
	if ( '' === $name ) {
		return '?';
	}
	$first = mb_substr( $name, 0, 1 );
	$last  = '';
	$space = strrpos( $name, ' ' );
	if ( false !== $space ) {
		$last = mb_substr( $name, $space + 1, 1 );
	}
	return strtoupper( $first . $last );
};

do_action( 'buddynext_part_search_result_section_media_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-labelledby="bn-search-section-media">
	<header class="bn-search-section__header">
		<h2 id="bn-search-section-media" class="bn-search-section__title">
			<?php esc_html_e( 'Media', 'buddynext' ); ?>
		</h2>
		<span class="bn-search-section__count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %1$d shown, %2$d total. */
					__( '%1$d of %2$d', 'buddynext' ),
					count( $bn_media ),
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
							'type' => 'media',
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
		<?php foreach ( $bn_media as $media_row ) : ?>
			<?php
			$media_author = (int) $media_row->author_id;
			$media_user   = $media_author ? get_userdata( $media_author ) : null;
			$media_name   = $media_user ? $media_user->display_name : __( 'Unknown', 'buddynext' );
			$media_init   = $bn_initials_fn( $media_name );
			$snippet_html = null !== $bn_highlight_fn
				? (string) call_user_func( $bn_highlight_fn, (string) $media_row->content, $bn_query )
				: esc_html( (string) $media_row->content );
			?>
			<article class="bn-card bn-search-row bn-search-row--media" data-interactive>
				<header class="bn-search-row__head">
					<span class="bn-avatar" data-size="sm" aria-hidden="true">
						<?php echo esc_html( $media_init ); ?>
					</span>
					<span class="bn-search-row__author"><?php echo esc_html( $media_name ); ?></span>
					<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Media', 'buddynext' ); ?></span>
				</header>
				<div class="bn-search-row__text">
					<?php echo $snippet_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- highlight_fn returns safe HTML. ?>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</section>
<?php
do_action( 'buddynext_part_search_result_section_media_after', $args );
