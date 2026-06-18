<?php
/**
 * Notifications - inner hub template (v2).
 *
 * Renders inside the shared hub-shell main column. This template does NOT own
 * the rail or page grid - those are produced by templates/shell/hub-shell.php.
 * Sidebar widgets (quick filters, type breakdown, recent actors, preferences link)
 * are registered against the `buddynext_right_sidebar` action; the shell detects
 * the hook and auto-renders the right column.
 *
 * Composes the v2 primitive layer:
 *   .bn-section-head            page title + Mark-all-read action
 *   .bn-tabs / .bn-tab          filter strip (All / Unread / Mentions / …)
 *   .bn-card[data-v2]           group list wrapper
 *   .bn-notif-row[--unread]     per-notification row
 *   .bn-badge[data-tone]        type pill
 *   .bn-btn[data-variant][data-size] inline + header actions
 *
 * Overridable: copy to {theme}/buddynext/notifications/index.php.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BuddyNext\Notifications\NotificationMessageService;
use BuddyNext\Notifications\NotificationService;
use BuddyNext\Profile\AvatarService;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().
$current_user_id = get_current_user_id();

// Resolve active filter tab (sanitized).
$allowed_filters = array( 'all', 'unread', 'mention', 'reaction', 'comment', 'follow', 'space', 'message' );
$active_filter   = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $active_filter, $allowed_filters, true ) ) {
	$active_filter = 'all';
}

// Filter-key -> notification type list. Shared between the type-filtered fetch
// below and the per-type unread tally (so the in-template SQL is gone but the
// "which types belong to which tab" mapping stays declarative).
$filter_type_map = array(
	'reaction' => array( 'bn.post_reacted' ),
	'comment'  => array( 'bn.post_commented' ),
	'mention'  => array( 'bn.mention' ),
	'follow'   => array( 'bn.new_follower', 'bn.connection_accepted', 'bn.connection_requested' ),
	'space'    => array( 'bn.space_invite', 'bn.space_join_requested', 'bn.space_new_post' ),
	'message'  => array( 'bn.new_message' ),
);

// Pagination (simple offset; cap at 25 per page).
$bn_per_page = 25;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_paged  = isset( $_GET['paged'] ) ? max( 1, (int) sanitize_text_field( wp_unslash( $_GET['paged'] ) ) ) : 1;
$bn_offset = ( $bn_paged - 1 ) * $bn_per_page;

$notification_service = new NotificationService();

// Map the active filter tab onto the service's read-state filter. The 'unread'
// tab maps to the read-state filter; the type tabs all list 'all' read-states
// and are narrowed in PHP after fetch (the type set is small and capped at one
// page, so this stays a single query + a cheap in-memory filter).
$svc_filter     = ( 'unread' === $active_filter ) ? 'unread' : 'all';
$active_types   = $filter_type_map[ $active_filter ] ?? array();
$is_type_filter = ! empty( $active_types );

// For type tabs we cannot push the type set through count_for_user(), so fetch
// a generous page and count the matched rows; All / Unread use the service
// count directly. Type tabs are inherently small.
if ( $is_type_filter ) {
	$listed      = $notification_service->list_for_user( $current_user_id, null, 200, 'all', 0 );
	$all_items   = array_values(
		array_filter(
			$listed['items'] ?? array(),
			static function ( array $item ) use ( $active_types ): bool {
				return in_array( (string) ( $item['type'] ?? '' ), $active_types, true );
			}
		)
	);
	$total_count = count( $all_items );
	$items       = array_slice( $all_items, $bn_offset, $bn_per_page );
} else {
	$listed      = $notification_service->list_for_user( $current_user_id, null, $bn_per_page, $svc_filter, $bn_offset );
	$items       = $listed['items'] ?? array();
	$total_count = $notification_service->count_for_user( $current_user_id, $svc_filter );
}

// Hydrated service rows are associative; the row/group parts read them as
// objects, so coerce. They carry id/type/sender_id/object_id/object_type/
// group_key/group_count/is_read/created_at — exactly what the parts render.
$rows = array_map(
	static function ( array $item ): object {
		return (object) $item;
	},
	$items
);

$total_pages = (int) max( 1, ceil( $total_count / $bn_per_page ) );

// Per-type unread counts -> tab + sidebar badges (replaces the in-template
// conditional-SUM query). Aggregate the per-type map onto each filter tab.
$type_unread = $notification_service->unread_counts_by_type( $current_user_id );
$sum_types   = static function ( array $types ) use ( $type_unread ): int {
	$sum = 0;
	foreach ( $types as $t ) {
		$sum += (int) ( $type_unread[ $t ] ?? 0 );
	}
	return $sum;
};

$total_unread    = array_sum( array_map( 'intval', $type_unread ) );
$reaction_unread = $sum_types( $filter_type_map['reaction'] );
$comment_unread  = $sum_types( $filter_type_map['comment'] );
$mention_unread  = $sum_types( $filter_type_map['mention'] );
$follow_unread   = $sum_types( $filter_type_map['follow'] );
$space_unread    = $sum_types( $filter_type_map['space'] );
$message_unread  = $sum_types( $filter_type_map['message'] );

// Message composer service: composes per-row copy/url/icon/tone/label AND
// primes the WP user cache (compose_batch -> cache_users), so the actor avatar
// lookups below resolve without N+1 get_userdata() round-trips.
$message_service = null;
if ( function_exists( 'buddynext_service' ) ) {
	$message_service = buddynext_service( 'notification_message' );
}
if ( ! $message_service instanceof NotificationMessageService ) {
	$message_service = new NotificationMessageService();
}

/**
 * Compose every visible row up-front so render_row() is a pure presenter.
 *
 * @var array<int,array<string,mixed>> $composed_rows id => composed payload.
 */
