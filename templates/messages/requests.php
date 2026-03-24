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
$messages_url_raw = get_permalink( get_page_by_path( 'messages' ) );
$messages_url     = ! empty( $messages_url_raw ) ? $messages_url_raw : home_url( '/messages/' );

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
require __DIR__ . '/../partials/nav.php';
?>
<div
	class="bn-message-requests"
	data-wp-interactive="buddynext/messages"
	data-wp-context='{"nonce":"<?php echo esc_js( $action_nonce ); ?>","restNonce":"<?php echo esc_js( $rest_nonce ); ?>","mvsRestBase":"<?php echo esc_js( $mvs_rest_base ); ?>"}'
>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap');

:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs:  11px;  --text-sm: 13px;  --text-base: 15px;
	--text-lg:  17px;  --text-xl: 20px;  --text-2xl: 24px;
	--leading-body: 1.7;
	--bg:          #ffffff;
	--bg-subtle:   #f8f8f7;
	--bg-hover:    #f1f1f0;
	--surface:     #ffffff;
	--border:      #e8e8e5;
	--border-soft: #f1f1ee;
	--text-1:      #37352f;
	--text-2:      #787774;
	--text-3:      #aeaca8;
	--brand:       #0073aa;
	--brand-light: #e8f4fb;
	--brand-hover: #005f8e;
	--green:    #059669;  --green-bg:  #ecfdf5;
	--red:      #dc2626;  --red-bg:    #fef2f2;
	--s1: 4px;  --s2: 8px;   --s3: 12px;  --s4: 16px;
	--s5: 20px; --s6: 24px;  --s8: 32px;
	--radius-sm: 6px; --radius: 10px; --radius-lg: 14px;
}
[data-theme="dark"] {
	--bg:          #191919;
	--bg-subtle:   #202020;
	--bg-hover:    #2a2a2a;
	--surface:     #252525;
	--border:      #333330;
	--border-soft: #2c2c2a;
	--text-1:      #e8e8e6;
	--text-2:      #9b9b97;
	--text-3:      #6b6b67;
	--brand:       #4dabdb;
	--brand-light: #1a2e3a;
	--brand-hover: #5fbfe8;
	--green:       #34d399;  --green-bg: #0d2420;
	--red:         #f87171;  --red-bg:   #2d0f0f;
}

/* ── Shell ───────────────────────────────────────────────── */
.bn-message-requests {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	line-height: var(--leading-body);
	max-width: 640px;
	margin: 0 auto;
	padding: 0;
}

/* ── Breadcrumb ──────────────────────────────────────────── */
.bn-req-breadcrumb {
	display: flex;
	align-items: center;
	gap: var(--s2);
	margin-bottom: var(--s4);
	font-size: var(--text-sm);
}
.bn-req-breadcrumb a { color: var(--brand); text-decoration: none; }
.bn-req-breadcrumb a:hover { text-decoration: underline; }
.bn-req-breadcrumb-sep { color: var(--text-3); }
.bn-req-breadcrumb-current { color: var(--text-1); }

/* ── Page heading ────────────────────────────────────────── */
.bn-req-title {
	font-family: var(--font-display);
	font-size: var(--text-xl);
	font-weight: 800;
	margin-bottom: var(--s1);
	color: var(--text-1);
}
.bn-req-subtitle {
	font-size: var(--text-sm);
	color: var(--text-2);
	margin-bottom: var(--s6);
	line-height: 1.6;
	max-width: 520px;
}

/* ── Info box ────────────────────────────────────────────── */
.bn-req-info-box {
	background: var(--brand-light);
	border: 1px solid #bfdbfe;
	border-radius: var(--radius-sm);
	padding: var(--s3) var(--s4);
	margin-bottom: var(--s5);
	font-size: var(--text-sm);
	color: #1e40af;
	line-height: 1.5;
}
[data-theme="dark"] .bn-req-info-box {
	background: var(--brand-light);
	border-color: #1e3a5f;
	color: var(--brand);
}

