<?php
/**
 * Notifications — full-page template (v2).
 *
 * Displays grouped, filterable notifications for the current user. Uses the
 * v2 attribute-driven primitive layer: .bn-tabs/.bn-tab[aria-selected] with
 * .bn-tab__count for filter tabs, .bn-avatar[data-size="sm"] for actor
 * avatars, .bn-badge[data-tone] for notification type pills, and
 * .bn-btn[data-variant][data-size] for actions. All styling lives in
 * assets/css/bn-notifications.css.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

// Fetch up to 50 notifications for the current user, ordered newest first.
$base_sql = "SELECT n.id, n.type, n.sender_id, n.object_id, n.object_type, n.group_key, n.group_count, n.is_read, n.created_at
	 FROM {$wpdb->prefix}bn_notifications AS n
	 WHERE n.recipient_id = %d"
	. $filter_sql .
	' ORDER BY n.created_at DESC LIMIT 50';
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
$rows = $wpdb->get_results( $wpdb->prepare( $base_sql, $current_user_id ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

// Count unread per type for tab/sidebar badges.
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

// Map notification type → type-pill tone + Lucide icon slug.
$type_meta = array(
	'bn.post_reacted'         => array(
		'tone' => 'accent',
		'icon' => 'heart',
	),
	'bn.post_commented'       => array(
		'tone' => 'info',
		'icon' => 'message-circle',
	),
	'bn.mention'              => array(
		'tone' => 'accent',
		'icon' => 'at-sign',
	),
	'bn.new_follower'         => array(
		'tone' => 'info',
		'icon' => 'user',
	),
	'bn.connection_requested' => array(
		'tone' => 'info',
		'icon' => 'users',
	),
	'bn.connection_accepted'  => array(
		'tone' => 'success',
		'icon' => 'users',
	),
	'bn.space_invite'         => array(
		'tone' => 'accent',
		'icon' => 'home',
	),
	'bn.space_join_requested' => array(
		'tone' => 'accent',
		'icon' => 'home',
	),
	'bn.space_new_post'       => array(
		'tone' => 'accent',
		'icon' => 'home',
	),
	'bn.new_message'          => array(
		'tone' => 'info',
		'icon' => 'mail',
	),
	'bn.badge_awarded'        => array(
		'tone' => 'warn',
		'icon' => 'award',
	),
	'bn.strike_issued'        => array(
		'tone' => 'danger',
		'icon' => 'alert-triangle',
	),
	'bn.strike_warning'       => array(
		'tone' => 'warn',
		'icon' => 'alert-triangle',
	),
	'bn.member_suspended'     => array(
		'tone' => 'danger',
		'icon' => 'lock',
	),
	'bn.appeal_resolved'      => array(
		'tone' => 'success',
		'icon' => 'check-circle',
	),
);

// Human-readable type label for a11y aria-label on the type pill.
$type_label = array(
	'bn.post_reacted'         => __( 'Reaction', 'buddynext' ),
	'bn.post_commented'       => __( 'Comment', 'buddynext' ),
	'bn.mention'              => __( 'Mention', 'buddynext' ),
	'bn.new_follower'         => __( 'New follower', 'buddynext' ),
	'bn.connection_requested' => __( 'Connection request', 'buddynext' ),
	'bn.connection_accepted'  => __( 'Connection accepted', 'buddynext' ),
	'bn.space_invite'         => __( 'Space invite', 'buddynext' ),
	'bn.space_join_requested' => __( 'Space join request', 'buddynext' ),
	'bn.space_new_post'       => __( 'New post in space', 'buddynext' ),
	'bn.new_message'          => __( 'Message', 'buddynext' ),
	'bn.badge_awarded'        => __( 'Badge', 'buddynext' ),
	'bn.strike_issued'        => __( 'Strike', 'buddynext' ),
	'bn.strike_warning'       => __( 'Strike warning', 'buddynext' ),
	'bn.member_suspended'     => __( 'Suspension', 'buddynext' ),
	'bn.appeal_resolved'      => __( 'Appeal resolved', 'buddynext' ),
);

/**
 * Return the display name for an actor.
 *
 * @param int $actor_id Actor user ID.
 * @return string Display name (unescaped — escape at the call site).
 */
