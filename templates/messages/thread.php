<?php
/**
 * DM Thread template.
 *
 * Renders a v2 split-pane messaging view: conversation rail on the left,
 * active thread pane on the right. On <= 768px the split collapses to a
 * single pane (per v2 mobile pattern).
 *
 * BuddyNext is the UI layer only. All message data is owned by WPMediaVerse
 * and loaded via the REST API at mvs/v1/*.
 *
 * Visual canon: docs/v2 Plans/v2/dm-thread.html
 * Primitives:   .bn-split, .bn-card, .bn-btn, .bn-textarea, .bn-badge,
 *               .bn-avatar, .bn-modal-backdrop, .bn-tooltip-trigger.
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

// ── Search / tab for conv rail ───────────────────────────────────────────────
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

/**
 * Stable avatar tone slot (1..8) for a user id, used as a CSS class suffix.
 *
 * @param int $user_id User ID.
 * @return int
 */
$bn_avatar_tone = static function ( int $user_id ): int {
	return ( $user_id % 8 ) + 1;
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

/**
 * Format a timestamp as ISO 8601 UTC for the `<time datetime>` attribute.
 *
 * @param string|int $timestamp ISO 8601 date string or Unix timestamp.
 * @return string
 */
$bn_iso_time = static function ( $timestamp ): string {
	if ( empty( $timestamp ) ) {
		return '';
	}
	$ts = is_numeric( $timestamp ) ? (int) $timestamp : strtotime( (string) $timestamp );
	return gmdate( 'c', $ts );
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

/**
 * Fires before the messages thread inner content.
 *
 * @param int $conv_id Conversation ID.
 */
do_action( 'buddynext_messages_thread_before', $conv_id );

$messages_input_id = 'bn-dm-input-' . (int) $conv_id;
?>
<div
	class="bn-dm-shell<?php echo ( $conv_id > 0 ) ? ' is-thread-active' : ''; ?>"
	data-wp-interactive="buddynext/messages"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'convId'      => (int) $conv_id,
				'tab'         => $active_tab,
				'nonce'       => $action_nonce,
				'restNonce'   => $rest_nonce,
				'mvsRestBase' => $mvs_rest_base,
				'replyToId'   => 0,
				'replyToText' => '',
				'confirmOpen' => false,
			)
		)
	);
	?>
	'
>

<?php if ( ! $mvs_active ) : ?>

	<div class="bn-card bn-dm-dep-notice" role="status">
		<div class="bn-dm-dep-notice__head">
			<span class="bn-dm-dep-notice__icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
			<span class="bn-badge" data-tone="warn"><?php esc_html_e( 'Dependency required', 'buddynext' ); ?></span>
		</div>
		<h2 class="bn-dm-dep-notice__title"><?php esc_html_e( 'Direct messaging requires WPMediaVerse', 'buddynext' ); ?></h2>
		<p class="bn-dm-dep-notice__body">
			<?php esc_html_e( 'Install and activate the WPMediaVerse plugin to enable direct messaging in BuddyNext.', 'buddynext' ); ?>
		</p>
	</div>

