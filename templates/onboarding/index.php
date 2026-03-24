<?php
/**
 * BuddyNext — Onboarding Wizard template.
 *
 * Multi-step wizard shown to new users after registration (once).
 * Steps: 1 Profile → 2 Interests → 3 Spaces → 4 People to Follow
 *
 * Each step auto-saves via REST POST buddynext/v1/onboarding/step.
 * Step tracking uses the WP Interactivity API store (no page reload).
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

// Must be logged in.
$ob_user_id = get_current_user_id();
if ( ! $ob_user_id ) {
	wp_safe_redirect( wp_login_url( get_permalink() ) );
	exit;
}

$ob_user = get_userdata( $ob_user_id );
if ( ! $ob_user ) {
	wp_die( esc_html__( 'User not found.', 'buddynext' ) );
}

// Already completed onboarding?
$onboarding_done = (bool) get_user_meta( $ob_user_id, 'bn_onboarding_completed', true );
if ( $onboarding_done && ! isset( $_GET['redo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( home_url( '/feed/' ) );
	exit;
}

global $wpdb;

$display_name  = $ob_user->display_name;
$current_login = $ob_user->user_login;

$name_parts = explode( ' ', $display_name );
$initials   = '';
foreach ( array_slice( $name_parts, 0, 2 ) as $part ) {
	$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
}
$initials = ! empty( $initials ) ? $initials : mb_strtoupper( mb_substr( $current_login, 0, 2 ) );

$avatar_url = get_avatar_url( $ob_user_id, array( 'size' => 100 ) );
$bio        = (string) get_user_meta( $ob_user_id, 'bn_bio', true );
$location   = (string) get_user_meta( $ob_user_id, 'bn_location', true );
$saved_step = max( 1, (int) get_user_meta( $ob_user_id, 'bn_onboarding_step', true ) );

// Interests available for selection (step 2).
$all_interests = array(
	array(
		'icon'  => 'code',
		'label' => 'Web Dev',
	),
	array(
		'icon'  => 'palette',
		'label' => 'Design',
	),
	array(
		'icon'  => 'cpu',
		'label' => 'AI & ML',
	),
	array(
		'icon'  => 'rocket',
		'label' => 'Startups',
	),
	array(
		'icon'  => 'megaphone',
		'label' => 'Marketing',
	),
	array(
		'icon'  => 'bar-chart',
		'label' => 'Data',
	),
	array(
		'icon'  => 'target',
		'label' => 'Product',
	),
	array(
		'icon'  => 'edit',
		'label' => 'Writing',
	),
	array(
		'icon'  => 'globe',
		'label' => 'Open Source',
	),
	array(
		'icon'  => 'gamepad',
		'label' => 'Gaming',
	),
	array(
		'icon'  => 'music',
		'label' => 'Music',
	),
	array(
		'icon'  => 'camera',
		'label' => 'Photography',
	),
);

$saved_interests_raw = (string) get_user_meta( $ob_user_id, 'bn_interests', true );
$saved_interests     = array_filter( array_map( 'trim', explode( ',', $saved_interests_raw ) ) );

// Recommended spaces (step 3) — pull from bn_spaces.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
$recommended_spaces = $wpdb->get_results( "SELECT id, name, member_count, description FROM {$wpdb->prefix}bn_spaces ORDER BY member_count DESC LIMIT 6" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$joined_space_ids_raw = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT space_id FROM {$wpdb->prefix}bn_space_members WHERE user_id = %d AND status = 'active'",
		$ob_user_id
	)
);
$joined_space_ids     = array_map( 'intval', $joined_space_ids_raw );

// Suggested people to follow (step 4).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$suggested_users = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT u.ID, u.display_name, u.user_login,
		        um.meta_value AS headline,
		        ( SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows f2
		          WHERE f2.following_id = u.ID ) AS follower_count
		FROM {$wpdb->users} u
		LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = 'bn_headline'
		WHERE u.ID != %d
		ORDER BY follower_count DESC
		LIMIT 5",
		$ob_user_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$already_following = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT following_id FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
		$ob_user_id
	)
);
$already_following = array_map( 'intval', $already_following );

$rest_nonce = wp_create_nonce( 'wp_rest' );

// Step config (label, icon).
$steps       = array(
	1 => array(
		'label' => __( 'Profile', 'buddynext' ),
		'icon'  => 'user',
	),
	2 => array(
		'label' => __( 'Interests', 'buddynext' ),
		'icon'  => 'target',
	),
	3 => array(
		'label' => __( 'Spaces', 'buddynext' ),
		'icon'  => 'building',
	),
	4 => array(
		'label' => __( 'People', 'buddynext' ),
		'icon'  => 'users',
	),
);
$total_steps = count( $steps );
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

/* ── Onboarding shell ───────────────────────────────────────────────── */
.bn-ob-wrap {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	background: var(--bg-subtle);
	-webkit-font-smoothing: antialiased;
	min-height: 100vh;
	padding-bottom: var(--s8);
}