$get_display_name = static function ( int $actor_id ) use ( $actor_data ): string {
	return $actor_data[ $actor_id ]['display_name'] ?? __( 'Someone', 'buddynext' );
};

/**
 * Render an actor avatar — image when available, initials fallback.
 *
 * @param int $actor_id Actor user ID.
 */
$render_avatar = static function ( int $actor_id ) use ( $actor_data ): void {
	$entry      = $actor_data[ $actor_id ] ?? array(
		'avatar_url' => '',
		'initials'   => '?',
	);
	$avatar_url = $entry['avatar_url'] ?? '';
	$initials   = $entry['initials'] ?? '?';
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
		// translators: %d is the number of minutes.
		return sprintf( _n( '%d min', '%d min', $m, 'buddynext' ), $m );
	}
	if ( $diff < 86400 ) {
		$h = (int) round( $diff / 3600 );
		// translators: %d is the number of hours.
		return sprintf( _n( '%dh', '%dh', $h, 'buddynext' ), $h );
	}
	$d = (int) round( $diff / 86400 );
	// translators: %d is the number of days.
	return sprintf( _n( '%dd', '%dd', $d, 'buddynext' ), $d );
};

$mark_all_nonce = wp_create_nonce( 'wp_rest' );
$rest_url       = esc_url( rest_url( 'buddynext/v1/me/notifications/read-all' ) );

/**
 * Render a single notification row.
 *
 * @param object   $row              Notification DB row.
 * @param callable $render_avatar    Avatar render closure.
 * @param callable $get_display_name Display name closure.
 * @param callable $time_ago         Time-ago closure.
 * @param array    $type_meta        Type → tone/icon map.
 * @param array    $type_label       Type → human-readable label.
 */
