<?php
/**
 * DM Thread template.
 *
 * Renders a split-pane messaging view: conversation list on the left,
 * active thread on the right. On mobile, only the active thread is shown.
 *
 * BuddyNext is the UI layer only. All message data is owned by WPMediaVerse
 * and loaded via the REST API at mvs/v1/*.
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	wp_safe_redirect( wp_login_url( get_permalink() ) );
	exit;
}

// ── WPMediaVerse dependency check ─────────────────────────────────────────────
$mvs_active = class_exists( 'WPMediaVerse\Core\Plugin' );

// ── Auth / context ────────────────────────────────────────────────────────────
$current_user_id = get_current_user_id();

// ── Active conversation ───────────────────────────────────────────────────────
$conv_id = absint( $_GET['conversation'] ?? 0 );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Search / tab for conv list panel ─────────────────────────────────────────
$list_search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$active_tab   = sanitize_key( $_GET['tab'] ?? 'all' );                   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$allowed_tabs = array( 'all', 'unread', 'requests' );
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'all';
}

// ── Nonces ────────────────────────────────────────────────────────────────────
$rest_nonce    = wp_create_nonce( 'wp_rest' );
$action_nonce  = wp_create_nonce( 'bn_messages_action' );
$mvs_rest_base = rest_url( 'mvs/v1' );

// ── Bootstrap data (server-side) ──────────────────────────────────────────────
$conversations        = array();
$thread_data          = null;
$other_user           = null;
$messages             = array();
$unread_count         = 0;
$request_count        = 0;
$pinned_conversations = array();
$recent_conversations = array();

if ( $mvs_active && $current_user_id > 0 ) {
	// Conversation list.
	$list_api_url = add_query_arg(
		array(
			'tab'      => $active_tab,
			'per_page' => 30,
		),
		rest_url( 'mvs/v1/me/conversations' )
	);

	$list_response = wp_remote_get(
		$list_api_url,
		array(
			'headers' => array(
				'X-WP-Nonce' => $rest_nonce,
				'Cookie'     => isset( $_SERVER['HTTP_COOKIE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_COOKIE'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			),
			'timeout' => 5,
		)
	);

	if ( ! is_wp_error( $list_response ) && 200 === wp_remote_retrieve_response_code( $list_response ) ) {
		$list_body = json_decode( wp_remote_retrieve_body( $list_response ), true );
		if ( is_array( $list_body ) ) {
			$conversations = $list_body['conversations'] ?? array();
			$unread_count  = (int) ( $list_body['unread_total'] ?? 0 );
			$request_count = (int) ( $list_body['request_count'] ?? 0 );
		}
	}

	foreach ( $conversations as $conv ) {
		if ( ! empty( $conv['is_pinned'] ) ) {
			$pinned_conversations[] = $conv;
		} else {
			$recent_conversations[] = $conv;
		}
	}

	// Active thread messages.
	if ( $conv_id > 0 ) {
		$thread_api_url = rest_url( 'mvs/v1/conversations/' . $conv_id . '/messages' );

		$thread_response = wp_remote_get(
			$thread_api_url,
			array(
				'headers' => array(
					'X-WP-Nonce' => $rest_nonce,
					'Cookie'     => isset( $_SERVER['HTTP_COOKIE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_COOKIE'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				),
				'timeout' => 5,
			)
		);

		if ( ! is_wp_error( $thread_response ) && 200 === wp_remote_retrieve_response_code( $thread_response ) ) {
			$thread_body = json_decode( wp_remote_retrieve_body( $thread_response ), true );
			if ( is_array( $thread_body ) ) {
				$thread_data = $thread_body;
				$messages    = $thread_body['messages'] ?? array();
				// Resolve other user details.
				$other_user_id = (int) ( $thread_body['other_user_id'] ?? 0 );
				if ( $other_user_id > 0 ) {
					$other_user = get_userdata( $other_user_id );
				}
			}
		}
	}
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Return two-character initials for a display name.
 *
 * @param string $name Display name.
 * @return string
 */
$bn_initials = static function ( string $name ): string {
	$parts = array_filter( explode( ' ', $name ) );
	if ( count( $parts ) >= 2 ) {
		return mb_strtoupper( mb_substr( (string) reset( $parts ), 0, 1 ) . mb_substr( (string) end( $parts ), 0, 1 ) );
	}
	return mb_strtoupper( mb_substr( $name, 0, 2 ) );
};

