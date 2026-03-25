<?php
/**
 * Notifications — full-page template.
 *
 * Displays grouped, filterable notifications for the current user.
 * Supports filter tabs (All, Reactions, Comments, People, Spaces, Messages),
 * read/unread state, inline actions (accept/decline space invites), and a
 * "Mark all read" button that calls buddynext/v1/me/notifications/read-all.
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
$allowed_filters = array( 'all', 'reaction', 'comment', 'follow', 'space', 'message' );
$active_filter   = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $active_filter, $allowed_filters, true ) ) {
	$active_filter = 'all';
}

// Build WHERE clause for the active filter.
$filter_sql   = '';
$filter_types = array();
if ( 'reaction' === $active_filter ) {
	$filter_types = array( 'bn.post_reacted' );
} elseif ( 'comment' === $active_filter ) {
	$filter_types = array( 'bn.post_commented' );
} elseif ( 'follow' === $active_filter ) {
	$filter_types = array( 'bn.new_follower', 'bn.connection_accepted', 'bn.connection_requested' );
} elseif ( 'space' === $active_filter ) {
	$filter_types = array( 'bn.space_invite', 'bn.space_join_requested', 'bn.space_new_post' );
} elseif ( 'message' === $active_filter ) {
	$filter_types = array( 'bn.new_message' );
}

if ( ! empty( $filter_types ) ) {
	$type_count = count( $filter_types );
	// Build a safe type-filter clause: %s placeholders resolved via prepare().
	$type_phs = implode( ', ', array_fill( 0, $type_count, '%s' ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$filter_sql = $wpdb->prepare( " AND n.type IN ($type_phs)", $filter_types );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
}

// Fetch up to 50 notifications for the current user, ordered newest first.
// $filter_sql is fully prepared above; build final SQL by string concat before prepare().
$base_sql = "SELECT n.id, n.type, n.sender_id, n.object_id, n.object_type, n.group_key, n.group_count, n.is_read, n.created_at
	 FROM {$wpdb->prefix}bn_notifications AS n
	 WHERE n.recipient_id = %d"
	. $filter_sql .
	' ORDER BY n.created_at DESC LIMIT 50';
$rows     = $wpdb->get_results( $wpdb->prepare( $base_sql, $current_user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

// Count unread per type for badges.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$unread_counts = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT
			SUM( CASE WHEN is_read = 0 THEN 1 ELSE 0 END ) AS total_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.post_reacted') THEN 1 ELSE 0 END ) AS reaction_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.post_commented') THEN 1 ELSE 0 END ) AS comment_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.new_follower','bn.connection_accepted','bn.connection_requested') THEN 1 ELSE 0 END ) AS follow_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.space_invite','bn.space_join_requested','bn.space_new_post') THEN 1 ELSE 0 END ) AS space_unread,
			SUM( CASE WHEN is_read = 0 AND type IN ('bn.new_message') THEN 1 ELSE 0 END ) AS message_unread
		 FROM {$wpdb->prefix}bn_notifications
		 WHERE recipient_id = %d",
		$current_user_id
	),
	ARRAY_A
);

$counts          = ! empty( $unread_counts ) ? $unread_counts[0] : array();
$total_unread    = (int) ( $counts['total_unread'] ?? 0 );
$reaction_unread = (int) ( $counts['reaction_unread'] ?? 0 );
$comment_unread  = (int) ( $counts['comment_unread'] ?? 0 );
$follow_unread   = (int) ( $counts['follow_unread'] ?? 0 );
$space_unread    = (int) ( $counts['space_unread'] ?? 0 );
$message_unread  = (int) ( $counts['message_unread'] ?? 0 );

// Prefetch actor display names and avatars in a single query to avoid N+1.
$actor_ids  = array_unique( array_filter( array_column( $rows ?? array(), 'sender_id' ) ) );
$actor_data = array();
if ( ! empty( $actor_ids ) ) {
	foreach ( $actor_ids as $actor_id ) {
		$actor_id                = (int) $actor_id;
		$actor_user              = get_userdata( $actor_id );
		$actor_data[ $actor_id ] = array(
			'display_name' => $actor_user ? $actor_user->display_name : __( 'Someone', 'buddynext' ),
			'initials'     => $actor_user ? strtoupper( substr( $actor_user->display_name, 0, 1 ) . substr( (string) ( strrchr( $actor_user->display_name, ' ' ) ? strrchr( $actor_user->display_name, ' ' ) : '' ), 1, 1 ) ) : '?',
		);
	}
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

// Map notification type → icon background colour and emoji.
$type_meta = array(
	'bn.post_reacted'         => array(
		'color' => '#dc2626',
		'icon'  => buddynext_get_icon( 'heart' ),
	),
	'bn.post_commented'       => array(
		'color' => '#0073aa',
		'icon'  => buddynext_get_icon( 'message-circle' ),
	),
	'bn.new_follower'         => array(
		'color' => '#059669',
		'icon'  => buddynext_get_icon( 'user' ),
	),
	'bn.connection_requested' => array(
		'color' => '#059669',
		'icon'  => buddynext_get_icon( 'users' ),
	),
	'bn.connection_accepted'  => array(
		'color' => '#22c55e',
		'icon'  => buddynext_get_icon( 'users' ),
	),
	'bn.space_invite'         => array(
		'color' => '#7c3aed',
		'icon'  => buddynext_get_icon( 'home' ),
	),
	'bn.space_join_requested' => array(
		'color' => '#7c3aed',
		'icon'  => buddynext_get_icon( 'home' ),
	),
	'bn.new_message'          => array(
		'color' => '#0073aa',
		'icon'  => buddynext_get_icon( 'mail' ),
	),
	'bn.badge_awarded'        => array(
		'color' => '#d97706',
		'icon'  => buddynext_get_icon( 'award' ),
	),
	'bn.mention'              => array(
		'color' => '#dc2626',
		'icon'  => buddynext_get_icon( 'megaphone' ),
	),
	'bn.strike_issued'        => array(
		'color' => '#d97706',
		'icon'  => buddynext_get_icon( 'alert-triangle' ),
	),
	'bn.strike_warning'       => array(
		'color' => '#d97706',
		'icon'  => buddynext_get_icon( 'alert-triangle' ),
	),
	'bn.member_suspended'     => array(
		'color' => '#dc2626',
		'icon'  => buddynext_get_icon( 'lock' ),
	),
	'bn.appeal_resolved'      => array(
		'color' => '#059669',
		'icon'  => buddynext_get_icon( 'check-circle' ),
	),
	'bn.space_new_post'       => array(
		'color' => '#7c3aed',
		'icon'  => buddynext_get_icon( 'home' ),
	),
);

// Avatar colour palette — deterministic from user ID.
$avatar_palette = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0f766e', '#d97706', '#475569' );

/**
 * Return a CSS background colour for an actor avatar from the palette.
 *
 * @param int $user_id Actor user ID.
 * @return string Hex colour string.
 */
