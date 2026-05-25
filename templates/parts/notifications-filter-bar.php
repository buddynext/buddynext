<?php
/**
 * BuddyNext template part: notifications-filter-bar.
 *
 * Renders the notifications filter tab strip
 * (All / Unread / Mentions / Comments / Reactions / Spaces).
 *
 * Used by: templates/notifications/index.php.
 *
 * @package BuddyNext
 *
 * @var string $active_filter Optional. The currently-active filter key. Default 'all'.
 * @var array  $tabs          Optional. List of tab descriptors, each
 *                            { 'key' => string, 'label' => string, 'count' => int }.
 *                            Filterable via `buddynext_part_notifications_filter_bar_args`
 *                            so Pro plugins can register additional tabs.
 * @var array  $classes       Optional. Extra CSS classes appended to `.bn-tabs`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notifications_filter_bar_before', $args )
 *   - do_action( 'buddynext_part_notifications_filter_bar_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notifications_filter_bar_args',    array $args )
 *   - apply_filters( 'buddynext_part_notifications_filter_bar_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'active_filter' => isset( $active_filter ) ? (string) $active_filter : 'all',
	'tabs'          => isset( $tabs ) ? (array) $tabs : array(),
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notifications_filter_bar_args', $args );

$bn_classes = array_merge( array( 'bn-tabs', 'bn-notif-tabs' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notifications_filter_bar_classes', $bn_classes, $args );
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

$bn_active = (string) $args['active_filter'];
$bn_tabs   = (array) $args['tabs'];

do_action( 'buddynext_part_notifications_filter_bar_before', $args );
?>
<nav class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'Notification filters', 'buddynext' ); ?>" role="tablist">
	<?php
	foreach ( $bn_tabs as $bn_tab ) :
		if ( ! is_array( $bn_tab ) ) {
			continue;
		}
		$filter_key   = isset( $bn_tab['key'] ) ? (string) $bn_tab['key'] : '';
		$filter_label = isset( $bn_tab['label'] ) ? (string) $bn_tab['label'] : '';
		$filter_count = isset( $bn_tab['count'] ) ? (int) $bn_tab['count'] : 0;
		if ( '' === $filter_key || '' === $filter_label ) {
			continue;
		}
		$is_active = ( $filter_key === $bn_active );
		$tab_url   = add_query_arg(
			array(
				'filter' => $filter_key,
				'paged'  => false,
			)
		);
		?>
		<a href="<?php echo esc_url( $tab_url ); ?>"
			class="bn-tab<?php echo $is_active ? ' is-active' : ''; ?>"
			role="tab"
			data-filter="<?php echo esc_attr( $filter_key ); ?>"
			data-wp-on--click="actions.setFilter"
			aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
			aria-current="<?php echo $is_active ? 'page' : 'false'; ?>">
			<?php echo esc_html( $filter_label ); ?>
			<?php if ( $filter_count > 0 ) : ?>
				<span class="bn-tab__count"><?php echo esc_html( (string) min( $filter_count, 99 ) ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>
<?php
do_action( 'buddynext_part_notifications_filter_bar_after', $args );
