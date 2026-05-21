<?php
/**
 * BuddyNext — Edit Profile template (v2 design system).
 *
 * Context variables expected:
 *   $user_id  int  The ID of the profile being edited (always current user or admin).
 *
 * Composed from v2 primitives in bn-base.css: .bn-card, .bn-input, .bn-textarea,
 * .bn-btn[data-variant], .bn-badge, .bn-avatar, .bn-toggle, .bn-modal. Mirrors
 * the hero visual language of templates/profile/view.php so view + edit feel
 * like the same surface.
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
$profile_email_raw = $profile_user->user_email;
$user_login_str    = $profile_user->user_login;

// Avatar initials.
$name_parts = explode( ' ', $display_name );
$initials   = '';
foreach ( array_slice( $name_parts, 0, 2 ) as $part ) {
	$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
}
if ( ! $initials ) {
	$initials = mb_strtoupper( mb_substr( $user_login_str, 0, 2 ) );
}

$avatar_url = get_avatar_url( $user_id, array( 'size' => 192 ) );
$cover_url  = (string) get_user_meta( $user_id, 'buddynext_cover_url', true );

// Load profile through service — reads from bn_profile_values.
$service = buddynext_service( 'profiles' );
$profile = $service->get_profile( $user_id, $user_id );

// Build flat key=>value map for basic/social fields.
$fv = array();
if ( isset( $profile['groups'] ) ) {
	foreach ( $profile['groups'] as $grp ) {
		if ( 'flat' === $grp['type'] ) {
			foreach ( $grp['fields'] as $f ) {
				$fv[ $f['field_key'] ] = $f['value'] ?? '';
			}
		}
	}
}

// Repeater entries keyed by group_key.
$work_entries = array();
$edu_entries  = array();
if ( isset( $profile['groups'] ) ) {
	foreach ( $profile['groups'] as $grp ) {
		if ( 'repeater' === $grp['type'] ) {
			if ( 'work_experience' === $grp['group_key'] ) {
				$work_entries = $grp['entries'] ?? array();
			} elseif ( 'education' === $grp['group_key'] ) {
				$edu_entries = $grp['entries'] ?? array();
			}
		}
	}
}

// Convenience vars used in template.
$headline      = $fv['headline'] ?? '';
$bio           = $fv['bio'] ?? '';
$location      = $fv['location'] ?? '';
$website       = $fv['website'] ?? '';
$pronouns      = $fv['pronouns'] ?? '';
$interests_str = $fv['interests'] ?? '';
$interests     = array_filter( array_map( 'trim', explode( ',', $interests_str ) ) );

$social_twitter   = $fv['social_twitter'] ?? '';
$social_linkedin  = $fv['social_linkedin'] ?? '';
$social_github    = $fv['social_github'] ?? '';
$social_instagram = $fv['social_instagram'] ?? '';
$social_youtube   = $fv['social_youtube'] ?? '';

// Notification prefs (booleans stored in user meta).
$pref_email_replies  = (bool) get_user_meta( $user_id, 'bn_pref_email_replies', true );
$pref_email_mentions = (bool) get_user_meta( $user_id, 'bn_pref_email_mentions', true );
$pref_email_follows  = (bool) get_user_meta( $user_id, 'bn_pref_email_follows', true );
$pref_email_digest   = (bool) get_user_meta( $user_id, 'bn_pref_email_digest', true );

// Profile URL slug.
$profile_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
$profile_url  = \BuddyNext\Core\PageRouter::profile_url( $user_id );

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
<?php
/**
 * Fires before the profile edit inner content.
 *
 * @param int $user_id Profile being edited.
 */
