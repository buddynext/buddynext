<?php
/**
 * BuddyNext template part: hashtag-empty-state.
 *
 * Renders the "no posts" card on a hashtag feed. Headline varies by sort
 * tab (latest / top / following). Includes a primary CTA to compose a
 * tagged post (logged-in) or sign up (anonymous).
 *
 * Used by: templates/hashtags/feed.php.
 *
 * @package BuddyNext
 *
 * @var string $hashtag_slug Required. Hashtag slug (without leading #).
 * @var string $bn_sort      Optional. Active sort: latest|top|following. Default 'latest'.
 * @var bool   $is_logged_in Optional. Whether viewer is logged in. Default false.
 * @var array  $classes      Optional. Extra CSS classes appended to `.bn-hashtag-empty`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_hashtag_empty_state_before', $args )
 *   - do_action( 'buddynext_part_hashtag_empty_state_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_hashtag_empty_state_args',    array $args )
 *   - apply_filters( 'buddynext_part_hashtag_empty_state_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'hashtag_slug' => isset( $hashtag_slug ) ? (string) $hashtag_slug : '',
	'bn_sort'      => isset( $bn_sort ) ? (string) $bn_sort : 'latest',
	'is_logged_in' => isset( $is_logged_in ) ? (bool) $is_logged_in : false,
	'classes'      => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_hashtag_empty_state_args', $args );

if ( '' === (string) $args['hashtag_slug'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-hashtag-empty' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_hashtag_empty_state_classes', $bn_classes, $args );
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

$bn_slug      = (string) $args['hashtag_slug'];
$bn_sort      = (string) $args['bn_sort'];
$bn_logged_in = (bool) $args['is_logged_in'];

do_action( 'buddynext_part_hashtag_empty_state_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<div class="bn-hashtag-empty__icon" aria-hidden="true"><?php buddynext_icon( 'hash' ); ?></div>
	<h2 class="bn-hashtag-empty__title">
		<?php
		if ( 'following' === $bn_sort ) {
			printf(
				/* translators: %s: hashtag slug */
				esc_html__( 'No posts from people you follow tagged #%s', 'buddynext' ),
				esc_html( $bn_slug )
			);
		} elseif ( 'top' === $bn_sort ) {
			printf(
				/* translators: %s: hashtag slug */
				esc_html__( 'No top posts in the last 7 days for #%s', 'buddynext' ),
				esc_html( $bn_slug )
			);
		} else {
			printf(
				/* translators: %s: hashtag slug */
				esc_html__( 'No posts with #%s yet', 'buddynext' ),
				esc_html( $bn_slug )
			);
		}
		?>
	</h2>
	<p class="bn-hashtag-empty__lede">
		<?php esc_html_e( 'Be the first to share something on this topic.', 'buddynext' ); ?>
	</p>
	<?php if ( $bn_logged_in ) : ?>
		<button
			class="bn-btn"
			data-variant="primary"
			data-size="md"
			type="button"
			data-wp-on--click="actions.openComposerWithTag"
			data-hashtag="<?php echo esc_attr( $bn_slug ); ?>"
		>
			<?php buddynext_icon( 'edit' ); ?>
			<span><?php esc_html_e( 'Be the first to post', 'buddynext' ); ?></span>
		</button>
	<?php else : ?>
		<a
			class="bn-btn"
			data-variant="primary"
			data-size="md"
			href="<?php echo esc_url( wp_registration_url() ); ?>"
		>
			<span><?php esc_html_e( 'Join to post', 'buddynext' ); ?></span>
		</a>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_hashtag_empty_state_after', $args );