$composed_rows = array();
$composed_list = $message_service->compose_batch( $items );
foreach ( $items as $i => $item ) {
	$composed_rows[ (int) $item['id'] ] = $composed_list[ $i ] ?? array();
}

/**
 * Build the actor avatar map (display name + initials + avatar URL) from the
 * composed payloads. compose_batch() already primed the user cache, so
 * get_avatar_url() is a cache hit; initials come from the shared AvatarService.
 *
 * @var array<int,array<string,string>> $actor_data
 */
$actor_data = array();
foreach ( $composed_rows as $payload ) {
	$actor_id = (int) ( $payload['actor_id'] ?? 0 );
	if ( $actor_id <= 0 || isset( $actor_data[ $actor_id ] ) ) {
		continue;
	}
	$name                    = (string) ( $payload['actor_name'] ?? '' );
	$actor_data[ $actor_id ] = array(
		'display_name' => $name,
		'initials'     => AvatarService::initials_for( $name ),
		'avatar_url'   => get_avatar_url( $actor_id, array( 'size' => 56 ) ),
	);
}

// Recent actors - last 6 distinct senders who triggered a notification.
$recent_actors = array();
foreach ( $notification_service->recent_actor_ids( $current_user_id, 6 ) as $actor_id ) {
	$actor_id = (int) $actor_id;
	if ( isset( $actor_data[ $actor_id ] ) ) {
		$recent_actors[ $actor_id ] = $actor_data[ $actor_id ];
		continue;
	}
	$actor_user = get_userdata( $actor_id );
	if ( ! $actor_user ) {
		continue;
	}
	$display                    = (string) $actor_user->display_name;
	$recent_actors[ $actor_id ] = array(
		'display_name' => $display,
		'initials'     => AvatarService::initials_for( $display ),
		'avatar_url'   => get_avatar_url( $actor_id, array( 'size' => 56 ) ),
	);
}

// Group rows into Today / Yesterday / Older.
$today_ts     = strtotime( 'today midnight' );
$yesterday_ts = strtotime( 'yesterday midnight' );
$groups       = array(
	'today'     => array(),
	'yesterday' => array(),
	'older'     => array(),
);
foreach ( $rows ?? array() as $row ) {
	// created_at is stored in UTC; anchor the parse to UTC so the day grouping
	// matches the UTC midnight boundaries computed above.
	$row_ts = (int) strtotime( $row->created_at . ' UTC' );
	if ( $row_ts >= $today_ts ) {
		$groups['today'][] = $row;
	} elseif ( $row_ts >= $yesterday_ts ) {
		$groups['yesterday'][] = $row;
	} else {
		$groups['older'][] = $row;
	}
}

