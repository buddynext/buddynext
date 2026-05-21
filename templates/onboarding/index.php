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
$onboarding_done = (bool) get_user_meta( $ob_user_id, 'bn_onboarding_complete', true );
if ( $onboarding_done && ! isset( $_GET['redo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( \BuddyNext\Core\PageRouter::activity_url() );
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

// Suggested people to follow (step 4).
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

	<div class="bn-ob-shell">

		<!-- Progress stepper -->
		<nav class="bn-stepper bn-ob-stepper"
			aria-label="<?php esc_attr_e( 'Onboarding progress', 'buddynext' ); ?>">
			<?php
			$step_keys = array_keys( $steps );
			foreach ( $step_keys as $idx => $step_num ) :
				$state = '';
				if ( $step_num < $saved_step ) {
					$state = 'done';
				} elseif ( $step_num === $saved_step ) {
					$state = 'active';
				}
				$step_info = $steps[ $step_num ];
				?>
				<div class="bn-stepper__item"
					<?php
					if ( $state ) :
						?>
						data-state="<?php echo esc_attr( $state ); ?>"<?php endif; ?>
					<?php
					if ( 'active' === $state ) :
						?>
						aria-current="step"<?php endif; ?>
				>
					<span class="bn-stepper__dot" aria-hidden="true">
						<?php
						if ( 'done' === $state ) {
							buddynext_icon( 'check' );
						} else {
							echo esc_html( (string) $step_num );
						}
						?>
					</span>
					<span class="bn-ob-stepper__label"><?php echo esc_html( $step_info['label'] ); ?></span>
				</div>
				<?php if ( $idx < count( $step_keys ) - 1 ) : ?>
					<span class="bn-stepper__bar" aria-hidden="true"></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>

		<!-- ── Step 1: Profile ── -->
		<section class="bn-ob-step <?php echo 1 === $saved_step ? 'is-active' : ''; ?>"
			id="bn-ob-step-1"
			data-step="1"
			aria-labelledby="bn-ob-step-1-title"
			<?php echo 1 !== $saved_step ? 'hidden' : ''; ?>
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
						placeholder="<?php esc_attr_e( 'Your full name', 'buddynext' ); ?>" />
				</div>

				<div class="bn-ob-field">
					<label class="bn-ob-label" for="bn-ob-username">
						<?php esc_html_e( 'Username', 'buddynext' ); ?>
					</label>
					<input class="bn-input"
						type="text"
						id="bn-ob-username"
						name="user_login"
						value="<?php echo esc_attr( $current_login ); ?>"
						placeholder="@username"
						data-wp-on--input="actions.checkUsername" />
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
						placeholder="<?php esc_attr_e( 'Tell the community a bit about yourself...', 'buddynext' ); ?>"><?php echo esc_textarea( $bio ); ?></textarea>
				</div>

				<div class="bn-ob-field">
					<label class="bn-ob-label" for="bn-ob-location">
						<?php esc_html_e( 'Location', 'buddynext' ); ?>
						<span class="bn-ob-label__hint"><?php esc_html_e( '(optional)', 'buddynext' ); ?></span>
					</label>
					<input class="bn-input"
						type="text"
						id="bn-ob-location"
						name="bn_location"
						value="<?php echo esc_attr( $location ); ?>"
						placeholder="<?php esc_attr_e( 'City, Country', 'buddynext' ); ?>" />
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
					data-wp-on--click="actions.nextStep">
					<?php esc_html_e( 'Continue', 'buddynext' ); ?>
				</button>
			</div>

		</section>

		<!-- ── Step 2: Interests ── -->
		<section class="bn-ob-step <?php echo 2 === $saved_step ? 'is-active' : ''; ?>"
			id="bn-ob-step-2"
			data-step="2"
			aria-labelledby="bn-ob-step-2-title"
			<?php echo 2 !== $saved_step ? 'hidden' : ''; ?>
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'target' ); ?></span>
				<h1 id="bn-ob-step-2-title" class="bn-ob-step__title"><?php esc_html_e( 'Pick your interests', 'buddynext' ); ?></h1>
				<p class="bn-ob-step__sub"><?php esc_html_e( "We'll show you relevant posts and spaces based on what you choose.", 'buddynext' ); ?></p>
			</header>

			<div class="bn-card bn-ob-card" data-v2="true">
				<div class="bn-ob-chips">
					<?php foreach ( $all_interests as $interest ) : ?>
						<?php $is_selected = in_array( $interest['label'], $saved_interests, true ); ?>
						<button class="bn-badge bn-ob-chip"
							type="button"
							data-tone="<?php echo $is_selected ? 'accent' : 'neutral'; ?>"
							data-interest="<?php echo esc_attr( $interest['label'] ); ?>"
							aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>"
							data-wp-on--click="actions.toggleInterest">
							<span class="bn-ob-chip__icon" aria-hidden="true"><?php buddynext_icon( $interest['icon'] ); ?></span>
							<span class="bn-ob-chip__label"><?php echo esc_html( $interest['label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
				<p class="bn-ob-chips__count" data-wp-text="state.interestCountLabel">
					<?php
					$saved_count = count( $saved_interests );
					echo esc_html(
						sprintf(
							/* translators: %d: number of selected interests */
							_n( '%d selected', '%d selected', $saved_count, 'buddynext' ),
							$saved_count
						) . ' · ' . __( 'Pick at least 3', 'buddynext' )
					);
					?>
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
					<?php esc_html_e( 'Skip', 'buddynext' ); ?>
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

		<!-- ── Step 3: Spaces ── -->
		<section class="bn-ob-step <?php echo 3 === $saved_step ? 'is-active' : ''; ?>"
			id="bn-ob-step-3"
			data-step="3"
			aria-labelledby="bn-ob-step-3-title"
			<?php echo 3 !== $saved_step ? 'hidden' : ''; ?>
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'building' ); ?></span>
				<h1 id="bn-ob-step-3-title" class="bn-ob-step__title"><?php esc_html_e( 'Join some spaces', 'buddynext' ); ?></h1>
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
								data-wp-on--click="actions.toggleSpace">
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
					<?php esc_html_e( 'Skip', 'buddynext' ); ?>
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

		<!-- ── Step 4: Follow People ── -->
		<section class="bn-ob-step <?php echo 4 === $saved_step ? 'is-active' : ''; ?>"
			id="bn-ob-step-4"
			data-step="4"
			aria-labelledby="bn-ob-step-4-title"
			<?php echo 4 !== $saved_step ? 'hidden' : ''; ?>
		>

			<header class="bn-ob-step__head">
				<span class="bn-ob-step__icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></span>
				<h1 id="bn-ob-step-4-title" class="bn-ob-step__title"><?php esc_html_e( 'Follow some members', 'buddynext' ); ?></h1>
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
									data-wp-on--click="actions.toggleFollow">
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
				<span class="bn-ob-actions__spacer" aria-hidden="true"></span>
				<button class="bn-btn"
					type="button"
					data-variant="primary"
					data-size="lg"
					data-wp-on--click="actions.completeOnboarding">
					<?php esc_html_e( "Let's go", 'buddynext' ); ?>
				</button>
			</div>

		</section>

	</div>

</div>
