<?php
/**
 * BuddyNext template part: hashtag-hero.
 *
 * Renders the hashtag header card: title with hash icon and optional trend
 * chip, the follow / create-post action buttons, the three-stat strip
 * (posts / contributors / first-used), and the sort tab bar
 * (Latest / Top / Following).
 *
 * Used by: templates/hashtags/feed.php.
 *
 * @package BuddyNext
 *
 * @var string $hashtag_slug      Required. The hashtag slug (without leading #).
 * @var int    $post_count_total  Optional. Total posts tagged. Default 0.
 * @var int    $contributor_count Optional. Distinct contributor count. Default 0.
 * @var string $first_used_label  Optional. Localised first-used date string. Default ''.
 * @var bool   $follows_hashtag   Optional. Whether the current user follows. Default false.
 * @var int    $current_user_id   Optional. Viewing user ID. Default 0.
 * @var bool   $is_logged_in      Optional. Whether viewer is logged in. Default false.
 * @var string $bn_sort           Optional. Active sort: latest|top|following. Default 'latest'.
 * @var array  $classes           Optional. Extra CSS classes appended to `.bn-hashtag-header`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_hashtag_hero_before', $args )
 *   - do_action( 'buddynext_part_hashtag_hero_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_hashtag_hero_args',    array $args )
 *   - apply_filters( 'buddynext_part_hashtag_hero_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'hashtag_slug'      => isset( $hashtag_slug ) ? (string) $hashtag_slug : '',
	'post_count_total'  => isset( $post_count_total ) ? (int) $post_count_total : 0,
	'contributor_count' => isset( $contributor_count ) ? (int) $contributor_count : 0,
	'first_used_label'  => isset( $first_used_label ) ? (string) $first_used_label : '',
	'follows_hashtag'   => isset( $follows_hashtag ) ? (bool) $follows_hashtag : false,
	'current_user_id'   => isset( $current_user_id ) ? (int) $current_user_id : 0,
	'is_logged_in'      => isset( $is_logged_in ) ? (bool) $is_logged_in : false,
	'bn_sort'           => isset( $bn_sort ) ? (string) $bn_sort : 'latest',
	'classes'           => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_hashtag_hero_args', $args );

if ( '' === (string) $args['hashtag_slug'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-hashtag-header' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_hashtag_hero_classes', $bn_classes, $args );
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

$bn_slug        = (string) $args['hashtag_slug'];
$bn_post_count  = (int) $args['post_count_total'];
$bn_contribs    = (int) $args['contributor_count'];
$bn_first_used  = (string) $args['first_used_label'];
$bn_follows     = (bool) $args['follows_hashtag'];
$bn_logged_in   = (bool) $args['is_logged_in'];
$bn_sort_active = (string) $args['bn_sort'];

do_action( 'buddynext_part_hashtag_hero_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-labelledby="bn-hashtag-title">
	<header class="bn-hashtag-header__top">
		<div class="bn-hashtag-header__heading">
			<h1 class="bn-hashtag-header__title" id="bn-hashtag-title">
				<span aria-hidden="true">#</span><?php echo esc_html( $bn_slug ); ?>
			</h1>
			<?php if ( $bn_post_count > 0 ) : ?>
				<span class="bn-hashtag-header__trend" aria-hidden="true">
					<?php buddynext_icon( 'trending-up' ); ?>
				</span>
			<?php endif; ?>
		</div>

		<div class="bn-hashtag-header__actions">
			<?php if ( $bn_logged_in ) : ?>
				<button
					class="bn-btn bn-htf<?php echo $bn_follows ? ' following' : ''; ?>"
					data-variant="<?php echo $bn_follows ? 'secondary' : 'primary'; ?>"
					data-size="md"
					data-current-state="<?php echo $bn_follows ? 'following' : 'follow'; ?>"
					type="button"
					data-wp-on--click="actions.toggleFollowHashtag"
					data-hashtag="<?php echo esc_attr( $bn_slug ); ?>"
					aria-pressed="<?php echo $bn_follows ? 'true' : 'false'; ?>"
				>
					<?php // Both labels render; the .following class on the button (toggled by toggleFollowHashtag) swaps which is visible, so the button stays in sync after a click. ?>
					<span class="bn-htf__icon" aria-hidden="true"><?php buddynext_icon( 'check' ); ?></span>
					<span class="bn-htf__on"><?php esc_html_e( 'Following', 'buddynext' ); ?></span>
					<span class="bn-htf__off">
						<?php
						printf(
							/* translators: %s: hashtag slug */
							esc_html__( 'Follow #%s', 'buddynext' ),
							esc_html( $bn_slug )
						);
						?>
					</span>
				</button>
				<button
					class="bn-btn"
					data-variant="ghost"
					data-size="md"
					type="button"
					data-wp-on--click="actions.openComposerWithTag"
					data-hashtag="<?php echo esc_attr( $bn_slug ); ?>"
				>
					<?php buddynext_icon( 'edit' ); ?>
					<span><?php esc_html_e( 'Create post', 'buddynext' ); ?></span>
				</button>
			<?php else : ?>
				<a
					class="bn-btn"
					data-variant="primary"
					data-size="md"
					href="<?php echo esc_url( wp_registration_url() ); ?>"
				>
					<span>
						<?php
						printf(
							/* translators: %s: hashtag slug */
							esc_html__( 'Follow #%s', 'buddynext' ),
							esc_html( $bn_slug )
						);
						?>
					</span>
				</a>
			<?php endif; ?>
		</div>
	</header>

	<div class="bn-stat-grid bn-hashtag-header__stats">
		<div class="bn-stat">
			<div class="bn-stat__label"><?php esc_html_e( 'Posts', 'buddynext' ); ?></div>
			<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $bn_post_count ) ); ?></div>
		</div>
		<div class="bn-stat">
			<div class="bn-stat__label"><?php esc_html_e( 'Contributors', 'buddynext' ); ?></div>
			<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $bn_contribs ) ); ?></div>
		</div>
		<?php if ( '' !== $bn_first_used ) : ?>
			<div class="bn-stat">
				<div class="bn-stat__label"><?php esc_html_e( 'First used', 'buddynext' ); ?></div>
				<div class="bn-stat__value bn-hashtag-header__date"><?php echo esc_html( $bn_first_used ); ?></div>
			</div>
		<?php endif; ?>
	</div>

	<nav class="bn-tabs bn-hashtag-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Sort posts', 'buddynext' ); ?>">
		<?php
		$bn_ht_tabs = array(
			'latest'    => array(
				'label' => __( 'Latest', 'buddynext' ),
				'count' => $bn_post_count,
			),
			'top'       => array(
				'label' => __( 'Top', 'buddynext' ),
				'count' => null,
			),
			'following' => array(
				'label' => __( 'Following only', 'buddynext' ),
				'count' => null,
				'guard' => ! $bn_logged_in,
			),
		);
		foreach ( $bn_ht_tabs as $tab_key => $tab_info ) :
			if ( ! empty( $tab_info['guard'] ) ) {
				continue;
			}
			$tab_active = ( $bn_sort_active === $tab_key );
			$tab_url    = add_query_arg( 'sort', $tab_key, PageRouter::hashtag_feed_url( $bn_slug ) );
			?>
			<a
				class="bn-tab"
				role="tab"
				href="<?php echo esc_url( $tab_url ); ?>"
				data-sort="<?php echo esc_attr( $tab_key ); ?>"
				data-wp-on--click="actions.setSort"
				aria-selected="<?php echo $tab_active ? 'true' : 'false'; ?>"
			>
				<?php echo esc_html( $tab_info['label'] ); ?>
				<?php if ( null !== $tab_info['count'] ) : ?>
					<span class="bn-tab__count"><?php echo esc_html( number_format_i18n( (int) $tab_info['count'] ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>
</section>
<?php
do_action( 'buddynext_part_hashtag_hero_after', $args );
