<?php
/**
 * BuddyNext template part: dm-rail.
 *
 * Renders the messages rail (left column): title + unread badge,
 * search input, filter tabs (All / Unread / Requests), pinned + recent
 * conversation lists, and the "New message" CTA footer.
 *
 * Used by: templates/messages/thread.php.
 *
 * @package BuddyNext
 *
 * @var array    $pinned_conversations Optional. Pinned conversation rows. Default [].
 * @var array    $recent_conversations Optional. Recent conversation rows. Default [].
 * @var int      $active_conv_id       Optional. Currently-active conversation ID. Default 0.
 * @var string   $active_tab           Optional. Active tab (all|unread|requests). Default 'all'.
 * @var string   $list_search          Optional. Initial search input value. Default ''.
 * @var int      $unread_count         Optional. Total unread count. Default 0.
 * @var int      $request_count        Optional. Pending request count. Default 0.
 * @var int      $current_user_id      Optional. Viewing user ID. Default 0.
 * @var string   $compose_url          Optional. URL of the compose-new-message screen.
 * @var callable $initials_fn          Required. Initials helper.
 * @var callable $tone_fn              Required. Tone-slot helper.
 * @var callable $relative_fn          Required. Relative-time helper.
 * @var callable $online_fn            Required. Online helper.
 * @var array    $tabs                 Optional. Tab definition map (slug => label). Filtered
 *                                     via `buddynext_part_dm_rail_args` so Pro group-DM (P14)
 *                                     can register additional tabs.
 * @var array    $classes              Optional. Extra CSS classes on the rail.
 *
 * Fires:
 *   - do_action( 'buddynext_part_dm_rail_before', $args )
 *   - do_action( 'buddynext_part_dm_rail_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_dm_rail_args',    array $args )
 *   - apply_filters( 'buddynext_part_dm_rail_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'pinned_conversations' => isset( $pinned_conversations ) ? (array) $pinned_conversations : array(),
	'recent_conversations' => isset( $recent_conversations ) ? (array) $recent_conversations : array(),
	'active_conv_id'       => isset( $active_conv_id ) ? (int) $active_conv_id : 0,
	'active_tab'           => isset( $active_tab ) ? (string) $active_tab : 'all',
	'list_search'          => isset( $list_search ) ? (string) $list_search : '',
	'unread_count'         => isset( $unread_count ) ? (int) $unread_count : 0,
	'request_count'        => isset( $request_count ) ? (int) $request_count : 0,
	'current_user_id'      => isset( $current_user_id ) ? (int) $current_user_id : 0,
	'compose_url'          => isset( $compose_url ) ? (string) $compose_url : '',
	'initials_fn'          => isset( $initials_fn ) && is_callable( $initials_fn ) ? $initials_fn : null,
	'tone_fn'              => isset( $tone_fn ) && is_callable( $tone_fn ) ? $tone_fn : null,
	'relative_fn'          => isset( $relative_fn ) && is_callable( $relative_fn ) ? $relative_fn : null,
	'online_fn'            => isset( $online_fn ) && is_callable( $online_fn ) ? $online_fn : null,
	'tabs'                 => isset( $tabs ) ? (array) $tabs : array(
		'all'      => __( 'All', 'buddynext' ),
		'unread'   => __( 'Unread', 'buddynext' ),
		'requests' => __( 'Requests', 'buddynext' ),
	),
	'classes'              => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_dm_rail_args', $args );

if ( null === $args['initials_fn'] || null === $args['tone_fn'] || null === $args['relative_fn'] || null === $args['online_fn'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-split__rail', 'bn-dm-rail' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_dm_rail_classes', $bn_classes, $args );
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

$pinned        = (array) $args['pinned_conversations'];
$recent        = (array) $args['recent_conversations'];
$active_id     = (int) $args['active_conv_id'];
$tab_active    = (string) $args['active_tab'];
$search_val    = (string) $args['list_search'];
$unread        = (int) $args['unread_count'];
$req_count     = (int) $args['request_count'];
$viewer_id     = (int) $args['current_user_id'];
$compose       = (string) $args['compose_url'];
$rail_tabs     = (array) $args['tabs'];
$call_initials = $args['initials_fn'];
$call_tone     = $args['tone_fn'];
$call_relative = $args['relative_fn'];
$call_online   = $args['online_fn'];

do_action( 'buddynext_part_dm_rail_before', $args );
?>
<aside class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'Conversations', 'buddynext' ); ?>">

	<div class="bn-split__rail-head bn-dm-rail__head">
		<h2 class="bn-split__rail-title"><?php esc_html_e( 'Messages', 'buddynext' ); ?></h2>
		<?php if ( $unread > 0 ) : ?>
			<span class="bn-badge" data-tone="accent" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: unread count */ _n( '%d unread', '%d unread', $unread, 'buddynext' ), $unread ) ); ?>">
				<?php echo esc_html( (string) min( $unread, 99 ) ); ?>
			</span>
		<?php endif; ?>
	</div>

	<div class="bn-dm-rail__search">
		<label for="bn-dm-search" class="bn-visually-hidden">
			<?php esc_html_e( 'Search conversations', 'buddynext' ); ?>
		</label>
		<input
			id="bn-dm-search"
			class="bn-input"
			type="search"
			placeholder="<?php esc_attr_e( 'Search conversations', 'buddynext' ); ?>"
			value="<?php echo esc_attr( $search_val ); ?>"
			data-wp-on--input="actions.onPanelSearchInput"
		>
	</div>

	<nav class="bn-tabs bn-dm-rail__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Conversation filter', 'buddynext' ); ?>">
		<?php foreach ( $rail_tabs as $tab_key => $tab_label ) : ?>
			<?php
			$is_active_tab = $tab_key === $tab_active;
			$tab_url       = add_query_arg(
				array(
					'tab'          => $tab_key,
					'conversation' => $active_id > 0 ? $active_id : false,
				),
				get_permalink()
			);
			?>
			<a
				href="<?php echo esc_url( $tab_url ); ?>"
				class="bn-tab<?php echo $is_active_tab ? ' is-active' : ''; ?>"
				role="tab"
				aria-selected="<?php echo $is_active_tab ? 'true' : 'false'; ?>"
				data-tab="<?php echo esc_attr( (string) $tab_key ); ?>"
				data-wp-on--click="actions.switchPanelTab"
			>
				<span><?php echo esc_html( (string) $tab_label ); ?></span>
				<?php if ( 'unread' === $tab_key && $unread > 0 ) : ?>
					<span class="bn-badge" data-tone="accent"><?php echo esc_html( (string) min( $unread, 99 ) ); ?></span>
				<?php endif; ?>
				<?php if ( 'requests' === $tab_key && $req_count > 0 ) : ?>
					<span class="bn-badge" data-tone="danger"><?php echo esc_html( (string) min( $req_count, 99 ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="bn-dm-rail__list" role="list">

		<?php if ( ! empty( $pinned ) ) : ?>
			<div class="bn-dm-rail__section">
				<span class="bn-dm-rail__section-icon" aria-hidden="true"><?php buddynext_icon( 'bookmark' ); ?></span>
				<?php esc_html_e( 'Pinned', 'buddynext' ); ?>
			</div>
			<?php foreach ( $pinned as $bn_conv ) : ?>
				<?php
				buddynext_get_template(
					'parts/dm-rail-item.php',
					array(
						'conversation'    => $bn_conv,
						'active_conv_id'  => $active_id,
						'active_tab'      => $tab_active,
						'current_user_id' => $viewer_id,
						'initials_fn'     => $call_initials,
						'tone_fn'         => $call_tone,
						'relative_fn'     => $call_relative,
						'online_fn'       => $call_online,
					)
				);
				?>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( ! empty( $recent ) ) : ?>
			<?php if ( ! empty( $pinned ) ) : ?>
				<div class="bn-dm-rail__section">
					<?php esc_html_e( 'Recent', 'buddynext' ); ?>
				</div>
			<?php endif; ?>
			<?php foreach ( $recent as $bn_conv ) : ?>
				<?php
				buddynext_get_template(
					'parts/dm-rail-item.php',
					array(
						'conversation'    => $bn_conv,
						'active_conv_id'  => $active_id,
						'active_tab'      => $tab_active,
						'current_user_id' => $viewer_id,
						'initials_fn'     => $call_initials,
						'tone_fn'         => $call_tone,
						'relative_fn'     => $call_relative,
						'online_fn'       => $call_online,
					)
				);
				?>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( empty( $pinned ) && empty( $recent ) ) : ?>
			<div class="bn-dm-rail__empty">
				<?php
				// Tab-aware empty copy — "No conversations yet" reads wrong under the
				// Unread / Requests filters.
				if ( 'requests' === $tab_active ) {
					esc_html_e( 'No message requests.', 'buddynext' );
				} elseif ( 'unread' === $tab_active ) {
					esc_html_e( 'No unread messages.', 'buddynext' );
				} else {
					esc_html_e( 'No conversations yet.', 'buddynext' );
				}
				?>
			</div>
		<?php endif; ?>

	</div>

	<div class="bn-dm-rail__foot">
		<button type="button" class="bn-btn" data-variant="primary" data-size="md" data-wp-on--click="actions.openCompose" aria-haspopup="dialog">
			<span class="bn-btn__icon" aria-hidden="true"><?php buddynext_icon( 'plus' ); ?></span>
			<?php esc_html_e( 'New message', 'buddynext' ); ?>
		</button>
	</div>

</aside>
<?php
do_action( 'buddynext_part_dm_rail_after', $args );
