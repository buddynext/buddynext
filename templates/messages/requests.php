<?php
/**
 * Message Requests template.
 *
 * Displays pending message requests from users the current user does not follow.
 * Requests arrive via WPMediaVerse (mvs/v1/me/conversations?tab=requests). BuddyNext is
 * the UI layer only — it does not own conversation or message data.
 *
 * Actions:
 *   Accept  → conversation moves to main inbox (POST mvs/v1/conversations/{id}/accept)
 *   Delete  → removes request, sender is not notified
 *   Block   → adds sender to bn_blocks, conversation is permanently removed
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// ── WPMediaVerse dependency check ─────────────────────────────────────────────
$mvs_active = class_exists( 'WPMediaVerse\Core\Plugin' );

// ── Auth ──────────────────────────────────────────────────────────────────────
$current_user_id = get_current_user_id();

// ── Nonces ────────────────────────────────────────────────────────────────────
$rest_nonce    = wp_create_nonce( 'wp_rest' );
$action_nonce  = wp_create_nonce( 'bn_messages_action' );
$mvs_rest_base = rest_url( 'mvs/v1' );

// ── Fetch pending requests via WPMediaVerse REST API ─────────────────────────
$requests    = array();
$total_count = 0;

if ( $mvs_active && $current_user_id > 0 ) {
	$api_url = rest_url( 'mvs/v1/me/conversations' );

	$api_response = wp_remote_get(
		$api_url,
		array(
			'headers' => array(
				'X-WP-Nonce' => $rest_nonce,
				'Cookie'     => isset( $_SERVER['HTTP_COOKIE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_COOKIE'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			),
			'timeout' => 5,
		)
	);

	if ( ! is_wp_error( $api_response ) && 200 === wp_remote_retrieve_response_code( $api_response ) ) {
		$body = json_decode( wp_remote_retrieve_body( $api_response ), true );
		if ( is_array( $body ) ) {
			$requests    = (array) ( $body['requests'] ?? array() );
			$total_count = (int) ( $body['total'] ?? count( $requests ) );
		}
	}
}

// ── URLs ──────────────────────────────────────────────────────────────────────
$messages_url = \BuddyNext\Core\PageRouter::messages_url();

// ── Helper: avatar initials ───────────────────────────────────────────────────
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

// Avatar colour palette.
$avatar_colours   = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#dc2626', '#d97706' );
$bn_avatar_colour = static function ( int $user_id ) use ( $avatar_colours ): string {
	return $avatar_colours[ $user_id % count( $avatar_colours ) ];
};

/**
 * Format a UTC timestamp as a relative human-readable string.
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
		return _x( 'Just now', 'relative time', 'buddynext' );
	}
	if ( $diff < 3600 ) {
		$m = (int) floor( $diff / 60 );
		return sprintf(
			/* translators: %d: number of minutes ago */
			_n( '%d minute ago', '%d minutes ago', $m, 'buddynext' ),
			$m
		);
	}
	if ( $diff < 86400 ) {
		$h = (int) floor( $diff / 3600 );
		return sprintf(
			/* translators: %d: number of hours ago */
			_n( '%d hour ago', '%d hours ago', $h, 'buddynext' ),
			$h
		);
	}
	$d = (int) floor( $diff / 86400 );
	return sprintf(
		/* translators: %d: number of days ago */
		_n( '%d day ago', '%d days ago', $d, 'buddynext' ),
		$d
	);
};
?>
<?php
$bn_nav_active = 'messages';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div
	class="bn-message-requests"
	data-wp-interactive="buddynext/messages"
	data-wp-context='{"nonce":"<?php echo esc_js( $action_nonce ); ?>","restNonce":"<?php echo esc_js( $rest_nonce ); ?>","mvsRestBase":"<?php echo esc_js( $mvs_rest_base ); ?>"}'
>

<nav class="bn-req-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'buddynext' ); ?>">
	<a href="<?php echo esc_url( $messages_url ); ?>"><?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Messages', 'buddynext' ); ?></a>
	<span class="bn-req-breadcrumb-sep" aria-hidden="true">/</span>
	<span class="bn-req-breadcrumb-current"><?php esc_html_e( 'Requests', 'buddynext' ); ?></span>
