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
 * Layer-3 composition: render is factored into named parts under
 * `templates/parts/dm-*.php`. Each part fires the standard 4-hook contract
 * per `docs/specs/TEMPLATE-PARTS.md`.
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

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().

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

			<?php
			buddynext_get_template(
				'parts/dm-rail.php',
				array(
					'pinned_conversations' => $pinned_conversations,
					'recent_conversations' => $recent_conversations,
					'active_conv_id'       => $conv_id,
					'active_tab'           => $active_tab,
					'list_search'          => $list_search,
					'unread_count'         => $unread_count,
					'request_count'        => $request_count,
					'current_user_id'      => $current_user_id,
					'compose_url'          => $compose_url,
					'initials_fn'          => $bn_initials,
					'tone_fn'              => $bn_avatar_tone,
					'relative_fn'          => $bn_relative_time,
					'online_fn'            => $bn_is_online,
				)
			);
			?>

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
					$back_url    = remove_query_arg( 'conversation' );

					buddynext_get_template(
						'parts/dm-thread-header.php',
						array(
							'display_name'  => $other_user_display,
							'other_user_id' => $other_user_id_th,
							'is_online'     => $other_is_online,
							'tone'          => $th_tone,
							'initials'      => $th_initials,
							'avatar_html'   => $th_avatar,
							'profile_url'   => $th_profile,
							'back_url'      => $back_url,
						)
					);

					buddynext_get_template(
						'parts/dm-thread-messages.php',
						array(
							'messages'         => $messages,
							'current_user_id'  => $current_user_id,
							'other_is_typing'  => $other_is_typing,
							'other_first_name' => ( $other_user instanceof WP_User ) ? explode( ' ', $other_user->display_name )[0] : '',
							'thread_tone'      => $th_tone,
							'thread_initials'  => $th_initials,
							'aria_label'       => sprintf(
								/* translators: %s: other user display name */
								__( 'Conversation with %s', 'buddynext' ),
								$other_user_display
							),
						)
					);

					buddynext_get_template(
						'parts/dm-composer.php',
						array(
							'conversation_id' => $conv_id,
							'input_id'        => $messages_input_id,
						)
					);
					?>

				<?php endif; ?>

			</section>

		</div><!-- /.bn-split -->

	</div><!-- /.bn-card -->

	<?php
	buddynext_get_template( 'parts/dm-delete-modal.php', array() );
	?>

<?php endif; ?>

</div>
