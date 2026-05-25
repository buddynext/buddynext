<?php
/**
 * BuddyNext template part: search-result-section-spaces.
 *
 * Renders the Spaces result section on the search results page: section
 * header (title, "shown / total" count, optional "See all" link) followed by
 * a list of `.bn-search-row--space` cards (avatar, title, member count and
 * description meta line, plus a Join / Joined CTA for logged-in viewers).
 *
 * Used by: templates/search/results.php.
 *
 * @package BuddyNext
 *
 * @var array  $spaces      Optional. Result rows (each exposes `object_id`, `content`). Default [].
 * @var int    $viewer_id   Optional. Currently-viewing user ID. Default 0.
 * @var string $query       Optional. Current search query. Default ''.
 * @var string $active_type Optional. Active type tab — drives "See all" visibility. Default 'all'.
 * @var int    $total_count Optional. Total spaces matching the query. Default 0.
 * @var array  $classes     Optional. Extra CSS classes appended to `.bn-search-section`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_search_result_section_spaces_before', $args )
 *   - do_action( 'buddynext_part_search_result_section_spaces_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_search_result_section_spaces_args',    array $args )
 *   - apply_filters( 'buddynext_part_search_result_section_spaces_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

global $wpdb;

$args = array(
	'spaces'      => isset( $spaces ) ? (array) $spaces : array(),
	'viewer_id'   => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'query'       => isset( $query ) ? (string) $query : '',
	'active_type' => isset( $active_type ) ? (string) $active_type : 'all',
	'total_count' => isset( $total_count ) ? (int) $total_count : 0,
	'classes'     => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_search_result_section_spaces_args', $args );

if ( empty( $args['spaces'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-search-section' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_search_result_section_spaces_classes', $bn_classes, $args );
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

$bn_spaces      = (array) $args['spaces'];
$bn_viewer_id   = (int) $args['viewer_id'];
$bn_query       = (string) $args['query'];
$bn_active_type = (string) $args['active_type'];
$bn_total       = (int) $args['total_count'];

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

do_action( 'buddynext_part_search_result_section_spaces_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-labelledby="bn-search-section-spaces">
	<header class="bn-search-section__header">
		<h2 id="bn-search-section-spaces" class="bn-search-section__title">
			<?php esc_html_e( 'Spaces', 'buddynext' ); ?>
		</h2>
		<span class="bn-search-section__count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %1$d shown, %2$d total. */
					__( '%1$d of %2$d', 'buddynext' ),
					count( $bn_spaces ),
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
							'type' => 'spaces',
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
		<?php foreach ( $bn_spaces as $space ) : ?>
			<?php
			$space_id_int = (int) $space->object_id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$bn_space_row = $wpdb->get_row( $wpdb->prepare( "SELECT name, description, member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id_int ) );
			$space_name   = $bn_space_row ? (string) $bn_space_row->name : (string) $space->content;
			$space_desc   = $bn_space_row ? (string) $bn_space_row->description : '';
			$member_count = $bn_space_row ? (int) $bn_space_row->member_count : 0;
			$space_inits  = $bn_initials_fn( $space_name );
			$is_member    = false;
			if ( $bn_viewer_id ) {
				$is_member = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT 1 FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
						$space_id_int,
						$bn_viewer_id
					)
				);
			}
			$space_url = '';
			if ( class_exists( '\\BuddyNext\\Core\\PageRouter' ) ) {
				$space_url = (string) \BuddyNext\Core\PageRouter::space_url( $space_id_int );
			}
			?>
			<article class="bn-card bn-search-row bn-search-row--space" data-interactive>
				<a class="bn-search-row__link" href="<?php echo esc_url( $space_url ); ?>">
					<span class="bn-avatar" data-size="md" aria-hidden="true">
						<?php echo esc_html( $space_inits ); ?>
					</span>
					<span class="bn-search-row__info">
						<span class="bn-search-row__title">
							<?php echo esc_html( $space_name ); ?>
							<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Space', 'buddynext' ); ?></span>
						</span>
						<?php if ( $member_count > 0 || '' !== $space_desc ) : ?>
							<span class="bn-search-row__meta">
								<?php if ( $member_count > 0 ) : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d = member count. */
											_n( '%d member', '%d members', $member_count, 'buddynext' ),
											$member_count
										)
									);
									?>
									<?php if ( '' !== $space_desc ) : ?>
										<span aria-hidden="true"> &middot; </span>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( '' !== $space_desc ) : ?>
									<?php echo esc_html( $space_desc ); ?>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</span>
				</a>
				<?php if ( $bn_viewer_id ) : ?>
					<button type="button"
						class="bn-btn"
						data-variant="<?php echo $is_member ? 'secondary' : 'primary'; ?>"
						data-size="sm"
						data-wp-on--click="actions.toggleSpaceMembership"
						data-space-id="<?php echo esc_attr( (string) $space_id_int ); ?>"
						aria-pressed="<?php echo $is_member ? 'true' : 'false'; ?>">
						<?php echo $is_member ? esc_html__( 'Joined', 'buddynext' ) : esc_html__( 'Join', 'buddynext' ); ?>
					</button>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
</section>
<?php
do_action( 'buddynext_part_search_result_section_spaces_after', $args );
