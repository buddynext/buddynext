<?php
/**
 * BuddyNext template part: notifications-sidecard-types.
 *
 * Sidebar widget listing the per-type unread breakdown (Mentions /
 * Reactions / Comments / People / Spaces / Messages). Each row links
 * to the notifications hub with the matching `filter` query var.
 *
 * Used by: templates/notifications/index.php (right sidebar).
 *
 * @package BuddyNext
 *
 * @var array  $types         Required. Map keyed by filter slug, each value
 *                            { 'label' => string, 'icon' => string, 'count' => int }.
 * @var string $active_filter Optional. Currently-active filter key. Default 'all'.
 * @var array  $classes       Optional. Extra CSS classes appended to `.bn-notif-sidecard`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notifications_sidecard_types_before', $args )
 *   - do_action( 'buddynext_part_notifications_sidecard_types_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notifications_sidecard_types_args',    array $args )
 *   - apply_filters( 'buddynext_part_notifications_sidecard_types_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'types'         => isset( $types ) ? (array) $types : array(),
	'active_filter' => isset( $active_filter ) ? (string) $active_filter : 'all',
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notifications_sidecard_types_args', $args );

if ( empty( $args['types'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-notif-sidecard' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notifications_sidecard_types_classes', $bn_classes, $args );
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

$bn_types         = (array) $args['types'];
$bn_active_filter = (string) $args['active_filter'];

do_action( 'buddynext_part_notifications_sidecard_types_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" data-v2 aria-labelledby="bn-notif-side-types">
	<header id="bn-notif-side-types" class="bn-notif-sidecard__head"><?php esc_html_e( 'By type', 'buddynext' ); ?></header>
	<?php foreach ( $bn_types as $type_key => $stype ) : ?>
		<?php $is_active = ( $type_key === $bn_active_filter ); ?>
		<a href="<?php echo esc_url( add_query_arg( 'filter', $type_key ) ); ?>"
			class="bn-notif-sidecard__row<?php echo $is_active ? ' is-active' : ''; ?>"
			<?php
			if ( $is_active ) {
				echo 'aria-current="page"';}
			?>
		>
			<span class="bn-notif-sidecard__icon" aria-hidden="true"><?php buddynext_icon( $stype['icon'] ); ?></span>
			<span class="bn-notif-sidecard__label"><?php echo esc_html( $stype['label'] ); ?></span>
			<?php if ( $stype['count'] > 0 ) : ?>
				<span class="bn-badge" data-tone="info"><?php echo esc_html( (string) min( (int) $stype['count'], 99 ) ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</section>
<?php
do_action( 'buddynext_part_notifications_sidecard_types_after', $args );
