<?php
/**
 * BuddyNext — Onboarding Wizard template.
 *
 * Four-step wizard shown to new users after registration. Steps:
 *   1. Profile     — display name, username, bio.
 *   2. Interests   — multi-select tag grid (at least 1 required).
 *   3. Spaces      — suggested-spaces card list (join inline).
 *   4. People      — suggested-follows grid (follow inline).
 *
 * Each step is fully reactive via the Interactivity API store
 * (`@buddynext/onboarding`). Continue / Back / Skip / Finish actions
 * walk the wizard without page reloads. A visual `.bn-progress` bar
 * grows step-to-step.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

// Guest gate and "already completed" redirect are enforced upstream in
// PageRouter::dispatch_hub_template() so they fire before wp_head().
$ob_user_id = get_current_user_id();

$ob_user = get_userdata( $ob_user_id );
if ( ! $ob_user ) {
	wp_die( esc_html__( 'User not found.', 'buddynext' ) );
}

global $wpdb;

$display_name  = $ob_user->display_name;
$current_login = $ob_user->user_login;
// Surface the user's BN profile slug ("handle") if they've set one,
// otherwise fall back to the WP login as a reasonable starting point.
// The onboarding submit saves whatever the user finalises into the
// bn_profile_slug user_meta via PUT /profile-slug.
$current_slug = (string) get_user_meta( $ob_user_id, 'bn_profile_slug', true );
if ( '' === $current_slug ) {
	$current_slug = $current_login;
}

$name_parts = explode( ' ', $display_name );
$initials   = '';
foreach ( array_slice( $name_parts, 0, 2 ) as $part ) {
	$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
}
$initials = ! empty( $initials ) ? $initials : mb_strtoupper( mb_substr( $current_login, 0, 2 ) );

$avatar_url = get_avatar_url( $ob_user_id, array( 'size' => 100 ) );
$bio        = (string) get_user_meta( $ob_user_id, 'bn_bio', true );
$saved_step = max( 1, (int) get_user_meta( $ob_user_id, 'bn_onboarding_step', true ) );

// Recommended spaces (step 2) — pull from bn_spaces.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
$recommended_spaces = $wpdb->get_results( "SELECT id, name, member_count, description FROM {$wpdb->prefix}bn_spaces ORDER BY member_count DESC LIMIT 6" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$joined_space_ids_raw = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT space_id FROM {$wpdb->prefix}bn_space_members WHERE user_id = %d AND status = 'active'",
		$ob_user_id
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$joined_space_ids = array_map( 'intval', $joined_space_ids_raw );

// Suggested people to follow (step 3).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$already_following = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT following_id FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
		$ob_user_id
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$already_following = array_map( 'intval', $already_following );

$rest_nonce = wp_create_nonce( 'wp_rest' );
$rest_root  = esc_url_raw( rest_url( 'buddynext/v1/' ) );

// Read the user's master channel prefs so the toggles render with the
// right initial state. Defaults mirror NotificationController::get_notification_channels:
// in-app + email default on; push defaults to whether Pro Push is installed.
$channel_prefs   = get_user_meta( $ob_user_id, 'bn_channel_prefs', true );
$channel_prefs   = is_array( $channel_prefs ) ? $channel_prefs : array();
$push_available  = class_exists( '\\BuddyNextPro\\Push\\PushDispatcher' );
$initial_email   = array_key_exists( 'email', $channel_prefs )  ? (bool) $channel_prefs['email']  : true;
$initial_in_app  = array_key_exists( 'in_app', $channel_prefs ) ? (bool) $channel_prefs['in_app'] : true;
$initial_push    = array_key_exists( 'push', $channel_prefs )   ? (bool) $channel_prefs['push']   : $push_available;

// Step config (label, icon) — V2-aligned set:
// 1 Profile · 2 Spaces · 3 People · 4 Notifications.
// "Interests" used to live here but nothing downstream read the saved
// bn_interests meta, so the step was removed; "Notifications" replaces
// it so the user gets a deliberate choice about how BN will ping them.
$steps        = array(
	1 => array(
		'label' => __( 'Profile', 'buddynext' ),
		'icon'  => 'user',
	),
	2 => array(
		'label' => __( 'Spaces', 'buddynext' ),
		'icon'  => 'building',
	),
	3 => array(
		'label' => __( 'People', 'buddynext' ),
		'icon'  => 'users',
	),
	4 => array(
		'label' => __( 'Notifications', 'buddynext' ),
		'icon'  => 'bell',
	),
);
$total_steps  = count( $steps );
$activity_url = \BuddyNext\Core\PageRouter::activity_url();
?>

<div class="bn-ob-wrap"
	data-wp-interactive="buddynext/onboarding"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'step'              => $saved_step,
			'totalSteps'        => $total_steps,
			'joinedSpaces'      => $joined_space_ids,
			'followingUsers'    => $already_following,
			'displayName'       => $display_name,
			'displayNameDirty'  => false,
			'userLogin'           => $current_slug,
			'bio'               => $bio,
			'usernameAvailable'   => true,
			'usernameChecking'    => false,
			'usernameStatusLabel' => '',
			'channelEmail'        => $initial_email,
			'channelInApp'        => $initial_in_app,
			'channelPush'         => $initial_push,
			'pushAvailable'       => $push_available,
			'saving'            => false,
			'error'             => '',
			'restNonce'         => $rest_nonce,
			'restUrl'           => $rest_root,
			'redirectUrl'       => esc_url_raw( $activity_url ),
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>

	<div class="bn-ob-shell">

		<div class="bn-ob-form">

		<!-- Form header: brand + step counter (no separate progress bar — the
		     stepper below is itself the progress indicator). -->
		<div class="bn-ob-form-head">
			<span class="bn-ob-form-head__step" data-wp-text="state.stepLabel"><?php echo esc_html( sprintf( /* translators: 1: current step, 2: total steps */ __( 'Step %1$d of %2$d', 'buddynext' ), $saved_step, $total_steps ) ); ?></span>
		</div>

		<!-- Numbered step header -->
		<nav class="bn-stepper bn-ob-stepper"
			aria-label="<?php esc_attr_e( 'Onboarding steps', 'buddynext' ); ?>">
			<?php
			$step_keys = array_keys( $steps );
			foreach ( $step_keys as $idx => $step_num ) :
				$step_info = $steps[ $step_num ];
				?>
				<div class="bn-stepper__item"
					data-step="<?php echo esc_attr( (string) $step_num ); ?>"
					data-wp-class--is-done="<?php echo esc_attr( 'state.isStepDone' . $step_num ); ?>"
					data-wp-class--is-active="<?php echo esc_attr( 'state.isStepActive' . $step_num ); ?>"
				>
					<span class="bn-stepper__dot" aria-hidden="true">
						<?php echo esc_html( (string) $step_num ); ?>
					</span>
					<span class="bn-ob-stepper__label"><?php echo esc_html( $step_info['label'] ); ?></span>
				</div>
				<?php if ( $idx < count( $step_keys ) - 1 ) : ?>
					<span class="bn-stepper__bar" aria-hidden="true"></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>

		<div class="bn-ob-form-body">

		<!-- ── Step 1: Profile ── -->
		<section class="bn-ob-step"
			id="bn-ob-step-1"
			data-step="1"
			aria-labelledby="bn-ob-step-1-title"
			data-wp-bind--hidden="!state.isStep1"
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'user' ); ?></span>
				<h1 id="bn-ob-step-1-title" class="bn-ob-step__title"><?php esc_html_e( 'Set up your profile', 'buddynext' ); ?></h1>
				<p class="bn-ob-step__sub"><?php esc_html_e( 'Help others discover you. You can change this any time.', 'buddynext' ); ?></p>
			</header>

			<div class="bn-card bn-ob-card" data-v2="true">
				<div class="bn-ob-avatar-row">
					<button class="bn-avatar bn-ob-avatar"
						type="button"
						data-size="xl"
						aria-label="<?php esc_attr_e( 'Upload profile photo', 'buddynext' ); ?>"
						data-wp-on--click="actions.triggerAvatarUpload">
						<?php if ( $avatar_url ) : ?>
							<img src="<?php echo esc_attr( $avatar_url ); ?>"
								alt="<?php echo esc_attr( $display_name ); ?>" />
						<?php else : ?>
							<?php echo esc_html( $initials ); ?>
						<?php endif; ?>
					</button>
					<input type="file"
						class="bn-ob-avatar-input"
						accept="image/jpeg,image/png"
						hidden
						data-wp-on--change="actions.handleAvatarUpload" />
					<div class="bn-ob-avatar-row__hint">
						<strong><?php esc_html_e( 'Add a profile photo', 'buddynext' ); ?></strong>
						<span><?php esc_html_e( 'JPG or PNG, max 4MB.', 'buddynext' ); ?></span>
					</div>
				</div>

				<div class="bn-ob-field">
					<label class="bn-ob-label" for="bn-ob-displayname">
						<?php esc_html_e( 'Display name', 'buddynext' ); ?>
					</label>
					<input class="bn-input"
						type="text"
						id="bn-ob-displayname"
						name="display_name"
						value="<?php echo esc_attr( $display_name ); ?>"
						placeholder="<?php esc_attr_e( 'Your full name', 'buddynext' ); ?>"
						data-wp-on--input="actions.setDisplayName" />
					<span class="bn-ob-field__msg"
						data-wp-bind--hidden="!state.displayNameError"
						data-wp-text="state.displayNameError"></span>
				</div>

				<div class="bn-ob-field">
					<label class="bn-ob-label" for="bn-ob-username">
						<?php esc_html_e( 'Username', 'buddynext' ); ?>
						<span class="bn-ob-label__hint"><?php esc_html_e( '3+ characters, letters & numbers', 'buddynext' ); ?></span>
					</label>
					<div class="bn-ob-input-prefix"
						data-wp-class--is-checking="context.usernameChecking"
						data-wp-class--is-ok="context.usernameAvailable"
					>
						<span class="bn-ob-input-prefix__prefix" aria-hidden="true">@</span>
						<input class="bn-ob-username"
							type="text"
							id="bn-ob-username"
							name="user_login"
							value="<?php echo esc_attr( $current_slug ); ?>"
							placeholder="<?php esc_attr_e( 'username', 'buddynext' ); ?>"
							autocomplete="username"
							autocapitalize="off"
							spellcheck="false"
							data-wp-on--input="actions.checkUsername" />
						<span class="bn-ob-input-prefix__status"
							data-wp-class--tone-ok="context.usernameAvailable"
							data-wp-class--tone-bad="!context.usernameAvailable"
							data-wp-bind--hidden="!context.usernameStatusLabel"
							data-wp-text="context.usernameStatusLabel"></span>
					</div>
				</div>

				<div class="bn-ob-field">
					<label class="bn-ob-label" for="bn-ob-bio">
						<?php esc_html_e( 'Bio', 'buddynext' ); ?>
						<span class="bn-ob-label__hint"><?php esc_html_e( '(optional)', 'buddynext' ); ?></span>
					</label>
					<textarea class="bn-textarea"
						id="bn-ob-bio"
						name="bn_bio"
						rows="3"
						placeholder="<?php esc_attr_e( 'Tell the community a bit about yourself...', 'buddynext' ); ?>"
						data-wp-on--input="actions.setBio"><?php echo esc_textarea( $bio ); ?></textarea>
				</div>
			</div>

			<div class="bn-ob-actions">
				<span class="bn-ob-actions__spacer" aria-hidden="true"></span>
				<button class="bn-btn"
					type="button"
					data-variant="ghost"
					data-size="lg"
					data-wp-on--click="actions.skipStep">
					<?php esc_html_e( 'Skip for now', 'buddynext' ); ?>
				</button>
				<button class="bn-btn"
					type="button"
					data-variant="primary"
					data-size="lg"
					data-wp-bind--disabled="state.continueDisabledStep1"
					data-wp-on--click="actions.nextStep">
					<?php esc_html_e( 'Continue', 'buddynext' ); ?>
				</button>
			</div>

		</section>

		<!-- ── Step 2: Spaces ── -->
		<section class="bn-ob-step"
			id="bn-ob-step-2"
			data-step="2"
			aria-labelledby="bn-ob-step-2-title"
			data-wp-bind--hidden="!state.isStep2"
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'building' ); ?></span>
				<h1 id="bn-ob-step-2-title" class="bn-ob-step__title"><?php esc_html_e( 'Join some spaces', 'buddynext' ); ?></h1>
				<p class="bn-ob-step__sub"><?php esc_html_e( 'Spaces are topic-focused communities. Join the ones that interest you.', 'buddynext' ); ?></p>
			</header>

			<div class="bn-ob-spaces">
				<?php if ( $recommended_spaces ) : ?>
					<?php foreach ( $recommended_spaces as $space ) : ?>
						<?php
						$space_id  = (int) $space->id;
						$is_joined = in_array( $space_id, $joined_space_ids, true );
						?>
						<div class="bn-card bn-ob-space-card" data-interactive="true">
							<div class="bn-ob-space-card__head">
								<span class="bn-avatar bn-ob-space-avatar"
									data-size="md"
									aria-hidden="true">
									<?php buddynext_icon( 'home' ); ?>
								</span>
								<div class="bn-ob-space-card__meta">
									<h3 class="bn-ob-space-card__name"><?php echo esc_html( $space->name ); ?></h3>
									<p class="bn-ob-space-card__members">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: member count */
												__( '%s members', 'buddynext' ),
												number_format_i18n( (int) $space->member_count )
											)
										);
										?>
									</p>
								</div>
							</div>
							<?php if ( ! empty( $space->description ) ) : ?>
								<p class="bn-ob-space-card__desc"><?php echo esc_html( wp_trim_words( $space->description, 14 ) ); ?></p>
							<?php endif; ?>
							<button class="bn-btn bn-ob-space-card__cta"
								type="button"
								data-variant="<?php echo $is_joined ? 'secondary' : 'primary'; ?>"
								data-size="sm"
								data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
								aria-pressed="<?php echo $is_joined ? 'true' : 'false'; ?>"
								data-wp-on--click="actions.joinSuggestedSpace">
								<?php echo $is_joined ? esc_html__( 'Joined', 'buddynext' ) : esc_html__( 'Join', 'buddynext' ); ?>
							</button>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p class="bn-ob-empty">
						<?php esc_html_e( 'No spaces available yet. You can explore spaces after setup.', 'buddynext' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="bn-ob-actions">
				<button class="bn-btn"
					type="button"
					data-variant="ghost"
					data-size="lg"
					data-wp-on--click="actions.prevStep">
					<?php esc_html_e( 'Back', 'buddynext' ); ?>
				</button>
				<button class="bn-btn"
					type="button"
					data-variant="ghost"
					data-size="lg"
					data-wp-on--click="actions.skipStep">
					<?php esc_html_e( 'Skip for now', 'buddynext' ); ?>
				</button>
				<button class="bn-btn"
					type="button"
					data-variant="primary"
					data-size="lg"
					data-wp-on--click="actions.nextStep">
					<?php esc_html_e( 'Continue', 'buddynext' ); ?>
				</button>
			</div>

		</section>

		<!-- ── Step 3: Follow People ── -->
		<section class="bn-ob-step"
			id="bn-ob-step-3"
			data-step="3"
			aria-labelledby="bn-ob-step-3-title"
			data-wp-bind--hidden="!state.isStep3"
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></span>
				<h1 id="bn-ob-step-3-title" class="bn-ob-step__title"><?php esc_html_e( 'Follow some members', 'buddynext' ); ?></h1>
				<p class="bn-ob-step__sub"><?php esc_html_e( 'Start building your feed by following members in your areas of interest.', 'buddynext' ); ?></p>
			</header>

			<div class="bn-card bn-ob-card" data-v2="true">
				<?php if ( $suggested_users ) : ?>
					<ul class="bn-ob-people" role="list">
						<?php foreach ( $suggested_users as $sug_user ) : ?>
							<?php
							$sug_id         = (int) $sug_user->ID;
							$sug_name       = $sug_user->display_name;
							$sug_login      = $sug_user->user_login;
							$sug_headline   = ! empty( $sug_user->headline ) ? $sug_user->headline : '';
							$sug_followers  = (int) $sug_user->follower_count;
							$sug_avatar_url = get_avatar_url( $sug_id, array( 'size' => 72 ) );
							$sug_initials   = '';
							foreach ( array_slice( explode( ' ', $sug_name ), 0, 2 ) as $p ) {
								$sug_initials .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
							}
							$is_following_sug = in_array( $sug_id, $already_following, true );
							?>
							<li class="bn-ob-person">
								<span class="bn-avatar" data-size="md">
									<?php if ( $sug_avatar_url ) : ?>
										<img src="<?php echo esc_attr( $sug_avatar_url ); ?>"
											alt="<?php echo esc_attr( $sug_name ); ?>" />
									<?php else : ?>
										<?php echo esc_html( $sug_initials ); ?>
									<?php endif; ?>
								</span>
								<div class="bn-ob-person__info">
									<p class="bn-ob-person__name"><?php echo esc_html( $sug_name ); ?></p>
									<p class="bn-ob-person__meta">
										<?php if ( $sug_headline ) : ?>
											<?php echo esc_html( $sug_headline ); ?>
										<?php else : ?>
											@<?php echo esc_html( $sug_login ); ?>
											·
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
									</p>
								</div>
								<button class="bn-btn bn-ob-person__cta <?php echo $is_following_sug ? 'is-following' : ''; ?>"
									type="button"
									data-variant="<?php echo $is_following_sug ? 'secondary' : 'primary'; ?>"
									data-size="sm"
									data-user-id="<?php echo esc_attr( (string) $sug_id ); ?>"
									aria-pressed="<?php echo $is_following_sug ? 'true' : 'false'; ?>"
									data-wp-on--click="actions.followSuggestedUser">
									<?php echo $is_following_sug ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="bn-ob-empty">
						<?php esc_html_e( 'No suggestions yet. Discover members in the member directory after setup.', 'buddynext' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="bn-ob-actions">
				<button class="bn-btn"
					type="button"
					data-variant="ghost"
					data-size="lg"
					data-wp-on--click="actions.prevStep">
					<?php esc_html_e( 'Back', 'buddynext' ); ?>
				</button>
				<button class="bn-btn"
					type="button"
					data-variant="ghost"
					data-size="lg"
					data-wp-on--click="actions.skipStep">
					<?php esc_html_e( 'Skip for now', 'buddynext' ); ?>
				</button>
				<button class="bn-btn"
					type="button"
					data-variant="primary"
					data-size="lg"
					data-wp-on--click="actions.nextStep">
					<?php esc_html_e( 'Continue', 'buddynext' ); ?>
				</button>
			</div>

		</section>

		<!-- ── Step 4: Notifications ── -->
		<section class="bn-ob-step"
			id="bn-ob-step-4"
			data-step="4"
			aria-labelledby="bn-ob-step-4-title"
			data-wp-bind--hidden="!state.isStep4"
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'bell' ); ?></span>
				<h1 id="bn-ob-step-4-title" class="bn-ob-step__title"><?php esc_html_e( 'How should we ping you?', 'buddynext' ); ?></h1>
				<p class="bn-ob-step__sub"><?php esc_html_e( "Pick the channels you want to hear from. You can fine-tune which events on each channel from your settings.", 'buddynext' ); ?></p>
			</header>

			<div class="bn-ob-channels" role="group" aria-label="<?php esc_attr_e( 'Notification channels', 'buddynext' ); ?>">
				<label class="bn-ob-channel">
					<span class="bn-ob-channel__icon" aria-hidden="true"><?php buddynext_icon( 'mail' ); ?></span>
					<span class="bn-ob-channel__body">
						<span class="bn-ob-channel__name"><?php esc_html_e( 'Email', 'buddynext' ); ?></span>
						<span class="bn-ob-channel__hint"><?php esc_html_e( 'Daily summary of mentions, comments, and important activity.', 'buddynext' ); ?></span>
					</span>
					<input type="checkbox"
						class="bn-ob-channel__toggle"
						data-channel="email"
						data-wp-bind--checked="context.channelEmail"
						data-wp-on--change="actions.toggleChannel" />
				</label>

				<label class="bn-ob-channel">
					<span class="bn-ob-channel__icon" aria-hidden="true"><?php buddynext_icon( 'bell' ); ?></span>
					<span class="bn-ob-channel__body">
						<span class="bn-ob-channel__name"><?php esc_html_e( 'In-app', 'buddynext' ); ?></span>
						<span class="bn-ob-channel__hint"><?php esc_html_e( 'Bell badge and notification panel inside BuddyNext.', 'buddynext' ); ?></span>
					</span>
					<input type="checkbox"
						class="bn-ob-channel__toggle"
						data-channel="in_app"
						data-wp-bind--checked="context.channelInApp"
						data-wp-on--change="actions.toggleChannel" />
				</label>

				<label class="bn-ob-channel"
					data-wp-bind--hidden="!context.pushAvailable">
					<span class="bn-ob-channel__icon" aria-hidden="true"><?php buddynext_icon( 'smartphone' ); ?></span>
					<span class="bn-ob-channel__body">
						<span class="bn-ob-channel__name"><?php esc_html_e( 'Push', 'buddynext' ); ?></span>
						<span class="bn-ob-channel__hint"><?php esc_html_e( 'Real-time alerts on your browser or device.', 'buddynext' ); ?></span>
					</span>
					<input type="checkbox"
						class="bn-ob-channel__toggle"
						data-channel="push"
						data-wp-bind--checked="context.channelPush"
						data-wp-on--change="actions.toggleChannel" />
				</label>
			</div>

			<div class="bn-ob-actions">
				<button class="bn-btn"
					type="button"
					data-variant="ghost"
					data-size="lg"
					data-wp-on--click="actions.prevStep">
					<?php esc_html_e( 'Back', 'buddynext' ); ?>
				</button>
				<button class="bn-btn"
					type="button"
					data-variant="primary"
					data-size="lg"
					data-wp-bind--disabled="state.saving"
					data-wp-on--click="actions.finish">
					<span data-wp-bind--hidden="state.saving"><?php esc_html_e( 'Finish', 'buddynext' ); ?></span>
					<span data-wp-bind--hidden="!state.saving"><?php esc_html_e( 'Saving…', 'buddynext' ); ?></span>
				</button>
			</div>

		</section>

		<div class="bn-ob-error" role="alert" aria-live="polite"
			data-wp-bind--hidden="!state.error"
			data-wp-text="state.error"></div>

		</div><!-- /.bn-ob-form-body -->

		</div><!-- /.bn-ob-form -->

		<aside class="bn-ob-canvas" aria-label="<?php esc_attr_e( 'Live profile preview', 'buddynext' ); ?>">
			<div class="bn-ob-canvas__inner">
				<div class="bn-ob-canvas__eyebrow">
					<?php esc_html_e( 'Preview', 'buddynext' ); ?>
				</div>

				<div class="bn-ob-preview-card">
					<div class="bn-ob-preview-card__head">
						<div class="bn-ob-preview-card__avatar" aria-hidden="true">
							<span data-wp-text="state.previewInitial"><?php echo esc_html( strtoupper( substr( (string) $display_name, 0, 1 ) ) ?: '?' ); ?></span>
						</div>
						<div class="bn-ob-preview-card__id">
							<div class="bn-ob-preview-card__name" data-wp-text="state.previewName">
								<?php echo esc_html( $display_name ?: __( 'Your name', 'buddynext' ) ); ?>
							</div>
							<div class="bn-ob-preview-card__handle" data-wp-text="state.previewHandle">
								<?php echo esc_html( '@' . $current_slug ); ?>
							</div>
						</div>
					</div>
					<p class="bn-ob-preview-card__bio" data-wp-text="state.previewBio">
						<?php echo esc_html( $bio ?: __( "Add a short bio so people know what you're into.", 'buddynext' ) ); ?>
					</p>
				</div>

				<p class="bn-ob-canvas__caption">
					<?php esc_html_e( "This is how other members will see you. Update anything from your profile later.", 'buddynext' ); ?>
				</p>
			</div>
		</aside>

	</div>

</div>
