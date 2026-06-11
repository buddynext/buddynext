<?php
/**
 * BuddyNext template part: dm-rail-item.
 *
 * Renders a single conversation row inside the messages rail. Used by
 * `parts/dm-rail.php` once per pinned + recent conversation.
 *
 * @package BuddyNext
 *
 * @var array    $conversation    Required. MVS conversation row.
 * @var int      $active_conv_id  Optional. Currently-active conversation ID. Default 0.
 * @var string   $active_tab      Optional. Active rail tab. Default 'all'.
 * @var int      $current_user_id Optional. Viewing user ID. Default 0.
 * @var callable $initials_fn     Required. (string $name): string — initials helper.
 * @var callable $tone_fn         Required. (int $user_id): int — tone slot helper.
 * @var callable $relative_fn     Required. ($timestamp): string — relative-time helper.
 * @var callable $online_fn       Required. (int $user_id): bool — online helper.
 * @var array    $classes         Optional. Extra CSS classes on the row.
 *
 * Fires:
 *   - do_action( 'buddynext_part_dm_rail_item_before', $args )
 *   - do_action( 'buddynext_part_dm_rail_item_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_dm_rail_item_args',    array $args )
 *   - apply_filters( 'buddynext_part_dm_rail_item_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'conversation'    => isset( $conversation ) ? (array) $conversation : array(),
	'active_conv_id'  => isset( $active_conv_id ) ? (int) $active_conv_id : 0,
	'active_tab'      => isset( $active_tab ) ? (string) $active_tab : 'all',
	'current_user_id' => isset( $current_user_id ) ? (int) $current_user_id : 0,
	'initials_fn'     => isset( $initials_fn ) && is_callable( $initials_fn ) ? $initials_fn : null,
	'tone_fn'         => isset( $tone_fn ) && is_callable( $tone_fn ) ? $tone_fn : null,
	'relative_fn'     => isset( $relative_fn ) && is_callable( $relative_fn ) ? $relative_fn : null,
	'online_fn'       => isset( $online_fn ) && is_callable( $online_fn ) ? $online_fn : null,
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_dm_rail_item_args', $args );

if ( empty( $args['conversation'] ) || null === $args['initials_fn'] || null === $args['tone_fn'] || null === $args['relative_fn'] || null === $args['online_fn'] ) {
	return;
}

$conv          = (array) $args['conversation'];
$active_id     = (int) $args['active_conv_id'];
$bn_tab        = (string) $args['active_tab'];
$initials_call = $args['initials_fn'];
$tone_call     = $args['tone_fn'];
$relative_call = $args['relative_fn'];
$online_call   = $args['online_fn'];

$c_id        = absint( $conv['id'] ?? 0 );
$c_uid       = absint( $conv['other_user_id'] ?? 0 );
$c_name      = sanitize_text_field( $conv['other_user_name'] ?? '' );
$c_preview   = sanitize_text_field( $conv['last_message_preview'] ?? '' );
$c_at        = $conv['last_message_at'] ?? '';
$c_unread    = absint( $conv['unread_count'] ?? 0 );
$c_online    = (bool) $online_call( $c_uid );
$c_is_active = $c_id === $active_id;
$c_initials  = (string) $initials_call( $c_name );
$c_tone      = (int) $tone_call( $c_uid );
$c_typing    = ! empty( $conv['other_user_typing'] );
$c_pinned    = ! empty( $conv['is_pinned'] );
$c_is_group  = ! empty( $conv['is_group'] );
$c_members   = absint( $conv['member_count'] ?? 0 );
$c_url       = add_query_arg(
	array(
		'conversation' => $c_id,
		'tab'          => $bn_tab,
	),
	get_permalink()
);
$c_av_html   = get_avatar( $c_uid, 36, '', $c_name, array( 'force_display' => true ) );
$presence    = $c_online ? 'online' : 'offline';

$bn_classes = array_merge(
	array( 'bn-split__rail-item', 'bn-dm-rail__item', 'bn-dm-tone-' . $c_tone ),
	array_filter( (array) $args['classes'], 'is_string' )
);
if ( $c_unread > 0 ) {
	$bn_classes[] = 'is-unread';
}
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_dm_rail_item_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_dm_rail_item_before', $args );
?>
<a
	href="<?php echo esc_url( $c_url ); ?>"
	class="<?php echo esc_attr( $bn_class ); ?>"
	role="listitem"
	<?php if ( $c_is_active ) : ?>
		aria-current="true"
	<?php endif; ?>
	data-conv-id="<?php echo esc_attr( (string) $c_id ); ?>"
>
	<span class="bn-avatar bn-dm-avatar<?php echo $c_is_group ? ' bn-dm-avatar--group' : ''; ?>" data-size="md"<?php echo $c_is_group ? '' : ' data-presence="' . esc_attr( $presence ) . '"'; ?> aria-hidden="true">
		<?php if ( $c_is_group ) : ?>
			<?php buddynext_icon( 'users' ); ?>
		<?php elseif ( false !== strpos( $c_av_html, 'src=' ) ) : ?>
			<?php
			echo wp_kses(
				$c_av_html,
				array(
					'img' => array(
						'src'      => true,
						'class'    => true,
						'alt'      => true,
						'width'    => true,
						'height'   => true,
						'loading'  => true,
						'decoding' => true,
					),
				)
			);
			?>
		<?php else : ?>
			<span class="bn-avatar__initials"><?php echo esc_html( $c_initials ); ?></span>
		<?php endif; ?>
	</span>

	<span class="bn-dm-rail__item-info">
		<span class="bn-dm-rail__item-row">
			<span class="bn-dm-rail__item-name"><?php echo esc_html( $c_name ); ?></span>
			<time class="bn-dm-rail__item-time" datetime="<?php echo esc_attr( is_numeric( $c_at ) ? gmdate( 'c', (int) $c_at ) : (string) $c_at ); ?>">
				<?php echo esc_html( (string) $relative_call( $c_at ) ); ?>
			</time>
		</span>
		<span class="bn-dm-rail__item-preview<?php echo $c_typing ? ' is-typing' : ''; ?>">
			<?php
			if ( $c_typing ) {
				echo esc_html__( 'Typing…', 'buddynext' );
			} elseif ( '' !== $c_preview ) {
				echo esc_html( $c_preview );
			} elseif ( $c_is_group ) {
				/* translators: %d: number of group members. */
				echo esc_html( sprintf( _n( '%d member', '%d members', $c_members, 'buddynext' ), $c_members ) );
			}
			?>
		</span>
	</span>

	<span class="bn-dm-rail__item-meta">
		<?php if ( $c_unread > 0 ) : ?>
			<span class="bn-badge" data-tone="accent" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: unread count */ _n( '%d unread', '%d unread', $c_unread, 'buddynext' ), $c_unread ) ); ?>">
				<?php echo esc_html( (string) min( $c_unread, 99 ) ); ?>
			</span>
		<?php endif; ?>
		<?php if ( $c_pinned ) : ?>
			<span class="bn-dm-rail__item-pin" aria-label="<?php esc_attr_e( 'Pinned', 'buddynext' ); ?>"><?php buddynext_icon( 'bookmark' ); ?></span>
		<?php endif; ?>
	</span>
</a>
<?php
do_action( 'buddynext_part_dm_rail_item_after', $args );