$avatar_color = static function ( int $user_id ) use ( $avatar_palette ): string {
	return $avatar_palette[ $user_id % count( $avatar_palette ) ];
};

/**
 * Return initials for an actor.
 *
 * @param int $actor_id Actor user ID.
 * @return string Two-character initials.
 */
$get_initials = static function ( int $actor_id ) use ( $actor_data ): string {
	return isset( $actor_data[ $actor_id ] ) ? esc_html( $actor_data[ $actor_id ]['initials'] ) : '?';
};

/**
 * Return the display name for an actor.
 *
 * @param int $actor_id Actor user ID.
 * @return string Escaped display name.
 */
$get_display_name = static function ( int $actor_id ) use ( $actor_data ): string {
	return isset( $actor_data[ $actor_id ] ) ? esc_html( $actor_data[ $actor_id ]['display_name'] ) : esc_html__( 'Someone', 'buddynext' );
};

/**
 * Human-readable time difference.
 *
 * @param string $created_at MySQL datetime string.
 * @return string e.g. "2 min ago".
 */
$time_ago = static function ( string $created_at ): string {
	$diff = time() - (int) strtotime( $created_at );
	if ( $diff < 60 ) {
		return esc_html__( 'just now', 'buddynext' );
	}
	if ( $diff < 3600 ) {
		$m = (int) round( $diff / 60 );
		// translators: %d is the number of minutes.
		return esc_html( sprintf( _n( '%d min ago', '%d min ago', $m, 'buddynext' ), $m ) );
	}
	if ( $diff < 86400 ) {
		$h = (int) round( $diff / 3600 );
		// translators: %d is the number of hours.
		return esc_html( sprintf( _n( '%dh ago', '%dh ago', $h, 'buddynext' ), $h ) );
	}
	$d = (int) round( $diff / 86400 );
	// translators: %d is the number of days.
	return esc_html( sprintf( _n( '%dd ago', '%dd ago', $d, 'buddynext' ), $d ) );
};

