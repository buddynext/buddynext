<?php
/**
 * BuddyNext — Edit Profile template.
 *
 * Context variables expected:
 *   $user_id  int  The ID of the profile being edited (always current user or admin).
 *
 * Saves via REST POST buddynext/v1/profile/me (JSON, nonce in X-WP-Nonce header).
 * Cover/avatar upload via REST POST buddynext/v1/profile/avatar.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

// Must be logged in and editing own profile (or admin).
$current_user_id = get_current_user_id();
if ( ! $current_user_id ) {
	wp_safe_redirect( wp_login_url( get_permalink() ) );
	exit;
}

if ( empty( $user_id ) || ! is_int( $user_id ) ) {
	$user_id = $current_user_id;
}

// Only own profile or administrators may edit.
if ( $user_id !== $current_user_id && ! current_user_can( 'edit_users' ) ) {
	wp_die( esc_html__( 'You do not have permission to edit this profile.', 'buddynext' ), 403 );
}

$profile_user = get_userdata( $user_id );
if ( ! $profile_user ) {
	wp_die( esc_html__( 'Profile not found.', 'buddynext' ) );
}

global $wpdb;

$display_name      = $profile_user->display_name;
$profile_login     = $profile_user->user_login;
$profile_email_raw = $profile_user->user_email;

// Avatar initials.
$name_parts = explode( ' ', $display_name );
$initials   = '';
foreach ( array_slice( $name_parts, 0, 2 ) as $part ) {
	$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
}
$initials = $initials ? $initials : mb_strtoupper( mb_substr( $profile_login, 0, 2 ) );

$avatar_url = get_avatar_url( $user_id, array( 'size' => 80 ) );

// Profile meta.
$headline         = (string) get_user_meta( $user_id, 'bn_headline', true );
$bio              = (string) get_user_meta( $user_id, 'bn_bio', true );
$location         = (string) get_user_meta( $user_id, 'bn_location', true );
$website          = (string) get_user_meta( $user_id, 'bn_website', true );
$social_twitter   = (string) get_user_meta( $user_id, 'bn_social_twitter', true );
$social_linkedin  = (string) get_user_meta( $user_id, 'bn_social_linkedin', true );
$social_github    = (string) get_user_meta( $user_id, 'bn_social_github', true );
$social_instagram = (string) get_user_meta( $user_id, 'bn_social_instagram', true );
$interests_raw    = (string) get_user_meta( $user_id, 'bn_interests', true );
$interests        = array_filter( array_map( 'trim', explode( ',', $interests_raw ) ) );

// Stats for preview widget.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$post_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'published'",
		$user_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$follower_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE following_id = %d",
		$user_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$following_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
		$user_id
	)
);

$format_count = static function ( int $n ): string {
	if ( $n >= 1000 ) {
		return round( $n / 1000, 1 ) . 'k';
	}
	return (string) $n;
};

$rest_nonce = wp_create_nonce( 'wp_rest' );
?>
<style>
/* ── Design tokens ─────────────────────────────────────────────────────── */
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
	--green: #34d399; --green-bg: #0d2420;
	--amber: #fbbf24; --amber-bg: #2a2000;
	--red: #f87171; --red-bg: #2d0f0f;
}

/* ── Edit Profile page shell ─────────────────────────────────────────── */
.bn-ep-wrap {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	background: var(--bg-subtle);
	-webkit-font-smoothing: antialiased;
	padding-bottom: 80px;
}

.bn-ep-shell {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--s6) var(--s8);
	display: grid;
	grid-template-columns: 1fr 220px;
	gap: var(--s6);
	align-items: start;
}

/* Page title row — spans both columns */
.bn-ep-title-row {
	grid-column: 1 / -1;
	margin-bottom: var(--s2);
}
.bn-ep-title {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	color: var(--text-1);
	letter-spacing: -0.5px;
	line-height: 1.2;
}
.bn-ep-subtitle {
	font-size: var(--text-sm);
	color: var(--text-3);
	margin-top: 3px;
}

/* Form area */
.bn-ep-form { min-width: 0; }

