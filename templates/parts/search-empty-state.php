<?php
/**
 * BuddyNext template part: search-empty-state.
 *
 * Renders the search-results empty card. Two distinct copy/CTA states are
 * supported via the `state` arg:
 *   - `no_query`   — viewer has not submitted a query yet.
 *   - `no_results` — query was submitted but produced zero matches.
 *
 * The wrapper shell (icon + title + body) is identical between states; only
 * the copy and the optional "Search the entire community" CTA differ.
 *
 * Used by: templates/search/results.php.
 *
 * @package BuddyNext
 *
 * @var string $state   Optional. 'no_query' | 'no_results'. Default 'no_query'.
 * @var string $query   Optional. Current search query string (used by 'no_results' copy). Default ''.
 * @var array  $classes Optional. Extra CSS classes appended to `.bn-search-empty`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_search_empty_state_before', $args )
 *   - do_action( 'buddynext_part_search_empty_state_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_search_empty_state_args',    array $args )
 *   - apply_filters( 'buddynext_part_search_empty_state_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_allowed_states = array( 'no_query', 'no_results' );

$args = array(
	'state'   => ( isset( $state ) && in_array( (string) $state, $bn_allowed_states, true ) ) ? (string) $state : 'no_query',
	'query'   => isset( $query ) ? (string) $query : '',
	'classes' => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_search_empty_state_args', $args );

$bn_classes = array_merge( array( 'bn-search-empty' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_search_empty_state_classes', $bn_classes, $args );
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

$bn_state = (string) $args['state'];
$bn_query = (string) $args['query'];

do_action( 'buddynext_part_search_empty_state_before', $args );
?>
<?php if ( 'no_results' === $bn_state ) : ?>
	<div class="<?php echo esc_attr( $bn_class ); ?>">
		<span class="bn-search-empty__icon" aria-hidden="true">
			<?php buddynext_icon( 'search' ); ?>
		</span>
		<h2 class="bn-search-empty__title">
			<?php
			printf(
				/* translators: %s = search query (escaped). */
				esc_html__( 'Nothing found for %s', 'buddynext' ),
				'<strong>"' . esc_html( $bn_query ) . '"</strong>'
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped.
			?>
		</h2>
		<p class="bn-search-empty__body">
			<?php esc_html_e( 'Try different keywords, or remove a filter from the sidebar.', 'buddynext' ); ?>
		</p>
		<a class="bn-btn" data-variant="primary" data-size="md"
			href="<?php echo esc_url( remove_query_arg( array( 'q', 'type', 'date', 'sort' ) ) ); ?>">
			<?php esc_html_e( 'Search the entire community', 'buddynext' ); ?>
		</a>
	</div>
<?php else : ?>
	<div class="<?php echo esc_attr( $bn_class ); ?>">
		<span class="bn-search-empty__icon" aria-hidden="true">
			<?php buddynext_icon( 'search' ); ?>
		</span>
		<h2 class="bn-search-empty__title">
			<?php esc_html_e( 'Search the community', 'buddynext' ); ?>
		</h2>
		<p class="bn-search-empty__body">
			<?php esc_html_e( 'Find members, posts, spaces and hashtags. Press', 'buddynext' ); ?>
			<kbd class="bn-kbd">/</kbd>
			<?php esc_html_e( 'anywhere to focus, or', 'buddynext' ); ?>
			<kbd class="bn-kbd">Esc</kbd>
			<?php esc_html_e( 'to clear.', 'buddynext' ); ?>
		</p>
	</div>
<?php endif; ?>
<?php
do_action( 'buddynext_part_search_empty_state_after', $args );
