<?php
/**
 * BuddyNext template part: notifications-group.
 *
 * Renders a single time-bucket cluster (Today / Yesterday / Older) of
 * notification rows: a labelled group header (with optional unread count)
 * followed by a `.bn-card.bn-notif-list` listing of rows, each delegated to
 * the `parts/notification-row.php` part.
 *
 * Used by: templates/notifications/index.php.
 *
 * @package BuddyNext
 *
 * @var string   $group_key     Required. Group bucket key (today|yesterday|older).
 * @var string   $group_label   Required. Already-translated group heading.
 * @var array    $group_rows    Required. Notification DB rows in this group.
 * @var array    $composed_rows Required. Map of id => composed presentation payload.
 * @var callable $render_row_fn Required. Callable that renders one row given
 *                              ( $row, $payload, $render_avatar_fn, $time_ago_fn ).
 * @var callable $render_avatar_fn Required. Avatar render closure.
 * @var callable $time_ago_fn      Required. Time-ago closure.
 * @var array    $classes          Optional. Extra CSS classes appended to `.bn-notif-group`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notifications_group_before', $args )
 *   - do_action( 'buddynext_part_notifications_group_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notifications_group_args',    array $args )
 *   - apply_filters( 'buddynext_part_notifications_group_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'group_key'        => isset( $group_key ) ? (string) $group_key : '',
	'group_label'      => isset( $group_label ) ? (string) $group_label : '',
	'group_rows'       => isset( $group_rows ) ? (array) $group_rows : array(),
	'composed_rows'    => isset( $composed_rows ) ? (array) $composed_rows : array(),
	'render_row_fn'    => isset( $render_row_fn ) && is_callable( $render_row_fn ) ? $render_row_fn : null,
	'render_avatar_fn' => isset( $render_avatar_fn ) && is_callable( $render_avatar_fn ) ? $render_avatar_fn : null,
	'time_ago_fn'      => isset( $time_ago_fn ) && is_callable( $time_ago_fn ) ? $time_ago_fn : null,
	'classes'          => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notifications_group_args', $args );

if ( empty( $args['group_rows'] )
	|| '' === (string) $args['group_label']
	|| null === $args['render_row_fn']
	|| null === $args['render_avatar_fn']
	|| null === $args['time_ago_fn']
) {
	return;
}

$bn_classes = array_merge( array( 'bn-notif-group' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notifications_group_classes', $bn_classes, $args );
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

$bn_group_label   = (string) $args['group_label'];
$bn_group_rows    = (array) $args['group_rows'];
$bn_composed      = (array) $args['composed_rows'];
$bn_render_row    = $args['render_row_fn'];
$bn_render_avatar = $args['render_avatar_fn'];
$bn_time_ago      = $args['time_ago_fn'];
$unread_in_group  = count( array_filter( $bn_group_rows, static fn( $r ) => ! (bool) $r->is_read ) );

do_action( 'buddynext_part_notifications_group_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>">
	<header class="bn-notif-group__head">
		<span class="bn-notif-group__title"><?php echo esc_html( $bn_group_label ); ?></span>
		<?php if ( $unread_in_group > 0 ) : ?>
			<span class="bn-notif-group__meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d is the count of unread notifications in this group. */
						_n( '%d unread', '%d unread', $unread_in_group, 'buddynext' ),
						$unread_in_group
					)
				);
				?>
			</span>
		<?php endif; ?>
	</header>
	<div class="bn-card bn-notif-list" data-v2 role="list">
		<?php
		foreach ( $bn_group_rows as $notif_row ) {
			$payload = $bn_composed[ (int) $notif_row->id ] ?? array();
			$bn_render_row( $notif_row, $payload, $bn_render_avatar, $bn_time_ago );
		}
		?>
	</div>
</section>
<?php
do_action( 'buddynext_part_notifications_group_after', $args );
