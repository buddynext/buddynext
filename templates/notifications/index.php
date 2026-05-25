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

global $wpdb;

$current_user_id = get_current_user_id();
if ( ! $current_user_id ) {
	wp_safe_redirect( wp_login_url( get_permalink() ) );
	exit;
}

// Resolve active filter tab (sanitized).
$allowed_filters = array( 'all', 'unread', 'mention', 'reaction', 'comment', 'follow', 'space', 'message' );
$active_filter   = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $active_filter, $allowed_filters, true ) ) {
	$active_filter = 'all';
}

// Build WHERE clause for the active filter.
$filter_sql    = '';
$filter_types  = array();
$filter_unread = ( 'unread' === $active_filter );
if ( 'reaction' === $active_filter ) {
	$filter_types = array( 'bn.post_reacted' );
} elseif ( 'comment' === $active_filter ) {
	$filter_types = array( 'bn.post_commented' );
} elseif ( 'mention' === $active_filter ) {
	$filter_types = array( 'bn.mention' );
} elseif ( 'follow' === $active_filter ) {
	$filter_types = array( 'bn.new_follower', 'bn.connection_accepted', 'bn.connection_requested' );
} elseif ( 'space' === $active_filter ) {
	$filter_types = array( 'bn.space_invite', 'bn.space_join_requested', 'bn.space_new_post' );
} elseif ( 'message' === $active_filter ) {
	$filter_types = array( 'bn.new_message' );
}

if ( ! empty( $filter_types ) ) {
	$type_count = count( $filter_types );
	$type_phs   = implode( ', ', array_fill( 0, $type_count, '%s' ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$filter_sql = $wpdb->prepare( " AND n.type IN ($type_phs)", $filter_types );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
} elseif ( $filter_unread ) {
	$filter_sql = ' AND n.is_read = 0';
}

// Pagination (simple offset; cap at 25 per page).
$bn_per_page = 25;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_paged  = isset( $_GET['paged'] ) ? max( 1, (int) sanitize_text_field( wp_unslash( $_GET['paged'] ) ) ) : 1;
$bn_offset = ( $bn_paged - 1 ) * $bn_per_page;

// Count total rows for pagination (filtered).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$count_sql   = "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications AS n WHERE n.recipient_id = %d" . $filter_sql;
$total_count = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $current_user_id ) );

$base_sql = "SELECT n.id, n.type, n.sender_id, n.object_id, n.object_type, n.group_key, n.group_count, n.is_read, n.created_at
	 FROM {$wpdb->prefix}bn_notifications AS n
	 WHERE n.recipient_id = %d"
	. $filter_sql .
	' ORDER BY n.created_at DESC LIMIT %d OFFSET %d';
$rows     = $wpdb->get_results( $wpdb->prepare( $base_sql, $current_user_id, $bn_per_page, $bn_offset ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$total_pages = (int) max( 1, ceil( $total_count / $bn_per_page ) );

// Count unread per type for tab + sidebar badges.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$unread_counts = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT
			SUM( CASE WHEN is_read = 0 THEN 1 ELSE 0 END ) AS total_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.post_reacted') THEN 1 ELSE 0 END ) AS reaction_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.post_commented') THEN 1 ELSE 0 END ) AS comment_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.mention') THEN 1 ELSE 0 END ) AS mention_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.new_follower','bn.connection_accepted','bn.connection_requested') THEN 1 ELSE 0 END ) AS follow_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.space_invite','bn.space_join_requested','bn.space_new_post') THEN 1 ELSE 0 END ) AS space_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.new_message') THEN 1 ELSE 0 END ) AS message_unread
		 FROM {$wpdb->prefix}bn_notifications
		 WHERE recipient_id = %d",
		$current_user_id
	),
	ARRAY_A
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

$counts          = ! empty( $unread_counts ) ? $unread_counts[0] : array();
$total_unread    = (int) ( $counts['total_unread'] ?? 0 );
$reaction_unread = (int) ( $counts['reaction_unread'] ?? 0 );
$comment_unread  = (int) ( $counts['comment_unread'] ?? 0 );
$mention_unread  = (int) ( $counts['mention_unread'] ?? 0 );
$follow_unread   = (int) ( $counts['follow_unread'] ?? 0 );
$space_unread    = (int) ( $counts['space_unread'] ?? 0 );
$message_unread  = (int) ( $counts['message_unread'] ?? 0 );