/* Cover + avatar hero */
.bn-ep-cover-section {
	border-radius: var(--radius-lg);
	overflow: visible;
	position: relative;
	margin-bottom: 48px;
}
.bn-ep-cover-photo {
	width: 100%;
	height: 160px;
	background: linear-gradient(135deg, #0073aa 0%, #5b21b6 50%, #0f766e 100%);
	border-radius: var(--radius-lg);
	display: flex;
	align-items: flex-end;
	justify-content: flex-end;
	position: relative;
}
.bn-ep-cover-btn {
	position: absolute;
	bottom: var(--s3);
	right: var(--s3);
	background: rgba(0,0,0,0.55);
	color: #fff;
	border: none;
	border-radius: var(--radius-sm);
	padding: 6px var(--s3);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
	display: flex;
	align-items: center;
	gap: 5px;
}
.bn-ep-cover-btn:hover { background: rgba(0,0,0,0.72); }

.bn-ep-avatar-overlap {
	position: absolute;
	bottom: -40px;
	left: var(--s6);
	display: flex;
	align-items: flex-end;
	gap: var(--s3);
}
.bn-ep-avatar-wrap { position: relative; }
.bn-ep-avatar {
	width: 80px;
	height: 80px;
	border-radius: 50%;
	background: var(--brand);
	color: #fff;
	font-family: var(--font-display);
	font-size: var(--text-xl);
	font-weight: 800;
	display: flex;
	align-items: center;
	justify-content: center;
	border: 3px solid var(--bg);
	overflow: hidden;
	flex-shrink: 0;
}
.bn-ep-avatar img { width: 100%; height: 100%; object-fit: cover; }
.bn-ep-avatar-btn {
	position: absolute;
	bottom: 2px;
	right: 2px;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background: var(--brand);
	color: #fff;
	border: 2px solid var(--bg);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 11px;
	cursor: pointer;
	font-family: var(--font-body);
}
.bn-ep-avatar-btn:hover { background: var(--brand-hover); }

/* Form cards */
.bn-ep-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	margin-bottom: var(--s4);
	overflow: hidden;
}
.bn-ep-card-header {
	padding: var(--s4) var(--s5);
	border-bottom: 1px solid var(--border-soft);
}
.bn-ep-card-title {
	font-size: var(--text-base);
	font-weight: 700;
	color: var(--text-1);
}
.bn-ep-card-body {
	padding: var(--s5);
	display: flex;
	flex-direction: column;
	gap: var(--s4);
}

/* Form groups */
.bn-ep-group { display: flex; flex-direction: column; gap: 6px; }
.bn-ep-label {
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--text-1);
}
.bn-ep-label-hint {
	font-size: var(--text-xs);
	color: var(--text-3);
	font-weight: 400;
	margin-left: 6px;
}
.bn-ep-input {
	background: var(--bg-subtle);
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	padding: 9px var(--s3);
	font-size: var(--text-base);
	color: var(--text-1);
	font-family: var(--font-body);
	width: 100%;
	transition: border-color 0.15s, background 0.15s;
}
.bn-ep-input:focus {
	outline: none;
	border-color: var(--brand);
	background: var(--bg);
}
.bn-ep-input::placeholder { color: var(--text-3); }
.bn-ep-input[readonly] {
	color: var(--text-3);
	cursor: not-allowed;
	background: var(--bg-hover);
}
textarea.bn-ep-input { resize: vertical; line-height: 1.6; min-height: 90px; }

/* Social links grid */
.bn-ep-social-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: var(--s3);
}
.bn-ep-social-wrap { position: relative; }
.bn-ep-social-icon {
	position: absolute;
	left: 10px;
	top: 50%;
	transform: translateY(-50%);
	font-size: 14px;
	color: var(--text-2);
	line-height: 1;
	pointer-events: none;
}
.bn-ep-social-wrap .bn-ep-input { padding-left: 34px; }

/* Tags / interests area */
.bn-ep-tags-area {
	background: var(--bg-subtle);
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	padding: var(--s2) var(--s3);
	display: flex;
	flex-wrap: wrap;
	gap: var(--s1);
	align-items: center;
	min-height: 44px;
	cursor: text;
	transition: border-color 0.15s;
}
.bn-ep-tags-area:focus-within {
	border-color: var(--brand);
	background: var(--bg);
}
.bn-ep-tag {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: var(--brand-light);
	color: var(--brand);
	border: 1px solid var(--brand);
	border-radius: 12px;
	padding: 2px var(--s2);
	font-size: var(--text-xs);
	font-weight: 600;
}
.bn-ep-tag-remove {
	cursor: pointer;
	opacity: 0.7;
	line-height: 1;
	font-size: 13px;
	background: none;
	border: none;
	color: inherit;
	padding: 0;
	font-family: var(--font-body);
}
.bn-ep-tag-remove:hover { opacity: 1; }
.bn-ep-tag-input {
	border: none;
	background: transparent;
	font-size: var(--text-sm);
	color: var(--text-1);
	font-family: var(--font-body);
	outline: none;
	min-width: 80px;
	flex: 1;
}
.bn-ep-tag-input::placeholder { color: var(--text-3); }