/**
 * Render an actor avatar - image when available, initials fallback.
 *
 * @param int $actor_id Actor user ID.
 */
$render_avatar = static function ( int $actor_id ) use ( $actor_data ): void {
	$entry      = $actor_data[ $actor_id ] ?? array(
		'avatar_url' => '',
		'initials'   => '?',
	);
	$avatar_url = (string) ( $entry['avatar_url'] ?? '' );
	$initials   = (string) $entry['initials'];
	if ( '' === $initials ) {
		$initials = '?';
	}
	if ( $avatar_url ) {
		?>
		<span class="bn-avatar bn-notif-row__avatar" data-size="sm">
			<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="28" height="28" loading="lazy">
		</span>
		<?php
		return;
	}
	?>
	<span class="bn-avatar bn-notif-row__avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
	<?php
};

/**
 * Human-readable time difference for the <time> label.
 *
 * Thin adapter over the canonical buddynext_time_ago() helper (UTC-anchored)
 * so render_row can keep receiving a callable. Returns esc_html()'d output.
 *
 * @param string $created_at UTC MySQL datetime string.
 * @return string e.g. "2m ago".
 */
$time_ago = static function ( string $created_at ): string {
	return buddynext_time_ago( $created_at );
};

$mark_all_nonce = wp_create_nonce( 'wp_rest' );
$rest_url       = esc_url( rest_url( 'buddynext/v1/me/notifications/read-all' ) );

/**
 * Render a single notification row by delegating to the row template part.
 *
 * The message, link, icon, tone, and label are pre-composed by
 * NotificationMessageService::compose() so the row is a pure presenter.
 *
 * @param object   $row                Notification DB row.
 * @param array    $payload            Pre-composed presentation payload.
 * @param callable $render_avatar_call Avatar render closure.
 * @param callable $time_ago_call      Time-ago closure.
 */
$render_row = static function ( object $row, array $payload, callable $render_avatar_call, callable $time_ago_call ): void {
	buddynext_get_template(
		'parts/notification-row.php',
		array(
			'notif_row'     => $row,
			'payload'       => $payload,
			'render_avatar' => $render_avatar_call,
			'time_ago'      => $time_ago_call,
		)
	);
};

// ── Right sidebar widgets ────────────────────────────────────────────────
// Register sidebar widget callbacks on the shared hub-shell action. The shell
// detects via has_action() (after this template's output buffer flushes) and
// renders the right column automatically.
$sidebar_data = array(
	'active_filter'   => $active_filter,
	'total_unread'    => $total_unread,
	'reaction_unread' => $reaction_unread,
	'comment_unread'  => $comment_unread,
	'mention_unread'  => $mention_unread,
	'follow_unread'   => $follow_unread,
	'space_unread'    => $space_unread,
	'message_unread'  => $message_unread,
	'recent_actors'   => $recent_actors,
);