// Prefetch actor display names + avatar URLs in a single pass to avoid N+1.
$actor_ids  = array_unique( array_filter( array_column( $rows ?? array(), 'sender_id' ) ) );
$actor_data = array();
foreach ( $actor_ids as $actor_id ) {
	$actor_id   = (int) $actor_id;
	$actor_user = get_userdata( $actor_id );
	if ( ! $actor_user ) {
		$actor_data[ $actor_id ] = array(
			'display_name' => __( 'Someone', 'buddynext' ),
			'initials'     => '?',
			'avatar_url'   => '',
		);
		continue;
	}
	$display                 = $actor_user->display_name;
	$first                   = mb_substr( $display, 0, 1 );
	$last_word               = strrchr( $display, ' ' );
	$second                  = $last_word ? mb_substr( ltrim( $last_word ), 0, 1 ) : '';
	$initials                = strtoupper( $first . $second );
	$actor_data[ $actor_id ] = array(
		'display_name' => $display,
		'initials'     => $initials,
		'avatar_url'   => get_avatar_url( $actor_id, array( 'size' => 56 ) ),
	);
}

// Recent actors - last 6 distinct senders who triggered a notification for this user.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$recent_actor_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT sender_id
		 FROM {$wpdb->prefix}bn_notifications
		 WHERE recipient_id = %d AND sender_id > 0
		 GROUP BY sender_id
		 ORDER BY MAX(created_at) DESC
		 LIMIT %d",
		$current_user_id,
		6
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

$recent_actors = array();
foreach ( $recent_actor_ids ?? array() as $actor_id ) {
	$actor_id = (int) $actor_id;
	if ( isset( $actor_data[ $actor_id ] ) ) {
		$recent_actors[ $actor_id ] = $actor_data[ $actor_id ];
		continue;
	}
	$actor_user = get_userdata( $actor_id );
	if ( ! $actor_user ) {
		continue;
	}
	$display                    = $actor_user->display_name;
	$first                      = mb_substr( $display, 0, 1 );
	$last_word                  = strrchr( $display, ' ' );
	$second                     = $last_word ? mb_substr( ltrim( $last_word ), 0, 1 ) : '';
	$recent_actors[ $actor_id ] = array(
		'display_name' => $display,
		'initials'     => strtoupper( $first . $second ),
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
	$row_ts = strtotime( $row->created_at );
	if ( $row_ts >= $today_ts ) {
		$groups['today'][] = $row;
	} elseif ( $row_ts >= $yesterday_ts ) {
		$groups['yesterday'][] = $row;
	} else {
		$groups['older'][] = $row;
	}
}

// Resolve the message-composer service from the container if available, with
// a direct instantiation fallback for partial bootstraps.
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
 * Returns a map keyed by row id with the message + url + icon + tone + label.
 *
 * @var array<int,array<string,mixed>> $composed_rows
 */
$composed_rows = array();
foreach ( $rows ?? array() as $row_obj ) {
	$row_array                               = (array) $row_obj;
	$composed_rows[ (int) $row_array['id'] ] = $message_service->compose( $row_array );
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
 * Human-readable time difference suitable for display inside <time>.
 *
 * @param string $created_at MySQL datetime string.
 * @return string e.g. "2 min".
 */
$time_ago = static function ( string $created_at ): string {
	$diff = time() - (int) strtotime( $created_at );
	if ( $diff < 60 ) {
		return __( 'just now', 'buddynext' );
	}
	if ( $diff < 3600 ) {
		$m = (int) round( $diff / 60 );
		/* translators: %d is the number of minutes. */
		return sprintf( _n( '%d min', '%d min', $m, 'buddynext' ), $m );
	}
	if ( $diff < 86400 ) {
		$h = (int) round( $diff / 3600 );
		/* translators: %d is the number of hours. */
		return sprintf( _n( '%dh', '%dh', $h, 'buddynext' ), $h );
	}
	$d = (int) round( $diff / 86400 );
	/* translators: %d is the number of days. */
	return sprintf( _n( '%dd', '%dd', $d, 'buddynext' ), $d );
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

	<div class="bn-notifs-main__content"
		data-bn-notif-content
		data-unread-count="<?php echo esc_attr( (string) $total_unread ); ?>"
		data-active-filter="<?php echo esc_attr( $active_filter ); ?>">

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
