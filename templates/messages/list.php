<?php
/**
 * DM Conversation List template.
 *
 * BuddyNext is the UI layer only. Conversation data is owned by WPMediaVerse
 * and fetched via the REST API at mvs/v1/me/conversations.
 *
 * If WPMediaVerse is not active the template renders a dependency notice.
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

// ── Active tab ────────────────────────────────────────────────────────────────
$active_tab   = sanitize_key( $_GET['tab'] ?? 'all' );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$allowed_tabs = array( 'all', 'unread', 'requests', 'archived' );
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'all';
}

// ── Search term ───────────────────────────────────────────────────────────────
$search_term = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Compose mode ─────────────────────────────────────────────────────────────
$is_compose = isset( $_GET['action'] ) && 'compose' === sanitize_key( $_GET['action'] );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Nonces for interactive actions ───────────────────────────────────────────
$action_nonce  = wp_create_nonce( 'bn_messages_action' );
$rest_nonce    = wp_create_nonce( 'wp_rest' );
$mvs_rest_base = rest_url( 'mvs/v1' );
$bn_rest_base  = rest_url( 'buddynext/v1' );
$messages_url  = \BuddyNext\Core\PageRouter::messages_url();
$compose_url   = add_query_arg( array( 'action' => 'compose' ), $messages_url );
$requests_url  = add_query_arg( array( 'tab' => 'requests' ), $messages_url );

// ── Fetch conversations via WPMediaVerse REST API (server-side bootstrap) ─────
$conversations        = array();
$unread_count         = 0;
$request_count        = 0;
$pinned_conversations = array();
$recent_conversations = array();

if ( $mvs_active && $current_user_id > 0 ) {
	$api_url = add_query_arg(
		array(
			'tab'      => $active_tab,
			'search'   => $search_term,
			'per_page' => 30,
		),
		rest_url( 'mvs/v1/me/conversations' )
	);

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
			$conversations = $body['conversations'] ?? array();
			$unread_count  = (int) ( $body['unread_total'] ?? 0 );
			$request_count = (int) ( $body['request_count'] ?? 0 );
		}
	}

	// Split into pinned / recent for rendering.
	foreach ( $conversations as $conv ) {
		if ( ! empty( $conv['is_pinned'] ) ) {
			$pinned_conversations[] = $conv;
		} else {
			$recent_conversations[] = $conv;
		}
	}
}

// ── Helper: format relative time ─────────────────────────────────────────────
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

// Avatar colour palette — cycles by user ID.
$avatar_colours   = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#dc2626', '#d97706' );
$bn_avatar_colour = static function ( int $user_id ) use ( $avatar_colours ): string {
	return $avatar_colours[ $user_id % count( $avatar_colours ) ];
};

// Online threshold: 5 minutes.
$online_threshold = time() - 300;
$bn_is_online     = static function ( int $user_id ) use ( $online_threshold ): bool {
	$last_active = (int) get_user_meta( $user_id, 'bn_last_active', true );
	return $last_active >= $online_threshold;
};
?>
<?php
$bn_nav_active = 'messages';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div
	class="bn-messages-list bn-hub-shell"
	data-wp-interactive="buddynext/messages"
	data-wp-context='{"tab":"<?php echo esc_js( $active_tab ); ?>","search":"<?php echo esc_js( $search_term ); ?>","nonce":"<?php echo esc_js( $action_nonce ); ?>","restNonce":"<?php echo esc_js( $rest_nonce ); ?>","mvsRestBase":"<?php echo esc_js( $mvs_rest_base ); ?>","bnRestBase":"<?php echo esc_js( $bn_rest_base ); ?>","messagesUrl":"<?php echo esc_js( $messages_url ); ?>","isCompose":<?php echo $is_compose ? 'true' : 'false'; ?>,"composeQuery":"","composeResults":[]}'
>

<style>
/* Token aliases for legacy --radius-* references in this template. */
/* All canonical tokens injected by TokenService — no overrides here. */
:root {
	--radius-sm: var(--r-sm);
	--radius:    var(--r-md);
	--radius-lg: var(--r-lg);
}

