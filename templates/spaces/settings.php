<?php
/**
 * Template: Space Settings
 *
 * Renders the settings panel for a single space. Only accessible to
 * space admins/moderators or site admins. Includes General, Privacy,
 * Members, Moderation, Integrations, Notifications sections, and a
 * Danger Zone.
 *
 * Expected context var (set by template loader):
 *   $space_id (int) — the current space's primary key.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// ── Resolve space ─────────────────────────────────────────────────────────────

$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( ! $space_id ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Permission gate ───────────────────────────────────────────────────────────

if ( ! buddynext_can( get_current_user_id(), 'buddynext-spaces/manage-settings', array( 'space_id' => $space_id ) ) ) {
	wp_die( esc_html__( 'You do not have permission to manage this space.', 'buddynext' ) );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$space = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT s.*, c.name AS category_name, c.slug AS category_slug
		FROM {$wpdb->prefix}bn_spaces s
		LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id
		WHERE s.id = %d LIMIT 1",
		$space_id
	)
);

if ( ! $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$categories = $wpdb->get_results(
	"SELECT id, name, slug FROM {$wpdb->prefix}bn_space_categories ORDER BY name ASC"
);

// ── Active settings tab ───────────────────────────────────────────────────────

$settings_tab = isset( $_GET['bn_stab'] ) ? sanitize_key( wp_unslash( $_GET['bn_stab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Handle saved settings (POST) ─────────────────────────────────────────────

$save_notice = '';

$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
if ( 'POST' === $request_method && isset( $_POST['bn_space_settings_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_settings_nonce'] ) ), 'bn_space_settings_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		$update_data = array();

		if ( isset( $_POST['space_name'] ) ) {
			$update_data['name'] = sanitize_text_field( wp_unslash( $_POST['space_name'] ) );
		}
		if ( isset( $_POST['space_description'] ) ) {
			$update_data['description'] = sanitize_textarea_field( wp_unslash( $_POST['space_description'] ) );
		}
		if ( isset( $_POST['space_category_id'] ) ) {
			$update_data['category_id'] = absint( $_POST['space_category_id'] );
		}
		if ( isset( $_POST['space_type'] ) && in_array( wp_unslash( $_POST['space_type'] ), array( 'open', 'private', 'secret' ), true ) ) {
			$update_data['type'] = sanitize_key( wp_unslash( $_POST['space_type'] ) );
		}
		if ( isset( $_POST['allow_member_posts'] ) ) {
			update_option( 'bn_space_' . $space_id . '_allow_member_posts', 1 );
		} else {
			update_option( 'bn_space_' . $space_id . '_allow_member_posts', 0 );
		}
		if ( isset( $_POST['require_post_approval'] ) ) {
			update_option( 'bn_space_' . $space_id . '_require_post_approval', 1 );
		} else {
			update_option( 'bn_space_' . $space_id . '_require_post_approval', 0 );
		}
		if ( isset( $_POST['push_to_feed'] ) ) {
			update_option( 'bn_space_' . $space_id . '_push_to_feed', 1 );
		} else {
			update_option( 'bn_space_' . $space_id . '_push_to_feed', 0 );
		}
		if ( isset( $_POST['mvs_media_tab'] ) ) {
			update_option( 'bn_space_' . $space_id . '_mvs_media_tab', 1 );
		} else {
			update_option( 'bn_space_' . $space_id . '_mvs_media_tab', 0 );
		}
		if ( isset( $_POST['jetonomy_forum_id'] ) ) {
			update_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', absint( $_POST['jetonomy_forum_id'] ) );
		}

		if ( ! empty( $update_data ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_spaces',
				$update_data,
				array( 'id' => $space_id ),
				null,
				array( '%d' )
			);
		}

		// Re-fetch fresh space data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space       = $wpdb->get_row( $wpdb->prepare( "SELECT s.*, c.name AS category_name, c.slug AS category_slug FROM {$wpdb->prefix}bn_spaces s LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id WHERE s.id = %d LIMIT 1", $space_id ) );
		$save_notice = 'success';
	}
}

// ── Handle moderation settings POST ──────────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_moderation_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_moderation_nonce'] ) ), 'bn_space_moderation_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		update_option(
			'bn_space_' . $space_id . '_require_post_approval',
			isset( $_POST['require_post_approval'] ) ? 1 : 0
		);

		$raw_banned_words = isset( $_POST['banned_words'] ) ? sanitize_textarea_field( wp_unslash( $_POST['banned_words'] ) ) : '';
		update_option( 'bn_space_' . $space_id . '_banned_words', $raw_banned_words );
		$save_notice = 'success';
	}
}

// ── Handle notifications settings POST ───────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_notifications_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_notifications_nonce'] ) ), 'bn_space_notifications_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		$allowed_prefs = array( 'all', 'mentions', 'none' );
		$pref_value    = isset( $_POST['default_notification_pref'] )
			? sanitize_key( wp_unslash( $_POST['default_notification_pref'] ) )
			: 'all';
		if ( ! in_array( $pref_value, $allowed_prefs, true ) ) {
			$pref_value = 'all';
		}
		update_option( 'bn_space_' . $space_id . '_default_notification_pref', $pref_value );
		$save_notice = 'success';
	}
}

// ── Handle members tab POST actions ──────────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_members_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_members_nonce'] ) ), 'bn_space_members_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		$member_action = isset( $_POST['member_action'] ) ? sanitize_key( wp_unslash( $_POST['member_action'] ) ) : '';
		$target_user   = isset( $_POST['target_user_id'] ) ? absint( $_POST['target_user_id'] ) : 0;

		if ( in_array( $member_action, array( 'promote', 'demote', 'remove', 'ban', 'invite' ), true ) ) {
			$member_service = new \BuddyNext\Spaces\SpaceMemberService();
			$acting_user_id = get_current_user_id();

			if ( $target_user && 'promote' === $member_action ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'bn_space_members',
					array( 'role' => 'moderator' ),
					array(
						'space_id' => $space_id,
						'user_id'  => $target_user,
					),
					array( '%s' ),
					array( '%d', '%d' )
				);
				$save_notice = 'success';
			} elseif ( $target_user && 'demote' === $member_action ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'bn_space_members',
					array( 'role' => 'member' ),
					array(
						'space_id' => $space_id,
						'user_id'  => $target_user,
					),
					array( '%s' ),
					array( '%d', '%d' )
				);
				$save_notice = 'success';
			} elseif ( $target_user && 'remove' === $member_action ) {
				$remove_result = $member_service->remove( $space_id, $target_user, $acting_user_id );
				$save_notice   = ( ! is_wp_error( $remove_result ) ) ? 'success' : 'error';
			} elseif ( $target_user && 'ban' === $member_action ) {
				$ban_result  = $member_service->ban( $space_id, $target_user, $acting_user_id );
				$save_notice = ( ! is_wp_error( $ban_result ) ) ? 'success' : 'error';
			} elseif ( 'invite' === $member_action ) {
				$invite_identifier = isset( $_POST['invite_identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['invite_identifier'] ) ) : '';
				if ( $invite_identifier ) {
					$invite_user = is_email( $invite_identifier )
						? get_user_by( 'email', $invite_identifier )
						: get_user_by( 'login', $invite_identifier );
					if ( $invite_user ) {
						$invite_result = $member_service->invite( $space_id, $invite_user->ID, $acting_user_id );
						$save_notice   = ( ! is_wp_error( $invite_result ) ) ? 'success' : 'error';
					} else {
						$save_notice = 'error';
					}
				}
			}
		}
	}
}

$allow_member_posts    = (bool) get_option( 'bn_space_' . $space_id . '_allow_member_posts', 1 );
$require_post_approval = (bool) get_option( 'bn_space_' . $space_id . '_require_post_approval', 0 );
$push_to_feed          = (bool) get_option( 'bn_space_' . $space_id . '_push_to_feed', 1 );
$mvs_media_tab         = (bool) get_option( 'bn_space_' . $space_id . '_mvs_media_tab', 0 );
$jetonomy_forum_id     = (int) get_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', 0 );

// Moderation options.
$mod_require_approval = (bool) get_option( 'bn_space_' . $space_id . '_require_post_approval', 0 );
$mod_banned_words     = (string) get_option( 'bn_space_' . $space_id . '_banned_words', '' );

// Notifications option.
$default_notification_pref = (string) get_option( 'bn_space_' . $space_id . '_default_notification_pref', 'all' );

// Members list — always fetched so members tab renders without conditional query.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$space_members = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT sm.user_id, sm.role, sm.status, u.display_name, u.user_login, u.user_email
		FROM {$wpdb->prefix}bn_space_members sm
		INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
		WHERE sm.space_id = %d AND sm.status = 'active'
		ORDER BY FIELD(sm.role,'owner','moderator','member'), u.display_name ASC
		LIMIT 200",
		$space_id
	)
);

$space_url     = buddynext_space_url( $space->slug ?? '' );
$settings_base = buddynext_space_settings_url( $space->slug ?? '' );

?>
<style>
<?php /* phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- inline CSS token block */ ?>
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs: 11px; --text-sm: 13px; --text-base: 15px;
	--text-lg: 17px; --text-xl: 20px; --text-2xl: 24px;
	--leading-body: 1.7;
	--bg: #ffffff; --bg-subtle: #f8f8f7; --bg-hover: #f1f1f0;
	--surface: #ffffff; --border: #e8e8e5; --border-soft: #f1f1ee;
	--text-1: #37352f; --text-2: #787774; --text-3: #aeaca8;
	--brand: #0073aa; --brand-light: #e8f4fb; --brand-hover: #005f8e;
	--green: #059669; --green-bg: #ecfdf5;
	--amber: #d97706; --amber-bg: #fffbeb;
	--red: #dc2626; --red-bg: #fef2f2;
	--s1: 4px; --s2: 8px; --s3: 12px; --s4: 16px; --s5: 20px;
	--s6: 24px; --s8: 32px;
	--radius-sm: 6px; --radius: 10px; --radius-lg: 14px;
}
[data-theme="dark"] {
	--bg: #191919; --bg-subtle: #202020; --bg-hover: #2a2a2a;
	--surface: #252525; --border: #333330; --border-soft: #2c2c2a;
	--text-1: #e8e8e6; --text-2: #9b9b97; --text-3: #6b6b67;
	--brand: #4dabdb; --brand-light: #1a2e3a; --brand-hover: #5fbfe8;
}

