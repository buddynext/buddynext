<?php
/**
 * BuddyNext — Onboarding Wizard template.
 *
 * Five-step wizard shown to new users after registration. Steps:
 *   1. Profile       — display name, username, bio.
 *   2. Interests     — space-category chip grid ("What are you into?").
 *                      Auto-skipped server-side when the owner has defined
 *                      no categories (OnboardingService::step_list() drops
 *                      it; the wizard renders four steps, no gap).
 *   3. Spaces        — suggested-spaces card list (join inline).
 *   4. People        — suggested-follows grid (follow inline).
 *   5. Notifications — delivery-channel toggles.
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

defined( 'ABSPATH' ) || exit;

use BuddyNext\Feed\ExploreService;
use BuddyNext\Profile\AvatarService;

// Guest gate and "already completed" redirect are enforced upstream in
// PageRouter::dispatch_hub_template() so they fire before wp_head().
$ob_user_id = get_current_user_id();

$ob_user = get_userdata( $ob_user_id );
if ( ! $ob_user ) {
	wp_die( esc_html__( 'User not found.', 'buddynext' ) );
}

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

$initials = '' !== trim( $display_name )
	? AvatarService::initials_for( $display_name )
	: AvatarService::initials_for( $current_login );

$avatar_url = get_avatar_url( $ob_user_id, array( 'size' => 100 ) );
// Custom uploaded avatar only (empty for the generated initials fallback) — the
// live preview shows the photo when set, the initial otherwise.
$custom_avatar = (string) get_user_meta( $ob_user_id, 'bn_avatar', true );
$bio           = (string) get_user_meta( $ob_user_id, 'bn_bio', true );

$bn_ob_service = new \BuddyNext\Onboarding\OnboardingService();

// Server-decided step list — the Interests step is present only when the
// owner has authored space categories, so an empty taxonomy renders a
// four-step wizard with contiguous numbering (no gap, no empty step).
$steps       = $bn_ob_service->step_list();
$total_steps = count( $steps );
$step_pos    = array();
foreach ( $steps as $bn_ob_step_num => $bn_ob_step_info ) {
	$step_pos[ $bn_ob_step_info['key'] ] = (int) $bn_ob_step_num;
}

$saved_step = max( 1, min( $total_steps, (int) get_user_meta( $ob_user_id, 'bn_onboarding_step', true ) ) );
// Dev-only: ?_step=N (with redo) lets the user jump to a specific step
// for testing. Requires ?redo=1 to opt in so a stray bookmarked link
// can't be used to skip past a step.
if ( isset( $_GET['redo'] ) && isset( $_GET['_step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$saved_step = max( 1, min( $total_steps, (int) $_GET['_step'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

// Interests step data: the owner's live categories (name + color) and the
// member's already-stored picks (redo / resume case). Absent step → both empty.
$bn_ob_categories   = array();
$bn_ob_interest_ids = array();
if ( isset( $step_pos['interests'] ) ) {
	$bn_ob_categories   = ( new \BuddyNext\Spaces\SpaceCategoryService() )->get_all();
	$bn_ob_interest_ids = $bn_ob_service->get_interest_ids( $ob_user_id );
}

$bn_ob_members = buddynext_service( 'space_members' );
$bn_ob_follows = buddynext_service( 'follows' );
$bn_ob_explore = new ExploreService();

// Recommended spaces (step 2) — ranked, viewer-aware suggestions (social proof +
// category + popularity) that exclude spaces the new member is already in, including
// any auto-joined on signup. Falls back to popularity for a brand-new account.
$recommended_spaces = ( new \BuddyNext\Spaces\SpaceSuggestionService() )->suggest( $ob_user_id, 6 );

// Spaces the user already belongs to + people they already follow (prefill the
// Join / Follow button states) via bulk service accessors.
$joined_space_ids  = $bn_ob_members->spaces_for_user( $ob_user_id );
$already_following = $bn_ob_follows->following( $ob_user_id );

// Suggested people to follow — the ranked engine (interest overlap +
// friends-of-friends, same list as GET /follow-suggestions) so the picks made
// on the Interests step personalize this one; newest members remain the
// fallback when the engine has no signal (member skipped interests and
// follows nobody yet). The interests Continue reloads the page after saving,
// so a first-run wizard reaches this code AFTER the picks are stored.
$bn_ob_suggested_ids = array_slice( $bn_ob_follows->suggestions( $ob_user_id ), 0, 5 );
if ( array() === $bn_ob_suggested_ids ) {
	$bn_ob_suggested_ids = $bn_ob_explore->suggested_member_ids( 5 );
}

$suggested_users = array();
foreach ( $bn_ob_suggested_ids as $sug_uid ) {
	$sug_uid = (int) $sug_uid;
	if ( $sug_uid <= 0 || $sug_uid === $ob_user_id ) {
		continue;
	}
	$sug_wp_user = get_userdata( $sug_uid );
	if ( ! $sug_wp_user ) {
		continue;
	}
	$suggested_users[] = array(
		'id'             => $sug_uid,
		'display_name'   => $sug_wp_user->display_name,
		'user_login'     => $sug_wp_user->user_login,
		'headline'       => (string) get_user_meta( $sug_uid, 'bn_headline', true ),
		'follower_count' => $bn_ob_follows->follower_count( $sug_uid ),
	);
}

$rest_nonce = wp_create_nonce( 'wp_rest' );
$rest_root  = esc_url_raw( rest_url( 'buddynext/v1/' ) );

// Read the user's master channel prefs so the toggles render with the
// right initial state. Defaults mirror NotificationController::get_notification_channels:
// in-app + email default on; push defaults to whether Pro Push is installed.
$channel_prefs  = get_user_meta( $ob_user_id, 'bn_channel_prefs', true );
$channel_prefs  = is_array( $channel_prefs ) ? $channel_prefs : array();
$push_available = class_exists( '\\BuddyNextPro\\Push\\PushDispatcher' );
$initial_email  = array_key_exists( 'email', $channel_prefs ) ? (bool) $channel_prefs['email'] : true;
$initial_in_app = array_key_exists( 'in_app', $channel_prefs ) ? (bool) $channel_prefs['in_app'] : true;
$initial_push   = array_key_exists( 'push', $channel_prefs ) ? (bool) $channel_prefs['push'] : $push_available;
$initial_sound  = array_key_exists( 'sound', $channel_prefs ) ? (bool) $channel_prefs['sound'] : false;

$activity_url = \BuddyNext\Core\PageRouter::activity_url();
?>

<div class="bn-ob-wrap"
	data-wp-interactive="buddynext/onboarding"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'step'                => $saved_step,
			'totalSteps'          => $total_steps,
			'interestsStep'       => $step_pos['interests'] ?? 0,
			'interestIds'         => $bn_ob_interest_ids,
			'joinedSpaces'        => $joined_space_ids,
			'followingUsers'      => $already_following,
			'displayName'         => $display_name,
			'displayNameDirty'    => false,
			'userLogin'           => $current_slug,
			'bio'                 => $bio,
			'avatarUrl'           => $custom_avatar,
			'usernameAvailable'   => true,
			'usernameChecking'    => false,
			'usernameStatusLabel' => '',
			'channelEmail'        => $initial_email,
			'channelInApp'        => $initial_in_app,
			'channelPush'         => $initial_push,
			'channelSound'        => $initial_sound,
			'pushAvailable'       => $push_available,
			'saving'              => false,
			'error'               => '',
			'restNonce'           => $rest_nonce,
			'restUrl'             => $rest_root,
			'redirectUrl'         => esc_url_raw( $activity_url ),
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

			<div class="bn-ob-card">
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
						<span><?php esc_html_e( 'JPG or PNG, max 4MB, up to 1024×1024px.', 'buddynext' ); ?></span>
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

		<?php if ( isset( $step_pos['interests'] ) ) : ?>
		<!-- ── Step <?php echo esc_html( (string) $step_pos['interests'] ); ?>: Interests ── -->
		<section class="bn-ob-step"
			id="bn-ob-step-<?php echo esc_attr( (string) $step_pos['interests'] ); ?>"
			data-step="<?php echo esc_attr( (string) $step_pos['interests'] ); ?>"
			aria-labelledby="bn-ob-step-<?php echo esc_attr( (string) $step_pos['interests'] ); ?>-title"
			data-wp-bind--hidden="!state.isStep<?php echo esc_attr( (string) $step_pos['interests'] ); ?>"
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'sparkles' ); ?></span>
				<h1 id="bn-ob-step-<?php echo esc_attr( (string) $step_pos['interests'] ); ?>-title" class="bn-ob-step__title"><?php esc_html_e( 'What are you into?', 'buddynext' ); ?></h1>
				<p class="bn-ob-step__sub"><?php esc_html_e( 'Choose the topics you care about. You can change these on your profile any time.', 'buddynext' ); ?></p>
			</header>

			<div class="bn-ob-card">
				<div class="bn-ob-chips" role="group" aria-label="<?php esc_attr_e( 'Interest topics', 'buddynext' ); ?>">
					<?php foreach ( $bn_ob_categories as $bn_ob_cat ) : ?>
						<?php
						$bn_ob_cat_id     = (int) $bn_ob_cat['id'];
						$bn_ob_cat_picked = in_array( $bn_ob_cat_id, $bn_ob_interest_ids, true );
						?>
						<button class="bn-ob-chip<?php echo $bn_ob_cat_picked ? ' is-selected' : ''; ?>"
							type="button"
							data-cat-id="<?php echo esc_attr( (string) $bn_ob_cat_id ); ?>"
							aria-pressed="<?php echo $bn_ob_cat_picked ? 'true' : 'false'; ?>"
							data-wp-on--click="actions.toggleInterest">
							<span class="bn-ob-chip__label"><?php echo esc_html( (string) $bn_ob_cat['name'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
				<p class="bn-ob-chips__count"
					data-wp-bind--hidden="!state.interestsHintVisible">
					<?php esc_html_e( 'Pick a few to personalize your community.', 'buddynext' ); ?>
				</p>
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
					data-wp-bind--disabled="state.saving"
					data-wp-on--click="actions.continueInterests">
					<?php esc_html_e( 'Continue', 'buddynext' ); ?>
				</button>
			</div>

		</section>
		<?php endif; ?>

		<!-- ── Step <?php echo esc_html( (string) $step_pos['spaces'] ); ?>: Spaces ── -->
		<section class="bn-ob-step"
			id="bn-ob-step-<?php echo esc_attr( (string) $step_pos['spaces'] ); ?>"
			data-step="<?php echo esc_attr( (string) $step_pos['spaces'] ); ?>"
			aria-labelledby="bn-ob-step-<?php echo esc_attr( (string) $step_pos['spaces'] ); ?>-title"
			data-wp-bind--hidden="!state.isStep<?php echo esc_attr( (string) $step_pos['spaces'] ); ?>"
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'building' ); ?></span>
				<h1 id="bn-ob-step-<?php echo esc_attr( (string) $step_pos['spaces'] ); ?>-title" class="bn-ob-step__title"><?php esc_html_e( 'Join some spaces', 'buddynext' ); ?></h1>
				<p class="bn-ob-step__sub"><?php esc_html_e( 'Spaces are topic-focused communities. Join the ones that interest you.', 'buddynext' ); ?></p>
			</header>

			<div class="bn-ob-spaces">
				<?php if ( $recommended_spaces ) : ?>
					<?php foreach ( $recommended_spaces as $space ) : ?>
						<?php
						$space_id  = (int) $space['id'];
						$is_joined = in_array( $space_id, $joined_space_ids, true );
						?>
						<div class="bn-ob-space-card">
							<div class="bn-ob-space-card__head">
								<span class="bn-avatar bn-ob-space-avatar"
									data-size="md"
									aria-hidden="true">
									<?php if ( ! empty( $space['avatar_url'] ) ) : ?>
										<img src="<?php echo esc_url( (string) $space['avatar_url'] ); ?>"
											alt="<?php echo esc_attr( (string) $space['name'] ); ?>" loading="lazy" />
									<?php else : ?>
										<?php buddynext_icon( 'home' ); ?>
									<?php endif; ?>
								</span>
								<div class="bn-ob-space-card__meta">
									<h3 class="bn-ob-space-card__name"><?php echo esc_html( (string) $space['name'] ); ?></h3>
									<p class="bn-ob-space-card__members">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: member count */
												__( '%s members', 'buddynext' ),
												number_format_i18n( (int) $space['member_count'] )
											)
										);
										?>
									</p>
								</div>
							</div>
							<?php if ( ! empty( $space['description'] ) ) : ?>
								<p class="bn-ob-space-card__desc"><?php echo esc_html( wp_trim_words( (string) $space['description'], 14 ) ); ?></p>
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

		<!-- ── Step <?php echo esc_html( (string) $step_pos['people'] ); ?>: Follow People ── -->
		<section class="bn-ob-step"
			id="bn-ob-step-<?php echo esc_attr( (string) $step_pos['people'] ); ?>"
			data-step="<?php echo esc_attr( (string) $step_pos['people'] ); ?>"
			aria-labelledby="bn-ob-step-<?php echo esc_attr( (string) $step_pos['people'] ); ?>-title"
			data-wp-bind--hidden="!state.isStep<?php echo esc_attr( (string) $step_pos['people'] ); ?>"
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></span>
				<h1 id="bn-ob-step-<?php echo esc_attr( (string) $step_pos['people'] ); ?>-title" class="bn-ob-step__title"><?php esc_html_e( 'Follow some members', 'buddynext' ); ?></h1>
				<p class="bn-ob-step__sub"><?php esc_html_e( 'Start building your feed by following members in your areas of interest.', 'buddynext' ); ?></p>
			</header>

			<div class="bn-ob-card">
				<?php if ( $suggested_users ) : ?>
					<ul class="bn-ob-people" role="list">
						<?php foreach ( $suggested_users as $sug_user ) : ?>
							<?php
							$sug_id           = (int) $sug_user['id'];
							$sug_name         = (string) $sug_user['display_name'];
							$sug_login        = (string) $sug_user['user_login'];
							$sug_headline     = ! empty( $sug_user['headline'] ) ? (string) $sug_user['headline'] : '';
							$sug_followers    = (int) $sug_user['follower_count'];
							$sug_avatar_url   = get_avatar_url( $sug_id, array( 'size' => 72 ) );
							$sug_initials     = AvatarService::initials_for( $sug_name );
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

		<!-- ── Step <?php echo esc_html( (string) $step_pos['notifications'] ); ?>: Notifications ── -->
		<section class="bn-ob-step"
			id="bn-ob-step-<?php echo esc_attr( (string) $step_pos['notifications'] ); ?>"
			data-step="<?php echo esc_attr( (string) $step_pos['notifications'] ); ?>"
			aria-labelledby="bn-ob-step-<?php echo esc_attr( (string) $step_pos['notifications'] ); ?>-title"
			data-wp-bind--hidden="!state.isStep<?php echo esc_attr( (string) $step_pos['notifications'] ); ?>"
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'bell' ); ?></span>
				<h1 id="bn-ob-step-<?php echo esc_attr( (string) $step_pos['notifications'] ); ?>-title" class="bn-ob-step__title"><?php esc_html_e( 'How should we ping you?', 'buddynext' ); ?></h1>
				<p class="bn-ob-step__sub"><?php esc_html_e( 'Pick the channels you want to hear from. You can fine-tune which events on each channel from your settings.', 'buddynext' ); ?></p>
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
					<span class="bn-ob-channel__icon" aria-hidden="true"><?php buddynext_icon( 'zap' ); ?></span>
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

				<label class="bn-ob-channel">
					<span class="bn-ob-channel__icon" aria-hidden="true"><?php buddynext_icon( 'volume-2' ); ?></span>
					<span class="bn-ob-channel__body">
						<span class="bn-ob-channel__name"><?php esc_html_e( 'Sound', 'buddynext' ); ?></span>
						<span class="bn-ob-channel__hint"><?php esc_html_e( 'Play a short sound when a new notification arrives.', 'buddynext' ); ?></span>
					</span>
					<input type="checkbox"
						class="bn-ob-channel__toggle"
						data-channel="sound"
						data-wp-bind--checked="context.channelSound"
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
							<img class="bn-ob-preview-card__avatar-img"
								data-wp-bind--src="state.previewAvatar"
								data-wp-bind--hidden="!state.previewAvatar"
								<?php echo '' !== $custom_avatar ? 'src="' . esc_url( $custom_avatar ) . '"' : 'hidden'; ?>
								alt="" />
							<span data-wp-bind--hidden="state.previewAvatar" data-wp-text="state.previewInitial"><?php echo esc_html( '' !== (string) $display_name ? strtoupper( substr( (string) $display_name, 0, 1 ) ) : '?' ); ?></span>
						</div>
						<div class="bn-ob-preview-card__id">
							<div class="bn-ob-preview-card__name" data-wp-text="state.previewName">
								<?php echo esc_html( '' !== (string) $display_name ? $display_name : __( 'Your name', 'buddynext' ) ); ?>
							</div>
							<div class="bn-ob-preview-card__handle" data-wp-text="state.previewHandle">
								<?php echo esc_html( '@' . $current_slug ); ?>
							</div>
						</div>
					</div>
					<p class="bn-ob-preview-card__bio" data-wp-text="state.previewBio">
						<?php echo esc_html( '' !== (string) $bio ? $bio : __( "Add a short bio so people know what you're into.", 'buddynext' ) ); ?>
					</p>
				</div>

				<p class="bn-ob-canvas__caption">
					<?php esc_html_e( 'This is how other members will see you. Update anything from your profile later.', 'buddynext' ); ?>
				</p>
			</div>
		</aside>

	</div>

</div>