do_action( 'buddynext_profile_edit_before', isset( $user_id ) ? (int) $user_id : 0 );
?>
<div class="bn-ep-wrap"
	data-wp-interactive="buddynext/profile"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'userId'        => $user_id,
			'restNonce'     => $rest_nonce,
			'saved'         => false,
			'saving'        => false,
			'interests'     => array_values( $interests ),
			'profileSlug'   => $profile_slug,
			'profileUrl'    => $profile_url,
			'slugAvailable' => null,
			'slugChecking'  => false,
			'slugSaved'     => false,
			'slugSaving'    => false,
			'workEntries'   => array_values( $work_entries ),
			'eduEntries'    => array_values( $edu_entries ),
			'deleteOpen'    => false,
			'deleteText'    => '',
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>

	<div class="bn-ep-shell">

		<!-- Page title -->
		<header class="bn-ep-title-row">
			<h1 class="bn-ep-title"><?php esc_html_e( 'Edit Profile', 'buddynext' ); ?></h1>
			<p class="bn-ep-subtitle"><?php esc_html_e( 'How others see you across the community.', 'buddynext' ); ?></p>
		</header>

		<!-- Main form column -->
		<main class="bn-ep-form">

			<!-- Hero card (mirrors view.php visual language) -->
			<section class="bn-pf-hero bn-card bn-ep-hero">
				<div class="bn-pf-cover<?php echo '' !== $cover_url ? ' bn-pf-cover--has-image' : ''; ?>"
					<?php if ( '' !== $cover_url ) : ?>
					style="background-image:url('<?php echo esc_url( $cover_url ); ?>');"<?php endif; ?>>
					<button class="bn-pf-cover__edit bn-ep-cover-btn"
						type="button"
						data-wp-on--click="actions.triggerCoverUpload">
						<?php buddynext_icon( 'camera' ); ?>
						<span><?php esc_html_e( 'Change cover', 'buddynext' ); ?></span>
					</button>
				</div>

				<div class="bn-pf-head bn-ep-hero-head">
					<div class="bn-pf-avatar-wrap bn-ep-avatar-wrap">
						<span class="bn-avatar" data-size="2xl">
							<?php if ( $avatar_url ) : ?>
								<img src="<?php echo esc_url( $avatar_url ); ?>"
									alt="<?php echo esc_attr( $display_name ); ?>" />
							<?php else : ?>
								<?php echo esc_html( $initials ); ?>
							<?php endif; ?>
						</span>
						<button class="bn-ep-avatar-btn"
							type="button"
							aria-label="<?php esc_attr_e( 'Change profile photo', 'buddynext' ); ?>"
							data-wp-on--click="actions.triggerAvatarUpload">
							<?php buddynext_icon( 'edit' ); ?>
						</button>
					</div>

					<div class="bn-pf-id bn-ep-hero-id">
						<div class="bn-ep-hero-field">
							<label class="bn-ep-hero-label" for="bn-ep-name">
								<?php esc_html_e( 'Display name', 'buddynext' ); ?>
							</label>
							<input class="bn-input bn-ep-hero-name"
								type="text"
								id="bn-ep-name"
								name="display_name"
								value="<?php echo esc_attr( $display_name ); ?>"
								placeholder="<?php esc_attr_e( 'Your full name', 'buddynext' ); ?>"
								data-wp-on--blur="actions.autosave" />
						</div>
						<div class="bn-ep-hero-field">
							<label class="bn-ep-hero-label" for="bn-ep-headline">
								<?php esc_html_e( 'Headline', 'buddynext' ); ?>
							</label>
							<input class="bn-input bn-ep-hero-headline"
								type="text"
								id="bn-ep-headline"
								name="headline"
								value="<?php echo esc_attr( $headline ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. Software Engineer at Acme Co.', 'buddynext' ); ?>"
								aria-describedby="bn-ep-headline-hint"
								data-wp-on--blur="actions.autosave" />
							<span class="bn-ep-hint" id="bn-ep-headline-hint">
								<?php esc_html_e( 'Shown under your name across the community.', 'buddynext' ); ?>
							</span>
						</div>
						<div class="bn-ep-hero-handle">
							<span class="bn-badge" data-tone="accent">@<?php echo esc_html( $user_login_str ); ?></span>
						</div>
					</div>
				</div>

				<input
					type="file"
					id="bn-ep-avatar-file"
					accept="image/jpeg,image/png,image/gif,image/webp"
					class="bn-ep-file-hidden"
					data-wp-on--change="actions.handleAvatarFileChange"
				/>
				<input
					type="file"
					id="bn-ep-cover-file"
					accept="image/jpeg,image/png,image/gif,image/webp"
					class="bn-ep-file-hidden"
					data-wp-on--change="actions.handleCoverFileChange"
				/>
			</section><!-- /hero -->

			<!-- Section: About -->
			<section class="bn-card bn-ep-card">
				<header class="bn-ep-card-header">
					<h2 class="bn-ep-card-title"><?php esc_html_e( 'About', 'buddynext' ); ?></h2>
					<p class="bn-ep-card-subtitle">
						<?php esc_html_e( 'Help people discover what you care about.', 'buddynext' ); ?>
					</p>
				</header>
				<div class="bn-ep-card-body">
					<div class="bn-ep-grid">
						<div class="bn-ep-field">
							<label class="bn-ep-label" for="bn-ep-location">
								<?php esc_html_e( 'Location', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="text"
								id="bn-ep-location"
								name="location"
								value="<?php echo esc_attr( $location ); ?>"
								placeholder="<?php esc_attr_e( 'City, Country', 'buddynext' ); ?>"
								data-wp-on--blur="actions.autosave" />
						</div>
						<div class="bn-ep-field">
							<label class="bn-ep-label" for="bn-ep-website">
								<?php esc_html_e( 'Website', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="url"
								id="bn-ep-website"
								name="website"
								value="<?php echo esc_attr( $website ); ?>"
								placeholder="https://yoursite.com"
								data-wp-on--blur="actions.autosave" />
						</div>
						<div class="bn-ep-field">
							<label class="bn-ep-label" for="bn-ep-pronouns">
								<?php esc_html_e( 'Pronouns', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="text"
								id="bn-ep-pronouns"
								name="pronouns"
								value="<?php echo esc_attr( $pronouns ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. they/them', 'buddynext' ); ?>"
								data-wp-on--blur="actions.autosave" />
						</div>
					</div>
					<div class="bn-ep-field bn-ep-field--full">
						<label class="bn-ep-label" for="bn-ep-bio">
							<?php esc_html_e( 'Bio', 'buddynext' ); ?>
						</label>
						<textarea class="bn-textarea"
							id="bn-ep-bio"
							name="bio"
							rows="4"
							placeholder="<?php esc_attr_e( 'Tell the community a bit about yourself…', 'buddynext' ); ?>"
							aria-describedby="bn-ep-bio-hint"
							data-wp-on--blur="actions.autosave"><?php echo esc_textarea( $bio ); ?></textarea>
						<span class="bn-ep-hint" id="bn-ep-bio-hint">
							<?php esc_html_e( 'A few words about yourself, your work, and what you post about.', 'buddynext' ); ?>
						</span>
					</div>
				</div>
			</section><!-- /About -->

			<!-- Section: Social Links -->
			<section class="bn-card bn-ep-card">
				<header class="bn-ep-card-header">
					<h2 class="bn-ep-card-title"><?php esc_html_e( 'Social Links', 'buddynext' ); ?></h2>
					<p class="bn-ep-card-subtitle">
						<?php esc_html_e( 'Linked accounts appear on your profile header.', 'buddynext' ); ?>
					</p>
				</header>
				<div class="bn-ep-card-body">
					<div class="bn-ep-grid">
						<div class="bn-ep-field">
							<label class="bn-ep-label" for="bn-ep-twitter">
								<?php esc_html_e( 'Twitter / X', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="url"
								id="bn-ep-twitter"
								name="social_twitter"
								value="<?php echo esc_attr( $social_twitter ); ?>"
								placeholder="https://twitter.com/you"
								data-wp-on--blur="actions.autosave" />
						</div>
						<div class="bn-ep-field">
							<label class="bn-ep-label" for="bn-ep-linkedin">
								<?php esc_html_e( 'LinkedIn', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="url"
								id="bn-ep-linkedin"
								name="social_linkedin"
								value="<?php echo esc_attr( $social_linkedin ); ?>"
								placeholder="https://linkedin.com/in/you"
								data-wp-on--blur="actions.autosave" />
						</div>
						<div class="bn-ep-field">
							<label class="bn-ep-label" for="bn-ep-github">
								<?php esc_html_e( 'GitHub', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="url"
								id="bn-ep-github"
								name="social_github"
								value="<?php echo esc_attr( $social_github ); ?>"
								placeholder="https://github.com/you"
								data-wp-on--blur="actions.autosave" />
						</div>
						<div class="bn-ep-field">
							<label class="bn-ep-label" for="bn-ep-instagram">
								<?php esc_html_e( 'Instagram', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="url"
								id="bn-ep-instagram"
								name="social_instagram"
								value="<?php echo esc_attr( $social_instagram ); ?>"
								placeholder="https://instagram.com/you"
								data-wp-on--blur="actions.autosave" />
						</div>
						<div class="bn-ep-field">
							<label class="bn-ep-label" for="bn-ep-youtube">
								<?php esc_html_e( 'YouTube', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="url"
								id="bn-ep-youtube"
								name="social_youtube"
								value="<?php echo esc_attr( $social_youtube ); ?>"
								placeholder="https://youtube.com/@you"
								data-wp-on--blur="actions.autosave" />
						</div>
					</div>
				</div>
			</section><!-- /Social Links -->

			<!-- Section: Work Experience -->
			<section class="bn-card bn-ep-card">
				<header class="bn-ep-card-header">
					<h2 class="bn-ep-card-title"><?php esc_html_e( 'Work Experience', 'buddynext' ); ?></h2>
				</header>
				<div class="bn-ep-card-body" id="bn-ep-work-entries">
					<?php foreach ( $work_entries as $idx => $entry ) : ?>
					<div class="bn-ep-repeater-entry" data-entry-index="<?php echo (int) $idx; ?>">
						<header class="bn-ep-repeater-header">
							<span class="bn-ep-repeater-num"><?php echo absint( $idx + 1 ); ?></span>
							<button class="bn-btn bn-ep-repeater-remove"
								type="button"
								data-variant="ghost"
								data-size="sm"
								data-group="work_experience"
								data-entry-index="<?php echo (int) $idx; ?>"
								data-wp-on--click="actions.removeEntry"
								aria-label="<?php esc_attr_e( 'Remove this position', 'buddynext' ); ?>">
								<?php buddynext_icon( 'x' ); ?>
							</button>
						</header>
						<div class="bn-ep-grid">
							<div class="bn-ep-field">
								<label class="bn-ep-label" for="bn-ep-work-company-<?php echo (int) $idx; ?>">
									<?php esc_html_e( 'Company', 'buddynext' ); ?>
								</label>
								<input class="bn-input"
									type="text"
									id="bn-ep-work-company-<?php echo (int) $idx; ?>"
									name="work_experience[<?php echo (int) $idx; ?>][work_company]"
									value="<?php echo esc_attr( $entry['work_company'] ?? '' ); ?>"
									placeholder="<?php esc_attr_e( 'Company name', 'buddynext' ); ?>"
									data-wp-on--blur="actions.autosave" />
							</div>
							<div class="bn-ep-field">
								<label class="bn-ep-label" for="bn-ep-work-title-<?php echo (int) $idx; ?>">
									<?php esc_html_e( 'Job title', 'buddynext' ); ?>
								</label>
								<input class="bn-input"
									type="text"
									id="bn-ep-work-title-<?php echo (int) $idx; ?>"
									name="work_experience[<?php echo (int) $idx; ?>][work_title]"
									value="<?php echo esc_attr( $entry['work_title'] ?? '' ); ?>"
									placeholder="<?php esc_attr_e( 'Your role', 'buddynext' ); ?>"
									data-wp-on--blur="actions.autosave" />
							</div>
							<div class="bn-ep-field">
								<label class="bn-ep-label" for="bn-ep-work-location-<?php echo (int) $idx; ?>">
									<?php esc_html_e( 'Location', 'buddynext' ); ?>
								</label>
								<input class="bn-input"
									type="text"
									id="bn-ep-work-location-<?php echo (int) $idx; ?>"
									name="work_experience[<?php echo (int) $idx; ?>][work_location]"
									value="<?php echo esc_attr( $entry['work_location'] ?? '' ); ?>"
									placeholder="<?php esc_attr_e( 'City or Remote', 'buddynext' ); ?>"
									data-wp-on--blur="actions.autosave" />
							</div>
							<div class="bn-ep-field">
								<label class="bn-ep-label" for="bn-ep-work-daterange-<?php echo (int) $idx; ?>">
									<?php esc_html_e( 'Date range', 'buddynext' ); ?>
								</label>
								<input class="bn-input"
									type="text"
									id="bn-ep-work-daterange-<?php echo (int) $idx; ?>"
									name="work_experience[<?php echo (int) $idx; ?>][work_daterange]"
									value="<?php echo esc_attr( $entry['work_daterange'] ?? '' ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. Jan 2020 to Present', 'buddynext' ); ?>"
									data-wp-on--blur="actions.autosave" />
							</div>
						</div>
						<div class="bn-ep-field bn-ep-field--full">
							<label class="bn-ep-label" for="bn-ep-work-description-<?php echo (int) $idx; ?>">
								<?php esc_html_e( 'Description', 'buddynext' ); ?>
							</label>
							<textarea class="bn-textarea"
								id="bn-ep-work-description-<?php echo (int) $idx; ?>"
								rows="3"
								name="work_experience[<?php echo (int) $idx; ?>][work_description]"
								placeholder="<?php esc_attr_e( 'Brief description of your role', 'buddynext' ); ?>"
								data-wp-on--blur="actions.autosave"><?php echo esc_textarea( $entry['work_description'] ?? '' ); ?></textarea>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<footer class="bn-ep-card-footer">
					<button class="bn-btn bn-ep-add-entry"
						type="button"
						data-variant="ghost"
						data-size="sm"
						data-group="work_experience"
						data-wp-on--click="actions.addEntry">
						<?php buddynext_icon( 'plus' ); ?>
						<span><?php esc_html_e( 'Add position', 'buddynext' ); ?></span>
					</button>
				</footer>
			</section><!-- /Work -->

			<!-- Section: Education -->
			<section class="bn-card bn-ep-card">
				<header class="bn-ep-card-header">
					<h2 class="bn-ep-card-title"><?php esc_html_e( 'Education', 'buddynext' ); ?></h2>
				</header>
				<div class="bn-ep-card-body" id="bn-ep-edu-entries">
					<?php foreach ( $edu_entries as $idx => $entry ) : ?>
					<div class="bn-ep-repeater-entry" data-entry-index="<?php echo (int) $idx; ?>">
						<header class="bn-ep-repeater-header">
							<span class="bn-ep-repeater-num"><?php echo absint( $idx + 1 ); ?></span>
							<button class="bn-btn bn-ep-repeater-remove"
								type="button"
								data-variant="ghost"
								data-size="sm"
								data-group="education"
								data-entry-index="<?php echo (int) $idx; ?>"
								data-wp-on--click="actions.removeEntry"
								aria-label="<?php esc_attr_e( 'Remove this entry', 'buddynext' ); ?>">
								<?php buddynext_icon( 'x' ); ?>
							</button>
						</header>
						<div class="bn-ep-grid">
							<div class="bn-ep-field">
								<label class="bn-ep-label" for="bn-ep-edu-institution-<?php echo (int) $idx; ?>">
									<?php esc_html_e( 'Institution', 'buddynext' ); ?>
								</label>
								<input class="bn-input"
									type="text"
									id="bn-ep-edu-institution-<?php echo (int) $idx; ?>"
									name="education[<?php echo (int) $idx; ?>][edu_institution]"
									value="<?php echo esc_attr( $entry['edu_institution'] ?? '' ); ?>"
									placeholder="<?php esc_attr_e( 'School or University', 'buddynext' ); ?>"
									data-wp-on--blur="actions.autosave" />
							</div>
							<div class="bn-ep-field">
								<label class="bn-ep-label" for="bn-ep-edu-degree-<?php echo (int) $idx; ?>">
									<?php esc_html_e( 'Degree', 'buddynext' ); ?>
								</label>
								<input class="bn-input"
									type="text"
									id="bn-ep-edu-degree-<?php echo (int) $idx; ?>"
									name="education[<?php echo (int) $idx; ?>][edu_degree]"
									value="<?php echo esc_attr( $entry['edu_degree'] ?? '' ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. Bachelor of Science', 'buddynext' ); ?>"
									data-wp-on--blur="actions.autosave" />
							</div>
							<div class="bn-ep-field">
								<label class="bn-ep-label" for="bn-ep-edu-field-<?php echo (int) $idx; ?>">
									<?php esc_html_e( 'Field of study', 'buddynext' ); ?>
								</label>
								<input class="bn-input"
									type="text"
									id="bn-ep-edu-field-<?php echo (int) $idx; ?>"
									name="education[<?php echo (int) $idx; ?>][edu_field]"
									value="<?php echo esc_attr( $entry['edu_field'] ?? '' ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. Computer Science', 'buddynext' ); ?>"
									data-wp-on--blur="actions.autosave" />
							</div>
							<div class="bn-ep-field">
								<label class="bn-ep-label" for="bn-ep-edu-daterange-<?php echo (int) $idx; ?>">
									<?php esc_html_e( 'Date range', 'buddynext' ); ?>
								</label>
								<input class="bn-input"
									type="text"
									id="bn-ep-edu-daterange-<?php echo (int) $idx; ?>"
									name="education[<?php echo (int) $idx; ?>][edu_daterange]"
									value="<?php echo esc_attr( $entry['edu_daterange'] ?? '' ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. 2016 to 2020', 'buddynext' ); ?>"
									data-wp-on--blur="actions.autosave" />
							</div>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<footer class="bn-ep-card-footer">
					<button class="bn-btn bn-ep-add-entry"
						type="button"
						data-variant="ghost"
						data-size="sm"
						data-group="education"
						data-wp-on--click="actions.addEntry">
						<?php buddynext_icon( 'plus' ); ?>
						<span><?php esc_html_e( 'Add education', 'buddynext' ); ?></span>
					</button>
				</footer>
			</section><!-- /Education -->

			<!-- Section: Community Interests -->
			<section class="bn-card bn-ep-card">
				<header class="bn-ep-card-header">
					<h2 class="bn-ep-card-title"><?php esc_html_e( 'Community Interests', 'buddynext' ); ?></h2>
					<p class="bn-ep-card-subtitle">
						<?php esc_html_e( 'Used to personalise your feed and discovery.', 'buddynext' ); ?>
					</p>
				</header>
				<div class="bn-ep-card-body">
					<div class="bn-ep-field bn-ep-field--full">
						<label class="bn-ep-label" for="bn-ep-tag-input">
							<?php esc_html_e( 'Interests', 'buddynext' ); ?>
						</label>
						<div class="bn-ep-tags-area"
							data-wp-on--click="actions.focusTagInput">
							<?php foreach ( $interests as $interest ) : ?>
								<span class="bn-badge bn-ep-tag" data-tone="accent">
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
										<?php buddynext_icon( 'x' ); ?>
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
			</section><!-- /Interests -->

			<!-- Section: Notification preferences (toggles) -->
			<section class="bn-card bn-ep-card">
				<header class="bn-ep-card-header">
					<h2 class="bn-ep-card-title"><?php esc_html_e( 'Notification preferences', 'buddynext' ); ?></h2>
					<p class="bn-ep-card-subtitle">
						<?php esc_html_e( 'Choose which emails you receive.', 'buddynext' ); ?>
					</p>
				</header>
				<div class="bn-ep-card-body bn-ep-toggles">
					<div class="bn-toggle-row">
						<div class="bn-toggle-row__copy">
							<div class="bn-toggle-row__label" id="bn-ep-pref-replies-lbl">
								<?php esc_html_e( 'Replies to your posts', 'buddynext' ); ?>
							</div>
							<div class="bn-toggle-row__desc">
								<?php esc_html_e( 'Email me when someone replies to a post I made.', 'buddynext' ); ?>
							</div>
						</div>
						<button class="bn-toggle"
							type="button"
							role="switch"
							aria-labelledby="bn-ep-pref-replies-lbl"
							aria-checked="<?php echo $pref_email_replies ? 'true' : 'false'; ?>"
							data-pref="bn_pref_email_replies"
							data-wp-on--click="actions.togglePref">
						</button>
					</div>
					<div class="bn-toggle-row">
						<div class="bn-toggle-row__copy">
							<div class="bn-toggle-row__label" id="bn-ep-pref-mentions-lbl">
								<?php esc_html_e( 'Mentions', 'buddynext' ); ?>
							</div>
							<div class="bn-toggle-row__desc">
								<?php esc_html_e( 'Email me when someone @mentions me.', 'buddynext' ); ?>
							</div>
						</div>
						<button class="bn-toggle"
							type="button"
							role="switch"
							aria-labelledby="bn-ep-pref-mentions-lbl"
							aria-checked="<?php echo $pref_email_mentions ? 'true' : 'false'; ?>"
							data-pref="bn_pref_email_mentions"
							data-wp-on--click="actions.togglePref">
						</button>
					</div>
					<div class="bn-toggle-row">
						<div class="bn-toggle-row__copy">
							<div class="bn-toggle-row__label" id="bn-ep-pref-follows-lbl">
								<?php esc_html_e( 'New followers', 'buddynext' ); ?>
							</div>
							<div class="bn-toggle-row__desc">
								<?php esc_html_e( 'Email me when someone follows me.', 'buddynext' ); ?>
							</div>
						</div>
						<button class="bn-toggle"
							type="button"
							role="switch"
							aria-labelledby="bn-ep-pref-follows-lbl"
							aria-checked="<?php echo $pref_email_follows ? 'true' : 'false'; ?>"
							data-pref="bn_pref_email_follows"
							data-wp-on--click="actions.togglePref">
						</button>
					</div>
					<div class="bn-toggle-row">
						<div class="bn-toggle-row__copy">
							<div class="bn-toggle-row__label" id="bn-ep-pref-digest-lbl">
								<?php esc_html_e( 'Weekly digest', 'buddynext' ); ?>
							</div>
							<div class="bn-toggle-row__desc">
								<?php esc_html_e( 'A weekly summary of activity in your community.', 'buddynext' ); ?>
							</div>
						</div>
						<button class="bn-toggle"
							type="button"
							role="switch"
							aria-labelledby="bn-ep-pref-digest-lbl"
							aria-checked="<?php echo $pref_email_digest ? 'true' : 'false'; ?>"
							data-pref="bn_pref_email_digest"
							data-wp-on--click="actions.togglePref">
						</button>
					</div>
				</div>
			</section><!-- /Notifications -->

			<!-- Section: Account -->
			<section class="bn-card bn-ep-card">
				<header class="bn-ep-card-header">
					<h2 class="bn-ep-card-title"><?php esc_html_e( 'Account', 'buddynext' ); ?></h2>
				</header>
				<div class="bn-ep-card-body bn-ep-account-rows">
					<!-- Profile URL row -->
					<div class="bn-ep-field bn-ep-field--full bn-ep-slug-row">
						<label class="bn-ep-label" for="bn-ep-slug">
							<?php esc_html_e( 'Profile URL', 'buddynext' ); ?>
						</label>
						<div class="bn-ep-slug-field">
							<span class="bn-ep-slug-base">
								<?php echo esc_html( rtrim( \BuddyNext\Core\PageRouter::people_url(), '/' ) ); ?>/
							</span>
							<div class="bn-ep-slug-input-wrap">
								<input class="bn-input bn-ep-slug-input"
									type="text"
									id="bn-ep-slug"
									autocomplete="off"
									spellcheck="false"
									value="<?php echo esc_attr( $profile_slug ); ?>"
									placeholder="<?php esc_attr_e( 'your-custom-url', 'buddynext' ); ?>"
									aria-describedby="bn-ep-slug-status"
									data-wp-on--input="actions.checkSlug" />
								<span class="bn-ep-slug-indicator"
									id="bn-ep-slug-status"
									data-wp-bind--hidden="context.slugChecking || context.slugAvailable === null"
									data-wp-class--bn-ep-slug-ok="context.slugAvailable === true"
									data-wp-class--bn-ep-slug-err="context.slugAvailable === false">
									<span data-wp-bind--hidden="!context.slugAvailable"><?php buddynext_icon( 'check' ); ?></span>
									<span data-wp-bind--hidden="context.slugAvailable !== false"><?php esc_html_e( 'Taken', 'buddynext' ); ?></span>
								</span>
							</div>
							<button class="bn-btn"
								type="button"
								data-variant="secondary"
								data-size="md"
								data-wp-on--click="actions.saveSlug"
								data-wp-bind--disabled="!context.slugAvailable || context.slugSaving">
								<span data-wp-bind--hidden="context.slugSaved"><?php esc_html_e( 'Update URL', 'buddynext' ); ?></span>
								<span data-wp-bind--hidden="!context.slugSaved"><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Saved', 'buddynext' ); ?></span>
							</button>
						</div>
					</div>

					<div class="bn-ep-account-row">
						<div class="bn-ep-account-copy">
							<div class="bn-ep-account-label"><?php esc_html_e( 'Email address', 'buddynext' ); ?></div>
							<div class="bn-ep-account-value"><?php echo esc_html( $profile_email_raw ); ?></div>
						</div>
						<a class="bn-btn"
							data-variant="ghost"
							data-size="sm"
							href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">
							<?php esc_html_e( 'Change', 'buddynext' ); ?>
						</a>
					</div>

					<div class="bn-ep-account-row">
						<div class="bn-ep-account-copy">
							<div class="bn-ep-account-label"><?php esc_html_e( 'Password', 'buddynext' ); ?></div>
							<div class="bn-ep-account-value">
								<?php esc_html_e( 'Reset your account password.', 'buddynext' ); ?>
							</div>
						</div>
						<a class="bn-btn"
							data-variant="ghost"
							data-size="sm"
							href="<?php echo esc_url( wp_lostpassword_url() ); ?>">
							<?php esc_html_e( 'Reset', 'buddynext' ); ?>
						</a>
					</div>

					<div class="bn-ep-account-row">
						<div class="bn-ep-account-copy">
							<div class="bn-ep-account-label"><?php esc_html_e( 'All notifications', 'buddynext' ); ?></div>
							<div class="bn-ep-account-value">
								<?php esc_html_e( 'Full notification settings page.', 'buddynext' ); ?>
							</div>
						</div>
						<a class="bn-btn"
							data-variant="ghost"
							data-size="sm"
							href="<?php echo esc_url( \BuddyNext\Core\PageRouter::notifications_url() ); ?>">
							<?php esc_html_e( 'Manage', 'buddynext' ); ?>
						</a>
					</div>
				</div>
			</section><!-- /Account -->

			<!-- Section: Danger zone -->
			<section class="bn-card bn-ep-card bn-ep-danger" aria-labelledby="bn-ep-danger-title">
				<header class="bn-ep-card-header">
					<h2 class="bn-ep-card-title" id="bn-ep-danger-title">
						<?php esc_html_e( 'Danger zone', 'buddynext' ); ?>
					</h2>
					<p class="bn-ep-card-subtitle">
						<?php esc_html_e( 'Permanently delete your account. This cannot be undone.', 'buddynext' ); ?>
					</p>
				</header>
				<div class="bn-ep-card-body">
					<button class="bn-btn"
						type="button"
						data-variant="danger"
						data-size="md"
						data-wp-on--click="actions.openDelete">
						<?php esc_html_e( 'Delete account', 'buddynext' ); ?>
					</button>
				</div>
			</section><!-- /Danger zone -->

		</main><!-- /form area -->

		<!-- Sidebar -->
		<aside class="bn-ep-sidebar" aria-label="<?php esc_attr_e( 'Profile preview', 'buddynext' ); ?>">

			<!-- Profile preview -->
			<section class="bn-card bn-ep-preview-card">
				<header class="bn-ep-preview-header">
					<?php esc_html_e( 'Profile Preview', 'buddynext' ); ?>
				</header>
				<div class="bn-ep-preview-body">
					<span class="bn-avatar bn-ep-preview-avatar" data-size="lg">
						<?php if ( $avatar_url ) : ?>
							<img src="<?php echo esc_url( $avatar_url ); ?>"
								alt="<?php echo esc_attr( $display_name ); ?>" />
						<?php else : ?>
							<?php echo esc_html( $initials ); ?>
						<?php endif; ?>
					</span>
					<div class="bn-ep-preview-name"><?php echo esc_html( $display_name ); ?></div>
					<div class="bn-ep-preview-headline">
						<?php echo esc_html( $headline ? $headline : $location ); ?>
					</div>
					<div class="bn-ep-preview-stats">
						<div class="bn-ep-preview-stat">
							<div class="bn-ep-preview-stat-num"><?php echo esc_html( $format_count( $post_count ) ); ?></div>
							<div class="bn-ep-preview-stat-lbl"><?php esc_html_e( 'Posts', 'buddynext' ); ?></div>
						</div>
						<div class="bn-ep-preview-stat">
							<div class="bn-ep-preview-stat-num"><?php echo esc_html( $format_count( $follower_count ) ); ?></div>
							<div class="bn-ep-preview-stat-lbl"><?php esc_html_e( 'Followers', 'buddynext' ); ?></div>
						</div>
						<div class="bn-ep-preview-stat">
							<div class="bn-ep-preview-stat-num"><?php echo esc_html( $format_count( $following_count ) ); ?></div>
							<div class="bn-ep-preview-stat-lbl"><?php esc_html_e( 'Following', 'buddynext' ); ?></div>
						</div>
					</div>
				</div>
				<footer class="bn-ep-preview-note">
					<?php esc_html_e( 'How other members see your profile card across the community.', 'buddynext' ); ?>
				</footer>
			</section>

			<!-- Field visibility guide -->
			<section class="bn-card bn-ep-visibility-card">
				<header class="bn-ep-vis-title">
					<?php esc_html_e( 'Field Visibility', 'buddynext' ); ?>
				</header>
				<div class="bn-ep-vis-row">
					<span class="bn-ep-vis-dot bn-ep-vis-dot--public" aria-hidden="true"></span>
					<div class="bn-ep-vis-label">
						<strong><?php esc_html_e( 'Public', 'buddynext' ); ?></strong>
						<span><?php esc_html_e( 'visible to everyone', 'buddynext' ); ?></span>
					</div>
				</div>
				<div class="bn-ep-vis-row">
					<span class="bn-ep-vis-dot bn-ep-vis-dot--followers" aria-hidden="true"></span>
					<div class="bn-ep-vis-label">
						<strong><?php esc_html_e( 'Followers', 'buddynext' ); ?></strong>
						<span><?php esc_html_e( 'logged-in followers only', 'buddynext' ); ?></span>
					</div>
				</div>
				<div class="bn-ep-vis-row">
					<span class="bn-ep-vis-dot bn-ep-vis-dot--private" aria-hidden="true"></span>
					<div class="bn-ep-vis-label">
						<strong><?php esc_html_e( 'Private', 'buddynext' ); ?></strong>
						<span><?php esc_html_e( 'only you can see', 'buddynext' ); ?></span>
					</div>
				</div>
				<footer class="bn-ep-vis-note">
					<?php esc_html_e( 'Each field has its own visibility control in the full field editor.', 'buddynext' ); ?>
				</footer>
			</section>

		</aside><!-- /sidebar -->

	</div><!-- /bn-ep-shell -->

	<!-- Sticky save bar -->
	<div class="bn-ep-save-bar" role="region" aria-label="<?php esc_attr_e( 'Save changes', 'buddynext' ); ?>">
		<div class="bn-ep-save-bar-inner">
			<div class="bn-ep-save-status"
				data-wp-bind--hidden="!context.saved">
				<?php buddynext_icon( 'check' ); ?>
				<span><?php esc_html_e( 'All changes saved', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ep-save-actions">
				<a class="bn-btn"
					data-variant="ghost"
					data-size="md"
					href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $user_id ) ); ?>">
					<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
				</a>
				<button class="bn-btn"
					type="button"
					data-variant="primary"
					data-size="md"
					data-wp-on--click="actions.saveProfile"
					data-wp-bind--disabled="context.saving">
					<?php esc_html_e( 'Save changes', 'buddynext' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Delete account modal -->
	<div class="bn-modal-backdrop bn-ep-delete-backdrop"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bn-ep-delete-title"
		data-wp-bind--hidden="!context.deleteOpen">
		<div class="bn-modal__panel" data-tone="danger" data-size="sm">
			<header class="bn-modal__head">
				<h2 class="bn-modal__title" id="bn-ep-delete-title">
					<?php esc_html_e( 'Delete account?', 'buddynext' ); ?>
				</h2>
				<button class="bn-modal__close"
					type="button"
					aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
					data-wp-on--click="actions.closeDelete">
					<?php buddynext_icon( 'x' ); ?>
				</button>
			</header>
			<div class="bn-modal__body">
				<p>
					<?php esc_html_e( 'This permanently deletes your profile, posts, replies, follows, and uploaded media. This cannot be undone.', 'buddynext' ); ?>
				</p>
				<div class="bn-ep-field">
					<label class="bn-ep-label" for="bn-ep-delete-confirm">
						<?php
						printf(
							/* translators: %s: required confirmation word */
							esc_html__( 'Type %s to confirm', 'buddynext' ),
							'<strong>DELETE</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						);
						?>
					</label>
					<input class="bn-input"
						type="text"
						id="bn-ep-delete-confirm"
						autocomplete="off"
						spellcheck="false"
						data-wp-on--input="actions.updateDeleteText" />
				</div>
			</div>
			<footer class="bn-modal__foot">
				<button class="bn-btn"
					type="button"
					data-variant="ghost"
					data-size="md"
					data-wp-on--click="actions.closeDelete">
					<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
				</button>
				<button class="bn-btn"
					type="button"
					data-variant="danger"
					data-size="md"
					data-wp-bind--disabled="context.deleteText !== 'DELETE'"
					data-wp-on--click="actions.confirmDelete">
					<?php esc_html_e( 'Delete account', 'buddynext' ); ?>
				</button>
			</footer>
		</div>
	</div>

</div><!-- /bn-ep-wrap -->