.bn-ob-wizard {
	max-width: 540px;
	margin: 0 auto;
	padding: var(--s6) var(--s5) var(--s8);
}

/* Progress bar */
.bn-ob-progress-bar {
	height: 6px;
	background: var(--border);
	border-radius: 4px;
	margin-bottom: var(--s8);
	overflow: hidden;
}
.bn-ob-progress-fill {
	height: 100%;
	border-radius: 4px;
	background: var(--brand);
	transition: width 0.4s ease;
}

/* Step indicator row */
.bn-ob-steps-row {
	display: flex;
	justify-content: center;
	align-items: flex-start;
	gap: 0;
	margin-bottom: var(--s8);
}
.bn-ob-step-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
}
.bn-ob-step-connector {
	width: 60px;
	height: 2px;
	background: var(--border);
	margin-top: 15px;
	flex-shrink: 0;
	transition: background 0.3s;
}
.bn-ob-step-connector.done { background: var(--green); }
.bn-ob-step-dot {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: var(--text-xs);
	font-weight: 700;
	border: 2px solid var(--border);
	background: var(--surface);
	color: var(--text-2);
	transition: background 0.2s, border-color 0.2s, color 0.2s;
}
.bn-ob-step-dot.done    { background: var(--green); border-color: var(--green); color: #fff; }
.bn-ob-step-dot.active  { background: var(--brand); border-color: var(--brand); color: #fff; }
.bn-ob-step-label {
	font-size: 10px;
	color: var(--text-3);
	text-align: center;
	font-weight: 600;
}

/* Step header */
.bn-ob-step-header {
	text-align: center;
	margin-bottom: var(--s8);
}
.bn-ob-step-icon { width: 40px; height: 40px; margin-bottom: var(--s3); color: var(--brand); }
.bn-ob-step-icon svg { width: 100%; height: 100%; display: block; }
.bn-ob-step-title {
	font-family: var(--font-display);
	font-size: 22px;
	font-weight: 800;
	color: var(--text-1);
	margin-bottom: var(--s2);
}
.bn-ob-step-sub { font-size: var(--text-sm); color: var(--text-2); }

/* Step content card */
.bn-ob-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s8);
	box-shadow: 0 4px 16px rgba(0,0,0,0.05);
}

/* Step panels */
.bn-ob-step-panel { display: none; }
.bn-ob-step-panel.active { display: block; }

/* Avatar upload (step 1) */
.bn-ob-avatar-area { text-align: center; margin-bottom: var(--s6); }
.bn-ob-avatar-btn {
	width: 100px;
	height: 100px;
	border-radius: 50%;
	background: var(--brand);
	color: #fff;
	font-family: var(--font-display);
	font-size: 30px;
	font-weight: 800;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto var(--s2);
	position: relative;
	cursor: pointer;
	border: none;
	overflow: hidden;
}
.bn-ob-avatar-btn img { width: 100%; height: 100%; object-fit: cover; }
.bn-ob-avatar-edit {
	position: absolute;
	bottom: 0;
	right: 0;
	width: 28px;
	height: 28px;
	background: var(--surface);
	border-radius: 50%;
	border: 2px solid var(--border);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	color: var(--text-2);
}
.bn-ob-avatar-hint { font-size: var(--text-xs); color: var(--text-2); }