/* ── Shell ───────────────────────────────────────────────── */
.bn-messages-list {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	line-height: var(--leading-body);
}
.bn-msg-shell {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	overflow: hidden;
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
	position: relative;
}

/* ── Header ──────────────────────────────────────────────── */
.bn-msg-header {
	padding: var(--s4) var(--s5);
	border-bottom: 1px solid var(--border);
	display: flex;
	align-items: center;
}
.bn-msg-title {
	font-family: var(--font-display);
	font-size: var(--text-xl);
	font-weight: 800;
	flex: 1;
	color: var(--text-1);
}
.bn-compose-btn {
	background: var(--brand);
	color: #fff;
	padding: var(--s2) var(--s3);
	border-radius: var(--radius-sm);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	text-decoration: none;
	display: inline-block;
	transition: background 0.15s;
}
.bn-compose-btn:hover { background: var(--brand-hover); }

/* ── Search ──────────────────────────────────────────────── */
.bn-msg-search-row {
	padding: var(--s3) var(--s4);
	border-bottom: 1px solid var(--border-soft);
}
.bn-msg-search-wrap { position: relative; }
.bn-msg-search-icon {
	position: absolute;
	left: var(--s3);
	top: 50%;
	transform: translateY(-50%);
	color: var(--text-3);
	pointer-events: none;
	line-height: 1;
}
.bn-msg-search-input {
	width: 100%;
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	padding: var(--s2) var(--s3) var(--s2) calc(var(--s3) + 20px);
	font-size: var(--text-sm);
	font-family: var(--font-body);
	background: var(--bg-subtle);
	color: var(--text-1);
	outline: none;
	transition: border-color 0.15s;
}
.bn-msg-search-input:focus {
	border-color: var(--brand);
	background: var(--bg);
}

/* ── Tabs ─────────────────────────────────────────────────── */
.bn-msg-tabs {
	display: flex;
	border-bottom: 1px solid var(--border);
	padding: 0 var(--s3);
}
.bn-msg-tab {
	padding: 11px var(--s3);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	border-bottom: 2px solid transparent;
	text-decoration: none;
	white-space: nowrap;
	transition: color 0.15s, border-color 0.15s;
}
.bn-msg-tab:hover { color: var(--text-1); }
.bn-msg-tab.active,
.bn-msg-tab[aria-selected="true"] {
	color: var(--brand);
	border-bottom-color: var(--brand);
	font-weight: 600;
}
.bn-msg-tab-badge {
	display: inline-block;
	background: var(--red);
	color: #fff;
	font-size: 9px;
	padding: 1px 5px;
	border-radius: var(--radius);
	margin-left: var(--s1);
	font-weight: 700;
	vertical-align: middle;
}

/* ── Section heading ─────────────────────────────────────── */
.bn-conv-section-head {
	padding: var(--s2) var(--s4);
	font-size: var(--text-xs);
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--text-3);
	background: var(--bg-subtle);
	border-bottom: 1px solid var(--border-soft);
}

/* ── Conversation item ───────────────────────────────────── */
.bn-conv-item {
	display: flex;
	align-items: center;
	gap: var(--s3);
	padding: var(--s3) var(--s4);
	cursor: pointer;
	border-bottom: 1px solid var(--border-soft);
	transition: background 0.1s;
	text-decoration: none;
	color: inherit;
}
.bn-conv-item:last-child { border-bottom: none; }
.bn-conv-item:hover { background: var(--bg-hover); }
.bn-conv-item.bn-unread { background: var(--brand-light); }
[data-theme="dark"] .bn-conv-item.bn-unread { background: var(--brand-light); }

/* ── Avatars ─────────────────────────────────────────────── */
.bn-conv-avatar {
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	position: relative;
	width: 44px;
	height: 44px;
	font-size: 15px;
	overflow: visible;
}
.bn-conv-avatar img {
	width: 100%;
	height: 100%;
	border-radius: 50%;
	object-fit: cover;
}
.bn-conv-online {
	position: absolute;
	bottom: 1px;
	right: 1px;
	width: 11px;
	height: 11px;
	background: var(--green);
	border-radius: 50%;
	border: 2px solid var(--surface);
}