</nav>

<h1 class="bn-req-title"><?php esc_html_e( 'Message Requests', 'buddynext' ); ?></h1>
<p class="bn-req-subtitle">
	<?php esc_html_e( 'These are from people you don&rsquo;t follow. Accepting lets them message you. They can&rsquo;t see if you&rsquo;ve read the preview until you accept.', 'buddynext' ); ?>
</p>

<?php if ( ! $mvs_active ) : ?>

	<div class="bn-dependency-notice">
		<div class="bn-dependency-notice-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></div>
		<div class="bn-dependency-notice-title"><?php esc_html_e( 'Direct messaging requires WPMediaVerse', 'buddynext' ); ?></div>
		<p class="bn-dependency-notice-body">
			<?php esc_html_e( 'Install and activate the WPMediaVerse plugin to enable direct messaging in BuddyNext.', 'buddynext' ); ?>
		</p>
	</div>

<?php elseif ( ! is_user_logged_in() ) : ?>

	<div class="bn-dependency-notice">
		<div class="bn-dependency-notice-icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></div>
		<div class="bn-dependency-notice-title"><?php esc_html_e( 'Please sign in', 'buddynext' ); ?></div>
		<p class="bn-dependency-notice-body">
			<?php esc_html_e( 'You need to be signed in to view your message requests.', 'buddynext' ); ?>
		</p>
	</div>