add_action(
	'buddynext_right_sidebar',
	static function () use ( $sidebar_data ) {
		$active_filter   = (string) $sidebar_data['active_filter'];
		$total_unread    = (int) $sidebar_data['total_unread'];
		$reaction_unread = (int) $sidebar_data['reaction_unread'];
		$comment_unread  = (int) $sidebar_data['comment_unread'];
		$mention_unread  = (int) $sidebar_data['mention_unread'];
		$follow_unread   = (int) $sidebar_data['follow_unread'];
		$space_unread    = (int) $sidebar_data['space_unread'];
		$message_unread  = (int) $sidebar_data['message_unread'];
		$recent_actors   = (array) $sidebar_data['recent_actors'];

		$quick_filters = array(
			array(
				'key'   => 'unread',
				'label' => __( 'Unread only', 'buddynext' ),
				'icon'  => 'circle-dot',
				'count' => $total_unread,
			),
			array(
				'key'   => 'mention',
				'label' => __( 'Mentions of you', 'buddynext' ),
				'icon'  => 'at-sign',
				'count' => $mention_unread,
			),
			array(
				'key'   => 'follow',
				'label' => __( 'People', 'buddynext' ),
				'icon'  => 'users',
				'count' => $follow_unread,
			),
			array(
				'key'   => 'space',
				'label' => __( 'Spaces', 'buddynext' ),
				'icon'  => 'home',
				'count' => $space_unread,
			),
		);

		$sidebar_types = array(
			'mention'  => array(
				'label' => __( 'Mentions', 'buddynext' ),
				'icon'  => 'at-sign',
				'count' => $mention_unread,
			),
			'reaction' => array(
				'label' => __( 'Reactions', 'buddynext' ),
				'icon'  => 'heart',
				'count' => $reaction_unread,
			),
			'comment'  => array(
				'label' => __( 'Comments', 'buddynext' ),
				'icon'  => 'message-circle',
				'count' => $comment_unread,
			),
			'follow'   => array(
				'label' => __( 'People', 'buddynext' ),
				'icon'  => 'users',
				'count' => $follow_unread,
			),
			'space'    => array(
				'label' => __( 'Spaces', 'buddynext' ),
				'icon'  => 'home',
				'count' => $space_unread,
			),
			'message'  => array(
				'label' => __( 'Messages', 'buddynext' ),
				'icon'  => 'mail',
				'count' => $message_unread,
			),
		);

		buddynext_get_template(
			'parts/notifications-sidecard-quick-filters.php',
			array(
				'filters'       => $quick_filters,
				'active_filter' => $active_filter,
			)
		);

		buddynext_get_template(
			'parts/notifications-sidecard-types.php',
			array(
				'types'         => $sidebar_types,
				'active_filter' => $active_filter,
			)
		);

		buddynext_get_template(
			'parts/notifications-sidecard-recent-actors.php',
			array(
				'recent_actors' => $recent_actors,
			)
		);

		buddynext_get_template(
			'parts/notifications-sidecard-prefs.php',
			array()
		);

		// "This week" engagement stats card (Pattern D-6). Surfaces a 2×2
		// stat grid: notifications received with WoW delta, read rate,
		// new followers, total reactions + comments received. Personal so
		// it only renders for logged-in viewers.
		$bn_stats_uid = (int) get_current_user_id();
		if ( $bn_stats_uid > 0 ) {
			buddynext_get_template(
				'parts/sidebar-this-week-stats.php',
				array(
					'user_id' => $bn_stats_uid,
				)
			);

			// Muted-list management widget. The part returns early when
			// the viewer has muted nobody, so this call is free in the
			// common case.
			buddynext_get_template(
				'parts/notifications-sidecard-muted.php',
				array(
					'user_id' => $bn_stats_uid,
				)
			);
		}
	}
);