$avatar_colours   = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#dc2626', '#d97706' );
$bn_avatar_colour = static function ( int $user_id ) use ( $avatar_colours ): string {
	return $avatar_colours[ $user_id % count( $avatar_colours ) ];
};

$online_threshold = time() - 300;
$bn_is_online     = static function ( int $user_id ) use ( $online_threshold ): bool {
	$last_active = (int) get_user_meta( $user_id, 'bn_last_active', true );
	return $last_active >= $online_threshold;
};

/**
 * Format a UTC timestamp as a human-readable relative string.
 *
 * @param string|int $timestamp ISO 8601 date string or Unix timestamp.
 * @return string
 */
$bn_relative_time = static function ( $timestamp ): string {
	if ( empty( $timestamp ) ) {
		return '';
	}
	$ts   = is_numeric( $timestamp ) ? (int) $timestamp : strtotime( (string) $timestamp );
	$diff = time() - $ts;

	if ( $diff < 60 ) {
		return _x( 'Now', 'relative time: just now', 'buddynext' );
	}
	if ( $diff < 3600 ) {
		$m = (int) floor( $diff / 60 );
		return sprintf(
			/* translators: %d: number of minutes */
			_n( '%dm', '%dm', $m, 'buddynext' ),
			$m
		);
	}
	if ( $diff < 86400 ) {
		$h = (int) floor( $diff / 3600 );
		return sprintf(
			/* translators: %d: number of hours */
			_n( '%dh', '%dh', $h, 'buddynext' ),
			$h
		);
	}
	$d = (int) floor( $diff / 86400 );
	return sprintf(
		/* translators: %d: number of days */
		_n( '%dd', '%dd', $d, 'buddynext' ),
		$d
	);
};

/**
 * Format a message timestamp as a clock time.
 *
 * @param string|int $timestamp ISO 8601 date string or Unix timestamp.
 * @return string
 */
$bn_clock_time = static function ( $timestamp ): string {
	if ( empty( $timestamp ) ) {
		return '';
	}
	$ts = is_numeric( $timestamp ) ? (int) $timestamp : strtotime( (string) $timestamp );
	return wp_date( get_option( 'time_format', 'g:i A' ), $ts );
};

// Other user display data (resolved from the thread or conversation list).
$other_user_display = '';
$other_user_id_th   = 0;
$other_is_online    = false;
$other_is_typing    = false;

if ( $thread_data && $other_user instanceof WP_User ) {
	$other_user_display = $other_user->display_name;
	$other_user_id_th   = $other_user->ID;
	$other_is_online    = $bn_is_online( $other_user_id_th );
	$other_is_typing    = ! empty( $thread_data['other_user_typing'] );
}

$messages_page_url = get_permalink( get_page_by_path( 'messages' ) );
$compose_url       = add_query_arg( array( 'action' => 'compose' ), $messages_page_url );
?>
<?php
$bn_nav_active = 'messages';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div
	class="bn-messages-thread-shell"
	data-wp-interactive="buddynext/messages"
	data-wp-context='{"convId":<?php echo (int) $conv_id; ?>,"tab":"<?php echo esc_js( $active_tab ); ?>","nonce":"<?php echo esc_js( $action_nonce ); ?>","restNonce":"<?php echo esc_js( $rest_nonce ); ?>","mvsRestBase":"<?php echo esc_js( $mvs_rest_base ); ?>","replyToId":0,"replyToText":""}'
>


<?php if ( ! $mvs_active ) : ?>

	<div class="bn-dependency-notice">
		<div class="bn-dependency-notice-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></div>
		<div class="bn-dependency-notice-title"><?php esc_html_e( 'Direct messaging requires WPMediaVerse', 'buddynext' ); ?></div>
		<p class="bn-dependency-notice-body">
			<?php esc_html_e( 'Install and activate the WPMediaVerse plugin to enable direct messaging in BuddyNext.', 'buddynext' ); ?>
		</p>
	</div>