/* Form fields (step 1) */
.bn-ob-field { margin-bottom: var(--s5); }
.bn-ob-field:last-child { margin-bottom: 0; }
.bn-ob-label {
	display: block;
	font-weight: 600;
	font-size: var(--text-sm);
	margin-bottom: 6px;
	color: var(--text-1);
}
.bn-ob-label-hint {
	font-size: var(--text-xs);
	color: var(--text-3);
	font-weight: 400;
	margin-left: 6px;
}
.bn-ob-input {
	width: 100%;
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	padding: 10px var(--s3);
	font-size: var(--text-base);
	font-family: var(--font-body);
	color: var(--text-1);
	background: var(--bg);
	transition: border-color 0.15s;
}
.bn-ob-input:focus {
	outline: none;
	border-color: var(--brand);
}
.bn-ob-input::placeholder { color: var(--text-3); }
textarea.bn-ob-input { resize: none; min-height: 80px; }
.bn-ob-username-hint {
	font-size: var(--text-xs);
	margin-top: 4px;
	font-weight: 600;
}
.bn-ob-username-hint.available { color: var(--green); }
.bn-ob-username-hint.taken { color: var(--red); }

/* Interest chips (step 2) */
.bn-ob-chips-grid { display: flex; flex-wrap: wrap; gap: var(--s2); }
.bn-ob-chip {
	padding: 8px var(--s4);
	border-radius: 20px;
	border: 2px solid var(--border);
	background: var(--surface);
	font-size: var(--text-sm);
	font-weight: 500;
	cursor: pointer;
	display: flex;
	align-items: center;
	gap: 6px;
	transition: border-color 0.15s, background 0.15s, color 0.15s;
	color: var(--text-1);
	user-select: none;
}
.bn-ob-chip:hover { border-color: var(--brand); }
.bn-ob-chip.selected {
	background: var(--brand-light);
	border-color: var(--brand);
	color: var(--brand);
	font-weight: 600;
}
.bn-ob-chips-count {
	font-size: var(--text-xs);
	color: var(--brand);
	margin-top: var(--s3);
	font-weight: 600;
}

/* Spaces grid (step 3) */
.bn-ob-spaces-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: var(--s2);
}
.bn-ob-space-opt {
	border: 2px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s3);
	cursor: pointer;
	display: flex;
	align-items: center;
	gap: var(--s2);
	transition: border-color 0.15s, background 0.15s;
	background: var(--surface);
	color: var(--text-1);
}
.bn-ob-space-opt:hover { border-color: var(--brand); }
.bn-ob-space-opt.joined {
	border-color: var(--brand);
	background: var(--brand-light);
}
.bn-ob-space-icon {
	width: 36px;
	height: 36px;
	border-radius: var(--radius-sm);
	background: var(--brand-light);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 18px;
	flex-shrink: 0;
}
.bn-ob-space-name { font-weight: 600; font-size: var(--text-sm); }
.bn-ob-space-members { font-size: var(--text-xs); color: var(--text-2); }