/* Account rows */
.bn-ep-account-rows { padding-top: 0; padding-bottom: 0; gap: 0; }
.bn-ep-account-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--s4);
	padding: var(--s3) 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-ep-account-row:last-child { border-bottom: none; }
.bn-ep-account-label {
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-1);
	white-space: nowrap;
}
.bn-ep-account-value { font-size: var(--text-sm); color: var(--text-3); }
.bn-ep-account-link {
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--brand);
	cursor: pointer;
	text-decoration: none;
	white-space: nowrap;
	flex-shrink: 0;
}
.bn-ep-account-link:hover { color: var(--brand-hover); text-decoration: underline; }

/* Sidebar */
.bn-ep-sidebar {
	display: flex;
	flex-direction: column;
	gap: var(--s4);
	position: sticky;
	top: 120px;
}

.bn-ep-preview-widget {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	overflow: hidden;
}
.bn-ep-preview-header {
	padding: var(--s3) var(--s4);
	border-bottom: 1px solid var(--border-soft);
	font-size: var(--text-xs);
	font-weight: 700;
	color: var(--text-3);
	text-transform: uppercase;
	letter-spacing: 0.06em;
}
.bn-ep-preview-body {
	padding: var(--s4);
	text-align: center;
}
.bn-ep-preview-avatar {
	width: 56px;
	height: 56px;
	border-radius: 50%;
	background: var(--brand);
	color: #fff;
	font-family: var(--font-display);
	font-size: var(--text-lg);
	font-weight: 800;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto var(--s2);
	overflow: hidden;
}
.bn-ep-preview-avatar img { width: 100%; height: 100%; object-fit: cover; }
.bn-ep-preview-name {
	font-size: var(--text-base);
	font-weight: 700;
	color: var(--text-1);
}
.bn-ep-preview-headline {
	font-size: var(--text-xs);
	color: var(--text-2);
	margin-top: 3px;
	line-height: 1.4;
}
.bn-ep-preview-stats {
	display: flex;
	justify-content: center;
	gap: var(--s4);
	margin-top: var(--s4);
	padding-top: var(--s3);
	border-top: 1px solid var(--border-soft);
}
.bn-ep-preview-stat-num {
	font-size: var(--text-base);
	font-weight: 700;
	color: var(--text-1);
	line-height: 1.2;
}
.bn-ep-preview-stat-lbl { font-size: var(--text-xs); color: var(--text-3); }
.bn-ep-preview-note {
	padding: var(--s3) var(--s4);
	font-size: var(--text-xs);
	color: var(--text-3);
	background: var(--bg-subtle);
	border-top: 1px solid var(--border-soft);
	line-height: 1.5;
}

.bn-ep-visibility-widget {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s4);
}
.bn-ep-vis-title {
	font-size: var(--text-xs);
	font-weight: 700;
	color: var(--text-3);
	text-transform: uppercase;
	letter-spacing: 0.06em;
	margin-bottom: var(--s3);
}
.bn-ep-vis-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: 5px 0;
}
.bn-ep-vis-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
}
.bn-ep-vis-dot--public    { background: var(--green); }
.bn-ep-vis-dot--followers { background: var(--brand); }
.bn-ep-vis-dot--private   { background: var(--text-3); }
.bn-ep-vis-label { font-size: var(--text-xs); color: var(--text-2); }
.bn-ep-vis-note {
	margin-top: var(--s3);
	padding-top: var(--s3);
	border-top: 1px solid var(--border-soft);
	font-size: var(--text-xs);
	color: var(--text-3);
	line-height: 1.5;
}