/* ── Request card ────────────────────────────────────────── */
.bn-request-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s4);
	margin-bottom: var(--s3);
	transition: border-color 0.15s;
}
.bn-request-card:hover { border-color: #cbd5e1; }
[data-theme="dark"] .bn-request-card:hover { border-color: #4a4a47; }

/* ── Card header ─────────────────────────────────────────── */
.bn-req-card-header {
	display: flex;
	gap: var(--s3);
	align-items: flex-start;
	margin-bottom: var(--s3);
}
.bn-req-avatar {
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	width: 48px;
	height: 48px;
	font-size: 16px;
	overflow: hidden;
}
.bn-req-avatar img { width: 100%; height: 100%; object-fit: cover; }
.bn-req-identity { flex: 1; min-width: 0; }
.bn-req-name { font-weight: 700; font-size: var(--text-sm); color: var(--text-1); }
.bn-req-name a { color: inherit; text-decoration: none; }
.bn-req-name a:hover { color: var(--brand); }
.bn-req-meta { font-size: var(--text-xs); color: var(--text-2); margin-top: 2px; }
.bn-req-mutual {
	font-size: var(--text-xs);
	color: var(--green);
	margin-top: 3px;
	font-weight: 600;
}
.bn-req-time {
	font-size: var(--text-xs);
	color: var(--text-3);
	flex-shrink: 0;
	white-space: nowrap;
}

/* ── Message preview ─────────────────────────────────────── */
.bn-req-message-preview {
	background: var(--bg-subtle);
	border-radius: var(--radius-sm);
	padding: var(--s3) var(--s3);
	font-size: var(--text-sm);
	color: var(--text-1);
	line-height: 1.6;
	margin-bottom: var(--s4);
	border-left: 3px solid var(--border);
	font-style: italic;
}
[data-theme="dark"] .bn-req-message-preview { background: var(--bg-subtle); }

/* ── Actions ─────────────────────────────────────────────── */
.bn-req-actions { display: flex; gap: var(--s2); flex-wrap: wrap; }
.bn-btn-accept {
	background: var(--brand);
	color: #fff;
	padding: var(--s2) var(--s5);
	border-radius: var(--radius);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	transition: background 0.15s;
}
.bn-btn-accept:hover { background: var(--brand-hover); }
.bn-btn-delete {
	background: var(--bg);
	color: var(--text-2);
	padding: var(--s2) var(--s4);
	border-radius: var(--radius);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: 1.5px solid var(--border);
	font-family: var(--font-body);
	transition: border-color 0.15s;
}
.bn-btn-delete:hover { border-color: var(--text-2); }
.bn-btn-block {
	background: var(--bg);
	color: var(--red);
	padding: var(--s2) var(--s4);
	border-radius: var(--radius);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: 1.5px solid #fca5a5;
	font-family: var(--font-body);
	transition: background 0.15s, border-color 0.15s;
}
[data-theme="dark"] .bn-btn-block { border-color: #7f1d1d; }
.bn-btn-block:hover { background: var(--red-bg); }

/* ── Empty state ─────────────────────────────────────────── */
.bn-req-empty {
	text-align: center;
	padding: var(--s5);
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
}
.bn-req-empty-icon { font-size: 32px; margin-bottom: var(--s3); line-height: 1; }
.bn-req-empty-title { font-weight: 600; color: var(--text-1); margin-bottom: var(--s1); font-size: var(--text-base); }
.bn-req-empty-sub { font-size: var(--text-sm); color: var(--text-2); line-height: 1.5; }

/* ── Dependency notice ───────────────────────────────────── */
.bn-dependency-notice {
	background: #fffbeb;
	border: 1px solid #fde68a;
	border-radius: var(--radius);
	padding: var(--s6);
	text-align: center;
	color: var(--text-1);
}
[data-theme="dark"] .bn-dependency-notice {
	background: #2a2000;
	border-color: #854d0e;
}
.bn-dependency-notice-icon { font-size: 36px; margin-bottom: var(--s3); line-height: 1; }
.bn-dependency-notice-title { font-weight: 700; font-size: var(--text-lg); margin-bottom: var(--s2); }
.bn-dependency-notice-body { font-size: var(--text-sm); color: var(--text-2); }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 640px) {
	.bn-message-requests { padding: 0; }
	.bn-request-card { border-radius: var(--radius); }
	.bn-req-actions { flex-direction: column; }
	.bn-btn-accept,
	.bn-btn-delete,
	.bn-btn-block { width: 100%; text-align: center; padding: var(--s3); }
	.bn-req-card-header { flex-wrap: wrap; }
	.bn-req-time { width: 100%; text-align: left; }
}
</style>

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
				$profile_url   = get_author_posts_url( $sender_id );
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
