<?php
/**
 * BuddyNext template part: search-result-section-members.
 *
 * Renders the Members result section on the search results page: section
 * header (title, "shown / total" count, optional "See all" link) followed by
 * a list of compact `.bn-search-row--member` cards for each match.
 *
 * Presentation only: each row is a pre-enriched array from
 * SearchService::enrich_results( …, 'user' ) — name, initials, bio,
 * profile_url, is_following, is_self — so the part runs no queries.
 *
 * Visual output mirrors the legacy in-template member result list — the
 * compact `.bn-search-row` shell — not the full `.bn-md-card` produced by
 * `parts/member-card.php`. Pro/bridges that want the full card can hook
 * `buddynext_part_search_result_section_members_after` to override.
 *
 * Used by: templates/search/results.php.
 *
 * @package BuddyNext
 *
 * @var array  $members      Optional. Enriched member rows. Default [].
 * @var int    $viewer_id    Optional. Currently-viewing user ID. Default 0.
 * @var string $query        Optional. Current search query. Default ''.
 * @var string $active_type  Optional. Active type tab — drives "See all" visibility. Default 'all'.
 * @var int    $total_count  Optional. Total members matching the query. Default 0.
 * @var array  $classes      Optional. Extra CSS classes appended to `.bn-search-section`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_search_result_section_members_before', $args )
 *   - do_action( 'buddynext_part_search_result_section_members_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_search_result_section_members_args',    array $args )
 *   - apply_filters( 'buddynext_part_search_result_section_members_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'members'     => isset( $members ) ? (array) $members : array(),
	'viewer_id'   => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'query'       => isset( $query ) ? (string) $query : '',
	'active_type' => isset( $active_type ) ? (string) $active_type : 'all',
	'total_count' => isset( $total_count ) ? (int) $total_count : 0,
	'classes'     => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_search_result_section_members_args', $args );

if ( empty( $args['members'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-search-section' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_search_result_section_members_classes', $bn_classes, $args );
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

$bn_members     = (array) $args['members'];
$bn_viewer_id   = (int) $args['viewer_id'];
$bn_query       = (string) $args['query'];
$bn_active_type = (string) $args['active_type'];
$bn_total       = (int) $args['total_count'];

do_action( 'buddynext_part_search_result_section_members_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-labelledby="bn-search-section-members">
	<header class="bn-search-section__header">
		<h2 id="bn-search-section-members" class="bn-search-section__title">
			<?php esc_html_e( 'Members', 'buddynext' ); ?>
		</h2>
		<span class="bn-search-section__count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %1$d shown, %2$d total. */
					__( '%1$d of %2$d', 'buddynext' ),
					count( $bn_members ),
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
							'type' => 'members',
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
		foreach ( $bn_members as $person ) :
			$pid          = (int) ( $person['id'] ?? 0 );
			$pname        = (string) ( $person['name'] ?? '' );
			$pinits       = (string) ( $person['initials'] ?? '' );
			$bio_raw      = (string) ( $person['bio'] ?? '' );
			$profile_url  = (string) ( $person['profile_url'] ?? '' );
			$is_following = ! empty( $person['is_following'] );
			$is_self      = ! empty( $person['is_self'] );
			?>
			<article class="bn-card bn-search-row bn-search-row--member" data-interactive
				data-wp-context='
				<?php
				echo esc_attr(
					(string) wp_json_encode(
						array(
							'userId'    => $pid,
							'following' => $is_following,
						)
					)
				);
				?>
									'>
				<a class="bn-search-row__link" href="<?php echo esc_url( $profile_url ); ?>">
					<span class="bn-avatar" data-size="md" aria-hidden="true">
						<?php echo esc_html( $pinits ); ?>
					</span>
					<span class="bn-search-row__info">
						<span class="bn-search-row__title">
							<?php echo esc_html( $pname ); ?>
							<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Member', 'buddynext' ); ?></span>
							<?php
							$bn_sr_meta = (string) apply_filters( 'buddynext_search_member_meta_html', '', $pid );
							if ( '' !== $bn_sr_meta ) {
								echo $bn_sr_meta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by hooked plugin per filter contract
							}
							?>
						</span>
						<?php if ( '' !== $bio_raw ) : ?>
							<span class="bn-search-row__meta">
								<?php echo esc_html( $bio_raw ); ?>
							</span>
						<?php endif; ?>
					</span>
				</a>
				<?php if ( $bn_viewer_id && ! $is_self ) : ?>
					<button type="button"
						class="bn-btn"
						data-wp-class--following="context.following"
						data-variant="<?php echo $is_following ? 'secondary' : 'primary'; ?>"
						data-size="sm"
						data-wp-on--click="actions.toggleFollow"
						data-wp-bind--aria-pressed="context.following">
						<span data-wp-bind--hidden="context.following"><?php esc_html_e( 'Follow', 'buddynext' ); ?></span>
						<span data-wp-bind--hidden="!context.following"><?php esc_html_e( 'Following', 'buddynext' ); ?></span>
					</button>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
</section>
<?php
do_action( 'buddynext_part_search_result_section_members_after', $args );
