<?php
/**
 * BuddyNext template part: notifications-sidecard-quick-filters.
 *
 * Sidebar widget listing quick filter shortcuts (Unread only / Mentions /
 * People / Spaces) with their unread badge counts. Each row links to the
 * notifications hub with the matching `filter` query var.
 *
 * Used by: templates/notifications/index.php (right sidebar).
 *
 * @package BuddyNext
 *
 * @var array  $filters       Required. List of filter descriptors, each
 *                            { 'key' => string, 'label' => string, 'icon' => string, 'count' => int }.
 * @var string $active_filter Optional. Currently-active filter key. Default 'all'.
 * @var array  $classes       Optional. Extra CSS classes appended to `.bn-notif-sidecard`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notifications_sidecard_quick_filters_before', $args )
 *   - do_action( 'buddynext_part_notifications_sidecard_quick_filters_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notifications_sidecard_quick_filters_args',    array $args )
 *   - apply_filters( 'buddynext_part_notifications_sidecard_quick_filters_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'filters'       => isset( $filters ) ? (array) $filters : array(),
	'active_filter' => isset( $active_filter ) ? (string) $active_filter : 'all',
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notifications_sidecard_quick_filters_args', $args );

if ( empty( $args['filters'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-notif-sidecard' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notifications_sidecard_quick_filters_classes', $bn_classes, $args );
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

$bn_filters       = (array) $args['filters'];
$bn_active_filter = (string) $args['active_filter'];

do_action( 'buddynext_part_notifications_sidecard_quick_filters_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" data-v2 aria-labelledby="bn-notif-side-filters">
	<header id="bn-notif-side-filters" class="bn-notif-sidecard__head"><?php esc_html_e( 'Quick filters', 'buddynext' ); ?></header>
	<?php foreach ( $bn_filters as $qf ) : ?>
		<?php $is_active = ( $qf['key'] === $bn_active_filter ); ?>
		<a href="<?php echo esc_url( add_query_arg( 'filter', $qf['key'] ) ); ?>"
			class="bn-notif-sidecard__row<?php echo $is_active ? ' is-active' : ''; ?>"
			<?php
			if ( $is_active ) {
				echo 'aria-current="page"';}
			?>
		>
			<span class="bn-notif-sidecard__icon" aria-hidden="true"><?php buddynext_icon( $qf['icon'] ); ?></span>
			<span class="bn-notif-sidecard__label"><?php echo esc_html( $qf['label'] ); ?></span>
			<?php if ( $qf['count'] > 0 ) : ?>
				<span class="bn-badge" data-tone="accent"><?php echo esc_html( (string) min( (int) $qf['count'], 99 ) ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</section>
<?php
do_action( 'buddynext_part_notifications_sidecard_quick_filters_after', $args );