$render_row = static function ( object $row, callable $render_avatar, callable $get_display_name, callable $time_ago, array $type_meta, array $type_label ): void {
	$is_unread   = ! (bool) $row->is_read;
	$actor_id    = (int) $row->sender_id;
	$notif_type  = $row->type ?? '';
	$meta        = $type_meta[ $notif_type ] ?? array(
		'tone' => 'info',
		'icon' => 'bell',
	);
	$pill_label  = $type_label[ $notif_type ] ?? __( 'Notification', 'buddynext' );
	$group_count = isset( $row->group_count ) ? (int) $row->group_count : 1;
	$row_class   = 'bn-notif-row' . ( $is_unread ? ' bn-notif-row--unread' : '' );
	$object_id   = isset( $row->object_id ) ? (int) $row->object_id : 0;

	// Derive the navigation URL for this notification type.
	$link_url = match ( $notif_type ) {
		'bn.new_follower', 'bn.connection_requested', 'bn.connection_accepted'
			=> $actor_id ? \BuddyNext\Core\PageRouter::profile_url( $actor_id ) : '',
		'bn.space_invite', 'bn.space_join_requested', 'bn.space_new_post'
			=> $object_id ? \BuddyNext\Core\PageRouter::space_url( $object_id ) : '',
		'bn.new_message'
			=> \BuddyNext\Core\PageRouter::messages_url(),
		'bn.post_reacted', 'bn.post_commented', 'bn.mention'
			=> $object_id ? add_query_arg( 'post_id', $object_id, \BuddyNext\Core\PageRouter::activity_url() ) : '',
		default => '',
	};

	// Compose the notification message.
	if ( $group_count > 1 ) {
		$others           = $group_count - 1;
		$grouped_messages = array(
			'bn.new_follower'   => sprintf(
				/* translators: 1: actor display name, 2: number of other users */
				_n( '%1$s and %2$d other started following you.', '%1$s and %2$d others started following you.', $others, 'buddynext' ),
				$get_display_name( $actor_id ),
				$others
			),
			'bn.post_reacted'   => sprintf(
				/* translators: 1: actor display name, 2: number of other users */
				_n( '%1$s and %2$d other reacted to your post.', '%1$s and %2$d others reacted to your post.', $others, 'buddynext' ),
				$get_display_name( $actor_id ),
				$others
			),
			'bn.post_commented' => sprintf(
				/* translators: 1: actor display name, 2: number of other users */
				_n( '%1$s and %2$d other commented on your post.', '%1$s and %2$d others commented on your post.', $others, 'buddynext' ),
				$get_display_name( $actor_id ),
				$others
			),
			'bn.space_new_post' => sprintf(
				/* translators: 1: actor display name, 2: number of other users */
				_n( '%1$s and %2$d other posted in a space you follow.', '%1$s and %2$d others posted in a space you follow.', $others, 'buddynext' ),
				$get_display_name( $actor_id ),
				$others
			),
		);
		$message_text     = $grouped_messages[ $notif_type ] ?? '';
	} else {
		$message_text = '';
	}

	$type_messages = array(
		'bn.post_reacted'         => __( 'reacted to your post.', 'buddynext' ),
		'bn.post_commented'       => __( 'commented on your post.', 'buddynext' ),
		'bn.mention'              => __( 'mentioned you in a post.', 'buddynext' ),
		'bn.new_follower'         => __( 'started following you.', 'buddynext' ),
		'bn.connection_requested' => __( 'sent you a connection request.', 'buddynext' ),
		'bn.connection_accepted'  => __( 'accepted your connection request.', 'buddynext' ),
		'bn.space_invite'         => __( 'invited you to a space.', 'buddynext' ),
		'bn.space_join_requested' => __( 'requested to join your space.', 'buddynext' ),
		'bn.space_new_post'       => __( 'posted in a space you follow.', 'buddynext' ),
		'bn.new_message'          => __( 'sent you a message.', 'buddynext' ),
		'bn.badge_awarded'        => __( 'You earned a new badge.', 'buddynext' ),
		'bn.strike_issued'        => __( 'You received a strike.', 'buddynext' ),
		'bn.strike_warning'       => __( 'Strike warning issued.', 'buddynext' ),
		'bn.member_suspended'     => __( 'Your account has been suspended.', 'buddynext' ),
		'bn.appeal_resolved'      => __( 'Your appeal has been reviewed.', 'buddynext' ),
	);
	?>
	<div class="<?php echo esc_attr( $row_class ); ?>"
		data-wp-on--click="actions.markRead"
		data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>"
		<?php if ( $link_url ) : ?>
			data-notif-link="<?php echo esc_url( $link_url ); ?>"
		<?php endif; ?>>

		<?php if ( $is_unread ) : ?>
			<span class="bn-notif-row__pulse" aria-label="<?php esc_attr_e( 'Unread', 'buddynext' ); ?>"></span>
		<?php endif; ?>

		<div class="bn-notif-row__avatar-wrap">
			<?php $render_avatar( $actor_id ); ?>
			<span class="bn-notif-row__type" data-tone="<?php echo esc_attr( $meta['tone'] ); ?>" aria-label="<?php echo esc_attr( $pill_label ); ?>">
				<?php buddynext_icon( $meta['icon'] ); ?>
			</span>
		</div>

		<div class="bn-notif-row__body">
			<div class="bn-notif-row__text">
				<?php if ( '' !== $message_text ) : ?>
					<?php echo esc_html( $message_text ); ?>
				<?php else : ?>
					<strong><?php echo esc_html( $get_display_name( $actor_id ) ); ?></strong>
					<?php
					$default_msg = $type_messages[ $notif_type ] ?? __( 'sent you a notification.', 'buddynext' );
					echo ' ' . esc_html( $default_msg );
					?>
				<?php endif; ?>
			</div>

			<div class="bn-notif-row__meta">
				<span class="bn-badge" data-tone="<?php echo esc_attr( $meta['tone'] ); ?>"><?php echo esc_html( $pill_label ); ?></span>
				<time class="bn-notif-row__time" datetime="<?php echo esc_attr( mysql2date( DATE_W3C, $row->created_at, false ) ); ?>"><?php echo esc_html( $time_ago( $row->created_at ) ); ?></time>
			</div>

			<?php if ( 'bn.space_invite' === $notif_type ) : ?>
				<div class="bn-notif-row__actions">
					<button class="bn-btn" data-variant="primary" data-size="sm"
						data-wp-on--click="actions.acceptSpaceInvite"
						data-object-id="<?php echo esc_attr( (string) $row->object_id ); ?>"
						data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>">
						<?php esc_html_e( 'Accept', 'buddynext' ); ?>
					</button>
					<button class="bn-btn" data-variant="ghost" data-size="sm"
						data-wp-on--click="actions.declineSpaceInvite"
						data-object-id="<?php echo esc_attr( (string) $row->object_id ); ?>"
						data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>">
						<?php esc_html_e( 'Decline', 'buddynext' ); ?>
					</button>
				</div>
			<?php elseif ( $is_unread ) : ?>
				<div class="bn-notif-row__actions">
					<button class="bn-btn" data-variant="ghost" data-size="sm"
						data-wp-on--click="actions.markReadOnly"
						data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>"
						aria-label="<?php esc_attr_e( 'Mark this notification as read', 'buddynext' ); ?>">
						<?php esc_html_e( 'Mark as read', 'buddynext' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
};

$bn_nav_active = 'notifications';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>

<div class="bn-hub-shell">

<div class="bn-notifs-shell"
	data-wp-interactive="buddynext/notifications"
	data-wp-context='{"markedAll":false,"activeFilter":"<?php echo esc_attr( $active_filter ); ?>","nonce":"<?php echo esc_attr( $mark_all_nonce ); ?>","restUrl":"<?php echo esc_url( rest_url( 'buddynext/v1/me/notifications' ) ); ?>"}'>

<div class="bn-notifs-main">

	<header class="bn-notifs-header">
		<h1 class="bn-notifs-title">
			<?php esc_html_e( 'Notifications', 'buddynext' ); ?>
			<?php if ( $total_unread > 0 ) : ?>
				<span class="bn-badge" data-tone="accent">
				<?php
					echo esc_html(
						sprintf(
							/* translators: %d is the number of unread notifications. */
							_n( '%d new', '%d new', $total_unread, 'buddynext' ),
							min( $total_unread, 99 )
						)
					);
				?>
				</span>
			<?php endif; ?>
		</h1>
		<div class="bn-notifs-actions">
			<?php if ( $total_unread > 0 ) : ?>
				<button class="bn-btn" data-variant="secondary" data-size="sm"
					data-wp-on--click="actions.markAllRead"
					data-nonce="<?php echo esc_attr( $mark_all_nonce ); ?>"
					data-url="<?php echo esc_attr( $rest_url ); ?>">
					<?php buddynext_icon( 'check-circle' ); ?>
					<?php esc_html_e( 'Mark all read', 'buddynext' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</header>

	<nav class="bn-tabs bn-notif-tabs" aria-label="<?php esc_attr_e( 'Notification filters', 'buddynext' ); ?>" role="tablist">
		<?php
		$notif_tabs = array(
			'all'      => array(
				'label' => __( 'All', 'buddynext' ),
				'count' => $total_unread,
			),
			'unread'   => array(
				'label' => __( 'Unread', 'buddynext' ),
				'count' => $total_unread,
			),
			'mention'  => array(
				'label' => __( 'Mentions', 'buddynext' ),
				'count' => $mention_unread,
			),
			'reaction' => array(
				'label' => __( 'Reactions', 'buddynext' ),
				'count' => $reaction_unread,
			),
			'comment'  => array(
				'label' => __( 'Comments', 'buddynext' ),
				'count' => $comment_unread,
			),
			'follow'   => array(
				'label' => __( 'People', 'buddynext' ),
				'count' => $follow_unread,
			),
			'space'    => array(
				'label' => __( 'Spaces', 'buddynext' ),
				'count' => $space_unread,
			),
			'message'  => array(
				'label' => __( 'Messages', 'buddynext' ),
				'count' => $message_unread,
			),
		);
		foreach ( $notif_tabs as $key => $notif_tab ) :
			$is_active = ( $key === $active_filter );
			$tab_url   = add_query_arg( 'filter', $key );
			?>
			<a href="<?php echo esc_url( $tab_url ); ?>"
				class="bn-tab"
				role="tab"
				aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
				aria-current="<?php echo $is_active ? 'page' : 'false'; ?>">
				<?php echo esc_html( $notif_tab['label'] ); ?>
				<?php if ( $notif_tab['count'] > 0 ) : ?>
					<span class="bn-tab__count"><?php echo esc_html( (string) min( (int) $notif_tab['count'], 99 ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

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
		$has_any         = true;
		$unread_in_group = count( array_filter( $group_rows, static fn( $r ) => ! (bool) $r->is_read ) );
		?>
		<section class="bn-notif-group">
			<header class="bn-notif-group__head">
				<span class="bn-notif-group__title"><?php echo esc_html( $group_labels[ $group_key ] ); ?></span>
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
			<div class="bn-card bn-notif-list" data-v2>
				<?php
				foreach ( $group_rows as $notif_row ) {
					$render_row( $notif_row, $render_avatar, $get_display_name, $time_ago, $type_meta, $type_label );
				}
				?>
			</div>
		</section>
	<?php endforeach; ?>

	<?php if ( ! $has_any ) : ?>
		<div class="bn-card bn-notif-empty" data-v2>
			<span class="bn-notif-empty__emblem" aria-hidden="true"><?php buddynext_icon( 'bell' ); ?></span>
			<p class="bn-notif-empty__title"><?php esc_html_e( 'All caught up', 'buddynext' ); ?></p>
			<p class="bn-notif-empty__sub"><?php esc_html_e( 'No notifications match this filter. New activity will show up here.', 'buddynext' ); ?></p>
		</div>
	<?php endif; ?>

</div><!-- .bn-notifs-main -->

<aside class="bn-notifs-sidebar" aria-label="<?php esc_attr_e( 'Notification summary', 'buddynext' ); ?>">

	<section class="bn-card bn-notif-sidecard" data-v2>
		<header class="bn-notif-sidecard__head"><?php esc_html_e( 'By type', 'buddynext' ); ?></header>
		<?php
		$sidebar_types = array(
			'mention'  => array(
				'label' => __( 'Mentions', 'buddynext' ),
				'icon'  => 'at-sign',
				'count' => $mention_unread,
				'url'   => add_query_arg( 'filter', 'mention' ),
			),
			'reaction' => array(
				'label' => __( 'Reactions', 'buddynext' ),
				'icon'  => 'heart',
				'count' => $reaction_unread,
				'url'   => add_query_arg( 'filter', 'reaction' ),
			),
			'comment'  => array(
				'label' => __( 'Comments', 'buddynext' ),
				'icon'  => 'message-circle',
				'count' => $comment_unread,
				'url'   => add_query_arg( 'filter', 'comment' ),
			),
			'follow'   => array(
				'label' => __( 'People', 'buddynext' ),
				'icon'  => 'users',
				'count' => $follow_unread,
				'url'   => add_query_arg( 'filter', 'follow' ),
			),
			'space'    => array(
				'label' => __( 'Spaces', 'buddynext' ),
				'icon'  => 'home',
				'count' => $space_unread,
				'url'   => add_query_arg( 'filter', 'space' ),
			),
			'message'  => array(
				'label' => __( 'Messages', 'buddynext' ),
				'icon'  => 'mail',
				'count' => $message_unread,
				'url'   => add_query_arg( 'filter', 'message' ),
			),
		);
		foreach ( $sidebar_types as $stype ) :
			?>
			<a href="<?php echo esc_url( $stype['url'] ); ?>" class="bn-notif-sidecard__row">
				<span class="bn-notif-sidecard__icon" aria-hidden="true"><?php buddynext_icon( $stype['icon'] ); ?></span>
				<span class="bn-notif-sidecard__label"><?php echo esc_html( $stype['label'] ); ?></span>
				<?php if ( $stype['count'] > 0 ) : ?>
					<span class="bn-badge" data-tone="accent"><?php echo esc_html( (string) min( $stype['count'], 99 ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</section>

</aside>

</div><!-- .bn-notifs-shell -->

<?php buddynext_get_template( 'partials/sidebar.php' ); ?>

</div><!-- /.bn-hub-shell -->