.bn-settings {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	background: var(--bg-subtle);
	min-height: 100vh;
	padding: var(--s6) var(--s5);
}
.bn-settings__max {
	max-width: 900px;
	margin: 0 auto;
}
.bn-settings__breadcrumb {
	font-size: var(--text-xs);
	color: var(--text-3);
	margin-bottom: var(--s5);
}
.bn-settings__breadcrumb a {
	color: var(--brand);
	text-decoration: none;
}
.bn-settings__breadcrumb a:hover { text-decoration: underline; }

.bn-settings__heading {
	font-family: var(--font-display);
	font-size: var(--text-xl);
	font-weight: 800;
	margin-bottom: var(--s5);
}

.bn-settings__layout {
	display: grid;
	grid-template-columns: 220px 1fr;
	gap: var(--s6);
	align-items: flex-start;
}

/* ── Sidebar nav ── */
.bn-settings-nav {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
	position: sticky;
	top: var(--s6);
}
.bn-settings-nav__item {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: 11px 14px;
	cursor: pointer;
	color: var(--text-1);
	font-size: var(--text-sm);
	font-weight: 500;
	border-left: 3px solid transparent;
	text-decoration: none;
	transition: background 0.1s;
}
.bn-settings-nav__item:hover { background: var(--bg-subtle); }
.bn-settings-nav__item--active {
	background: var(--brand-light);
	color: var(--brand);
	font-weight: 600;
	border-left-color: var(--brand);
}
.bn-settings-nav__item--danger { color: var(--red); }
.bn-settings-nav__item--danger:hover { background: var(--red-bg); }
.bn-settings-nav__divider {
	height: 1px;
	background: var(--border-soft);
}