$mark_all_nonce = wp_create_nonce( 'wp_rest' );
$rest_url       = esc_url( rest_url( 'buddynext/v1/me/notifications/read-all' ) );

/**
 * Render a single notification row.
 *
 * @param object   $row              Notification DB row.
 * @param callable $get_initials     Initials closure.
 * @param callable $get_display_name Display name closure.
 * @param callable $time_ago         Time-ago closure.
 * @param callable $avatar_color     Avatar colour closure.
 * @param array    $type_meta        Type → icon/colour map.
 */
$render_row = static function ( object $row, callable $get_initials, callable $get_display_name, callable $time_ago, callable $avatar_color, array $type_meta ): void {
	$is_unread   = ! (bool) $row->is_read;
	$actor_id    = (int) $row->sender_id;
	$notif_type  = $row->type ?? '';
	$meta        = $type_meta[ $notif_type ] ?? array(
		'color' => '#9b9b97',
		'icon'  => buddynext_get_icon( 'bell' ),
	);
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

	// Grouped notification messages: show "X and N others did Y" when group_count > 1.
	// Singular messages are used for group_count === 1.
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

		if ( isset( $grouped_messages[ $notif_type ] ) ) {
			?>
			<div class="<?php echo esc_attr( $row_class ); ?>"
				data-wp-on--click="actions.markRead"
				data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>"
				<?php
				if ( $link_url ) :
					?>
					data-notif-link="<?php echo esc_url( $link_url ); ?>"<?php endif; ?>>

				<div class="bn-notif-ava" style="background:<?php echo esc_attr( $avatar_color( $actor_id ) ); ?>;">
					<?php echo $get_initials( $actor_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside closure. ?>
					<span class="bn-notif-type-icon" style="background:<?php echo esc_attr( $meta['color'] ); ?>;"
						aria-hidden="true"><?php echo $meta['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML entity literals, no user data. ?></span>
				</div>

				<div class="bn-notif-content">
					<div class="bn-notif-text">
						<?php echo esc_html( $grouped_messages[ $notif_type ] ); ?>
					</div>

					<?php if ( 'bn.space_invite' === $notif_type ) : ?>
						<div class="bn-notif-actions">
							<button class="bn-btn bn-btn--primary bn-btn--xs"
								data-wp-on--click="actions.acceptSpaceInvite"
								data-object-id="<?php echo esc_attr( (string) $row->object_id ); ?>"
								data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>">
								<?php esc_html_e( 'Accept', 'buddynext' ); ?>
							</button>
							<button class="bn-btn bn-btn--ghost bn-btn--xs"
								data-wp-on--click="actions.declineSpaceInvite"
								data-object-id="<?php echo esc_attr( (string) $row->object_id ); ?>"
								data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>">
								<?php esc_html_e( 'Decline', 'buddynext' ); ?>
							</button>
						</div>
					<?php endif; ?>

					<div class="bn-notif-time"><?php echo $time_ago( $row->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside closure. ?></div>
				</div>

				<?php if ( $is_unread ) : ?>
					<div class="bn-notif-unread-dot" aria-label="<?php esc_attr_e( 'Unread', 'buddynext' ); ?>"></div>
				<?php endif; ?>
			</div>
			<?php
			return;
		}
	}

	$type_messages = array(
		'bn.post_reacted'         => __( 'reacted to your post.', 'buddynext' ),
		'bn.post_commented'       => __( 'commented on your post.', 'buddynext' ),
		'bn.new_follower'         => __( 'started following you.', 'buddynext' ),
		'bn.connection_requested' => __( 'sent you a connection request.', 'buddynext' ),
		'bn.connection_accepted'  => __( 'accepted your connection request.', 'buddynext' ),
		'bn.space_invite'         => __( 'invited you to a space.', 'buddynext' ),
		'bn.space_join_requested' => __( 'requested to join your space.', 'buddynext' ),
		'bn.new_message'          => __( 'sent you a message.', 'buddynext' ),
		'bn.badge_awarded'        => __( 'You earned a new badge.', 'buddynext' ),
		'bn.mention'              => __( 'mentioned you in a post.', 'buddynext' ),
		'bn.strike_issued'        => __( 'You received a strike.', 'buddynext' ),
		'bn.strike_warning'       => __( 'Strike warning issued.', 'buddynext' ),
		'bn.member_suspended'     => __( 'Your account has been suspended.', 'buddynext' ),
		'bn.appeal_resolved'      => __( 'Your appeal has been reviewed.', 'buddynext' ),
		'bn.space_new_post'       => __( 'posted in a space you follow.', 'buddynext' ),
	);
	$notif_message = $type_messages[ $notif_type ] ?? __( 'sent you a notification.', 'buddynext' );
	?>
	<div class="<?php echo esc_attr( $row_class ); ?>"
		data-wp-on--click="actions.markRead"
		data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>"
		<?php
		if ( $link_url ) :
			?>
			data-notif-link="<?php echo esc_url( $link_url ); ?>"<?php endif; ?>>

		<div class="bn-notif-ava" style="background:<?php echo esc_attr( $avatar_color( $actor_id ) ); ?>;">
			<?php echo $get_initials( $actor_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside closure. ?>
			<span class="bn-notif-type-icon" style="background:<?php echo esc_attr( $meta['color'] ); ?>;"
				aria-hidden="true"><?php echo $meta['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML entity literals, no user data. ?></span>
		</div>

		<div class="bn-notif-content">
			<div class="bn-notif-text">
				<strong><?php echo $get_display_name( $actor_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside closure. ?></strong>
				<?php echo esc_html( $notif_message ); ?>
			</div>

			<?php if ( 'bn.space_invite' === $notif_type ) : ?>
				<div class="bn-notif-actions">
					<button class="bn-btn bn-btn--primary bn-btn--xs"
						data-wp-on--click="actions.acceptSpaceInvite"
						data-object-id="<?php echo esc_attr( (string) $row->object_id ); ?>"
						data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>">
						<?php esc_html_e( 'Accept', 'buddynext' ); ?>
					</button>
					<button class="bn-btn bn-btn--ghost bn-btn--xs"
						data-wp-on--click="actions.declineSpaceInvite"
						data-object-id="<?php echo esc_attr( (string) $row->object_id ); ?>"
						data-notif-id="<?php echo esc_attr( (string) $row->id ); ?>">
						<?php esc_html_e( 'Decline', 'buddynext' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<div class="bn-notif-time"><?php echo $time_ago( $row->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside closure. ?></div>
		</div>

		<?php if ( $is_unread ) : ?>
			<div class="bn-notif-unread-dot" aria-label="<?php esc_attr_e( 'Unread', 'buddynext' ); ?>"></div>
		<?php endif; ?>
	</div>
	<?php
};
?>
<?php
$bn_nav_active = 'notifications';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<style>
/* ── Design tokens ── */
:root {
	--radius-sm: var(--r-sm);
	--radius:    var(--r-md);
	--radius-lg: var(--r-lg);
	--shadow-sm: 0 2px 8px rgba(0,0,0,0.07);
}

/* ── Shell — hub two-column grid ── */
.bn-notifs-shell {
	max-width: 1100px;
	margin: 0 auto;
	padding: var(--s6) var(--s8);
	display: grid;
	grid-template-columns: 1fr 300px;
	gap: var(--s6);
	align-items: start;
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
}

/* ── Main column (notification list) ── */
.bn-notifs-main { min-width: 0; }

/* ── Sidebar (right column) ── */
.bn-notifs-sidebar {
	display: flex;
	flex-direction: column;
	gap: var(--s5);
}

.bn-notif-pref-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
}

.bn-notif-pref-head {
	padding: var(--s3) var(--s4);
	font-size: var(--text-xs);
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	color: var(--text-3);
	border-bottom: 1px solid var(--border-soft);
}

.bn-notif-pref-row {
	display: flex;
	align-items: center;
	gap: var(--s3);
	padding: var(--s3) var(--s4);
	border-bottom: 1px solid var(--border-soft);
	font-size: var(--text-sm);
	color: var(--text-2);
}

.bn-notif-pref-row:last-child { border-bottom: none; }

.bn-notif-pref-row svg {
	width: 14px;
	height: 14px;
	flex-shrink: 0;
	color: var(--text-3);
}

.bn-notif-pref-label { flex: 1; color: var(--text-1); }

.bn-notif-pref-count {
	font-size: var(--text-xs);
	font-weight: 600;
	background: var(--brand);
	color: #fff;
	padding: 1px 7px;
	border-radius: var(--radius-full, 9999px);
	min-width: 20px;
	text-align: center;
}

[data-theme="dark"] .bn-notif-pref-card {
	background: var(--surface);
	border-color: var(--border);
}

/* ── Page header ── */
.bn-notifs-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: var(--s5);
	flex-wrap: wrap;
	gap: var(--s3);
}
.bn-notifs-title {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	color: var(--text-1);
	letter-spacing: -0.5px;
}
.bn-notifs-actions {
	display: flex;
	gap: var(--s2);
	align-items: center;
}

/* ── Buttons ── */
.bn-btn {
	display: inline-flex;
	align-items: center;
	gap: var(--s1);
	padding: 7px 14px;
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: 1.5px solid var(--border);
	background: var(--surface);
	color: var(--text-1);
	text-decoration: none;
	line-height: 1;
	transition: background 0.1s, border-color 0.1s;
}
.bn-btn:hover { background: var(--bg-hover); }
.bn-btn--primary {
	background: var(--brand);
	color: #fff;
	border-color: var(--brand);
}
.bn-btn--primary:hover { background: var(--brand-hover); border-color: var(--brand-hover); }
.bn-btn--ghost {
	background: var(--surface);
	color: var(--text-2);
	border-color: var(--border);
}
.bn-btn--ghost:hover { background: var(--bg-hover); }
.bn-btn--xs { padding: 5px 12px; font-size: var(--text-xs); border-radius: var(--radius); }

/* ── Filter tabs ── */
.bn-notif-tabs {
	display: flex;
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
	margin-bottom: var(--s4);
}
.bn-ntab {
	flex: 1;
	text-align: center;
	padding: 10px var(--s2);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	border-right: 1px solid var(--border);
	text-decoration: none;
	transition: background 0.1s;
	white-space: nowrap;
}
.bn-ntab:last-child { border-right: none; }
.bn-ntab:hover { background: var(--bg-hover); color: var(--text-1); }
.bn-ntab--active {
	background: var(--brand);
	color: #fff;
	font-weight: 600;
}
.bn-ntab--active:hover { background: var(--brand-hover); }
.bn-ntab-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: var(--red);
	color: #fff;
	font-size: 10px;
	font-weight: 700;
	min-width: 16px;
	height: 16px;
	padding: 0 4px;
	border-radius: 8px;
	margin-left: 4px;
	vertical-align: middle;
}
.bn-ntab--active .bn-ntab-badge { background: rgba(255,255,255,0.4); }

/* ── Group heading ── */
.bn-notif-group-head {
	font-size: var(--text-xs);
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	color: var(--text-3);
	padding: var(--s3) 0 var(--s2);
}

/* ── Notification list ── */
.bn-notif-list {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
	margin-bottom: var(--s4);
}

/* ── Individual notification row ── */
.bn-notif-row {
	display: flex;
	gap: var(--s3);
	padding: 14px var(--s4);
	cursor: pointer;
	border-bottom: 1px solid var(--border-soft);
	align-items: flex-start;
	transition: background 0.1s;
}
.bn-notif-row:last-child { border-bottom: none; }
.bn-notif-row:hover { background: var(--bg-hover); }
.bn-notif-row--unread { background: var(--brand-light); }
.bn-notif-row--unread:hover { background: #d4ecf7; }
[data-theme="dark"] .bn-notif-row--unread:hover { background: #1e3a4a; }

/* ── Avatar ── */
.bn-notif-ava {
	width: 44px;
	height: 44px;
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	font-size: var(--text-sm);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	position: relative;
}
.bn-notif-type-icon {
	position: absolute;
	bottom: -2px;
	right: -2px;
	width: 18px;
	height: 18px;
	border-radius: 50%;
	border: 2px solid var(--surface);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 10px;
	line-height: 1;
}

/* ── Content ── */
.bn-notif-content { flex: 1; min-width: 0; }
.bn-notif-text {
	font-size: var(--text-sm);
	line-height: 1.5;
	color: var(--text-2);
}
.bn-notif-text strong { color: var(--text-1); }
.bn-notif-time {
	font-size: var(--text-xs);
	color: var(--text-3);
	margin-top: 3px;
}
.bn-notif-actions {
	display: flex;
	gap: var(--s2);
	margin-top: var(--s2);
}

/* ── Unread dot ── */
.bn-notif-unread-dot {
	width: 8px;
	height: 8px;
	background: var(--brand);
	border-radius: 50%;
	margin-top: 8px;
	flex-shrink: 0;
}

/* ── Empty state ── */
.bn-notif-empty {
	text-align: center;
	padding: var(--s8) var(--s6);
	color: var(--text-3);
	font-size: var(--text-sm);
}
.bn-notif-empty-icon { font-size: 36px; display: block; margin-bottom: var(--s3); }

/* ── Mark all feedback ── */
[data-wp-bind--hidden="!context.markedAll"] .bn-notif-unread-dot { display: none; }

/* ── Responsive ── */
@media ( max-width: 640px ) {
	.bn-notifs-shell {
		grid-template-columns: 1fr;
		padding: var(--s4) var(--s3);
	}
	.bn-notifs-sidebar { display: none; }
	.bn-notifs-header { flex-direction: column; align-items: flex-start; }
	.bn-notif-tabs { overflow-x: auto; border-radius: var(--radius-sm); }
	.bn-ntab { flex: 0 0 auto; padding: 9px var(--s3); }
	.bn-notif-row { gap: var(--s2); padding: var(--s3); }
	.bn-notif-ava { width: 38px; height: 38px; }
}
</style>

<div class="bn-notifs-shell"
	data-wp-interactive="buddynext/notifications"
	data-wp-context='{"markedAll":false,"activeFilter":"<?php echo esc_attr( $active_filter ); ?>","nonce":"<?php echo esc_attr( $mark_all_nonce ); ?>","restUrl":"<?php echo esc_url( rest_url( 'buddynext/v1/me/notifications' ) ); ?>"}'>

<div class="bn-notifs-main">

	<div class="bn-notifs-header">
		<h1 class="bn-notifs-title">
			<?php esc_html_e( 'Notifications', 'buddynext' ); ?>
			<?php if ( $total_unread > 0 ) : ?>
				<span class="bn-ntab-badge"><?php echo esc_html( (string) min( $total_unread, 99 ) ); ?></span>
			<?php endif; ?>
		</h1>
		<div class="bn-notifs-actions">
			<?php if ( $total_unread > 0 ) : ?>
				<button class="bn-btn bn-btn--primary"
					data-wp-on--click="actions.markAllRead"
					data-nonce="<?php echo esc_attr( $mark_all_nonce ); ?>"
					data-url="<?php echo esc_attr( $rest_url ); ?>">
					<?php esc_html_e( 'Mark all read', 'buddynext' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

	<!-- Filter tabs -->
	<nav class="bn-notif-tabs" aria-label="<?php esc_attr_e( 'Notification filters', 'buddynext' ); ?>">
		<?php
		$notif_tabs = array(
			'all'      => array(
				'label' => __( 'All', 'buddynext' ),
				'count' => $total_unread,
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
			$tab_url   = esc_url( add_query_arg( 'filter', $key ) );
			$tab_class = 'bn-ntab' . ( $is_active ? ' bn-ntab--active' : '' );
			?>
			<a href="<?php echo $tab_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>"
				class="<?php echo esc_attr( $tab_class ); ?>"
				aria-current="<?php echo $is_active ? 'page' : 'false'; ?>">
				<?php echo esc_html( $notif_tab['label'] ); ?>
				<?php if ( $notif_tab['count'] > 0 ) : ?>
					<span class="bn-ntab-badge"><?php echo esc_html( (string) min( (int) $notif_tab['count'], 99 ) ); ?></span>
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
		$has_any = true;

		$unread_in_group = count( array_filter( $group_rows, static fn( $r ) => ! (bool) $r->is_read ) );
		?>
		<div class="bn-notif-group-head">
			<?php
			echo esc_html( $group_labels[ $group_key ] );
			if ( $unread_in_group > 0 ) {
				echo ' &mdash; ';
				// translators: %d is the count of unread notifications in this group.
				echo esc_html( sprintf( _n( '%d unread', '%d unread', $unread_in_group, 'buddynext' ), $unread_in_group ) );
			}
			?>
		</div>
		<div class="bn-notif-list">
			<?php
			foreach ( $group_rows as $notif_row ) {
				$render_row( $notif_row, $get_initials, $get_display_name, $time_ago, $avatar_color, $type_meta );
			}
			?>
		</div>
	<?php endforeach; ?>

	<?php if ( ! $has_any ) : ?>
		<div class="bn-notif-empty">
			<span class="bn-notif-empty-icon" aria-hidden="true"><?php buddynext_icon( 'bell' ); ?></span>
			<?php esc_html_e( 'No notifications here yet.', 'buddynext' ); ?>
		</div>
	<?php endif; ?>

</div><!-- .bn-notifs-main -->

<!-- ── Sidebar ── -->
<aside class="bn-notifs-sidebar" aria-label="<?php esc_attr_e( 'Notification summary', 'buddynext' ); ?>">

	<div class="bn-notif-pref-card">
		<div class="bn-notif-pref-head"><?php esc_html_e( 'By type', 'buddynext' ); ?></div>

		<?php
		$sidebar_types = array(
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
			<a href="<?php echo esc_url( $stype['url'] ); ?>" class="bn-notif-pref-row">
				<?php buddynext_icon( $stype['icon'] ); ?>
				<span class="bn-notif-pref-label"><?php echo esc_html( $stype['label'] ); ?></span>
				<?php if ( $stype['count'] > 0 ) : ?>
					<span class="bn-notif-pref-count"><?php echo esc_html( (string) min( $stype['count'], 99 ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</div>

	<?php
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$notif_sbar_user     = get_current_user_id();
	$notif_sbar_trending = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT slug, post_count FROM ' . $wpdb->prefix . 'bn_hashtags ORDER BY post_count DESC LIMIT %d',
			5
		)
	);
	$notif_sbar_spaces   = $notif_sbar_user ? $wpdb->get_results(
		$wpdb->prepare(
			'SELECT s.id, s.name, s.slug, s.member_count, s.avatar_url
			 FROM ' . $wpdb->prefix . 'bn_spaces s
			 INNER JOIN ' . $wpdb->prefix . 'bn_space_members sm
			   ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = %s
			 ORDER BY s.member_count DESC
			 LIMIT %d',
			$notif_sbar_user,
			'active',
			3
		)
	) : array();
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	?>

	<?php if ( ! empty( $notif_sbar_trending ) ) : ?>
	<div class="bn-sidebar-card">
		<div class="bn-sidebar-card__header"><?php esc_html_e( 'Trending Topics', 'buddynext' ); ?></div>
		<div class="bn-sidebar-card__body">
			<ul class="bn-htag-list">
				<?php foreach ( $notif_sbar_trending as $notif_htag ) : ?>
				<li class="bn-htag-item">
					<a
						href="<?php echo esc_url( home_url( '/activity/hashtag/' . rawurlencode( $notif_htag->slug ) . '/' ) ); ?>"
						class="bn-htag-item__link"
					>#<?php echo esc_html( $notif_htag->slug ); ?></a>
					<span class="bn-htag-item__count"><?php echo esc_html( number_format_i18n( (int) $notif_htag->post_count ) ); ?></span>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $notif_sbar_spaces ) ) : ?>
	<div class="bn-sidebar-card">
		<div class="bn-sidebar-card__header"><?php esc_html_e( 'Your Spaces', 'buddynext' ); ?></div>
		<div class="bn-sidebar-card__body">
			<?php foreach ( $notif_sbar_spaces as $notif_sp ) : ?>
				<?php $notif_sp_url = home_url( '/spaces/' . $notif_sp->slug . '/' ); ?>
			<a href="<?php echo esc_url( $notif_sp_url ); ?>" class="bn-sbar-space-row">
				<span class="bn-sbar-space-icon" aria-hidden="true">
					<?php if ( ! empty( $notif_sp->avatar_url ) ) : ?>
						<img src="<?php echo esc_attr( $notif_sp->avatar_url ); ?>" alt="" width="32" height="32" loading="lazy">
					<?php else : ?>
						<?php echo esc_html( strtoupper( mb_substr( (string) $notif_sp->name, 0, 2 ) ) ); ?>
					<?php endif; ?>
				</span>
				<span class="bn-sbar-space-info">
					<span class="bn-sbar-space-name"><?php echo esc_html( $notif_sp->name ); ?></span>
					<span class="bn-sbar-space-meta"><?php echo esc_html( number_format_i18n( (int) $notif_sp->member_count ) ); ?> <?php esc_html_e( 'members', 'buddynext' ); ?></span>
				</span>
			</a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( home_url( '/spaces/' ) ); ?>" class="bn-sidebar-see-all"><?php esc_html_e( 'Browse all spaces', 'buddynext' ); ?></a>
		</div>
	</div>
	<?php endif; ?>

</aside>

</div><!-- .bn-notifs-shell -->