/* Fixed save bar */
.bn-ep-save-bar {
	position: fixed;
	bottom: 0;
	left: 0;
	right: 0;
	background: var(--bg);
	border-top: 1px solid var(--border);
	padding: 0 var(--s8);
	height: 60px;
	display: flex;
	align-items: center;
	z-index: 180;
}
.bn-ep-save-bar-inner {
	max-width: 900px;
	margin: 0 auto;
	width: 100%;
	display: flex;
	align-items: center;
	justify-content: space-between;
}
.bn-ep-save-status {
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--green);
	display: flex;
	align-items: center;
	gap: var(--s1);
}
.bn-ep-save-actions { display: flex; align-items: center; gap: var(--s3); }
.bn-ep-btn-cancel {
	background: var(--bg-hover);
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	padding: 8px var(--s4);
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--text-2);
	cursor: pointer;
	font-family: var(--font-body);
	text-decoration: none;
}
.bn-ep-btn-cancel:hover { color: var(--text-1); }
.bn-ep-btn-save {
	background: var(--brand);
	color: #fff;
	border: none;
	border-radius: var(--radius-sm);
	padding: 9px var(--s5);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
}
.bn-ep-btn-save:hover { background: var(--brand-hover); }
.bn-ep-btn-save[disabled] { opacity: 0.6; cursor: not-allowed; }

/* ── Mobile ── */
@media (max-width: 640px) {
	.bn-ep-shell {
	grid-template-columns: 1fr;
	padding: var(--s4);
	}
	.bn-ep-sidebar { position: static; top: auto; }
	.bn-ep-social-grid { grid-template-columns: 1fr; }
	.bn-ep-save-bar { padding: 0 var(--s4); }
	.bn-ep-save-status { display: none; }
}
</style>