/* ── Conv main content ───────────────────────────────────── */
.bn-conv-main { flex: 1; min-width: 0; }
.bn-conv-top {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 3px;
}
.bn-conv-name {
	font-weight: 600;
	font-size: var(--text-sm);
	color: var(--text-1);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.bn-conv-name.bn-unread-name { font-weight: 700; }
.bn-conv-time {
	font-size: var(--text-xs);
	color: var(--text-3);
	flex-shrink: 0;
	margin-left: var(--s2);
}
.bn-conv-time.bn-recent { color: var(--brand); font-weight: 600; }
.bn-conv-preview {
	font-size: 12.5px;
	color: var(--text-2);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.bn-conv-preview.bn-unread-preview { color: var(--text-1); font-weight: 500; }
.bn-conv-preview.bn-muted { color: var(--text-3); font-style: italic; }
.bn-conv-preview.bn-typing { color: var(--brand); font-style: italic; }

/* ── Conv right meta ─────────────────────────────────────── */
.bn-conv-right {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: var(--s1);
	flex-shrink: 0;
}
.bn-unread-badge {
	background: var(--brand);
	color: #fff;
	font-size: var(--text-xs);
	font-weight: 700;
	padding: 2px 7px;
	border-radius: var(--radius);
	min-width: 20px;
	text-align: center;
}
.bn-conv-icon { font-size: var(--text-sm); color: var(--text-3); line-height: 1; }

/* ── Dependency notice ───────────────────────────────────── */
.bn-dependency-notice {
	background: #fffbeb;
	border: 1px solid #fde68a;
	border-radius: var(--radius);
	padding: var(--s5) var(--s6);
	text-align: center;
	color: var(--text-1);
}
[data-theme="dark"] .bn-dependency-notice {
	background: #2a2000;
	border-color: #854d0e;
}
.bn-dependency-notice-icon { font-size: 36px; margin-bottom: var(--s3); line-height: 1; }
.bn-dependency-notice-title { font-weight: 700; font-size: var(--text-lg); margin-bottom: var(--s2); }
.bn-dependency-notice-body { font-size: var(--text-sm); color: var(--text-2); line-height: 1.6; }

/* ── Empty state ─────────────────────────────────────────── */
.bn-msg-empty {
	text-align: center;
	padding: var(--s8) var(--s5);
}
.bn-msg-empty-icon { font-size: 48px; margin-bottom: var(--s3); line-height: 1; }
.bn-msg-empty-title { font-weight: 700; font-size: var(--text-lg); margin-bottom: var(--s2); color: var(--text-1); }
.bn-msg-empty-sub { font-size: var(--text-sm); color: var(--text-2); margin-bottom: var(--s4); }
.bn-btn-primary {
	background: var(--brand);
	color: #fff;
	padding: 9px var(--s5);
	border-radius: var(--radius);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	text-decoration: none;
	display: inline-block;
	transition: background 0.15s;
}
.bn-btn-primary:hover { background: var(--brand-hover); }

/* ── Swipe hint ──────────────────────────────────────────── */
.bn-swipe-hint {
	padding: var(--s2) var(--s4);
	font-size: var(--text-xs);
	color: var(--text-3);
	border-top: 1px solid var(--border-soft);
	text-align: center;
}

/* ── Compose overlay ─────────────────────────────────────── */
.bn-compose-overlay {
	position: absolute;
	inset: 0;
	background: var(--bg);
	border-radius: var(--radius);
	z-index: 10;
	display: flex;
	flex-direction: column;
}
.bn-compose-overlay[hidden] { display: none; }
.bn-compose-panel { display: flex; flex-direction: column; height: 100%; }
.bn-compose-header {
	display: flex;
	align-items: center;
	gap: var(--s3);
	padding: var(--s4) var(--s4) var(--s3);
	border-bottom: 1px solid var(--border);
}
.bn-compose-back {
	background: none; border: none; cursor: pointer; padding: var(--s1);
	color: var(--text-2); border-radius: var(--radius-sm); line-height: 0;
}
.bn-compose-back:hover { background: var(--bg-hover); color: var(--text-1); }
.bn-compose-back svg { width: 18px; height: 18px; }
.bn-compose-title { font-weight: 600; font-size: var(--text-base); color: var(--text-1); }
.bn-compose-to-row {
	display: flex;
	align-items: flex-start;
	gap: var(--s2);
	padding: var(--s3) var(--s4);
	border-bottom: 1px solid var(--border-soft);
	min-height: 44px;
}
.bn-compose-to-label {
	font-size: var(--text-sm); color: var(--text-2); font-weight: 500;
	padding-top: 2px; flex-shrink: 0;
}
.bn-compose-to-wrap { flex: 1; display: flex; flex-wrap: wrap; gap: var(--s1); align-items: center; }
.bn-compose-recipient-pill {
	display: inline-flex; align-items: center; gap: var(--s1);
	background: var(--brand-light); color: var(--brand);
	border-radius: var(--radius-sm); padding: 2px var(--s2);
	font-size: var(--text-sm); font-weight: 500;
}
.bn-compose-recipient-pill[hidden] { display: none; }
.bn-compose-pill-remove {
	background: none; border: none; cursor: pointer; padding: 0;
	color: var(--brand); line-height: 0; opacity: 0.7;
}
.bn-compose-pill-remove:hover { opacity: 1; }
.bn-compose-pill-remove svg { width: 12px; height: 12px; }
.bn-compose-search-input {
	flex: 1; border: none; outline: none; background: transparent;
	font-size: var(--text-sm); color: var(--text-1); min-width: 120px;
}
.bn-compose-search-input[hidden] { display: none; }
.bn-compose-search-input::placeholder { color: var(--text-3); }
.bn-compose-results {
	list-style: none; margin: 0; padding: var(--s1) 0;
	border-bottom: 1px solid var(--border-soft); max-height: 240px; overflow-y: auto;
}
.bn-compose-results[hidden] { display: none; }
.bn-compose-result-item {
	display: flex; align-items: center; gap: var(--s3);
	padding: var(--s2) var(--s4); cursor: pointer;
}
.bn-compose-result-item:hover { background: var(--bg-hover); }
.bn-compose-result-name { font-size: var(--text-sm); color: var(--text-1); font-weight: 500; }
.bn-compose-footer {
	padding: var(--s4); margin-top: auto;
	border-top: 1px solid var(--border-soft);
}
.bn-compose-start-btn { width: 100%; justify-content: center; }
.bn-compose-start-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 640px) {
	.bn-msg-shell { border-radius: 0; border-left: none; border-right: none; }
	.bn-msg-header { padding: var(--s3) var(--s4); }
	.bn-msg-title { font-size: var(--text-lg); }
	.bn-compose-overlay { border-radius: 0; }
}
</style>

<?php if ( ! $mvs_active ) : ?>

	<div class="bn-dependency-notice">
		<div class="bn-dependency-notice-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></div>
		<div class="bn-dependency-notice-title"><?php esc_html_e( 'Direct messaging requires WPMediaVerse', 'buddynext' ); ?></div>
		<p class="bn-dependency-notice-body">
			<?php esc_html_e( 'Install and activate the WPMediaVerse plugin to enable direct messaging in BuddyNext.', 'buddynext' ); ?>
		</p>
	</div>

<?php else : ?>

<div class="bn-msg-grid">

<div class="bn-msg-shell">

	<div class="bn-msg-header">
		<h1 class="bn-msg-title"><?php esc_html_e( 'Messages', 'buddynext' ); ?></h1>
		<a
			href="<?php echo esc_url( $compose_url ); ?>"
			class="bn-compose-btn"
			data-wp-on--click="actions.openCompose"
		>
			+ <?php esc_html_e( 'New message', 'buddynext' ); ?>
		</a>
	</div>

	<?php /* ── Compose overlay ── */ ?>
	<div
		class="bn-compose-overlay"
		data-wp-bind--hidden="!context.isCompose"
		aria-modal="true"
		role="dialog"
		aria-label="<?php esc_attr_e( 'New message', 'buddynext' ); ?>"
	>
		<div class="bn-compose-panel">
			<div class="bn-compose-header">
				<button
					class="bn-compose-back"
					type="button"
					aria-label="<?php esc_attr_e( 'Cancel', 'buddynext' ); ?>"
					data-wp-on--click="actions.closeCompose"
				>
					<?php buddynext_icon( 'arrow-left' ); ?>
				</button>
				<span class="bn-compose-title"><?php esc_html_e( 'New message', 'buddynext' ); ?></span>
			</div>
			<div class="bn-compose-to-row">
				<span class="bn-compose-to-label"><?php esc_html_e( 'To:', 'buddynext' ); ?></span>
				<div class="bn-compose-to-wrap">
					<div
						class="bn-compose-recipient-pill"
						data-wp-bind--hidden="!state.composeRecipientId"
					>
						<span data-wp-text="state.composeRecipientName"></span>
						<button
							type="button"
							class="bn-compose-pill-remove"
							aria-label="<?php esc_attr_e( 'Remove recipient', 'buddynext' ); ?>"
							data-wp-on--click="actions.clearRecipient"
						><?php buddynext_icon( 'x' ); ?></button>
					</div>
					<input
						type="search"
						class="bn-compose-search-input"
						placeholder="<?php esc_attr_e( 'Search people&hellip;', 'buddynext' ); ?>"
						autocomplete="off"
						data-wp-bind--hidden="state.composeRecipientId"
						data-wp-on--input="actions.onComposeSearch"
					>
				</div>
			</div>
			<ul
				class="bn-compose-results"
				data-wp-bind--hidden="!context.composeResults.length"
				role="listbox"
			>
				<template data-wp-each--user="context.composeResults">
					<li
						class="bn-compose-result-item"
						role="option"
						data-wp-on--click="actions.selectRecipient"
					>
						<span class="bn-compose-result-name" data-wp-text="context.user.name"></span>
					</li>
				</template>
			</ul>
			<div class="bn-compose-footer">
				<button
					type="button"
					class="bn-btn-primary bn-compose-start-btn"
					data-wp-bind--disabled="state.composeIsDisabled"
					data-wp-on--click="actions.startConversation"
				>
					<span data-wp-bind--hidden="state.composeBusy"><?php esc_html_e( 'Start conversation', 'buddynext' ); ?></span>
					<span data-wp-bind--hidden="!state.composeBusy"><?php esc_html_e( 'Starting&hellip;', 'buddynext' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<div class="bn-msg-search-row">
		<form class="bn-msg-search-wrap" method="get" action="" role="search" aria-label="<?php esc_attr_e( 'Search conversations', 'buddynext' ); ?>">
			<span class="bn-msg-search-icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></span>
			<input
				class="bn-msg-search-input"
				type="search"
				name="s"
				value="<?php echo esc_attr( $search_term ); ?>"
				placeholder="<?php esc_attr_e( 'Search conversations and people&hellip;', 'buddynext' ); ?>"
				aria-label="<?php esc_attr_e( 'Search conversations', 'buddynext' ); ?>"
				data-wp-on--input="actions.onSearchInput"
			>
		</form>
	</div>

	<nav class="bn-msg-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Message folders', 'buddynext' ); ?>">

		<?php
		$msg_tabs = array(
			'all'      => __( 'All', 'buddynext' ),
			'unread'   => __( 'Unread', 'buddynext' ),
			'requests' => __( 'Requests', 'buddynext' ),
			'archived' => __( 'Archived', 'buddynext' ),
		);
		foreach ( $msg_tabs as $tab_key => $tab_label ) :
			$is_active = $tab_key === $active_tab;
			$tab_url   = add_query_arg( array( 'tab' => $tab_key ), remove_query_arg( 's' ) );
			?>
			<a
				href="<?php echo esc_url( $tab_url ); ?>"
				class="bn-msg-tab<?php echo $is_active ? ' active' : ''; ?>"
				role="tab"
				aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
				data-tab="<?php echo esc_attr( $tab_key ); ?>"
				data-wp-on--click="actions.switchTab"
			>
				<?php echo esc_html( $tab_label ); ?>
				<?php if ( 'unread' === $tab_key && $unread_count > 0 ) : ?>
					<span class="bn-msg-tab-badge"><?php echo esc_html( (string) min( $unread_count, 99 ) ); ?></span>
				<?php endif; ?>
				<?php if ( 'requests' === $tab_key && $request_count > 0 ) : ?>
					<span class="bn-msg-tab-badge"><?php echo esc_html( (string) min( $request_count, 99 ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>

	</nav>

	<?php if ( empty( $conversations ) ) : ?>

		<div class="bn-msg-empty">
			<div class="bn-msg-empty-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></div>
			<div class="bn-msg-empty-title"><?php esc_html_e( 'No conversations yet', 'buddynext' ); ?></div>
			<p class="bn-msg-empty-sub"><?php esc_html_e( 'Start a conversation with someone from the community.', 'buddynext' ); ?></p>
			<a href="<?php echo esc_url( $compose_url ); ?>" class="bn-btn-primary">
				<?php esc_html_e( 'Start a conversation', 'buddynext' ); ?>
			</a>
		</div>

	<?php else : ?>

		<?php
		/**
		 * Render a single conversation row.
		 *
		 * @param array    $conv            Conversation data from WPMediaVerse REST API.
		 * @param callable $bn_relative_time Relative time formatter.
		 * @param callable $bn_initials      Initials generator.
		 * @param callable $bn_avatar_colour Avatar colour picker.
		 * @param callable $bn_is_online     Online status checker.
		 */
		$render_conv_item = static function ( array $conv, callable $bn_relative_time, callable $bn_initials, callable $bn_avatar_colour, callable $bn_is_online ): void {
			$conv_id       = absint( $conv['id'] ?? 0 );
			$other_user_id = absint( $conv['other_user_id'] ?? 0 );
			$other_name    = sanitize_text_field( $conv['other_user_name'] ?? '' );
			$preview       = sanitize_text_field( $conv['last_message_preview'] ?? '' );
			$timestamp     = $conv['last_message_at'] ?? '';
			$unread        = absint( $conv['unread_count'] ?? 0 );
			$is_pinned     = ! empty( $conv['is_pinned'] );
			$is_muted      = ! empty( $conv['is_muted'] );
			$is_typing     = ! empty( $conv['other_user_typing'] );
			$is_mine_last  = ! empty( $conv['last_message_is_mine'] );

			$thread_url = add_query_arg( array( 'conversation' => $conv_id ), get_permalink() );
			$rel_time   = $bn_relative_time( $timestamp );
			$initials   = $bn_initials( $other_name );
			$bg_colour  = $bn_avatar_colour( $other_user_id );
			$is_online  = $bn_is_online( $other_user_id );

			$item_classes = 'bn-conv-item';
			if ( $unread > 0 && ! $is_muted ) {
				$item_classes .= ' bn-unread';
			}

			if ( $is_muted ) {
				$preview_class = 'bn-conv-preview bn-muted';
				$preview       = esc_html__( 'Muted', 'buddynext' );
			} elseif ( $is_typing ) {
				$preview_class = 'bn-conv-preview bn-typing';
				$preview       = esc_html__( 'Typing&hellip;', 'buddynext' );
			} elseif ( $is_mine_last ) {
				$preview_class = 'bn-conv-preview';
				$preview       = esc_html__( 'You: ', 'buddynext' ) . esc_html( $preview );
			} else {
				$preview_class = $unread > 0 ? 'bn-conv-preview bn-unread-preview' : 'bn-conv-preview';
				$preview       = esc_html( $preview );
			}
			?>
			<a
				href="<?php echo esc_url( $thread_url ); ?>"
				class="<?php echo esc_attr( $item_classes ); ?>"
				data-conv-id="<?php echo esc_attr( (string) $conv_id ); ?>"
				data-wp-on--click="actions.openConversation"
			>
				<div
					class="bn-conv-avatar"
					style="background: <?php echo esc_attr( $bg_colour ); ?>;"
					aria-hidden="true"
				>
					<?php
					$avatar_html = get_avatar( $other_user_id, 44, '', esc_attr( $other_name ), array( 'force_display' => true ) );
					if ( false !== strpos( $avatar_html, 'src=' ) ) {
						echo wp_kses(
							$avatar_html,
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
					} else {
						echo esc_html( $initials );
					}
					?>
					<?php if ( $is_online && ! $is_muted ) : ?>
						<span class="bn-conv-online"></span>
					<?php endif; ?>
				</div>

				<div class="bn-conv-main">
					<div class="bn-conv-top">
						<span class="bn-conv-name<?php echo ( $unread > 0 && ! $is_muted ) ? ' bn-unread-name' : ''; ?>">
							<?php echo esc_html( $other_name ); ?>
						</span>
						<span class="bn-conv-time<?php echo $unread > 0 ? ' bn-recent' : ''; ?>">
							<?php echo esc_html( $rel_time ); ?>
						</span>
					</div>
					<div class="<?php echo esc_attr( $preview_class ); ?>">
						<?php
						if ( $is_muted ) {
							echo esc_html__( 'Muted', 'buddynext' );
						} elseif ( $is_typing ) {
							esc_html_e( 'Typing&hellip;', 'buddynext' );
						} else {
							echo esc_html( $preview );
						}
						?>
					</div>
				</div>

				<div class="bn-conv-right">
					<?php if ( $is_pinned ) : ?>
						<span class="bn-conv-icon" aria-label="<?php esc_attr_e( 'Pinned', 'buddynext' ); ?>"><?php buddynext_icon( 'bookmark' ); ?></span>
					<?php endif; ?>
					<?php if ( $is_muted ) : ?>
						<span class="bn-conv-icon" aria-label="<?php esc_attr_e( 'Muted', 'buddynext' ); ?>"></span>
					<?php elseif ( $unread > 0 ) : ?>
						<span class="bn-unread-badge"><?php echo esc_html( (string) min( $unread, 99 ) ); ?></span>
					<?php endif; ?>
				</div>
			</a>
			<?php
		};
	?>

		<?php if ( ! empty( $pinned_conversations ) ) : ?>
			<div class="bn-conv-section-head"><?php buddynext_icon( 'bookmark' ); ?> <?php esc_html_e( 'Pinned', 'buddynext' ); ?></div>
			<?php foreach ( $pinned_conversations as $conv ) : ?>
				<?php $render_conv_item( $conv, $bn_relative_time, $bn_initials, $bn_avatar_colour, $bn_is_online ); ?>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( ! empty( $recent_conversations ) ) : ?>
			<?php if ( ! empty( $pinned_conversations ) ) : ?>
				<div class="bn-conv-section-head"><?php esc_html_e( 'Recent', 'buddynext' ); ?></div>
			<?php endif; ?>
			<?php foreach ( $recent_conversations as $conv ) : ?>
				<?php $render_conv_item( $conv, $bn_relative_time, $bn_initials, $bn_avatar_colour, $bn_is_online ); ?>
			<?php endforeach; ?>
		<?php endif; ?>

		<p class="bn-swipe-hint"><?php esc_html_e( 'Long-press any conversation to mute, archive, or delete', 'buddynext' ); ?></p>

	<?php endif; ?>

</div><!-- .bn-msg-shell -->

	<!-- Right column: welcome state (shown when no conversation is open) -->
	<div class="bn-msg-welcome" aria-label="<?php esc_attr_e( 'Select a conversation', 'buddynext' ); ?>">
		<div class="bn-msg-welcome-icon" aria-hidden="true">
			<?php buddynext_icon( 'message-circle' ); ?>
		</div>
		<p class="bn-msg-welcome-title"><?php esc_html_e( 'Your messages', 'buddynext' ); ?></p>
		<p class="bn-msg-welcome-sub"><?php esc_html_e( 'Select a conversation from the list or start a new one.', 'buddynext' ); ?></p>
		<a
			href="<?php echo esc_url( add_query_arg( 'action', 'compose', $messages_url ) ); ?>"
			class="bn-compose-btn"
			data-wp-on--click="actions.openCompose"
		>
			+ <?php esc_html_e( 'New message', 'buddynext' ); ?>
		</a>
	</div>

</div><!-- .bn-msg-grid -->

<?php endif; ?>

<?php buddynext_get_template( 'partials/sidebar.php' ); ?>

</div>