/* People to follow (step 4) */
.bn-ob-follow-person {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: var(--s2) 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-ob-follow-person:last-child { border-bottom: none; }
.bn-ob-follow-av {
	width: 38px;
	height: 38px;
	border-radius: 50%;
	background: var(--brand);
	color: #fff;
	font-family: var(--font-display);
	font-size: var(--text-xs);
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	overflow: hidden;
}
.bn-ob-follow-av img { width: 100%; height: 100%; object-fit: cover; }
.bn-ob-follow-info { flex: 1; min-width: 0; }
.bn-ob-follow-name {
	font-weight: 600;
	font-size: var(--text-sm);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.bn-ob-follow-meta { font-size: var(--text-xs); color: var(--text-2); }
.bn-ob-btn-follow {
	padding: 6px var(--s4);
	border-radius: 14px;
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: 1.5px solid var(--brand);
	color: var(--brand);
	background: var(--surface);
	font-family: var(--font-body);
	flex-shrink: 0;
	transition: background 0.15s, color 0.15s;
}
.bn-ob-btn-follow:hover { background: var(--brand-light); }
.bn-ob-btn-follow.following {
	background: var(--brand);
	color: #fff;
	border-color: var(--brand);
}

/* Actions row */
.bn-ob-actions {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: var(--s6);
}
.bn-ob-btn-next {
	background: var(--brand);
	color: #fff;
	padding: 12px var(--s8);
	border-radius: 24px;
	font-size: var(--text-sm);
	font-weight: 700;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	transition: background 0.15s;
}
.bn-ob-btn-next:hover { background: var(--brand-hover); }
.bn-ob-btn-skip {
	color: var(--text-3);
	font-size: var(--text-sm);
	cursor: pointer;
	font-weight: 500;
	background: none;
	border: none;
	font-family: var(--font-body);
	padding: 0;
}
.bn-ob-btn-skip:hover { color: var(--text-2); }
.bn-ob-btn-back {
	color: var(--text-1);
	font-size: var(--text-sm);
	cursor: pointer;
	font-weight: 500;
	display: flex;
	align-items: center;
	gap: 4px;
	background: none;
	border: none;
	font-family: var(--font-body);
	padding: 0;
}
.bn-ob-btn-back:hover { color: var(--brand); }

/* ── Mobile ── */
@media (max-width: 640px) {
	.bn-ob-wizard { padding: var(--s4); }
	.bn-ob-card { padding: var(--s5); }
	.bn-ob-spaces-grid { grid-template-columns: 1fr; }
	.bn-ob-step-connector { width: 32px; }
}
</style>

<div class="bn-ob-wrap"
	data-wp-interactive="buddynext/onboarding"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'step'              => $saved_step,
			'totalSteps'        => $total_steps,
			'interests'         => array_values( $saved_interests ),
			'joinedSpaces'      => $joined_space_ids,
			'followingUsers'    => $already_following,
			'restNonce'         => $rest_nonce,
			'displayName'       => $display_name,
			'userLogin'         => $current_login,
			'usernameAvailable' => true,
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>

	<div class="bn-ob-wizard">

		<!-- Progress bar -->
		<div class="bn-ob-progress-bar">
			<div class="bn-ob-progress-fill"
				style="width: <?php echo esc_attr( (string) ( ( $saved_step / $total_steps ) * 100 ) ); ?>%;"
				data-wp-style--width="state.progressPct + '%'"></div>
		</div>

		<!-- Step indicator dots -->
		<div class="bn-ob-steps-row" aria-label="<?php esc_attr_e( 'Onboarding progress', 'buddynext' ); ?>">
			<?php foreach ( $steps as $step_num => $step_info ) : ?>
				<div class="bn-ob-step-item">
					<div class="bn-ob-step-dot <?php echo $step_num < $saved_step ? 'done' : ( $step_num === $saved_step ? 'active' : '' ); ?>"
						aria-label="<?php echo esc_attr( $step_info['label'] ); ?>">
						<?php echo $step_num < $saved_step ? '&#10003;' : esc_html( (string) $step_num ); ?>
					</div>
					<div class="bn-ob-step-label"><?php echo esc_html( $step_info['label'] ); ?></div>
				</div>
				<?php if ( $step_num < $total_steps ) : ?>
					<div class="bn-ob-step-connector <?php echo $step_num < $saved_step ? 'done' : ''; ?>"></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>

		<!-- ── Step 1: Profile ── -->
		<div class="bn-ob-step-panel <?php echo 1 === $saved_step ? 'active' : ''; ?>"
			id="bn-ob-step-1"
			data-step="1">

			<div class="bn-ob-step-header">
				<div class="bn-ob-step-icon"><?php buddynext_icon( 'user' ); ?></div>
				<div class="bn-ob-step-title"><?php esc_html_e( 'Set up your profile', 'buddynext' ); ?></div>
				<div class="bn-ob-step-sub"><?php esc_html_e( 'Help others discover you. You can change this any time.', 'buddynext' ); ?></div>
			</div>

			<div class="bn-ob-card">
				<!-- Avatar upload -->
				<div class="bn-ob-avatar-area">
					<button class="bn-ob-avatar-btn"
						type="button"
						aria-label="<?php esc_attr_e( 'Upload profile photo', 'buddynext' ); ?>"
						data-wp-on--click="actions.triggerAvatarUpload">
						<?php if ( $avatar_url ) : ?>
							<img src="<?php echo esc_attr( $avatar_url ); ?>"
								alt="<?php echo esc_attr( $display_name ); ?>" />
						<?php else : ?>
							<?php echo esc_html( $initials ); ?>
						<?php endif; ?>
						<span class="bn-ob-avatar-edit" aria-hidden="true">&#9998;</span>
					</button>
					<div class="bn-ob-avatar-hint"><?php esc_html_e( 'Tap to upload photo', 'buddynext' ); ?></div>
				</div>

				<div class="bn-ob-field">
					<label class="bn-ob-label" for="bn-ob-displayname">
						<?php esc_html_e( 'Display Name', 'buddynext' ); ?>
					</label>
					<input class="bn-ob-input"
						type="text"
						id="bn-ob-displayname"
						name="display_name"
						value="<?php echo esc_attr( $display_name ); ?>"
						placeholder="<?php esc_attr_e( 'Your full name', 'buddynext' ); ?>" />
				</div>

				<div class="bn-ob-field">
					<label class="bn-ob-label" for="bn-ob-username">
						<?php esc_html_e( 'Username', 'buddynext' ); ?>
					</label>
					<input class="bn-ob-input"
						type="text"
						id="bn-ob-username"
						name="user_login"
						value="<?php echo esc_attr( $current_login ); ?>"
						placeholder="@username"
						data-wp-on--input="actions.checkUsername" />
					<div class="bn-ob-username-hint available"
						data-wp-text="state.usernameHint"
						data-wp-bind--hidden="!state.usernameHint">
						&#10003; <?php echo esc_html( $current_login ); ?> <?php esc_html_e( 'is available', 'buddynext' ); ?>
					</div>
				</div>

				<div class="bn-ob-field">
					<label class="bn-ob-label" for="bn-ob-bio">
						<?php esc_html_e( 'Bio', 'buddynext' ); ?>
						<span class="bn-ob-label-hint"><?php esc_html_e( '(optional)', 'buddynext' ); ?></span>
					</label>
					<textarea class="bn-ob-input"
						id="bn-ob-bio"
						name="bn_bio"
						rows="3"
						placeholder="<?php esc_attr_e( 'Tell the community a bit about yourself...', 'buddynext' ); ?>"><?php echo esc_textarea( $bio ); ?></textarea>
				</div>

				<div class="bn-ob-field">
					<label class="bn-ob-label" for="bn-ob-location">
						<?php esc_html_e( 'Location', 'buddynext' ); ?>
						<span class="bn-ob-label-hint"><?php esc_html_e( '(optional)', 'buddynext' ); ?></span>
					</label>
					<input class="bn-ob-input"
						type="text"
						id="bn-ob-location"
						name="bn_location"
						value="<?php echo esc_attr( $location ); ?>"
						placeholder="<?php esc_attr_e( 'City, Country', 'buddynext' ); ?>" />
				</div>
			</div>

			<div class="bn-ob-actions">
				<button class="bn-ob-btn-skip"
					type="button"
					data-wp-on--click="actions.skipStep">
					<?php esc_html_e( 'Skip for now', 'buddynext' ); ?>
				</button>
				<button class="bn-ob-btn-next"
					type="button"
					data-wp-on--click="actions.nextStep">
					<?php esc_html_e( 'Continue', 'buddynext' ); ?> &rarr;
				</button>
			</div>

		</div><!-- /step 1 -->

		<!-- ── Step 2: Interests ── -->
		<div class="bn-ob-step-panel <?php echo 2 === $saved_step ? 'active' : ''; ?>"
			id="bn-ob-step-2"
			data-step="2">

			<div class="bn-ob-step-header">
				<div class="bn-ob-step-icon"><?php buddynext_icon( 'target' ); ?></div>
				<div class="bn-ob-step-title"><?php esc_html_e( 'Pick your interests', 'buddynext' ); ?></div>
				<div class="bn-ob-step-sub"><?php esc_html_e( "We'll show you relevant posts and spaces based on what you choose.", 'buddynext' ); ?></div>
			</div>

			<div class="bn-ob-card">
				<div class="bn-ob-chips-grid">
					<?php foreach ( $all_interests as $interest ) : ?>
						<button class="bn-ob-chip <?php echo in_array( $interest['label'], $saved_interests, true ) ? 'selected' : ''; ?>"
							type="button"
							data-interest="<?php echo esc_attr( $interest['label'] ); ?>"
							data-wp-on--click="actions.toggleInterest">
							<?php buddynext_icon( $interest['icon'] ); ?> <?php echo esc_html( $interest['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
				<div class="bn-ob-chips-count"
					data-wp-text="state.interestCountLabel">
					<?php
					$saved_count = count( $saved_interests );
					echo esc_html(
						sprintf(
							/* translators: %d: number of selected interests */
							_n( '%d selected', '%d selected', $saved_count, 'buddynext' ),
							$saved_count
						) . ' &middot; ' . __( 'Pick at least 3', 'buddynext' )
					);
					?>
				</div>
			</div>

			<div class="bn-ob-actions">
				<button class="bn-ob-btn-back"
					type="button"
					data-wp-on--click="actions.prevStep">
					&larr; <?php esc_html_e( 'Back', 'buddynext' ); ?>
				</button>
				<button class="bn-ob-btn-skip"
					type="button"
					data-wp-on--click="actions.skipStep">
					<?php esc_html_e( 'Skip', 'buddynext' ); ?>
				</button>
				<button class="bn-ob-btn-next"
					type="button"
					data-wp-on--click="actions.nextStep">
					<?php esc_html_e( 'Continue', 'buddynext' ); ?> &rarr;
				</button>
			</div>

		</div><!-- /step 2 -->

		<!-- ── Step 3: Spaces ── -->
		<div class="bn-ob-step-panel <?php echo 3 === $saved_step ? 'active' : ''; ?>"
			id="bn-ob-step-3"
			data-step="3">

			<div class="bn-ob-step-header">
				<div class="bn-ob-step-icon"><?php buddynext_icon( 'building' ); ?></div>
				<div class="bn-ob-step-title"><?php esc_html_e( 'Join some spaces', 'buddynext' ); ?></div>
				<div class="bn-ob-step-sub"><?php esc_html_e( 'Spaces are topic-focused communities. Join the ones that interest you.', 'buddynext' ); ?></div>
			</div>

			<div class="bn-ob-card">
				<?php if ( $recommended_spaces ) : ?>
					<div class="bn-ob-spaces-grid">
						<?php foreach ( $recommended_spaces as $space ) : ?>
							<?php $space_id = (int) $space->id; ?>
							<button class="bn-ob-space-opt <?php echo in_array( $space_id, $joined_space_ids, true ) ? 'joined' : ''; ?>"
								type="button"
								data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
								data-wp-on--click="actions.toggleSpace"
								aria-pressed="<?php echo in_array( $space_id, $joined_space_ids, true ) ? 'true' : 'false'; ?>">
								<div class="bn-ob-space-icon">
									<?php buddynext_icon( 'home' ); ?>
								</div>
								<div>
									<div class="bn-ob-space-name"><?php echo esc_html( $space->name ); ?></div>
									<div class="bn-ob-space-members">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: member count */
												__( '%s members', 'buddynext' ),
												number_format_i18n( (int) $space->member_count )
											)
										);
										?>
									</div>
								</div>
							</button>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p style="color: var(--text-3); font-size: var(--text-sm); text-align: center; padding: var(--s4) 0;">
						<?php esc_html_e( 'No spaces available yet. You can explore spaces after setup.', 'buddynext' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="bn-ob-actions">
				<button class="bn-ob-btn-back"
					type="button"
					data-wp-on--click="actions.prevStep">
					&larr; <?php esc_html_e( 'Back', 'buddynext' ); ?>
				</button>
				<button class="bn-ob-btn-skip"
					type="button"
					data-wp-on--click="actions.skipStep">
					<?php esc_html_e( 'Skip', 'buddynext' ); ?>
				</button>
				<button class="bn-ob-btn-next"
					type="button"
					data-wp-on--click="actions.nextStep">
					<?php esc_html_e( 'Continue', 'buddynext' ); ?> &rarr;
				</button>
			</div>

		</div><!-- /step 3 -->

		<!-- ── Step 4: Follow People ── -->
		<div class="bn-ob-step-panel <?php echo 4 === $saved_step ? 'active' : ''; ?>"
			id="bn-ob-step-4"
			data-step="4">

			<div class="bn-ob-step-header">
				<div class="bn-ob-step-icon"><?php buddynext_icon( 'users' ); ?></div>
				<div class="bn-ob-step-title"><?php esc_html_e( 'Follow some members', 'buddynext' ); ?></div>
				<div class="bn-ob-step-sub"><?php esc_html_e( 'Start building your feed by following members in your areas of interest.', 'buddynext' ); ?></div>
			</div>

			<div class="bn-ob-card">
				<?php if ( $suggested_users ) : ?>
					<?php foreach ( $suggested_users as $sug_user ) : ?>
						<?php
						$sug_id         = (int) $sug_user->ID;
						$sug_name       = $sug_user->display_name;
						$sug_login      = $sug_user->user_login;
						$sug_headline   = ! empty( $sug_user->headline ) ? $sug_user->headline : '';
						$sug_followers  = (int) $sug_user->follower_count;
						$sug_avatar_url = get_avatar_url( $sug_id, array( 'size' => 38 ) );
						$sug_initials   = '';
						foreach ( array_slice( explode( ' ', $sug_name ), 0, 2 ) as $p ) {
							$sug_initials .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
						}
						$is_following_sug = in_array( $sug_id, $already_following, true );
						?>
						<div class="bn-ob-follow-person">
							<div class="bn-ob-follow-av">
								<?php if ( $sug_avatar_url ) : ?>
									<img src="<?php echo esc_attr( $sug_avatar_url ); ?>"
										alt="<?php echo esc_attr( $sug_name ); ?>" />
								<?php else : ?>
									<?php echo esc_html( $sug_initials ); ?>
								<?php endif; ?>
							</div>
							<div class="bn-ob-follow-info">
								<div class="bn-ob-follow-name"><?php echo esc_html( $sug_name ); ?></div>
								<div class="bn-ob-follow-meta">
									<?php if ( $sug_headline ) : ?>
										<?php echo esc_html( $sug_headline ); ?>
									<?php else : ?>
										@<?php echo esc_html( $sug_login ); ?>
										&middot;
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: follower count */
												_n( '%d follower', '%d followers', $sug_followers, 'buddynext' ),
												$sug_followers
											)
										);
										?>
									<?php endif; ?>
								</div>
							</div>
							<button class="bn-ob-btn-follow <?php echo $is_following_sug ? 'following' : ''; ?>"
								type="button"
								data-user-id="<?php echo esc_attr( (string) $sug_id ); ?>"
								data-wp-on--click="actions.toggleFollow">
								<?php echo $is_following_sug ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
							</button>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p style="color: var(--text-3); font-size: var(--text-sm); text-align: center; padding: var(--s4) 0;">
						<?php esc_html_e( 'No suggestions yet. Discover members in the member directory after setup.', 'buddynext' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="bn-ob-actions">
				<button class="bn-ob-btn-back"
					type="button"
					data-wp-on--click="actions.prevStep">
					&larr; <?php esc_html_e( 'Back', 'buddynext' ); ?>
				</button>
				<button class="bn-ob-btn-next"
					type="button"
					data-wp-on--click="actions.completeOnboarding">
					<?php esc_html_e( "Let's go!", 'buddynext' ); ?>
				</button>
			</div>

		</div><!-- /step 4 -->

	</div><!-- /wizard -->

</div><!-- /bn-ob-wrap -->