<div class="bn-ep-wrap"
	data-wp-interactive="buddynext/profile"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'userId'    => $user_id,
			'restNonce' => $rest_nonce,
			'saved'     => false,
			'saving'    => false,
			'interests' => array_values( $interests ),
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>

	<div class="bn-ep-shell">

		<!-- Page title -->
		<div class="bn-ep-title-row">
			<div class="bn-ep-title"><?php esc_html_e( 'Edit Profile', 'buddynext' ); ?></div>
			<div class="bn-ep-subtitle"><?php esc_html_e( 'How others see you', 'buddynext' ); ?></div>
		</div>

		<!-- Main form column -->
		<main class="bn-ep-form">

			<!-- Cover + avatar hero -->
			<div class="bn-ep-cover-section">
				<div class="bn-ep-cover-photo">
					<button class="bn-ep-cover-btn"
						type="button"
						data-wp-on--click="actions.triggerCoverUpload">
						&#128247; <?php esc_html_e( 'Change cover', 'buddynext' ); ?>
					</button>
					<div class="bn-ep-avatar-overlap">
						<div class="bn-ep-avatar-wrap">
							<div class="bn-ep-avatar">
								<?php if ( $avatar_url ) : ?>
									<img src="<?php echo esc_url( $avatar_url ); ?>"
										alt="<?php echo esc_attr( $display_name ); ?>" />
								<?php else : ?>
									<?php echo esc_html( $initials ); ?>
								<?php endif; ?>
							</div>
							<button class="bn-ep-avatar-btn"
								type="button"
								title="<?php esc_attr_e( 'Change profile photo', 'buddynext' ); ?>"
								data-wp-on--click="actions.triggerAvatarUpload">
								&#9998;
							</button>
						</div>
					</div>
				</div>
			</div>

			<!-- Section: Basic Info -->
			<div class="bn-ep-card">
				<div class="bn-ep-card-header">
					<div class="bn-ep-card-title"><?php esc_html_e( 'Basic Info', 'buddynext' ); ?></div>
				</div>
				<div class="bn-ep-card-body">
					<div class="bn-ep-group">
						<label class="bn-ep-label" for="bn-ep-name">
							<?php esc_html_e( 'Full Name', 'buddynext' ); ?>
						</label>
						<input class="bn-ep-input"
							type="text"
							id="bn-ep-name"
							name="display_name"
							value="<?php echo esc_attr( $display_name ); ?>"
							placeholder="<?php esc_attr_e( 'Your full name', 'buddynext' ); ?>"
							data-wp-on--blur="actions.autosave" />
					</div>
					<div class="bn-ep-group">
						<label class="bn-ep-label" for="bn-ep-headline">
							<?php esc_html_e( 'Headline', 'buddynext' ); ?>
							<span class="bn-ep-label-hint">
								<?php esc_html_e( 'shown under your name everywhere', 'buddynext' ); ?>
							</span>
						</label>
						<input class="bn-ep-input"
							type="text"
							id="bn-ep-headline"
							name="bn_headline"
							value="<?php echo esc_attr( $headline ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. Software Engineer at Acme Co.', 'buddynext' ); ?>"
							data-wp-on--blur="actions.autosave" />
					</div>
					<div class="bn-ep-group">
						<label class="bn-ep-label" for="bn-ep-location">
							<?php esc_html_e( 'Location', 'buddynext' ); ?>
						</label>
						<input class="bn-ep-input"
							type="text"
							id="bn-ep-location"
							name="bn_location"
							value="<?php echo esc_attr( $location ); ?>"
							placeholder="<?php esc_attr_e( 'City, Country', 'buddynext' ); ?>"
							data-wp-on--blur="actions.autosave" />
					</div>
					<div class="bn-ep-group">
						<label class="bn-ep-label" for="bn-ep-website">
							<?php esc_html_e( 'Website', 'buddynext' ); ?>
						</label>
						<input class="bn-ep-input"
							type="url"
							id="bn-ep-website"
							name="bn_website"
							value="<?php echo esc_attr( $website ); ?>"
							placeholder="https://yoursite.com"
							data-wp-on--blur="actions.autosave" />
					</div>
					<div class="bn-ep-group">
						<label class="bn-ep-label" for="bn-ep-bio">
							<?php esc_html_e( 'Bio', 'buddynext' ); ?>
							<span class="bn-ep-label-hint">
								<?php esc_html_e( 'about yourself in a few words', 'buddynext' ); ?>
							</span>
						</label>
						<textarea class="bn-ep-input"
							id="bn-ep-bio"
							name="bn_bio"
							rows="4"
							placeholder="<?php esc_attr_e( 'Tell the community a bit about yourself\xe2\x80\xa6', 'buddynext' ); ?>"
							data-wp-on--blur="actions.autosave"><?php echo esc_textarea( $bio ); ?></textarea>
					</div>
				</div>
			</div><!-- /Basic Info -->

			<!-- Section: Social Links -->
			<div class="bn-ep-card">
				<div class="bn-ep-card-header">
					<div class="bn-ep-card-title"><?php esc_html_e( 'Social Links', 'buddynext' ); ?></div>
				</div>
				<div class="bn-ep-card-body">
					<div class="bn-ep-social-grid">
						<div class="bn-ep-group">
							<label class="bn-ep-label" for="bn-ep-twitter">
								<?php esc_html_e( 'Twitter / X', 'buddynext' ); ?>
							</label>
							<div class="bn-ep-social-wrap">
								<span class="bn-ep-social-icon" aria-hidden="true">&#120143;</span>
								<input class="bn-ep-input"
									type="url"
									id="bn-ep-twitter"
									name="bn_social_twitter"
									value="<?php echo esc_attr( $social_twitter ); ?>"
									placeholder="https://twitter.com/you"
									data-wp-on--blur="actions.autosave" />
							</div>
						</div>
						<div class="bn-ep-group">
							<label class="bn-ep-label" for="bn-ep-linkedin">
								<?php esc_html_e( 'LinkedIn', 'buddynext' ); ?>
							</label>
							<div class="bn-ep-social-wrap">
								<span class="bn-ep-social-icon" aria-hidden="true">in</span>
								<input class="bn-ep-input"
									type="url"
									id="bn-ep-linkedin"
									name="bn_social_linkedin"
									value="<?php echo esc_attr( $social_linkedin ); ?>"
									placeholder="https://linkedin.com/in/you"
									data-wp-on--blur="actions.autosave" />
							</div>
						</div>
						<div class="bn-ep-group">
							<label class="bn-ep-label" for="bn-ep-github">
								<?php esc_html_e( 'GitHub', 'buddynext' ); ?>
							</label>
							<div class="bn-ep-social-wrap">
								<span class="bn-ep-social-icon" aria-hidden="true">&#9997;</span>
								<input class="bn-ep-input"
									type="url"
									id="bn-ep-github"
									name="bn_social_github"
									value="<?php echo esc_attr( $social_github ); ?>"
									placeholder="https://github.com/you"
									data-wp-on--blur="actions.autosave" />
							</div>
						</div>
						<div class="bn-ep-group">
							<label class="bn-ep-label" for="bn-ep-instagram">
								<?php esc_html_e( 'Instagram', 'buddynext' ); ?>
							</label>
							<div class="bn-ep-social-wrap">
								<span class="bn-ep-social-icon" aria-hidden="true">&#9678;</span>
								<input class="bn-ep-input"
									type="url"
									id="bn-ep-instagram"
									name="bn_social_instagram"
									value="<?php echo esc_attr( $social_instagram ); ?>"
									placeholder="https://instagram.com/you"
									data-wp-on--blur="actions.autosave" />
							</div>
						</div>
					</div>
				</div>
			</div><!-- /Social Links -->

			<!-- Section: Community Interests -->
			<div class="bn-ep-card">
				<div class="bn-ep-card-header">
					<div class="bn-ep-card-title"><?php esc_html_e( 'Community Interests', 'buddynext' ); ?></div>
				</div>
				<div class="bn-ep-card-body">
					<div class="bn-ep-group">
						<label class="bn-ep-label">
							<?php esc_html_e( 'Interests', 'buddynext' ); ?>
							<span class="bn-ep-label-hint">
								<?php esc_html_e( 'used for personalised feed &amp; discovery', 'buddynext' ); ?>
							</span>
						</label>
						<div class="bn-ep-tags-area"
							data-wp-on--click="actions.focusTagInput">
							<?php foreach ( $interests as $interest ) : ?>
								<span class="bn-ep-tag">
									#<?php echo esc_html( $interest ); ?>
									<button class="bn-ep-tag-remove"
										type="button"
										<?php
										/* translators: %s: interest tag name */
										$remove_label = sprintf( __( 'Remove interest: %s', 'buddynext' ), $interest );
										?>
									aria-label="<?php echo esc_attr( $remove_label ); ?>"
										data-interest="<?php echo esc_attr( $interest ); ?>"
										data-wp-on--click="actions.removeInterest">
										&times;
									</button>
								</span>
							<?php endforeach; ?>
							<input class="bn-ep-tag-input"
								type="text"
								id="bn-ep-tag-input"
								autocomplete="off"
								placeholder="<?php esc_attr_e( '+ Add interest', 'buddynext' ); ?>"
								data-wp-on--keydown="actions.addInterestOnEnter" />
						</div>
					</div>
				</div>
			</div><!-- /Interests -->

			<!-- Section: Account -->
			<div class="bn-ep-card">
				<div class="bn-ep-card-header">
					<div class="bn-ep-card-title"><?php esc_html_e( 'Account', 'buddynext' ); ?></div>
				</div>
				<div class="bn-ep-card-body bn-ep-account-rows">
					<div class="bn-ep-account-row">
						<div>
							<div class="bn-ep-account-label"><?php esc_html_e( 'Email address', 'buddynext' ); ?></div>
							<div class="bn-ep-account-value"><?php echo esc_html( $profile_email_raw ); ?></div>
						</div>
						<input class="bn-ep-input"
							type="email"
							readonly
							value="<?php echo esc_attr( $profile_email_raw ); ?>"
							style="max-width:240px;"
							aria-label="<?php esc_attr_e( 'Current email address (read-only)', 'buddynext' ); ?>" />
					</div>
					<div class="bn-ep-account-row">
						<div class="bn-ep-account-label"><?php esc_html_e( 'Password', 'buddynext' ); ?></div>
						<a class="bn-ep-account-link"
							href="<?php echo esc_url( wp_lostpassword_url() ); ?>">
							<?php esc_html_e( 'Change password', 'buddynext' ); ?> &rarr;
						</a>
					</div>
					<div class="bn-ep-account-row">
						<div class="bn-ep-account-label"><?php esc_html_e( 'Notification preferences', 'buddynext' ); ?></div>
						<a class="bn-ep-account-link"
							href="<?php echo esc_url( home_url( '/settings/notifications/' ) ); ?>">
							<?php esc_html_e( 'Manage notifications', 'buddynext' ); ?> &rarr;
						</a>
					</div>
				</div>
			</div><!-- /Account -->

		</main><!-- /form area -->

		<!-- Sidebar -->
		<aside class="bn-ep-sidebar">

			<!-- Profile preview -->
			<div class="bn-ep-preview-widget">
				<div class="bn-ep-preview-header">
					<?php esc_html_e( 'Profile Preview', 'buddynext' ); ?>
				</div>
				<div class="bn-ep-preview-body">
					<div class="bn-ep-preview-avatar">
						<?php if ( $avatar_url ) : ?>
							<img src="<?php echo esc_url( $avatar_url ); ?>"
								alt="<?php echo esc_attr( $display_name ); ?>" />
						<?php else : ?>
							<?php echo esc_html( $initials ); ?>
						<?php endif; ?>
					</div>
					<div class="bn-ep-preview-name"><?php echo esc_html( $display_name ); ?></div>
					<div class="bn-ep-preview-headline">
						<?php echo esc_html( $headline ? $headline : $location ); ?>
					</div>
					<div class="bn-ep-preview-stats">
						<div>
							<div class="bn-ep-preview-stat-num"><?php echo esc_html( $format_count( $post_count ) ); ?></div>
							<div class="bn-ep-preview-stat-lbl"><?php esc_html_e( 'Posts', 'buddynext' ); ?></div>
						</div>
						<div>
							<div class="bn-ep-preview-stat-num"><?php echo esc_html( $format_count( $follower_count ) ); ?></div>
							<div class="bn-ep-preview-stat-lbl"><?php esc_html_e( 'Followers', 'buddynext' ); ?></div>
						</div>
						<div>
							<div class="bn-ep-preview-stat-num"><?php echo esc_html( $format_count( $following_count ) ); ?></div>
							<div class="bn-ep-preview-stat-lbl"><?php esc_html_e( 'Following', 'buddynext' ); ?></div>
						</div>
					</div>
				</div>
				<div class="bn-ep-preview-note">
					<?php esc_html_e( 'This is how other members see your profile card across the community.', 'buddynext' ); ?>
				</div>
			</div>

			<!-- Field visibility guide -->
			<div class="bn-ep-visibility-widget">
				<div class="bn-ep-vis-title"><?php esc_html_e( 'Field Visibility', 'buddynext' ); ?></div>
				<div class="bn-ep-vis-row">
					<div class="bn-ep-vis-dot bn-ep-vis-dot--public"></div>
					<div class="bn-ep-vis-label">
						<strong><?php esc_html_e( 'Public', 'buddynext' ); ?></strong>
						&mdash; <?php esc_html_e( 'visible to everyone', 'buddynext' ); ?>
					</div>
				</div>
				<div class="bn-ep-vis-row">
					<div class="bn-ep-vis-dot bn-ep-vis-dot--followers"></div>
					<div class="bn-ep-vis-label">
						<strong><?php esc_html_e( 'Followers', 'buddynext' ); ?></strong>
						&mdash; <?php esc_html_e( 'logged-in followers only', 'buddynext' ); ?>
					</div>
				</div>
				<div class="bn-ep-vis-row">
					<div class="bn-ep-vis-dot bn-ep-vis-dot--private"></div>
					<div class="bn-ep-vis-label">
						<strong><?php esc_html_e( 'Private', 'buddynext' ); ?></strong>
						&mdash; <?php esc_html_e( 'only you can see', 'buddynext' ); ?>
					</div>
				</div>
				<div class="bn-ep-vis-note">
					<?php esc_html_e( 'Each field has its own visibility control — available in the full field editor.', 'buddynext' ); ?>
				</div>
			</div>

		</aside><!-- /sidebar -->

	</div><!-- /bn-ep-shell -->

	<!-- Fixed save bar -->
	<div class="bn-ep-save-bar">
		<div class="bn-ep-save-bar-inner">
			<div class="bn-ep-save-status"
				data-wp-bind--hidden="!context.saved">
				&#10003; <?php esc_html_e( 'All changes saved', 'buddynext' ); ?>
			</div>
			<div class="bn-ep-save-actions">
				<a class="bn-ep-btn-cancel"
					href="<?php echo esc_url( home_url( '/members/' . $profile_login . '/profile/' ) ); ?>">
					<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
				</a>
				<button class="bn-ep-btn-save"
					type="button"
					data-wp-on--click="actions.saveProfile"
					data-wp-bind--disabled="context.saving">
					<?php esc_html_e( 'Save Changes', 'buddynext' ); ?>
				</button>
			</div>
		</div>
	</div>

</div><!-- /bn-ep-wrap -->