/* ── Settings panel ── */
.bn-settings-panel {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
}
.bn-settings-section {
	padding: var(--s6);
	border-bottom: 1px solid var(--border-soft);
}
.bn-settings-section:last-child { border-bottom: none; }
.bn-settings-section__title {
	font-family: var(--font-display);
	font-size: var(--text-base);
	font-weight: 700;
	margin-bottom: var(--s1);
}
.bn-settings-section__desc {
	font-size: var(--text-xs);
	color: var(--text-2);
	margin-bottom: var(--s5);
}

/* ── Form fields ── */
.bn-field { margin-bottom: 18px; }
.bn-field:last-child { margin-bottom: 0; }
.bn-field label {
	display: block;
	font-size: var(--text-sm);
	font-weight: 600;
	margin-bottom: var(--s1);
	color: var(--text-1);
}
.bn-field__hint {
	font-size: var(--text-xs);
	color: var(--text-3);
	margin-top: var(--s1);
}
.bn-text-input {
	width: 100%;
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	padding: 9px var(--s3);
	font-size: var(--text-sm);
	font-family: var(--font-body);
	background: var(--surface);
	color: var(--text-1);
}
.bn-text-input:focus {
	outline: none;
	border-color: var(--brand);
	box-shadow: 0 0 0 3px var(--brand-light);
}
textarea.bn-text-input {
	resize: vertical;
	min-height: 80px;
	line-height: var(--leading-body);
}
.bn-select-input {
	width: 100%;
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	padding: 9px var(--s3);
	font-size: var(--text-sm);
	font-family: var(--font-body);
	background: var(--surface);
	color: var(--text-1);
	cursor: pointer;
}
.bn-select-input:focus {
	outline: none;
	border-color: var(--brand);
}

/* ── Avatar upload ── */
.bn-avatar-upload {
	display: flex;
	align-items: center;
	gap: var(--s4);
}
.bn-current-avatar {
	width: 72px;
	height: 72px;
	border-radius: var(--radius-lg);
	background: var(--brand-light);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 28px;
	border: 2px solid var(--border);
	flex-shrink: 0;
}
.bn-upload-btn {
	padding: 8px var(--s4);
	border-radius: var(--radius-sm);
	border: 1.5px solid var(--border);
	background: var(--surface);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
	color: var(--text-1);
}
.bn-upload-btn:hover { border-color: var(--brand); color: var(--brand); }