<?php else : ?>

	<!-- ── Conversation list panel ──────────────────────────── -->
	<div class="bn-conv-panel<?php echo ( $conv_id > 0 ) ? '' : ' bn-show-panel'; ?>">

		<div class="bn-conv-panel-header">
			<div class="bn-conv-panel-title"><?php esc_html_e( 'Messages', 'buddynext' ); ?></div>
			<input
				class="bn-conv-panel-search"
				type="search"
				placeholder="<?php esc_attr_e( 'Search conversations&hellip;', 'buddynext' ); ?>"
				value="<?php echo esc_attr( $list_search ); ?>"
				aria-label="<?php esc_attr_e( 'Search conversations', 'buddynext' ); ?>"
				data-wp-on--input="actions.onPanelSearchInput"
			>
		</div>

		<nav class="bn-conv-panel-tabs" role="tablist">
			<?php
			$panel_tabs = array(
				'all'      => __( 'All', 'buddynext' ),
				'unread'   => __( 'Unread', 'buddynext' ),
				'requests' => __( 'Requests', 'buddynext' ),
			);
			foreach ( $panel_tabs as $tab_key => $tab_label ) :
				$is_active_tab = $tab_key === $active_tab;
				?>
				<a
					href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'tab'          => $tab_key,
								'conversation' => ! empty( $conv_id ) ? $conv_id : false,
							),
							get_permalink()
						)
					);
					?>
							"
					class="bn-conv-panel-tab<?php echo $is_active_tab ? ' active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo $is_active_tab ? 'true' : 'false'; ?>"
					data-tab="<?php echo esc_attr( $tab_key ); ?>"
					data-wp-on--click="actions.switchPanelTab"
				>
					<?php echo esc_html( $tab_label ); ?>
					<?php if ( 'unread' === $tab_key && $unread_count > 0 ) : ?>
						<span class="bn-conv-panel-tab-badge"><?php echo esc_html( (string) min( $unread_count, 99 ) ); ?></span>
					<?php endif; ?>
					<?php if ( 'requests' === $tab_key && $request_count > 0 ) : ?>
						<span class="bn-conv-panel-tab-badge"><?php echo esc_html( (string) min( $request_count, 99 ) ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="bn-conv-list" role="list">

			<?php if ( ! empty( $pinned_conversations ) ) : ?>
				<div class="bn-conv-list-section-head"><?php buddynext_icon( 'bookmark' ); ?> <?php esc_html_e( 'Pinned', 'buddynext' ); ?></div>
				<?php foreach ( $pinned_conversations as $conv ) : ?>
					<?php
					$c_id        = absint( $conv['id'] ?? 0 );
					$c_uid       = absint( $conv['other_user_id'] ?? 0 );
					$c_name      = sanitize_text_field( $conv['other_user_name'] ?? '' );
					$c_preview   = sanitize_text_field( $conv['last_message_preview'] ?? '' );
					$c_at        = $conv['last_message_at'] ?? '';
					$c_unread    = absint( $conv['unread_count'] ?? 0 );
					$c_online    = $bn_is_online( $c_uid );
					$c_is_active = $c_id === $conv_id;
					$c_initials  = $bn_initials( $c_name );
					$c_colour    = $bn_avatar_colour( $c_uid );
					$c_url       = add_query_arg(
						array(
							'conversation' => $c_id,
							'tab'          => $active_tab,
						),
						get_permalink()
					);
					$c_av        = get_avatar( $c_uid, 40, '', esc_attr( $c_name ), array( 'force_display' => true ) );
					?>
					<a
						href="<?php echo esc_url( $c_url ); ?>"
						class="bn-conv-panel-item<?php echo $c_is_active ? ' bn-active' : ''; ?><?php echo $c_unread > 0 ? ' bn-unread' : ''; ?>"
						role="listitem"
						data-conv-id="<?php echo esc_attr( (string) $c_id ); ?>"
					>
						<div class="bn-conv-panel-avatar" style="background:<?php echo esc_attr( $c_colour ); ?>;" aria-hidden="true">
							<?php if ( false !== strpos( $c_av, 'src=' ) ) : ?>
								<?php
								echo wp_kses(
									$c_av,
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
								<?php echo esc_html( $c_initials ); ?>
							<?php endif; ?>
							<?php if ( $c_online ) : ?>
								<span class="bn-conv-panel-online"></span>
							<?php endif; ?>
						</div>
						<div class="bn-conv-panel-info">
							<div class="bn-conv-panel-name-row">
								<span class="bn-conv-panel-name"><?php echo esc_html( $c_name ); ?></span>
								<span class="bn-conv-panel-time"><?php echo esc_html( $bn_relative_time( $c_at ) ); ?></span>
							</div>
							<div class="bn-conv-panel-preview">
								<?php echo esc_html( ! empty( $conv['other_user_typing'] ) ? __( 'Typing&hellip;', 'buddynext' ) : $c_preview ); ?>
							</div>
						</div>
						<?php if ( $c_unread > 0 ) : ?>
							<span class="bn-conv-panel-unread"><?php echo esc_html( (string) min( $c_unread, 99 ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $conv['is_pinned'] ) ) : ?>
							<span style="font-size:12px;color:var(--text-3);" aria-hidden="true"><?php buddynext_icon( 'bookmark' ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( ! empty( $recent_conversations ) ) : ?>
				<?php if ( ! empty( $pinned_conversations ) ) : ?>
					<div class="bn-conv-list-section-head"><?php esc_html_e( 'Recent', 'buddynext' ); ?></div>
				<?php endif; ?>
				<?php foreach ( $recent_conversations as $conv ) : ?>
					<?php
					$c_id        = absint( $conv['id'] ?? 0 );
					$c_uid       = absint( $conv['other_user_id'] ?? 0 );
					$c_name      = sanitize_text_field( $conv['other_user_name'] ?? '' );
					$c_preview   = sanitize_text_field( $conv['last_message_preview'] ?? '' );
					$c_at        = $conv['last_message_at'] ?? '';
					$c_unread    = absint( $conv['unread_count'] ?? 0 );
					$c_online    = $bn_is_online( $c_uid );
					$c_is_active = $c_id === $conv_id;
					$c_initials  = $bn_initials( $c_name );
					$c_colour    = $bn_avatar_colour( $c_uid );
					$c_url       = add_query_arg(
						array(
							'conversation' => $c_id,
							'tab'          => $active_tab,
						),
						get_permalink()
					);
					$c_av        = get_avatar( $c_uid, 40, '', esc_attr( $c_name ), array( 'force_display' => true ) );
					?>
					<a
						href="<?php echo esc_url( $c_url ); ?>"
						class="bn-conv-panel-item<?php echo $c_is_active ? ' bn-active' : ''; ?><?php echo $c_unread > 0 ? ' bn-unread' : ''; ?>"
						role="listitem"
						data-conv-id="<?php echo esc_attr( (string) $c_id ); ?>"
					>
						<div class="bn-conv-panel-avatar" style="background:<?php echo esc_attr( $c_colour ); ?>;" aria-hidden="true">
							<?php if ( false !== strpos( $c_av, 'src=' ) ) : ?>
								<?php
								echo wp_kses(
									$c_av,
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
								<?php echo esc_html( $c_initials ); ?>
							<?php endif; ?>
							<?php if ( $c_online ) : ?>
								<span class="bn-conv-panel-online"></span>
							<?php endif; ?>
						</div>
						<div class="bn-conv-panel-info">
							<div class="bn-conv-panel-name-row">
								<span class="bn-conv-panel-name"><?php echo esc_html( $c_name ); ?></span>
								<span class="bn-conv-panel-time"><?php echo esc_html( $bn_relative_time( $c_at ) ); ?></span>
							</div>
							<div class="bn-conv-panel-preview">
								<?php echo esc_html( ! empty( $conv['other_user_typing'] ) ? __( 'Typing&hellip;', 'buddynext' ) : $c_preview ); ?>
							</div>
						</div>
						<?php if ( $c_unread > 0 ) : ?>
							<span class="bn-conv-panel-unread"><?php echo esc_html( (string) min( $c_unread, 99 ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>

		</div>

		<div class="bn-conv-panel-new-btn">
			<a href="<?php echo esc_url( $compose_url ); ?>">
				+ <?php esc_html_e( 'New Message', 'buddynext' ); ?>
			</a>
		</div>

	</div>

	<!-- ── Thread panel ─────────────────────────────────────── -->
	<div class="bn-thread-panel<?php echo ( 0 === $conv_id ) ? ' bn-hide-thread' : ''; ?>">

		<?php if ( 0 === $conv_id || null === $thread_data ) : ?>

			<div class="bn-thread-empty">
				<div class="bn-thread-empty-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></div>
				<div class="bn-thread-empty-title"><?php esc_html_e( 'Select a conversation', 'buddynext' ); ?></div>
				<p><?php esc_html_e( 'Choose from your existing conversations or start a new one.', 'buddynext' ); ?></p>
			</div>

		<?php else : ?>

			<!-- Thread header -->
			<div class="bn-thread-header">
				<a href="<?php echo esc_url( remove_query_arg( 'conversation' ) ); ?>" class="bn-mobile-back" aria-label="<?php esc_attr_e( 'Back to conversations', 'buddynext' ); ?>">
					<?php buddynext_icon( 'chevron-left' ); ?>
				</a>

				<?php
				$th_colour   = $bn_avatar_colour( $other_user_id_th );
				$th_initials = $bn_initials( $other_user_display );
				$th_avatar   = get_avatar( $other_user_id_th, 36, '', esc_attr( $other_user_display ), array( 'force_display' => true ) );
				$th_profile  = \BuddyNext\Core\PageRouter::profile_url( $other_user_id_th );
				?>
				<div class="bn-thread-avatar" style="background:<?php echo esc_attr( $th_colour ); ?>;" aria-hidden="true">
					<?php if ( false !== strpos( $th_avatar, 'src=' ) ) : ?>
						<?php
						echo wp_kses(
							$th_avatar,
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
						<?php echo esc_html( $th_initials ); ?>
					<?php endif; ?>
				</div>

				<div class="bn-thread-identity">
					<a href="<?php echo esc_url( $th_profile ); ?>" style="text-decoration:none;color:inherit;">
						<div class="bn-thread-name"><?php echo esc_html( $other_user_display ); ?></div>
					</a>
					<div class="bn-thread-status <?php echo $other_is_online ? 'online' : 'offline'; ?>">
						<?php
						if ( $other_is_online ) {
							esc_html_e( '● Online now', 'buddynext' );
						} else {
							esc_html_e( '○ Offline', 'buddynext' );
						}
						?>
					</div>
				</div>

				<div class="bn-thread-actions">
					<span class="bn-icon-btn" title="<?php esc_attr_e( 'Search in conversation', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'Search messages', 'buddynext' ); ?>"><?php buddynext_icon( 'search' ); ?></span>
					<span class="bn-icon-btn" title="<?php esc_attr_e( 'More options', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'More options', 'buddynext' ); ?>" data-wp-on--click="actions.openThreadOptions"><?php buddynext_icon( 'more-horizontal' ); ?></span>
				</div>
			</div>

			<!-- Messages area -->
			<div class="bn-messages-area" role="log" aria-live="polite" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: other user display name */ __( 'Conversation with %s', 'buddynext' ), $other_user_display ) ); ?>">

				<?php
				$prev_date = '';
				foreach ( $messages as $msg ) :
					$msg_id      = absint( $msg['id'] ?? 0 );
					$msg_body    = wp_kses(
						$msg['body'] ?? '',
						array(
							'br'     => array(),
							'em'     => array(),
							'strong' => array(),
						)
					);
					$msg_at      = $msg['created_at'] ?? '';
					$msg_uid     = absint( $msg['sender_id'] ?? 0 );
					$is_mine     = $msg_uid === $current_user_id;
					$reactions   = (array) ( $msg['reactions'] ?? array() );
					$reply_to    = $msg['reply_to'] ?? null;
					$read_by_all = ! empty( $msg['read_by_recipient'] );
					$clock       = $bn_clock_time( $msg_at );

					$msg_ts    = is_numeric( $msg_at ) ? (int) $msg_at : strtotime( (string) $msg_at );
					$msg_date  = wp_date( 'Y-m-d', $msg_ts );
					$today     = wp_date( 'Y-m-d' );
					$yesterday = wp_date( 'Y-m-d', time() - 86400 );

					if ( $msg_date !== $prev_date ) :
						if ( $msg_date === $today ) {
							$date_label = __( 'Today', 'buddynext' );
						} elseif ( $msg_date === $yesterday ) {
							$date_label = __( 'Yesterday', 'buddynext' );
						} else {
							$date_label = wp_date( get_option( 'date_format', 'F j, Y' ), $msg_ts );
						}
						?>
						<div class="bn-day-divider"><span><?php echo esc_html( $date_label ); ?></span></div>
						<?php
						$prev_date = $msg_date;
					endif;
					?>

					<div
						class="bn-msg-row<?php echo $is_mine ? ' bn-mine' : ''; ?>"
						data-msg-id="<?php echo esc_attr( (string) $msg_id ); ?>"
					>
						<?php if ( ! $is_mine ) : ?>
							<div class="bn-msg-small-avatar" style="background:<?php echo esc_attr( $th_colour ); ?>;" aria-hidden="true">
								<?php echo esc_html( $th_initials ); ?>
							</div>
						<?php endif; ?>

						<div class="bn-msg-bubble">
							<div class="bn-bubble <?php echo $is_mine ? 'bn-mine' : 'bn-them'; ?>">
								<?php if ( is_array( $reply_to ) && ! empty( $reply_to['body'] ) ) : ?>
									<div class="bn-quoted <?php echo $is_mine ? 'bn-mine' : 'bn-them'; ?>">
										<?php echo esc_html( wp_trim_words( sanitize_text_field( $reply_to['body'] ), 15 ) ); ?>
									</div>
								<?php endif; ?>
								<?php echo $msg_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already filtered via wp_kses above. ?>
							</div>
							<div class="bn-bubble-time"><?php echo esc_html( $clock ); ?></div>

							<?php if ( ! empty( $reactions ) ) : ?>
								<div class="bn-bubble-reactions">
									<?php foreach ( $reactions as $emoji => $count ) : ?>
										<button
											type="button"
											class="bn-reaction"
											data-msg-id="<?php echo esc_attr( (string) $msg_id ); ?>"
											data-emoji="<?php echo esc_attr( sanitize_text_field( (string) $emoji ) ); ?>"
											data-wp-on--click="actions.toggleReaction"
											aria-label="<?php echo esc_attr( sanitize_text_field( (string) $emoji ) . ' ' . absint( $count ) ); ?>"
										>
											<?php echo esc_html( sanitize_text_field( (string) $emoji ) . ' ' . absint( $count ) ); ?>
										</button>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<?php if ( $is_mine && $read_by_all ) : ?>
								<div class="bn-read-receipt" aria-label="<?php esc_attr_e( 'Read', 'buddynext' ); ?>"><?php buddynext_icon( 'check-double' ); ?> <?php esc_html_e( 'Read', 'buddynext' ); ?></div>
							<?php endif; ?>
						</div>
					</div>

				<?php endforeach; ?>

				<?php if ( $other_is_typing ) : ?>
					<div class="bn-typing-indicator" aria-live="polite">
						<div class="bn-msg-small-avatar" style="background:<?php echo esc_attr( $th_colour ); ?>;" aria-hidden="true">
							<?php echo esc_html( $th_initials ); ?>
						</div>
						<div class="bn-typing-dots" aria-hidden="true">
							<span class="bn-typing-dot"></span>
							<span class="bn-typing-dot"></span>
							<span class="bn-typing-dot"></span>
						</div>
						<span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: other user's first name */
									__( '%s is typing&hellip;', 'buddynext' ),
									( $other_user instanceof WP_User ) ? explode( ' ', $other_user->display_name )[0] : __( 'Someone', 'buddynext' )
								)
							);
							?>
						</span>
					</div>
				<?php endif; ?>

			</div>

			<!-- Input bar -->
			<div class="bn-input-bar">
				<div class="bn-reply-preview" data-wp-class--bn-hidden="!state.replyToId" style="display:none;">
					<span class="bn-reply-preview-text" data-wp-text="state.replyToText"></span>
					<button type="button" class="bn-reply-clear" aria-label="<?php esc_attr_e( 'Cancel reply', 'buddynext' ); ?>" data-wp-on--click="actions.clearReply"><?php buddynext_icon( 'x' ); ?></button>
				</div>

				<div class="bn-input-row">
					<button type="button" class="bn-input-icon" aria-label="<?php esc_attr_e( 'Insert emoji', 'buddynext' ); ?>" data-wp-on--click="actions.openEmojiPicker"><?php buddynext_icon( 'star' ); ?></button>
					<button type="button" class="bn-input-icon" aria-label="<?php esc_attr_e( 'Attach file', 'buddynext' ); ?>" data-wp-on--click="actions.openAttachment"><?php buddynext_icon( 'link' ); ?></button>
					<textarea
						class="bn-msg-input"
						rows="1"
						placeholder="<?php esc_attr_e( 'Type a message&hellip;', 'buddynext' ); ?>"
						aria-label="<?php esc_attr_e( 'Message input', 'buddynext' ); ?>"
						data-wp-on--keydown="actions.onInputKeydown"
						data-wp-on--input="actions.onMessageInput"
					></textarea>
					<button
						type="button"
						class="bn-send-btn"
						aria-label="<?php esc_attr_e( 'Send message', 'buddynext' ); ?>"
						data-conv-id="<?php echo esc_attr( (string) $conv_id ); ?>"
						data-wp-on--click="actions.sendMessage"
					><?php buddynext_icon( 'send' ); ?></button>
				</div>
			</div>

		<?php endif; ?>

	</div>

<?php endif; ?>

</div>
