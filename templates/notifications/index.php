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

use BuddyNext\Core\PageRouter;
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
 * Render a single notification row.
 *
 * The message, link, icon, tone, and label are pre-composed by
 * NotificationMessageService::compose() so this closure only handles the
 * presentation. Adding a new notification type means adding a case to the
 * service — no template changes required.
 *
 * @param object   $row           Notification DB row.
 * @param array    $payload       Pre-composed presentation payload.
 * @param callable $render_avatar Avatar render closure.
 * @param callable $time_ago      Time-ago closure.
 */
$render_row = static function ( object $row, array $payload, callable $render_avatar, callable $time_ago ): void {
	$is_unread   = ! (bool) $row->is_read;
	$actor_id    = (int) $row->sender_id;
	$notif_type  = (string) ( $row->type ?? '' );
	$row_class   = 'bn-notif-row' . ( $is_unread ? ' bn-notif-row--unread' : '' );
	$message     = (string) ( $payload['message'] ?? '' );
	$actor_name  = (string) ( $payload['actor_name'] ?? '' );
	$link_url    = (string) ( $payload['url'] ?? '' );
	$icon        = (string) ( $payload['icon'] ?? 'bell' );
	$tone        = (string) ( $payload['tone'] ?? 'info' );
	$pill_label  = (string) ( $payload['label'] ?? __( 'Notification', 'buddynext' ) );
	$group_count = (int) ( $payload['group_count'] ?? 1 );

	// Wrap the actor name in <strong> for visual emphasis without breaking
	// translator placeholder ordering. We do this by escaping the message
	// (which includes the actor name) and then substituting the escaped
	// actor name token for the bolded variant. This keeps every string
	// passing through esc_html and matches the v2 wireframe.
	$rendered = esc_html( $message );
	if ( '' !== $actor_name && false !== strpos( $message, $actor_name ) ) {
		$rendered = str_replace(
			esc_html( $actor_name ),
			'<strong>' . esc_html( $actor_name ) . '</strong>',
			$rendered
		);
	}
	?>
	<div class="<?php echo esc_attr( $row_class ); ?>"
		role="listitem"
		data-interactive
		data-wp-on--click="actions.markRead"
		data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>"
		data-notif-type="<?php echo esc_attr( $notif_type ); ?>"
		<?php if ( '' !== $link_url ) : ?>
			data-notif-link="<?php echo esc_url( $link_url ); ?>"
		<?php endif; ?>>

		<?php if ( $is_unread ) : ?>
			<span class="bn-notif-row__pulse" aria-label="<?php esc_attr_e( 'Unread', 'buddynext' ); ?>"></span>
		<?php endif; ?>

		<div class="bn-notif-row__avatar-wrap">
			<?php $render_avatar( $actor_id ); ?>
			<span class="bn-notif-row__type" data-tone="<?php echo esc_attr( $tone ); ?>" aria-label="<?php echo esc_attr( $pill_label ); ?>">
				<?php buddynext_icon( $icon ); ?>
			</span>
		</div>

		<div class="bn-notif-row__body">
			<div class="bn-notif-row__text">
				<?php
				// $rendered is built from esc_html()'d output with a single
				// <strong> wrap around the (already-escaped) actor name, so
				// the resulting HTML is safe to emit.
				echo $rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
				?>
				<?php if ( $group_count > 1 ) : ?>
					<span class="bn-notif-row__group" aria-hidden="true"><?php echo esc_html( '×' . (string) $group_count ); ?></span>
				<?php endif; ?>
			</div>

			<div class="bn-notif-row__meta">
				<span class="bn-badge" data-tone="<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $pill_label ); ?></span>
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
					<?php if ( '' !== $link_url ) : ?>
						<a class="bn-btn" data-variant="ghost" data-size="sm"
							href="<?php echo esc_url( $link_url ); ?>"
							data-wp-on--click="actions.openAndMark"
							data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>">
							<?php esc_html_e( 'Open', 'buddynext' ); ?>
						</a>
					<?php endif; ?>
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
		?>
		<section class="bn-card bn-notif-sidecard" data-v2 aria-labelledby="bn-notif-side-filters">
			<header id="bn-notif-side-filters" class="bn-notif-sidecard__head"><?php esc_html_e( 'Quick filters', 'buddynext' ); ?></header>
			<?php foreach ( $quick_filters as $qf ) : ?>
				<?php $is_active = ( $qf['key'] === $active_filter ); ?>
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

		<section class="bn-card bn-notif-sidecard" data-v2 aria-labelledby="bn-notif-side-types">
			<header id="bn-notif-side-types" class="bn-notif-sidecard__head"><?php esc_html_e( 'By type', 'buddynext' ); ?></header>
			<?php foreach ( $sidebar_types as $type_key => $stype ) : ?>
				<?php $is_active = ( $type_key === $active_filter ); ?>
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

		<?php if ( ! empty( $recent_actors ) ) : ?>
			<section class="bn-card bn-notif-sidecard" data-v2 aria-labelledby="bn-notif-side-recent">
				<header id="bn-notif-side-recent" class="bn-notif-sidecard__head"><?php esc_html_e( 'Recent actors', 'buddynext' ); ?></header>
				<div class="bn-notif-sidecard__actors">
					<?php foreach ( $recent_actors as $actor_id => $actor ) : ?>
						<a href="<?php echo esc_url( PageRouter::profile_url( (int) $actor_id ) ); ?>"
							class="bn-notif-sidecard__actor"
							title="<?php echo esc_attr( $actor['display_name'] ); ?>">
							<?php if ( ! empty( $actor['avatar_url'] ) ) : ?>
								<img src="<?php echo esc_url( $actor['avatar_url'] ); ?>" alt="<?php echo esc_attr( $actor['display_name'] ); ?>" width="32" height="32" loading="lazy">
							<?php else : ?>
								<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( $actor['initials'] ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<section class="bn-card bn-notif-sidecard" data-v2 aria-labelledby="bn-notif-side-prefs">
			<header id="bn-notif-side-prefs" class="bn-notif-sidecard__head"><?php esc_html_e( 'Preferences', 'buddynext' ); ?></header>
			<a href="<?php echo esc_url( PageRouter::notification_prefs_url() ); ?>" class="bn-notif-sidecard__row">
				<span class="bn-notif-sidecard__icon" aria-hidden="true"><?php buddynext_icon( 'settings' ); ?></span>
				<span class="bn-notif-sidecard__label"><?php esc_html_e( 'Notification preferences', 'buddynext' ); ?></span>
			</a>
		</section>
		<?php
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

	<header class="bn-section-head">
		<div class="bn-section-head__lead">
			<div class="bn-section-head__text">
				<h1 class="bn-section-head__title">
					<?php esc_html_e( 'Notifications', 'buddynext' ); ?>
					<?php if ( $total_unread > 0 ) : ?>
						<span class="bn-badge" data-tone="accent" data-wp-text="state.unreadLabel">
						<?php
						$display = $total_unread > 99 ? '99+' : (string) $total_unread;
						echo esc_html(
							sprintf(
								/* translators: %s is the formatted number of unread notifications (e.g. "12" or "99+"). */
								__( '%s new', 'buddynext' ),
								$display
							)
						);
						?>
						</span>
					<?php endif; ?>
				</h1>
			</div>
		</div>
		<div class="bn-section-head__actions">
			<?php if ( $total_unread > 0 ) : ?>
				<button class="bn-btn" data-variant="secondary" data-size="sm"
					data-wp-on--click="actions.markAllRead"
					data-nonce="<?php echo esc_attr( $mark_all_nonce ); ?>"
					data-url="<?php echo esc_attr( $rest_url ); ?>">
					<?php buddynext_icon( 'check-circle' ); ?>
					<?php esc_html_e( 'Mark all read', 'buddynext' ); ?>
				</button>
			<?php endif; ?>
			<a class="bn-btn bn-btn--prefs-link" data-variant="ghost" data-size="sm"
				href="<?php echo esc_url( PageRouter::notification_prefs_url() ); ?>"
				aria-label="<?php esc_attr_e( 'Notification preferences', 'buddynext' ); ?>">
				<?php buddynext_icon( 'settings' ); ?>
				<?php esc_html_e( 'Settings', 'buddynext' ); ?>
			</a>
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
			'comment'  => array(
				'label' => __( 'Comments', 'buddynext' ),
				'count' => $comment_unread,
			),
			'reaction' => array(
				'label' => __( 'Reactions', 'buddynext' ),
				'count' => $reaction_unread,
			),
			'space'    => array(
				'label' => __( 'Spaces', 'buddynext' ),
				'count' => $space_unread,
			),
		);
		foreach ( $notif_tabs as $key => $notif_tab ) :
			$is_active = ( $key === $active_filter );
			$tab_url   = add_query_arg(
				array(
					'filter' => $key,
					'paged'  => false,
				)
			);
			?>
			<a href="<?php echo esc_url( $tab_url ); ?>"
				class="bn-tab<?php echo $is_active ? ' is-active' : ''; ?>"
				role="tab"
				data-filter="<?php echo esc_attr( $key ); ?>"
				data-wp-on--click="actions.setFilter"
				aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
				aria-current="<?php echo $is_active ? 'page' : 'false'; ?>">
				<?php echo esc_html( $notif_tab['label'] ); ?>
				<?php if ( $notif_tab['count'] > 0 ) : ?>
					<span class="bn-tab__count"><?php echo esc_html( (string) min( (int) $notif_tab['count'], 99 ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

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
			<div class="bn-card bn-notif-list" data-v2 role="list">
				<?php
				foreach ( $group_rows as $notif_row ) {
					$payload = $composed_rows[ (int) $notif_row->id ] ?? array();
					$render_row( $notif_row, $payload, $render_avatar, $time_ago );
				}
				?>
			</div>
		</section>
	<?php endforeach; ?>

	<?php
	if ( ! $has_any ) :
		// Filter-specific empty-state copy so each tab tells the user what
		// they should see when activity exists for that category.
		$empty_copy  = array(
			'all'      => array(
				'title' => __( "You're all caught up", 'buddynext' ),
				'sub'   => __( 'New activity will show here. Try posting something or following a few members to get started.', 'buddynext' ),
				'icon'  => 'check-circle',
			),
			'unread'   => array(
				'title' => __( 'No unread notifications', 'buddynext' ),
				'sub'   => __( 'Everything has been read. Use the filters on the left to revisit older activity.', 'buddynext' ),
				'icon'  => 'check-circle',
			),
			'mention'  => array(
				'title' => __( 'No mentions yet', 'buddynext' ),
				'sub'   => __( 'When someone mentions you in a post or a comment, it will appear here.', 'buddynext' ),
				'icon'  => 'at-sign',
			),
			'comment'  => array(
				'title' => __( 'No comments yet', 'buddynext' ),
				'sub'   => __( 'Comments on your posts will appear here.', 'buddynext' ),
				'icon'  => 'message-circle',
			),
			'reaction' => array(
				'title' => __( 'No reactions yet', 'buddynext' ),
				'sub'   => __( 'Reactions to your posts will appear here.', 'buddynext' ),
				'icon'  => 'heart',
			),
			'follow'   => array(
				'title' => __( 'No follow activity', 'buddynext' ),
				'sub'   => __( 'New followers, connection requests, and accepted connections will appear here.', 'buddynext' ),
				'icon'  => 'users',
			),
			'space'    => array(
				'title' => __( 'No space activity', 'buddynext' ),
				'sub'   => __( 'Invites, join requests, and new posts in your spaces will appear here.', 'buddynext' ),
				'icon'  => 'home',
			),
			'message'  => array(
				'title' => __( 'No new messages', 'buddynext' ),
				'sub'   => __( 'Direct messages will appear here when someone reaches out.', 'buddynext' ),
				'icon'  => 'mail',
			),
		);
		$empty_state = $empty_copy[ $active_filter ] ?? $empty_copy['all'];
		?>
		<div class="bn-card bn-notif-empty" data-v2 role="status">
			<span class="bn-notif-empty__emblem" aria-hidden="true"><?php buddynext_icon( $empty_state['icon'] ); ?></span>
			<p class="bn-notif-empty__title"><?php echo esc_html( $empty_state['title'] ); ?></p>
			<p class="bn-notif-empty__sub"><?php echo esc_html( $empty_state['sub'] ); ?></p>
			<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( PageRouter::activity_url() ); ?>">
				<?php esc_html_e( 'Go to activity', 'buddynext' ); ?>
			</a>
		</div>
	<?php endif; ?>

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