.bn-cover-upload {
	height: 80px;
	background: var(--brand-light);
	border-radius: var(--radius-sm);
	border: 2px dashed #93c5fd;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	color: var(--brand);
	font-size: var(--text-sm);
	font-weight: 600;
	transition: background 0.15s;
}
.bn-cover-upload:hover { background: #dbeafe; }

/* ── Toggle rows ── */
.bn-toggle-row {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: var(--s4);
	padding: var(--s3) 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-toggle-row:last-child { border-bottom: none; }
.bn-toggle-label { flex: 1; }
.bn-toggle-label__title {
	font-weight: 600;
	font-size: var(--text-sm);
	margin-bottom: 2px;
}
.bn-toggle-label__desc {
	font-size: var(--text-xs);
	color: var(--text-2);
}
.bn-toggle {
	position: relative;
	width: 44px;
	height: 24px;
	flex-shrink: 0;
}
.bn-toggle input[type="checkbox"] {
	opacity: 0;
	width: 0;
	height: 0;
	position: absolute;
}
.bn-toggle__track {
	position: absolute;
	inset: 0;
	background: var(--border);
	border-radius: 12px;
	cursor: pointer;
	transition: background 0.2s;
}
.bn-toggle input:checked ~ .bn-toggle__track { background: var(--brand); }
.bn-toggle__thumb {
	position: absolute;
	width: 18px;
	height: 18px;
	background: #fff;
	border-radius: 50%;
	top: 3px;
	left: 3px;
	transition: left 0.2s;
	box-shadow: 0 1px 4px rgba(0,0,0,0.2);
	pointer-events: none;
}
.bn-toggle input:checked ~ .bn-toggle__thumb { left: 23px; }

/* ── Save row ── */
.bn-save-row {
	display: flex;
	justify-content: flex-end;
	gap: var(--s2);
	padding: var(--s4) var(--s6);
	background: var(--bg-subtle);
	border-top: 1px solid var(--border);
}
.bn-btn-save {
	background: var(--brand);
	color: #fff;
	padding: 9px var(--s6);
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	transition: background 0.15s;
}
.bn-btn-save:hover { background: var(--brand-hover); }
.bn-btn-cancel {
	background: var(--surface);
	color: var(--text-1);
	padding: 9px var(--s4);
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: 1.5px solid var(--border);
	font-family: var(--font-body);
	text-decoration: none;
}
.bn-btn-cancel:hover { border-color: var(--brand); }

/* ── Danger zone ── */
.bn-danger-zone {
	background: var(--surface);
	border: 1px solid #fca5a5;
	border-radius: var(--radius);
	padding: var(--s5);
	margin-top: var(--s4);
}
.bn-danger-zone__title {
	color: var(--red);
	font-weight: 700;
	margin-bottom: var(--s2);
}
.bn-danger-zone__desc {
	font-size: var(--text-sm);
	color: var(--text-2);
	margin-bottom: var(--s3);
}
.bn-btn-danger {
	background: var(--red);
	color: #fff;
	padding: 8px 18px;
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
}
.bn-btn-danger-ghost {
	background: var(--surface);
	color: var(--red);
	padding: 8px 18px;
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: 1.5px solid #fca5a5;
	font-family: var(--font-body);
}

/* ── Notice ── */
.bn-notice {
	padding: var(--s3) var(--s4);
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 600;
	margin-bottom: var(--s5);
}
.bn-notice--success { background: var(--green-bg); color: var(--green); border: 1px solid #6ee7b7; }
.bn-notice--error   { background: var(--red-bg);   color: var(--red);   border: 1px solid #fca5a5; }

/* ── Member list ── */
.bn-member-list { list-style: none; margin: 0; padding: 0; }
.bn-member-row {
	display: flex;
	align-items: center;
	gap: var(--s3);
	padding: var(--s3) 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-member-row:last-child { border-bottom: none; }
.bn-member-avatar {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	flex-shrink: 0;
	background: var(--brand-light);
	border: 1.5px solid var(--border);
	overflow: hidden;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: var(--text-sm);
	font-weight: 700;
	color: var(--brand);
}
.bn-member-avatar img { width: 100%; height: 100%; object-fit: cover; }
.bn-member-info { flex: 1; min-width: 0; }
.bn-member-name {
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--text-1);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.bn-member-meta {
	font-size: var(--text-xs);
	color: var(--text-3);
}
.bn-role-badge {
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	padding: 2px 7px;
	border-radius: 9999px;
	flex-shrink: 0;
}
.bn-role-badge--owner     { background: #fef3c7; color: #92400e; }
.bn-role-badge--moderator { background: var(--brand-light); color: var(--brand); }
.bn-role-badge--member    { background: var(--bg-subtle); color: var(--text-2); }
.bn-member-actions {
	display: flex;
	gap: var(--s1);
	flex-shrink: 0;
	flex-wrap: wrap;
}
.bn-btn-action {
	font-size: 11px;
	font-weight: 600;
	font-family: var(--font-body);
	padding: 4px 10px;
	border-radius: var(--radius-sm);
	cursor: pointer;
	border: 1.5px solid var(--border);
	background: var(--surface);
	color: var(--text-1);
	white-space: nowrap;
}
.bn-btn-action:hover { border-color: var(--brand); color: var(--brand); }
.bn-btn-action--danger { border-color: #fca5a5; color: var(--red); }
.bn-btn-action--danger:hover { background: var(--red-bg); }

/* ── Invite row ── */
.bn-invite-row {
	display: flex;
	gap: var(--s2);
	align-items: flex-end;
	margin-top: var(--s3);
}
.bn-invite-row .bn-text-input { flex: 1; }
.bn-invite-row .bn-btn-save { white-space: nowrap; }

/* ── Responsive ── */
@media (max-width: 1024px) {
	.bn-settings__layout { grid-template-columns: 200px 1fr; }
}
@media (max-width: 640px) {
	.bn-settings { padding: var(--s3); }
	.bn-settings__layout { grid-template-columns: 1fr; }
	.bn-settings-nav { position: static; display: flex; overflow-x: auto; border-radius: var(--radius-sm); }
	.bn-settings-nav__item { padding: var(--s2) var(--s3); border-left: none; border-bottom: 2px solid transparent; white-space: nowrap; }
	.bn-settings-nav__item--active { border-bottom-color: var(--brand); border-left: none; }
	.bn-settings-nav__divider { display: none; }
	.bn-avatar-upload { flex-direction: column; align-items: flex-start; }
	.bn-member-row { flex-wrap: wrap; }
	.bn-member-actions { width: 100%; margin-left: calc(36px + var(--s3)); }
	.bn-invite-row { flex-direction: column; align-items: stretch; }
}
<?php /* phpcs:enable */ ?>
</style>

<div
	class="bn-settings"
	data-wp-interactive="buddynext/spaces"
	data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
>
	<div class="bn-settings__max">

		<nav class="bn-settings__breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'buddynext' ); ?>">
			<a href="<?php echo esc_url( $space_url ); ?>"><?php echo esc_html( $space->name ?? '' ); ?></a>
			&rsaquo; <?php esc_html_e( 'Settings', 'buddynext' ); ?>
		</nav>

		<h1 class="bn-settings__heading">&#x2699;&#xFE0F; <?php esc_html_e( 'Space Settings', 'buddynext' ); ?></h1>

		<?php if ( 'success' === $save_notice ) : ?>
			<div class="bn-notice bn-notice--success" role="alert">
				<?php esc_html_e( 'Changes saved successfully.', 'buddynext' ); ?>
			</div>
		<?php elseif ( 'error' === $save_notice ) : ?>
			<div class="bn-notice bn-notice--error" role="alert">
				<?php esc_html_e( 'Security check failed. Please try again.', 'buddynext' ); ?>
			</div>
		<?php endif; ?>

		<div class="bn-settings__layout">

			<!-- Sidebar nav -->
			<nav class="bn-settings-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'buddynext' ); ?>">
				<?php
				$nav_items = array(
					'general'       => array(
						'icon'  => 'info',
						'label' => __( 'General', 'buddynext' ),
					),
					'privacy'       => array(
						'icon'  => 'lock',
						'label' => __( 'Privacy', 'buddynext' ),
					),
					'members'       => array(
						'icon'  => 'users',
						'label' => __( 'Members', 'buddynext' ),
					),
					'moderation'    => array(
						'icon'  => 'shield',
						'label' => __( 'Moderation', 'buddynext' ),
					),
					'integrations'  => array(
						'icon'  => 'link',
						'label' => __( 'Integrations', 'buddynext' ),
					),
					'notifications' => array(
						'icon'  => 'mail',
						'label' => __( 'Notifications', 'buddynext' ),
					),
				);
				foreach ( $nav_items as $tab_key => $nav_item ) :
					$is_active = ( $settings_tab === $tab_key );
					?>
					<a
						href="<?php echo esc_url( add_query_arg( 'bn_stab', $tab_key, $settings_base ) ); ?>"
						class="bn-settings-nav__item<?php echo $is_active ? ' bn-settings-nav__item--active' : ''; ?>"
						aria-current="<?php echo $is_active ? 'page' : 'false'; ?>"
					>
						<span aria-hidden="true"><?php buddynext_icon( $nav_item['icon'] ); ?></span>
						<?php echo esc_html( $nav_item['label'] ); ?>
					</a>
				<?php endforeach; ?>

				<div class="bn-settings-nav__divider"></div>

				<a
					href="<?php echo esc_url( add_query_arg( 'bn_stab', 'danger', $settings_base ) ); ?>"
					class="bn-settings-nav__item bn-settings-nav__item--danger"
				>
					<span aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
					<?php esc_html_e( 'Danger Zone', 'buddynext' ); ?>
				</a>
			</nav>

			<!-- Settings panel -->
			<div>

				<?php if ( 'members' === $settings_tab ) : ?>

					<div class="bn-settings-panel">
						<div class="bn-settings-section">
							<h2 class="bn-settings-section__title"><?php esc_html_e( 'Members', 'buddynext' ); ?></h2>
							<p class="bn-settings-section__desc">
								<?php
								printf(
									/* translators: %d: number of active members */
									esc_html__( '%d active members', 'buddynext' ),
									count( $space_members )
								);
								?>
							</p>

							<?php if ( empty( $space_members ) ) : ?>
								<p style="font-size:var(--text-sm);color:var(--text-3);">
									<?php esc_html_e( 'No active members yet.', 'buddynext' ); ?>
								</p>
							<?php else : ?>
								<ul class="bn-member-list">
									<?php foreach ( $space_members as $bn_member ) : ?>
										<?php
										$bn_member_avatar_url = get_avatar_url( (int) $bn_member->user_id, array( 'size' => 72 ) );
										$bn_member_initials   = strtoupper( substr( $bn_member->display_name, 0, 1 ) );
										$bn_member_role       = in_array( $bn_member->role, array( 'owner', 'moderator', 'member' ), true )
											? $bn_member->role
											: 'member';
										$bn_is_owner          = ( 'owner' === $bn_member_role );
										?>
										<li class="bn-member-row">
											<div class="bn-member-avatar">
												<?php if ( $bn_member_avatar_url ) : ?>
													<img
														src="<?php echo esc_url( $bn_member_avatar_url ); ?>"
														alt="<?php echo esc_attr( $bn_member->display_name ); ?>"
														width="36"
														height="36"
													>
												<?php else : ?>
													<?php echo esc_html( $bn_member_initials ); ?>
												<?php endif; ?>
											</div>

											<div class="bn-member-info">
												<p class="bn-member-name"><?php echo esc_html( $bn_member->display_name ); ?></p>
												<p class="bn-member-meta">@<?php echo esc_html( $bn_member->user_login ); ?></p>
											</div>

											<span class="bn-role-badge bn-role-badge--<?php echo esc_attr( $bn_member_role ); ?>">
												<?php echo esc_html( ucfirst( $bn_member_role ) ); ?>
											</span>

											<?php if ( ! $bn_is_owner ) : ?>
												<div class="bn-member-actions">
													<?php if ( 'member' === $bn_member_role ) : ?>
														<form method="post" action="">
															<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
															<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $bn_member->user_id ); ?>">
															<input type="hidden" name="member_action" value="promote">
															<button type="submit" class="bn-btn-action">
																<?php esc_html_e( 'Make Mod', 'buddynext' ); ?>
															</button>
														</form>
													<?php elseif ( 'moderator' === $bn_member_role ) : ?>
														<form method="post" action="">
															<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
															<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $bn_member->user_id ); ?>">
															<input type="hidden" name="member_action" value="demote">
															<button type="submit" class="bn-btn-action">
																<?php esc_html_e( 'Remove Mod', 'buddynext' ); ?>
															</button>
														</form>
													<?php endif; ?>

													<form method="post" action="">
														<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
														<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $bn_member->user_id ); ?>">
														<input type="hidden" name="member_action" value="remove">
														<button
															type="submit"
															class="bn-btn-action bn-btn-action--danger"
															onclick="return confirm('<?php echo esc_js( __( 'Remove this member from the space?', 'buddynext' ) ); ?>')"
														>
															<?php esc_html_e( 'Remove', 'buddynext' ); ?>
														</button>
													</form>

													<form method="post" action="">
														<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
														<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $bn_member->user_id ); ?>">
														<input type="hidden" name="member_action" value="ban">
														<button
															type="submit"
															class="bn-btn-action bn-btn-action--danger"
															onclick="return confirm('<?php echo esc_js( __( 'Ban this member? They will not be able to rejoin.', 'buddynext' ) ); ?>')"
														>
															<?php esc_html_e( 'Ban', 'buddynext' ); ?>
														</button>
													</form>
												</div>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>

						<div class="bn-settings-section">
							<h2 class="bn-settings-section__title"><?php esc_html_e( 'Invite Member', 'buddynext' ); ?></h2>
							<p class="bn-settings-section__desc">
								<?php esc_html_e( 'Enter a username or email address to send an invitation.', 'buddynext' ); ?>
							</p>
							<form method="post" action="">
								<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
								<input type="hidden" name="member_action" value="invite">
								<input type="hidden" name="target_user_id" value="0">
								<div class="bn-invite-row">
									<input
										type="text"
										name="invite_identifier"
										class="bn-text-input"
										placeholder="<?php esc_attr_e( 'Username or email address', 'buddynext' ); ?>"
										required
									>
									<button type="submit" class="bn-btn-save">
										<?php esc_html_e( 'Send Invite', 'buddynext' ); ?>
									</button>
								</div>
							</form>
						</div>
					</div>

				<?php elseif ( 'moderation' === $settings_tab ) : ?>

					<form method="post" action="">
						<?php wp_nonce_field( 'bn_space_moderation_' . $space_id, 'bn_space_moderation_nonce' ); ?>

						<div class="bn-settings-panel">
							<div class="bn-settings-section">
								<h2 class="bn-settings-section__title"><?php esc_html_e( 'Moderation', 'buddynext' ); ?></h2>
								<p class="bn-settings-section__desc"><?php esc_html_e( 'Control what members can post in this space.', 'buddynext' ); ?></p>

								<div class="bn-toggle-row">
									<div class="bn-toggle-label">
										<p class="bn-toggle-label__title"><?php esc_html_e( 'Pre-moderate posts', 'buddynext' ); ?></p>
										<p class="bn-toggle-label__desc"><?php esc_html_e( 'New posts from members go to a moderation queue before appearing publicly.', 'buddynext' ); ?></p>
									</div>
									<label class="bn-toggle" aria-label="<?php esc_attr_e( 'Pre-moderate posts', 'buddynext' ); ?>">
										<input type="checkbox" name="require_post_approval" value="1" <?php checked( $mod_require_approval ); ?>>
										<span class="bn-toggle__track"></span>
										<span class="bn-toggle__thumb"></span>
									</label>
								</div>

								<div class="bn-field" style="margin-top:var(--s4);">
									<label for="bn_banned_words"><?php esc_html_e( 'Banned words', 'buddynext' ); ?></label>
									<textarea
										id="bn_banned_words"
										name="banned_words"
										class="bn-text-input"
										rows="6"
										placeholder="<?php esc_attr_e( 'One word or phrase per line', 'buddynext' ); ?>"
									><?php echo esc_textarea( $mod_banned_words ); ?></textarea>
									<p class="bn-field__hint">
										<?php esc_html_e( 'Posts containing these words will be held for review. One word or phrase per line.', 'buddynext' ); ?>
									</p>
								</div>
							</div>

							<div class="bn-save-row">
								<a href="<?php echo esc_url( $space_url ); ?>" class="bn-btn-cancel">
									<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
								</a>
								<button type="submit" class="bn-btn-save">
									<?php esc_html_e( 'Save changes', 'buddynext' ); ?>
								</button>
							</div>
						</div>
					</form>

				<?php elseif ( 'notifications' === $settings_tab ) : ?>

					<form method="post" action="">
						<?php wp_nonce_field( 'bn_space_notifications_' . $space_id, 'bn_space_notifications_nonce' ); ?>

						<div class="bn-settings-panel">
							<div class="bn-settings-section">
								<h2 class="bn-settings-section__title"><?php esc_html_e( 'Notifications', 'buddynext' ); ?></h2>
								<p class="bn-settings-section__desc"><?php esc_html_e( 'Default notification setting applied when a new member joins this space.', 'buddynext' ); ?></p>

								<div class="bn-field">
									<label for="bn_default_notification_pref"><?php esc_html_e( 'Default notification preference for new members', 'buddynext' ); ?></label>
									<select
										id="bn_default_notification_pref"
										name="default_notification_pref"
										class="bn-select-input"
									>
										<option value="all" <?php selected( $default_notification_pref, 'all' ); ?>>
											<?php esc_html_e( 'All activity', 'buddynext' ); ?>
										</option>
										<option value="mentions" <?php selected( $default_notification_pref, 'mentions' ); ?>>
											<?php esc_html_e( 'Mentions only', 'buddynext' ); ?>
										</option>
										<option value="none" <?php selected( $default_notification_pref, 'none' ); ?>>
											<?php esc_html_e( 'None', 'buddynext' ); ?>
										</option>
									</select>
									<p class="bn-field__hint">
										<?php esc_html_e( 'Individual members can override this in their own notification settings.', 'buddynext' ); ?>
									</p>
								</div>
							</div>

							<div class="bn-save-row">
								<a href="<?php echo esc_url( $space_url ); ?>" class="bn-btn-cancel">
									<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
								</a>
								<button type="submit" class="bn-btn-save">
									<?php esc_html_e( 'Save changes', 'buddynext' ); ?>
								</button>
							</div>
						</div>
					</form>

				<?php else : ?>

					<form method="post" action="" enctype="multipart/form-data">
						<?php wp_nonce_field( 'bn_space_settings_' . $space_id, 'bn_space_settings_nonce' ); ?>

						<div class="bn-settings-panel">

							<?php if ( 'general' === $settings_tab ) : ?>

								<div class="bn-settings-section">
									<h2 class="bn-settings-section__title"><?php esc_html_e( 'General', 'buddynext' ); ?></h2>
									<p class="bn-settings-section__desc"><?php esc_html_e( 'Basic information about your space', 'buddynext' ); ?></p>

									<div class="bn-field">
										<label for="bn_space_icon"><?php esc_html_e( 'Space Icon', 'buddynext' ); ?></label>
										<div class="bn-avatar-upload">
											<div class="bn-current-avatar" aria-hidden="true">
												<?php echo wp_kses_data( bn_space_category_icon( $space->category_slug ?? '' ) ); ?>
											</div>
											<div>
												<button type="button" class="bn-upload-btn" id="bn_space_icon">
													<?php esc_html_e( 'Upload image', 'buddynext' ); ?>
												</button>
												<p class="bn-field__hint"><?php esc_html_e( 'Or pick an emoji icon', 'buddynext' ); ?></p>
											</div>
										</div>
									</div>

									<div class="bn-field">
										<label for="bn_cover_image"><?php esc_html_e( 'Cover Image', 'buddynext' ); ?></label>
										<div
											class="bn-cover-upload"
											role="button"
											tabindex="0"
											aria-label="<?php esc_attr_e( 'Upload cover photo', 'buddynext' ); ?>"
										>+ <?php esc_html_e( 'Upload cover photo', 'buddynext' ); ?></div>
										<input type="file" name="cover_image" id="bn_cover_image" accept="image/*" style="display:none;">
									</div>

									<div class="bn-field">
										<label for="space_name"><?php esc_html_e( 'Space Name', 'buddynext' ); ?> <span aria-hidden="true">*</span></label>
										<input
											type="text"
											id="space_name"
											name="space_name"
											class="bn-text-input"
											value="<?php echo esc_attr( $space->name ?? '' ); ?>"
											required
											maxlength="100"
										>
									</div>

									<div class="bn-field">
										<label for="space_description"><?php esc_html_e( 'Description', 'buddynext' ); ?></label>
										<textarea
											id="space_description"
											name="space_description"
											class="bn-text-input"
											maxlength="160"
										><?php echo esc_textarea( $space->description ?? '' ); ?></textarea>
										<p class="bn-field__hint"><?php esc_html_e( '160 characters max. Shown in the spaces directory.', 'buddynext' ); ?></p>
									</div>

									<div class="bn-field">
										<label for="space_category_id"><?php esc_html_e( 'Category', 'buddynext' ); ?></label>
										<select name="space_category_id" id="space_category_id" class="bn-select-input">
											<option value=""><?php esc_html_e( '— Select a category —', 'buddynext' ); ?></option>
											<?php foreach ( $categories as $bn_cat_item ) : ?>
												<option
													value="<?php echo esc_attr( (string) $bn_cat_item->id ); ?>"
													<?php selected( (int) ( $space->category_id ?? 0 ), (int) $bn_cat_item->id ); ?>
												><?php echo esc_html( $bn_cat_item->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>

							<?php elseif ( 'privacy' === $settings_tab ) : ?>

								<div class="bn-settings-section">
									<h2 class="bn-settings-section__title"><?php esc_html_e( 'Privacy', 'buddynext' ); ?></h2>
									<p class="bn-settings-section__desc"><?php esc_html_e( 'Who can see and join your space', 'buddynext' ); ?></p>

									<div class="bn-field">
										<label for="space_type"><?php esc_html_e( 'Space Visibility', 'buddynext' ); ?></label>
										<select name="space_type" id="space_type" class="bn-select-input">
											<option value="open" <?php selected( $space->type, 'open' ); ?>>
												<?php esc_html_e( 'Open — listed in directory, anyone can join', 'buddynext' ); ?>
											</option>
											<option value="private" <?php selected( $space->type, 'private' ); ?>>
												<?php esc_html_e( 'Private — listed but requires approval to join', 'buddynext' ); ?>
											</option>
											<option value="secret" <?php selected( $space->type, 'secret' ); ?>>
												<?php esc_html_e( 'Invite-only — not listed, admin invites only', 'buddynext' ); ?>
											</option>
										</select>
									</div>

									<div class="bn-toggle-row">
										<div class="bn-toggle-label">
											<p class="bn-toggle-label__title"><?php esc_html_e( 'Allow member posts', 'buddynext' ); ?></p>
											<p class="bn-toggle-label__desc"><?php esc_html_e( 'Members can post in the space feed. Disable for announcement-only spaces.', 'buddynext' ); ?></p>
										</div>
										<label class="bn-toggle" aria-label="<?php esc_attr_e( 'Allow member posts', 'buddynext' ); ?>">
											<input type="checkbox" name="allow_member_posts" value="1" <?php checked( $allow_member_posts ); ?>>
											<span class="bn-toggle__track"></span>
											<span class="bn-toggle__thumb"></span>
										</label>
									</div>

									<div class="bn-toggle-row">
										<div class="bn-toggle-label">
											<p class="bn-toggle-label__title"><?php esc_html_e( 'Require post approval', 'buddynext' ); ?></p>
											<p class="bn-toggle-label__desc"><?php esc_html_e( 'New member posts go to moderation queue before appearing publicly.', 'buddynext' ); ?></p>
										</div>
										<label class="bn-toggle" aria-label="<?php esc_attr_e( 'Require post approval', 'buddynext' ); ?>">
											<input type="checkbox" name="require_post_approval" value="1" <?php checked( $require_post_approval ); ?>>
											<span class="bn-toggle__track"></span>
											<span class="bn-toggle__thumb"></span>
										</label>
									</div>
								</div>

							<?php elseif ( 'integrations' === $settings_tab ) : ?>

								<div class="bn-settings-section">
									<h2 class="bn-settings-section__title"><?php esc_html_e( 'Integrations', 'buddynext' ); ?></h2>
									<p class="bn-settings-section__desc"><?php esc_html_e( 'Connect third-party features to this space', 'buddynext' ); ?></p>

									<div class="bn-toggle-row">
										<div class="bn-toggle-label">
											<p class="bn-toggle-label__title">&#x1F4AC; <?php esc_html_e( 'Linked Forum (Jetonomy)', 'buddynext' ); ?></p>
											<p class="bn-toggle-label__desc"><?php esc_html_e( 'Link a Jetonomy forum to show a Forum tab in this space.', 'buddynext' ); ?></p>
											<?php if ( class_exists( 'Jetonomy\Core\Plugin' ) ) : ?>
												<div style="margin-top:var(--s2);">
													<select name="jetonomy_forum_id" class="bn-select-input" style="width:auto;max-width:300px;font-size:var(--text-xs);padding:6px var(--s2);">
														<option value="0" <?php selected( $jetonomy_forum_id, 0 ); ?>><?php esc_html_e( 'No forum linked', 'buddynext' ); ?></option>
													</select>
												</div>
											<?php else : ?>
												<p style="font-size:var(--text-xs);color:var(--text-3);margin-top:var(--s1);">
													<?php esc_html_e( 'Jetonomy is not active on this site.', 'buddynext' ); ?>
												</p>
											<?php endif; ?>
										</div>
									</div>

									<div class="bn-toggle-row">
										<div class="bn-toggle-label">
											<p class="bn-toggle-label__title"><?php esc_html_e( 'Push space posts to activity feed', 'buddynext' ); ?></p>
											<p class="bn-toggle-label__desc"><?php esc_html_e( "Space posts appear in members' home feeds. Off = space-only.", 'buddynext' ); ?></p>
										</div>
										<label class="bn-toggle" aria-label="<?php esc_attr_e( 'Push space posts to activity feed', 'buddynext' ); ?>">
											<input type="checkbox" name="push_to_feed" value="1" <?php checked( $push_to_feed ); ?>>
											<span class="bn-toggle__track"></span>
											<span class="bn-toggle__thumb"></span>
										</label>
									</div>

									<div class="bn-toggle-row">
										<div class="bn-toggle-label">
											<p class="bn-toggle-label__title">&#x1F5BC;&#xFE0F; <?php esc_html_e( 'Media tab (WPMediaVerse)', 'buddynext' ); ?></p>
											<p class="bn-toggle-label__desc"><?php esc_html_e( 'Show a Media tab for uploading and sharing files in this space.', 'buddynext' ); ?></p>
										</div>
										<label class="bn-toggle" aria-label="<?php esc_attr_e( 'Enable Media tab', 'buddynext' ); ?>">
											<input type="checkbox" name="mvs_media_tab" value="1" <?php checked( $mvs_media_tab ); ?>>
											<span class="bn-toggle__track"></span>
											<span class="bn-toggle__thumb"></span>
										</label>
									</div>
								</div>

							<?php else : ?>

								<div class="bn-settings-section">
									<h2 class="bn-settings-section__title">
										<?php echo esc_html( ucfirst( $settings_tab ) ); ?>
									</h2>
									<p class="bn-settings-section__desc">
										<?php esc_html_e( 'This section is coming soon.', 'buddynext' ); ?>
									</p>
								</div>

							<?php endif; ?>

							<div class="bn-save-row">
								<a
									href="<?php echo esc_url( $space_url ); ?>"
									class="bn-btn-cancel"
								><?php esc_html_e( 'Cancel', 'buddynext' ); ?></a>
								<button type="submit" class="bn-btn-save">
									<?php esc_html_e( 'Save changes', 'buddynext' ); ?>
								</button>
							</div>

						</div>
					</form>

				<?php endif; ?>

				<div class="bn-danger-zone">
					<h2 class="bn-danger-zone__title">&#x26A0;&#xFE0F; <?php esc_html_e( 'Danger Zone', 'buddynext' ); ?></h2>
					<p class="bn-danger-zone__desc"><?php esc_html_e( 'These actions are permanent and cannot be undone.', 'buddynext' ); ?></p>
					<div style="display:flex;gap:var(--s2);flex-wrap:wrap;">
						<button
							type="button"
							class="bn-btn-danger"
							data-wp-on--click="actions.archiveSpace"
							data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
						><?php esc_html_e( 'Archive Space', 'buddynext' ); ?></button>
						<button
							type="button"
							class="bn-btn-danger-ghost"
							data-wp-on--click="actions.deleteSpace"
							data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
						><?php esc_html_e( 'Delete Space', 'buddynext' ); ?></button>
					</div>
				</div>

			</div>
		</div>
	</div>
</div>