/**
 * Fires before the notifications inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_notifications_before', $current_user_id );
?>
<?php
$initial_context = wp_json_encode(
	array(
		'markedAll'    => false,
		'activeFilter' => $active_filter,
		'nonce'        => $mark_all_nonce,
		'restUrl'      => rest_url( 'buddynext/v1/me/notifications' ),
		'unreadCount'  => $total_unread,
		'hasError'     => false,
		// Per-filter unread counts power the reactive tab + sidebar badges
		// (data-wp-text). markRead/markAllRead mutate these in place so the
		// badges stay in step without a DOM paint loop. The server re-renders
		// these fresh on every router navigation.
		'tabCounts'    => array(
			'all'      => $total_unread,
			'unread'   => $total_unread,
			'mention'  => $mention_unread,
			'comment'  => $comment_unread,
			'reaction' => $reaction_unread,
			'follow'   => $follow_unread,
			'space'    => $space_unread,
			'message'  => $message_unread,
		),
	)
);
?>
<div class="bn-notifs-main"
	data-wp-interactive="buddynext/notifications"
	data-wp-context='<?php echo esc_attr( (string) $initial_context ); ?>'>

	<?php
	buddynext_get_template(
		'parts/notifications-hero.php',
		array(
			'total_unread'   => $total_unread,
			'mark_all_nonce' => $mark_all_nonce,
			'rest_url'       => $rest_url,
		)
	);

	$notif_tabs = array(
		array(
			'key'   => 'all',
			'label' => __( 'All', 'buddynext' ),
			'count' => $total_unread,
		),
		array(
			'key'   => 'unread',
			'label' => __( 'Unread', 'buddynext' ),
			'count' => $total_unread,
		),
		array(
			'key'   => 'mention',
			'label' => __( 'Mentions', 'buddynext' ),
			'count' => $mention_unread,
		),
		array(
			'key'   => 'comment',
			'label' => __( 'Comments', 'buddynext' ),
			'count' => $comment_unread,
		),
		array(
			'key'   => 'reaction',
			'label' => __( 'Reactions', 'buddynext' ),
			'count' => $reaction_unread,
		),
		array(
			'key'   => 'space',
			'label' => __( 'Spaces', 'buddynext' ),
			'count' => $space_unread,
		),
	);

	buddynext_get_template(
		'parts/notifications-filter-bar.php',
		array(
			'active_filter' => $active_filter,
			'tabs'          => $notif_tabs,
		)
	);
	?>

	<div class="bn-notifs-main__content">

	<?php
	$group_labels = array(
		'today'     => __( 'Today', 'buddynext' ),
		'yesterday' => __( 'Yesterday', 'buddynext' ),
		'older'     => __( 'Older', 'buddynext' ),
	);

	$has_any = false;
	foreach ( $groups as $group_key => $group_rows ) :
		if ( empty( $group_rows ) ) {
			continue;
		}
		$has_any = true;
		buddynext_get_template(
			'parts/notifications-group.php',
			array(
				'group_key'        => (string) $group_key,
				'group_label'      => (string) $group_labels[ $group_key ],
				'group_rows'       => $group_rows,
				'composed_rows'    => $composed_rows,
				'render_row_fn'    => $render_row,
				'render_avatar_fn' => $render_avatar,
				'time_ago_fn'      => $time_ago,
			)
		);
	endforeach;

	if ( ! $has_any ) :
		buddynext_get_template(
			'parts/notifications-empty.php',
			array(
				'active_filter' => $active_filter,
			)
		);
	endif;
	?>

	<div class="bn-notif-error" hidden role="alert" data-wp-bind--hidden="!state.hasError">
		<span class="bn-notif-error__emblem" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
		<p class="bn-notif-error__title"><?php esc_html_e( 'Could not load notifications.', 'buddynext' ); ?></p>
		<button class="bn-btn" data-variant="secondary" data-size="sm" data-wp-on--click="actions.retry">
			<?php esc_html_e( 'Try again', 'buddynext' ); ?>
		</button>
	</div>

	<?php if ( $has_any && $total_pages > 1 ) : ?>
		<nav class="bn-notif-pagination" aria-label="<?php esc_attr_e( 'Notifications pagination', 'buddynext' ); ?>">
			<?php if ( $bn_paged > 1 ) : ?>
				<a class="bn-btn" data-variant="ghost" data-size="sm"
					href="<?php echo esc_url( add_query_arg( 'paged', $bn_paged - 1 ) ); ?>">
					<?php buddynext_icon( 'chevron-left' ); ?>
					<?php esc_html_e( 'Previous', 'buddynext' ); ?>
				</a>
			<?php endif; ?>
			<span class="bn-notif-pagination__meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: current page number, 2: total pages */
						__( 'Page %1$d of %2$d', 'buddynext' ),
						$bn_paged,
						$total_pages
					)
				);
				?>
			</span>
			<?php if ( $bn_paged < $total_pages ) : ?>
				<a class="bn-btn" data-variant="ghost" data-size="sm"
					href="<?php echo esc_url( add_query_arg( 'paged', $bn_paged + 1 ) ); ?>">
					<?php esc_html_e( 'Next', 'buddynext' ); ?>
					<?php buddynext_icon( 'chevron-right' ); ?>
				</a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

	</div><!-- /.bn-notifs-main__content -->

</div>
<?php
/**
 * Fires after the notifications inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_notifications_after', $current_user_id );
