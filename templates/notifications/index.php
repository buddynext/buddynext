<?php
/**
 * Notifications - inner hub template (v2).
 *
 * Renders inside the shared hub-shell main column. This template does NOT own
 * the rail or page grid - those are produced by templates/shell/hub-shell.php.
 * Sidebar widgets (quick filters, type breakdown, recent actors, preferences link,
 * "this week" stats, muted list) are registered by
 * includes/Sidebar/Providers/NotificationsSidebarProvider.php on the
 * `notifications` surface; this template just calls Surface::set() with the
 * raw context and the shell auto-renders the right column.
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

// Tab + page, read by the service (the same call PageRouter makes to answer 404
// for a page past the end).
$allowed_filters      = NotificationService::inbox_filters();
$notification_service = new NotificationService();
$bn_inbox             = $notification_service->inbox_page(
	$current_user_id,
	isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	max( 1, absint( get_query_var( 'paged', 1 ) ) )
);
$active_filter        = $bn_inbox['filter'];
$bn_paged             = $bn_inbox['page'];
$bn_per_page          = $bn_inbox['per_page'];
$items                = $bn_inbox['items'];
$total_count          = $bn_inbox['total'];

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
$tab_unread   = $notification_service->unread_counts_by_tab( $current_user_id );
$total_unread = $tab_unread['unread'];
// Badge (bell / nav) = UNSEEN, distinct from the Unread TAB count above. By the
// time this hub renders, the list has been marked seen (PageRouter), so this is
// 0 here — keeping the mobile badge consistent with every other surface.
$badge_unseen    = (int) $notification_service->unseen_count( $current_user_id );
$reaction_unread = $tab_unread['reaction'];
$comment_unread  = $tab_unread['comment'];
$mention_unread  = $tab_unread['mention'];
$follow_unread   = $tab_unread['follow'];
$space_unread    = $tab_unread['space'];
$message_unread  = $tab_unread['message'];

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
foreach ( $rows as $row ) {
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
 * For a system notification (no actor, e.g. an earned badge) there is no person
 * to show, so render the notification's own type icon instead of a "?" initials
 * placeholder, which reads as an unknown member.
 *
 * @param int    $actor_id Actor user ID (0 for system notifications).
 * @param string $icon     Notification type icon slug (used only when actor_id <= 0).
 */
$render_avatar = static function ( int $actor_id, string $icon = '' ) use ( $actor_data ): void {
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
	if ( $actor_id <= 0 && '' !== $icon && function_exists( 'buddynext_icon' ) ) {
		?>
		<span class="bn-avatar bn-notif-row__avatar bn-notif-row__avatar--system" data-size="sm" aria-hidden="true"><?php buddynext_icon( $icon ); ?></span>
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
// Set the surface + raw context BEFORE the shell renders the right column,
// same pattern as every other surface (feed/space/profile/etc.). The
// registry reads the surface via Surface::current() and
// NotificationsSidebarProvider reads this context via Surface::context() to
// rebuild $quick_filters / $sidebar_types and register the six sidecards.
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

\BuddyNext\Sidebar\Surface::set( 'notifications', $sidebar_data );

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
		'unreadCount'  => $badge_unseen,
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
		// People and Messages were only ever reachable from the sidebar filter
		// blocks. Those are gone (one filter model, owner decision 2026-08-30), so
		// they move here rather than disappearing - removing the duplicate controls
		// must not remove the two things only they could filter.
		array(
			'key'   => 'follow',
			'label' => __( 'Follows', 'buddynext' ),
			'count' => $follow_unread,
		),
		array(
			'key'   => 'message',
			'label' => __( 'Messages', 'buddynext' ),
			'count' => $message_unread,
		),
	);
	// A tab exists only for a filter the page accepts (Messages drops out while
	// messaging is off), so the bar never offers a filter that falls back to All.
	$notif_tabs = array_values( array_filter( $notif_tabs, static fn( array $t ): bool => in_array( (string) $t['key'], $allowed_filters, true ) ) );

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
					href="<?php echo esc_url( \BuddyNext\Core\PageRouter::page_url( '', $bn_paged - 1 ) ); ?>">
					<?php buddynext_icon( 'chevron-left' ); ?>
					<?php esc_html_e( 'Previous', 'buddynext' ); ?>
				</a>
			<?php endif; ?>
			<span class="bn-notif-pagination__meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: current page number, 2: total page count. */
						__( 'Page %1$d of %2$d', 'buddynext' ),
						$bn_paged,
						$total_pages
					)
				);
				?>
			</span>
			<?php if ( $bn_paged < $total_pages ) : ?>
				<a class="bn-btn" data-variant="ghost" data-size="sm"
					href="<?php echo esc_url( \BuddyNext\Core\PageRouter::page_url( '', $bn_paged + 1 ) ); ?>">
					<?php esc_html_e( 'Next', 'buddynext' ); ?>
					<?php buddynext_icon( 'chevron-right' ); ?>
				</a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

	<?php
	/*
	 * End-of-list marker on the final page of a non-empty list — the same closure
	 * the feed gives ("You've reached the end."). Without it a short single-page
	 * list just stopped, leaving the tail of the hub reading as empty canvas rather
	 * than a finished list (card 10124166442). On a multi-page list the "Next"
	 * pager is the affordance, so this only appears once the last page is reached.
	 */
	?>
	<?php if ( $has_any && $bn_paged >= $total_pages ) : ?>
		<div class="bn-notifs-end" role="status">
			<span class="bn-notifs-end__text"><?php esc_html_e( "You've reached the end.", 'buddynext' ); ?></span>
		</div>
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
