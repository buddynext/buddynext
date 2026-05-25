<?php
/**
 * BuddyNext template part: hashtag-sidebar-about.
 *
 * Sidebar "About this hashtag" card: created-by line, total posts,
 * first-used date, and a follow CTA (logged-in).
 *
 * Used by: templates/hashtags/feed.php.
 *
 * @package BuddyNext
 *
 * @var string $hashtag_slug     Required. Hashtag slug (without leading #).
 * @var int    $post_count_total Optional. Total posts. Default 0.
 * @var string $first_used_label Optional. Localised first-used date. Default ''.
 * @var bool   $follows_hashtag  Optional. Whether viewer follows. Default false.
 * @var bool   $is_logged_in     Optional. Whether viewer is logged in. Default false.
 * @var array  $classes          Optional. Extra CSS classes appended to `.bn-sidebar-widget`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_hashtag_sidebar_about_before', $args )
 *   - do_action( 'buddynext_part_hashtag_sidebar_about_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_hashtag_sidebar_about_args',    array $args )
 *   - apply_filters( 'buddynext_part_hashtag_sidebar_about_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'hashtag_slug'     => isset( $hashtag_slug ) ? (string) $hashtag_slug : '',
	'post_count_total' => isset( $post_count_total ) ? (int) $post_count_total : 0,
	'first_used_label' => isset( $first_used_label ) ? (string) $first_used_label : '',
	'follows_hashtag'  => isset( $follows_hashtag ) ? (bool) $follows_hashtag : false,
	'is_logged_in'     => isset( $is_logged_in ) ? (bool) $is_logged_in : false,
	'classes'          => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_hashtag_sidebar_about_args', $args );

if ( '' === (string) $args['hashtag_slug'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-sidebar-widget' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_hashtag_sidebar_about_classes', $bn_classes, $args );
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

$bn_slug       = (string) $args['hashtag_slug'];
$bn_post_count = (int) $args['post_count_total'];
$bn_first_used = (string) $args['first_used_label'];
$bn_follows    = (bool) $args['follows_hashtag'];
$bn_logged_in  = (bool) $args['is_logged_in'];

do_action( 'buddynext_part_hashtag_sidebar_about_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<h2 class="bn-sidebar-widget__title">
		<?php
		printf(
			/* translators: %s: hashtag slug */
			esc_html__( 'About #%s', 'buddynext' ),
			esc_html( $bn_slug )
		);
		?>
	</h2>
	<dl class="bn-hashtag-about">
		<div class="bn-hashtag-about__row">
			<dt class="bn-hashtag-about__label"><?php esc_html_e( 'Created by', 'buddynext' ); ?></dt>
			<dd class="bn-hashtag-about__value"><?php esc_html_e( 'Community', 'buddynext' ); ?></dd>
		</div>
		<div class="bn-hashtag-about__row">
			<dt class="bn-hashtag-about__label"><?php esc_html_e( 'Total posts', 'buddynext' ); ?></dt>
			<dd class="bn-hashtag-about__value"><?php echo esc_html( number_format_i18n( $bn_post_count ) ); ?></dd>
		</div>
		<?php if ( '' !== $bn_first_used ) : ?>
			<div class="bn-hashtag-about__row">
				<dt class="bn-hashtag-about__label"><?php esc_html_e( 'First used', 'buddynext' ); ?></dt>
				<dd class="bn-hashtag-about__value"><?php echo esc_html( $bn_first_used ); ?></dd>
			</div>
		<?php endif; ?>
	</dl>

	<?php if ( $bn_logged_in ) : ?>
		<button
			class="bn-btn bn-hashtag-about__cta"
			data-variant="<?php echo $bn_follows ? 'secondary' : 'primary'; ?>"
			data-size="sm"
			type="button"
			data-wp-on--click="actions.toggleFollowHashtag"
			data-hashtag="<?php echo esc_attr( $bn_slug ); ?>"
			aria-pressed="<?php echo $bn_follows ? 'true' : 'false'; ?>"
		>
			<?php if ( $bn_follows ) : ?>
				<?php buddynext_icon( 'check' ); ?>
				<span><?php esc_html_e( 'Following', 'buddynext' ); ?></span>
			<?php else : ?>
				<span><?php esc_html_e( 'Follow hashtag', 'buddynext' ); ?></span>
			<?php endif; ?>
		</button>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_hashtag_sidebar_about_after', $args );