<?php else : ?>

	<?php if ( $total_count > 0 ) : ?>
		<div class="bn-req-info-box">
			<strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of pending message requests */
						_n( '%d request waiting.', '%d requests waiting.', $total_count, 'buddynext' ),
						$total_count
					)
				);
				?>
			</strong>
			<?php esc_html_e( ' People you block won&rsquo;t be able to contact you. Your privacy settings control who can send requests.', 'buddynext' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( empty( $requests ) ) : ?>

		<div class="bn-req-empty">
			<div class="bn-req-empty-icon" aria-hidden="true"><?php buddynext_icon( 'mail' ); ?></div>
			<div class="bn-req-empty-title"><?php esc_html_e( 'No pending requests', 'buddynext' ); ?></div>
			<p class="bn-req-empty-sub">
				<?php esc_html_e( 'When someone who doesn&rsquo;t follow you sends a message, it will appear here first.', 'buddynext' ); ?>
			</p>
		</div>

	<?php else : ?>

		<div class="bn-requests-list" role="list">
			<?php foreach ( $requests as $request ) : ?>
				<?php
				$req_id        = absint( $request['id'] ?? 0 );
				$sender_id     = absint( $request['sender_id'] ?? 0 );
				$sender_name   = sanitize_text_field( $request['sender_name'] ?? '' );
				$sender_handle = sanitize_user( $request['sender_login'] ?? '' );
				$sender_bio    = sanitize_text_field( $request['sender_bio'] ?? '' );
				$mutual_count  = absint( $request['mutual_connections'] ?? 0 );
				$sent_at       = $request['sent_at'] ?? '';
				$preview_text  = sanitize_text_field( $request['preview'] ?? '' );
				$profile_url   = \BuddyNext\Core\PageRouter::profile_url( $sender_id );
				$req_initials  = $bn_initials( $sender_name );
				$req_colour    = $bn_avatar_colour( $sender_id );
				$req_time      = $bn_relative_time( $sent_at );
				$sender_avatar = get_avatar( $sender_id, 48, '', esc_attr( $sender_name ), array( 'force_display' => true ) );
				$accept_nonce  = wp_create_nonce( 'bn_req_accept_' . $req_id );
				$delete_nonce  = wp_create_nonce( 'bn_req_delete_' . $req_id );
				$block_nonce   = wp_create_nonce( 'bn_req_block_' . $sender_id );
				?>
				<article
					class="bn-request-card"
					role="listitem"
					data-request-id="<?php echo esc_attr( (string) $req_id ); ?>"
					data-sender-id="<?php echo esc_attr( (string) $sender_id ); ?>"
				>
					<div class="bn-req-card-header">
						<div
							class="bn-req-avatar"
							style="background: <?php echo esc_attr( $req_colour ); ?>;"
							aria-hidden="true"
						>
							<?php if ( false !== strpos( $sender_avatar, 'src=' ) ) : ?>
								<?php
								echo wp_kses(
									$sender_avatar,
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
								<?php echo esc_html( $req_initials ); ?>
							<?php endif; ?>
						</div>

						<div class="bn-req-identity">
							<div class="bn-req-name">
								<a href="<?php echo esc_url( $profile_url ); ?>">
									<?php echo esc_html( $sender_name ); ?>
								</a>
							</div>

							<?php if ( $sender_handle || $sender_bio ) : ?>
								<div class="bn-req-meta">
									<?php if ( $sender_handle ) : ?>
										@<?php echo esc_html( $sender_handle ); ?>
									<?php endif; ?>
									<?php if ( $sender_handle && $sender_bio ) : ?>
										&middot;
									<?php endif; ?>
									<?php if ( $sender_bio ) : ?>
										<?php echo esc_html( wp_trim_words( $sender_bio, 10 ) ); ?>
									<?php endif; ?>
								</div>
							<?php endif; ?>

							<?php if ( $mutual_count > 0 ) : ?>
								<div class="bn-req-mutual">
									<?php buddynext_icon( 'check' ); ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of mutual connections */
											_n( '%d mutual connection', '%d mutual connections', $mutual_count, 'buddynext' ),
											$mutual_count
										)
									);
									?>
								</div>
							<?php endif; ?>
						</div>

						<div class="bn-req-time"><?php echo esc_html( $req_time ); ?></div>
					</div>

					<?php if ( $preview_text ) : ?>
						<blockquote class="bn-req-message-preview">
							<?php echo esc_html( '"' . $preview_text . '"' ); ?>
						</blockquote>
					<?php endif; ?>

					<div class="bn-req-actions">
						<button
							type="button"
							class="bn-btn-accept"
							data-request-id="<?php echo esc_attr( (string) $req_id ); ?>"
							data-nonce="<?php echo esc_attr( $accept_nonce ); ?>"
							data-wp-on--click="actions.acceptRequest"
						>
							<?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Accept', 'buddynext' ); ?>
						</button>

						<button
							type="button"
							class="bn-btn-delete"
							data-request-id="<?php echo esc_attr( (string) $req_id ); ?>"
							data-nonce="<?php echo esc_attr( $delete_nonce ); ?>"
							data-wp-on--click="actions.deleteRequest"
						>
							<?php esc_html_e( 'Delete', 'buddynext' ); ?>
						</button>

						<button
							type="button"
							class="bn-btn-block"
							data-sender-id="<?php echo esc_attr( (string) $sender_id ); ?>"
							data-request-id="<?php echo esc_attr( (string) $req_id ); ?>"
							data-nonce="<?php echo esc_attr( $block_nonce ); ?>"
							data-wp-on--click="actions.blockSender"
						>
							<?php esc_html_e( 'Block', 'buddynext' ); ?>
						</button>
					</div>
				</article>

			<?php endforeach; ?>
		</div>

		<!-- Empty state shown after all requests are actioned (client-side) -->
		<div class="bn-req-empty" style="display:none;" data-wp-class--bn-visible="state.allRequestsActioned">
			<div class="bn-req-empty-icon" aria-hidden="true"><?php buddynext_icon( 'mail' ); ?></div>
			<div class="bn-req-empty-title"><?php esc_html_e( 'All caught up!', 'buddynext' ); ?></div>
			<p class="bn-req-empty-sub">
				<?php esc_html_e( 'When someone who doesn&rsquo;t follow you sends a message, it will appear here first.', 'buddynext' ); ?>
			</p>
		</div>

	<?php endif; ?>

<?php endif; ?>

</div>