<?php else : ?>

	<div class="bn-card bn-dm-card">

		<div class="bn-split bn-dm-split">

			<!-- ── Conversation rail ──────────────────────────────── -->
			<aside class="bn-split__rail bn-dm-rail" aria-label="<?php esc_attr_e( 'Conversations', 'buddynext' ); ?>">

				<div class="bn-split__rail-head bn-dm-rail__head">
					<h2 class="bn-split__rail-title"><?php esc_html_e( 'Messages', 'buddynext' ); ?></h2>
					<?php if ( $unread_count > 0 ) : ?>
						<span class="bn-badge" data-tone="accent" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: unread count */ _n( '%d unread', '%d unread', $unread_count, 'buddynext' ), $unread_count ) ); ?>">
							<?php echo esc_html( (string) min( $unread_count, 99 ) ); ?>
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
						value="<?php echo esc_attr( $list_search ); ?>"
						data-wp-on--input="actions.onPanelSearchInput"
					>
				</div>

				<nav class="bn-tabs bn-dm-rail__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Conversation filter', 'buddynext' ); ?>">
					<?php
					$panel_tabs = array(
						'all'      => __( 'All', 'buddynext' ),
						'unread'   => __( 'Unread', 'buddynext' ),
						'requests' => __( 'Requests', 'buddynext' ),
					);
					foreach ( $panel_tabs as $tab_key => $tab_label ) :
						$is_active_tab = $tab_key === $active_tab;
						$tab_url       = add_query_arg(
							array(
								'tab'          => $tab_key,
								'conversation' => ! empty( $conv_id ) ? $conv_id : false,
							),
							get_permalink()
						);
						?>
						<a
							href="<?php echo esc_url( $tab_url ); ?>"
							class="bn-tab<?php echo $is_active_tab ? ' is-active' : ''; ?>"
							role="tab"
							aria-selected="<?php echo $is_active_tab ? 'true' : 'false'; ?>"
							data-tab="<?php echo esc_attr( $tab_key ); ?>"
							data-wp-on--click="actions.switchPanelTab"
						>
							<span><?php echo esc_html( $tab_label ); ?></span>
							<?php if ( 'unread' === $tab_key && $unread_count > 0 ) : ?>
								<span class="bn-badge" data-tone="accent"><?php echo esc_html( (string) min( $unread_count, 99 ) ); ?></span>
							<?php endif; ?>
							<?php if ( 'requests' === $tab_key && $request_count > 0 ) : ?>
								<span class="bn-badge" data-tone="danger"><?php echo esc_html( (string) min( $request_count, 99 ) ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<div class="bn-dm-rail__list" role="list">

					<?php
					/**
					 * Render a conversation rail item.
					 *
					 * @param array<string, mixed> $conv          MVS conversation row.
					 * @param int                  $conv_id_active Currently-active conv ID.
					 * @param string               $active_tab    Active tab key.
					 * @param callable             $initials      Initials helper.
					 * @param callable             $tone          Tone-slot helper.
					 * @param callable             $relative_time Relative-time helper.
					 * @param callable             $is_online     Online helper.
					 * @return void
					 */
					$bn_render_conv_row = static function ( array $conv, int $conv_id_active, string $active_tab_inner, callable $initials, callable $tone, callable $relative_time, callable $is_online ): void {
						$c_id        = absint( $conv['id'] ?? 0 );
						$c_uid       = absint( $conv['other_user_id'] ?? 0 );
						$c_name      = sanitize_text_field( $conv['other_user_name'] ?? '' );
						$c_preview   = sanitize_text_field( $conv['last_message_preview'] ?? '' );
						$c_at        = $conv['last_message_at'] ?? '';
						$c_unread    = absint( $conv['unread_count'] ?? 0 );
						$c_online    = $is_online( $c_uid );
						$c_is_active = $c_id === $conv_id_active;
						$c_initials  = $initials( $c_name );
						$c_tone      = $tone( $c_uid );
						$c_typing    = ! empty( $conv['other_user_typing'] );
						$c_pinned    = ! empty( $conv['is_pinned'] );
						$c_url       = add_query_arg(
							array(
								'conversation' => $c_id,
								'tab'          => $active_tab_inner,
							),
							get_permalink()
						);
						$c_av_html   = get_avatar( $c_uid, 36, '', $c_name, array( 'force_display' => true ) );
						$presence    = $c_online ? 'online' : 'offline';

						$item_classes = array( 'bn-split__rail-item', 'bn-dm-rail__item', 'bn-dm-tone-' . $c_tone );
						if ( $c_unread > 0 ) {
							$item_classes[] = 'is-unread';
						}
						?>
						<a
							href="<?php echo esc_url( $c_url ); ?>"
							class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>"
							role="listitem"
							<?php if ( $c_is_active ) : ?>
								aria-current="true"
							<?php endif; ?>
							data-conv-id="<?php echo esc_attr( (string) $c_id ); ?>"
						>
							<span class="bn-avatar bn-dm-avatar" data-size="md" data-presence="<?php echo esc_attr( $presence ); ?>" aria-hidden="true">
								<?php if ( false !== strpos( $c_av_html, 'src=' ) ) : ?>
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
										<?php echo esc_html( $relative_time( $c_at ) ); ?>
									</time>
								</span>
								<span class="bn-dm-rail__item-preview<?php echo $c_typing ? ' is-typing' : ''; ?>">
									<?php echo esc_html( $c_typing ? __( 'Typing…', 'buddynext' ) : $c_preview ); ?>
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
					};
	?>

					<?php if ( ! empty( $pinned_conversations ) ) : ?>
						<div class="bn-dm-rail__section">
							<span class="bn-dm-rail__section-icon" aria-hidden="true"><?php buddynext_icon( 'bookmark' ); ?></span>
							<?php esc_html_e( 'Pinned', 'buddynext' ); ?>
						</div>
						<?php foreach ( $pinned_conversations as $bn_conv ) : ?>
							<?php $bn_render_conv_row( $bn_conv, $conv_id, $active_tab, $bn_initials, $bn_avatar_tone, $bn_relative_time, $bn_is_online ); ?>
						<?php endforeach; ?>
					<?php endif; ?>

					<?php if ( ! empty( $recent_conversations ) ) : ?>
						<?php if ( ! empty( $pinned_conversations ) ) : ?>
							<div class="bn-dm-rail__section">
								<?php esc_html_e( 'Recent', 'buddynext' ); ?>
							</div>
						<?php endif; ?>
						<?php foreach ( $recent_conversations as $bn_conv ) : ?>
							<?php $bn_render_conv_row( $bn_conv, $conv_id, $active_tab, $bn_initials, $bn_avatar_tone, $bn_relative_time, $bn_is_online ); ?>
						<?php endforeach; ?>
					<?php endif; ?>

					<?php if ( empty( $pinned_conversations ) && empty( $recent_conversations ) ) : ?>
						<div class="bn-dm-rail__empty">
							<?php esc_html_e( 'No conversations yet.', 'buddynext' ); ?>
						</div>
					<?php endif; ?>

				</div>

				<div class="bn-dm-rail__foot">
					<a class="bn-btn" data-variant="primary" data-size="md" href="<?php echo esc_url( $compose_url ); ?>">
						<span class="bn-btn__icon" aria-hidden="true"><?php buddynext_icon( 'plus' ); ?></span>
						<?php esc_html_e( 'New message', 'buddynext' ); ?>
					</a>
				</div>

			</aside>

			<!-- ── Thread pane ──────────────────────────────────── -->
			<section class="bn-split__pane bn-dm-pane" aria-label="<?php esc_attr_e( 'Conversation', 'buddynext' ); ?>">

				<?php if ( 0 === $conv_id || null === $thread_data ) : ?>

					<div class="bn-dm-pane__empty">
						<span class="bn-dm-pane__empty-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
						<h2 class="bn-dm-pane__empty-title"><?php esc_html_e( 'Select a conversation', 'buddynext' ); ?></h2>
						<p class="bn-dm-pane__empty-body"><?php esc_html_e( 'Choose from your existing conversations or start a new one.', 'buddynext' ); ?></p>
					</div>

				<?php else : ?>

					<?php
					$th_tone     = $bn_avatar_tone( $other_user_id_th );
					$th_initials = $bn_initials( $other_user_display );
					$th_avatar   = get_avatar( $other_user_id_th, 36, '', $other_user_display, array( 'force_display' => true ) );
					$th_profile  = \BuddyNext\Core\PageRouter::profile_url( $other_user_id_th );
					$th_presence = $other_is_online ? 'online' : 'offline';
					$back_url    = remove_query_arg( 'conversation' );
					?>

					<header class="bn-split__pane-head bn-dm-pane__head">
						<a href="<?php echo esc_url( $back_url ); ?>" class="bn-btn bn-dm-pane__back" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Back to conversations', 'buddynext' ); ?>">
							<?php buddynext_icon( 'chevron-left' ); ?>
						</a>

						<a href="<?php echo esc_url( $th_profile ); ?>" class="bn-dm-pane__identity">
							<span class="bn-avatar bn-dm-avatar bn-dm-tone-<?php echo (int) $th_tone; ?>" data-size="md" data-presence="<?php echo esc_attr( $th_presence ); ?>" aria-hidden="true">
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
									<span class="bn-avatar__initials"><?php echo esc_html( $th_initials ); ?></span>
								<?php endif; ?>
							</span>
							<span class="bn-dm-pane__identity-text">
								<span class="bn-dm-pane__identity-name"><?php echo esc_html( $other_user_display ); ?></span>
								<span class="bn-dm-pane__identity-status<?php echo $other_is_online ? ' is-online' : ''; ?>">
									<?php echo esc_html( $other_is_online ? __( 'Online now', 'buddynext' ) : __( 'Offline', 'buddynext' ) ); ?>
								</span>
							</span>
						</a>

						<div class="bn-dm-pane__actions" role="toolbar" aria-label="<?php esc_attr_e( 'Conversation actions', 'buddynext' ); ?>">
							<span class="bn-tooltip-trigger">
								<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Search messages', 'buddynext' ); ?>">
									<?php buddynext_icon( 'search' ); ?>
								</button>
								<span class="bn-tooltip" data-pos="bottom"><?php esc_html_e( 'Search', 'buddynext' ); ?></span>
							</span>
							<span class="bn-tooltip-trigger">
								<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Delete conversation', 'buddynext' ); ?>" data-wp-on--click="actions.openDeleteConfirm">
									<?php buddynext_icon( 'trash' ); ?>
								</button>
								<span class="bn-tooltip" data-pos="bottom"><?php esc_html_e( 'Delete', 'buddynext' ); ?></span>
							</span>
							<span class="bn-tooltip-trigger">
								<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'More options', 'buddynext' ); ?>" data-wp-on--click="actions.openThreadOptions">
									<?php buddynext_icon( 'more-horizontal' ); ?>
								</button>
								<span class="bn-tooltip" data-pos="bottom"><?php esc_html_e( 'More', 'buddynext' ); ?></span>
							</span>
						</div>
					</header>

					<div class="bn-split__pane-body bn-dm-pane__body" role="log" aria-live="polite" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: other user display name */ __( 'Conversation with %s', 'buddynext' ), $other_user_display ) ); ?>">

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
							$iso         = $bn_iso_time( $msg_at );

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
								<div class="bn-dm-divider" role="separator">
									<time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $msg_ts ) ); ?>">
										<?php echo esc_html( $date_label ); ?>
									</time>
								</div>
								<?php
								$prev_date = $msg_date;
							endif;
							?>

							<div
								class="bn-dm-msg<?php echo $is_mine ? ' is-mine' : ''; ?>"
								data-msg-id="<?php echo esc_attr( (string) $msg_id ); ?>"
							>
								<?php if ( ! $is_mine ) : ?>
									<span class="bn-avatar bn-dm-avatar bn-dm-tone-<?php echo (int) $th_tone; ?>" data-size="sm" aria-hidden="true">
										<span class="bn-avatar__initials"><?php echo esc_html( $th_initials ); ?></span>
									</span>
								<?php endif; ?>

								<div class="bn-dm-msg__content">
									<div class="bn-dm-bubble<?php echo $is_mine ? ' is-mine' : ''; ?>">
										<?php if ( is_array( $reply_to ) && ! empty( $reply_to['body'] ) ) : ?>
											<div class="bn-dm-bubble__quoted">
												<?php echo esc_html( wp_trim_words( sanitize_text_field( $reply_to['body'] ), 15 ) ); ?>
											</div>
										<?php endif; ?>
										<?php echo $msg_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already filtered via wp_kses above. ?>
									</div>

									<div class="bn-dm-msg__meta">
										<time class="bn-dm-msg__time" datetime="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( $clock ); ?></time>
										<?php if ( $is_mine && $read_by_all ) : ?>
											<span class="bn-dm-msg__receipt" aria-label="<?php esc_attr_e( 'Read', 'buddynext' ); ?>">
												<?php buddynext_icon( 'check-double' ); ?>
												<span><?php esc_html_e( 'Read', 'buddynext' ); ?></span>
											</span>
										<?php endif; ?>
									</div>

									<?php if ( ! empty( $reactions ) ) : ?>
										<div class="bn-dm-msg__reactions">
											<?php foreach ( $reactions as $emoji => $count ) : ?>
												<button
													type="button"
													class="bn-dm-msg__reaction"
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
								</div>
							</div>

						<?php endforeach; ?>

						<?php if ( $other_is_typing ) : ?>
							<div class="bn-dm-typing" aria-live="polite">
								<span class="bn-avatar bn-dm-avatar bn-dm-tone-<?php echo (int) $th_tone; ?>" data-size="sm" aria-hidden="true">
									<span class="bn-avatar__initials"><?php echo esc_html( $th_initials ); ?></span>
								</span>
								<span class="bn-dm-typing__dots" aria-hidden="true">
									<span class="bn-dm-typing__dot"></span>
									<span class="bn-dm-typing__dot"></span>
									<span class="bn-dm-typing__dot"></span>
								</span>
								<span class="bn-dm-typing__label">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: other user's first name */
											__( '%s is typing…', 'buddynext' ),
											( $other_user instanceof WP_User ) ? explode( ' ', $other_user->display_name )[0] : __( 'Someone', 'buddynext' )
										)
									);
									?>
								</span>
							</div>
						<?php endif; ?>

					</div>

					<!-- Composer -->
					<div class="bn-dm-composer">

						<div class="bn-card bn-dm-composer__reply" data-wp-class--is-hidden="!state.replyToId">
							<span class="bn-dm-composer__reply-label"><?php esc_html_e( 'Replying to', 'buddynext' ); ?></span>
							<span class="bn-dm-composer__reply-text" data-wp-text="state.replyToText"></span>
							<button
								type="button"
								class="bn-btn"
								data-variant="ghost"
								data-size="sm"
								aria-label="<?php esc_attr_e( 'Cancel reply', 'buddynext' ); ?>"
								data-wp-on--click="actions.clearReply"
							>
								<?php buddynext_icon( 'x' ); ?>
							</button>
						</div>

						<form class="bn-dm-composer__row" data-wp-on--submit="actions.sendMessage">
							<label for="<?php echo esc_attr( $messages_input_id ); ?>" class="bn-visually-hidden">
								<?php esc_html_e( 'Message', 'buddynext' ); ?>
							</label>

							<span class="bn-tooltip-trigger">
								<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Insert emoji', 'buddynext' ); ?>" data-wp-on--click="actions.openEmojiPicker">
									<?php buddynext_icon( 'smile' ); ?>
								</button>
								<span class="bn-tooltip" data-pos="top"><?php esc_html_e( 'Emoji', 'buddynext' ); ?></span>
							</span>

							<span class="bn-tooltip-trigger">
								<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Attach file', 'buddynext' ); ?>" data-wp-on--click="actions.openAttachment">
									<?php buddynext_icon( 'paperclip' ); ?>
								</button>
								<span class="bn-tooltip" data-pos="top"><?php esc_html_e( 'Attach', 'buddynext' ); ?></span>
							</span>

							<textarea
								id="<?php echo esc_attr( $messages_input_id ); ?>"
								class="bn-textarea bn-dm-composer__input"
								rows="1"
								placeholder="<?php esc_attr_e( 'Type a message…', 'buddynext' ); ?>"
								data-wp-on--keydown="actions.onInputKeydown"
								data-wp-on--input="actions.onMessageInput"
							></textarea>

							<button
								type="submit"
								class="bn-btn"
								data-variant="primary"
								data-size="md"
								aria-label="<?php esc_attr_e( 'Send message', 'buddynext' ); ?>"
								data-conv-id="<?php echo esc_attr( (string) $conv_id ); ?>"
								data-wp-on--click="actions.sendMessage"
							>
								<?php buddynext_icon( 'send' ); ?>
							</button>
						</form>
					</div>

				<?php endif; ?>

			</section>

		</div><!-- /.bn-split -->

	</div><!-- /.bn-card -->

	<!-- ── Delete-conversation confirmation modal ──────────────────── -->
	<div
		class="bn-modal-backdrop bn-dm-confirm"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bn-dm-confirm-title"
		data-wp-class--is-hidden="!state.confirmOpen"
		data-wp-on--click="actions.closeDeleteConfirm"
	>
		<div class="bn-modal__panel" data-tone="danger" data-size="sm" data-wp-on--click="actions.stopPropagation">
			<div class="bn-modal__head">
				<h2 id="bn-dm-confirm-title" class="bn-modal__title"><?php esc_html_e( 'Delete conversation?', 'buddynext' ); ?></h2>
				<button
					type="button"
					class="bn-modal__close"
					aria-label="<?php esc_attr_e( 'Close dialog', 'buddynext' ); ?>"
					data-wp-on--click="actions.closeDeleteConfirm"
				>
					<?php buddynext_icon( 'x' ); ?>
				</button>
			</div>
			<div class="bn-modal__body">
				<p><?php esc_html_e( 'This permanently removes all messages in this conversation for you. The other participant keeps their copy.', 'buddynext' ); ?></p>
			</div>
			<div class="bn-modal__foot">
				<button type="button" class="bn-btn" data-variant="ghost" data-size="md" data-wp-on--click="actions.closeDeleteConfirm">
					<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
				</button>
				<button type="button" class="bn-btn" data-variant="danger" data-size="md" data-wp-on--click="actions.confirmDeleteConversation">
					<?php esc_html_e( 'Delete', 'buddynext' ); ?>
				</button>
			</div>
		</div>
	</div>

<?php endif; ?>

</div>
