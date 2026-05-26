<?php
/**
 * BuddyNext — Edit Profile template (v2 design system).
 *
 * Composer template. Resolves the editing user, loads profile/social
 * data, prepares stats for the sidebar, then delegates rendering to the
 * `templates/parts/profile-edit-*` parts.
 *
 * Context variables expected:
 *   $user_id  int  The ID of the profile being edited (always current user or admin).
 *
 * Composed from v2 primitives in bn-base.css. Mirrors the hero visual
 * language of `templates/profile/view.php` so view + edit feel like the
 * same surface. Saves via REST POST `buddynext/v1/profile/me` (JSON,
 * nonce in X-WP-Nonce header). Cover/avatar upload via REST POST
 * `buddynext/v1/profile/avatar`.
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

// Privacy prefs (audience selects + booleans stored in user meta).
$privacy_audiences = array(
	'everyone'    => __( 'Everyone', 'buddynext' ),
	'members'     => __( 'Members only', 'buddynext' ),
	'connections' => __( 'My connections', 'buddynext' ),
	'nobody'      => __( 'Nobody', 'buddynext' ),
);

$privacy_see_email = (string) get_user_meta( $user_id, 'bn_privacy_see_email', true );
if ( '' === $privacy_see_email ) {
	$privacy_see_email = 'connections';
}
$privacy_dm = (string) get_user_meta( $user_id, 'bn_privacy_dm', true );
if ( '' === $privacy_dm ) {
	$privacy_dm = 'members';
}
$privacy_mention = (string) get_user_meta( $user_id, 'bn_privacy_mention', true );
if ( '' === $privacy_mention ) {
	$privacy_mention = 'everyone';
}
$privacy_show_in_directory = '0' !== (string) get_user_meta( $user_id, 'bn_privacy_show_in_directory', true );
$privacy_search_indexable  = '0' !== (string) get_user_meta( $user_id, 'bn_privacy_search_indexable', true );
// `bn_pro_hide_profile_views` is the canonical Pro-shared key (Pro P5.3 reads
// it to opt the viewer out of the who-viewed-your-profile widget). Free can
// save it too so the toggle stays consistent across plans.
$privacy_hide_views     = '1' === (string) get_user_meta( $user_id, 'bn_pro_hide_profile_views', true );
$privacy_account_private = (bool) get_user_meta( $user_id, 'bn_account_private', true );

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

$rest_nonce      = wp_create_nonce( 'wp_rest' );
$pending_email   = (string) get_user_meta( $user_id, 'bn_pending_email', true );
$people_url_base = rtrim( \BuddyNext\Core\PageRouter::people_url(), '/' );
$prefs_url       = \BuddyNext\Core\PageRouter::notification_prefs_url();

/**
 * Render a single non-validated text/url/email/select input using the
 * `parts/profile-field.php` part. Returns the rendered HTML so the
 * composer can hand it to `parts/profile-edit-section.php` via
 * `body_html`.
 *
 * @param array $field Field descriptor (see profile-field.php).
 * @return string Rendered HTML.
 */
$bn_render_field = static function ( array $field ): string {
	ob_start();
	buddynext_get_template( 'parts/profile-field.php', array( 'field' => $field ) );
	return (string) ob_get_clean();
};

/**
 * Render a notif/privacy toggle or audience-select row, returning HTML.
 *
 * @param string $part Template part name (without .php).
 * @param array  $vars Args for the part.
 * @return string Rendered HTML.
 */
$bn_capture = static function ( string $part, array $vars ): string {
	ob_start();
	buddynext_get_template( 'parts/' . $part . '.php', $vars );
	return (string) ob_get_clean();
};

/**
 * Fires before the profile edit inner content.
 *
 * @param int $user_id Profile being edited.
 */
do_action( 'buddynext_profile_edit_before', isset( $user_id ) ? (int) $user_id : 0 );
?>
<div class="bn-ep-wrap"
	data-wp-interactive="buddynext/profile"
	data-wp-init="callbacks.initEditGuard"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'userId'                   => $user_id,
			'restNonce'                => $rest_nonce,
			'saved'                    => false,
			'saving'                   => false,
			'isDirty'                  => false,
			'errors'                   => (object) array(),
			'interests'                => array_values( $interests ),
			'profileSlug'              => $profile_slug,
			'profileUrl'               => $profile_url,
			'slugAvailable'            => null,
			'slugChecking'             => false,
			'slugSaved'                => false,
			'slugSaving'               => false,
			'workEntries'              => array_values( $work_entries ),
			'eduEntries'               => array_values( $edu_entries ),
			'deleteOpen'               => false,
			'deleteText'               => '',
			'emailChangeOpen'          => false,
			'emailChangeSubmitting'    => false,
			'passwordChangeOpen'       => false,
			'passwordChangeSubmitting' => false,
			'passwordStrength'         => 0,
			'passwordStrengthLabel'    => '',
			'signOutSubmitting'        => false,
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>

	<form class="bn-ep-form-shell"
		data-wp-on--submit="actions.saveProfile"
		data-wp-on--input="actions.markDirty"
		data-wp-on--change="actions.markDirty"
		novalidate>

	<div class="bn-ep-shell">

		<!-- Page title -->
		<header class="bn-ep-title-row">
			<h1 class="bn-ep-title">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: member display name */
						__( 'Edit Profile · %s', 'buddynext' ),
						$display_name
					)
				);
				?>
			</h1>
			<p class="bn-ep-subtitle"><?php esc_html_e( 'How others see you across the community.', 'buddynext' ); ?></p>
		</header>

		<!-- Main form column -->
		<main class="bn-ep-form">

			<?php
			// Hero card.
			buddynext_get_template(
				'parts/profile-edit-hero.php',
				array(
					'profile_user_id' => $user_id,
					'display_name'    => $display_name,
					'headline'        => $headline,
					'username'        => $user_login_str,
					'avatar_url'      => $avatar_url,
					'cover_url'       => $cover_url,
					'initials'        => $initials,
				)
			);

			// About section — three grid fields + a full-width Bio textarea.
			$about_grid = array(
				array( 'text', 'location', __( 'Location', 'buddynext' ), $location, __( 'City, Country', 'buddynext' ), false ),
				array( 'url', 'website', __( 'Website', 'buddynext' ), $website, 'https://yoursite.com', true ),
				array( 'text', 'pronouns', __( 'Pronouns', 'buddynext' ), $pronouns, __( 'e.g. they/them', 'buddynext' ), false ),
			);
			$about_html = '<div class="bn-ep-grid">';
			foreach ( $about_grid as $f ) {
				$about_html .= $bn_render_field(
					array(
						'type'             => $f[0],
						'key'              => $f[1],
						'label'            => $f[2],
						'value'            => $f[3],
						'placeholder'      => $f[4],
						'validate_on_blur' => $f[5],
						'autosave_on_blur' => ! $f[5],
					)
				);
			}
			$about_html .= '</div>';
			$about_html .= $bn_render_field(
				array(
					'type'             => 'textarea',
					'key'              => 'bio',
					'label'            => __( 'Bio', 'buddynext' ),
					'value'            => $bio,
					'placeholder'      => __( 'Tell the community a bit about yourself…', 'buddynext' ),
					'hint'             => __( 'A few words about yourself, your work, and what you post about.', 'buddynext' ),
					'full_width'       => true,
					'rows'             => 4,
					'field_id'         => 'bn-ep-bio',
					'autosave_on_blur' => true,
				)
			);
			buddynext_get_template(
				'parts/profile-edit-section.php',
				array(
					'title'     => __( 'About', 'buddynext' ),
					'subtitle'  => __( 'Help people discover what you care about.', 'buddynext' ),
					'body_html' => $about_html,
				)
			);

			// Social Links section — five URL fields with validate-on-blur wiring.
			$socials     = array(
				array( 'social_twitter', __( 'Twitter / X', 'buddynext' ), 'https://twitter.com/you', $social_twitter ),
				array( 'social_linkedin', __( 'LinkedIn', 'buddynext' ), 'https://linkedin.com/in/you', $social_linkedin ),
				array( 'social_github', __( 'GitHub', 'buddynext' ), 'https://github.com/you', $social_github ),
				array( 'social_instagram', __( 'Instagram', 'buddynext' ), 'https://instagram.com/you', $social_instagram ),
				array( 'social_youtube', __( 'YouTube', 'buddynext' ), 'https://youtube.com/@you', $social_youtube ),
			);
			$social_html = '<div class="bn-ep-grid">';
			foreach ( $socials as $bn_social ) {
				$social_html .= $bn_render_field(
					array(
						'type'             => 'url',
						'key'              => $bn_social[0],
						'label'            => $bn_social[1],
						'value'            => $bn_social[3],
						'placeholder'      => $bn_social[2],
						'validate_on_blur' => true,
					)
				);
			}
			$social_html .= '</div>';
			buddynext_get_template(
				'parts/profile-edit-section.php',
				array(
					'title'     => __( 'Social Links', 'buddynext' ),
					'subtitle'  => __( 'Linked accounts appear on your profile header.', 'buddynext' ),
					'body_html' => $social_html,
				)
			);

			// Work Experience + Education — both use the field-group part,
			// wrapped inline because field-group emits body + footer (not a
			// `<section>` shell) by design.
			$repeater_groups = array(
				array(
					'title'    => __( 'Work Experience', 'buddynext' ),
					'field'    => array(
						'group_key'         => 'work_experience',
						'id_prefix'         => 'bn-ep-work-',
						'add_label'         => __( 'Add position', 'buddynext' ),
						'remove_aria_label' => __( 'Remove this position', 'buddynext' ),
						'fields'            => array(
							array(
								'key'         => 'work_company',
								'label'       => __( 'Company', 'buddynext' ),
								'placeholder' => __( 'Company name', 'buddynext' ),
							),
							array(
								'key'         => 'work_title',
								'label'       => __( 'Job title', 'buddynext' ),
								'placeholder' => __( 'Your role', 'buddynext' ),
							),
							array(
								'key'         => 'work_location',
								'label'       => __( 'Location', 'buddynext' ),
								'placeholder' => __( 'City or Remote', 'buddynext' ),
							),
							array(
								'key'         => 'work_daterange',
								'label'       => __( 'Date range', 'buddynext' ),
								'placeholder' => __( 'e.g. Jan 2020 to Present', 'buddynext' ),
							),
						),
						'description_field' => array(
							'key'         => 'work_description',
							'label'       => __( 'Description', 'buddynext' ),
							'placeholder' => __( 'Brief description of your role', 'buddynext' ),
							'rows'        => 3,
						),
					),
					'entries'  => $work_entries,
					'group_id' => 'bn-ep-work-entries',
				),
				array(
					'title'    => __( 'Education', 'buddynext' ),
					'field'    => array(
						'group_key'         => 'education',
						'id_prefix'         => 'bn-ep-edu-',
						'add_label'         => __( 'Add education', 'buddynext' ),
						'remove_aria_label' => __( 'Remove this entry', 'buddynext' ),
						'fields'            => array(
							array(
								'key'         => 'edu_institution',
								'label'       => __( 'Institution', 'buddynext' ),
								'placeholder' => __( 'School or University', 'buddynext' ),
							),
							array(
								'key'         => 'edu_degree',
								'label'       => __( 'Degree', 'buddynext' ),
								'placeholder' => __( 'e.g. Bachelor of Science', 'buddynext' ),
							),
							array(
								'key'         => 'edu_field',
								'label'       => __( 'Field of study', 'buddynext' ),
								'placeholder' => __( 'e.g. Computer Science', 'buddynext' ),
							),
							array(
								'key'         => 'edu_daterange',
								'label'       => __( 'Date range', 'buddynext' ),
								'placeholder' => __( 'e.g. 2016 to 2020', 'buddynext' ),
							),
						),
					),
					'entries'  => $edu_entries,
					'group_id' => 'bn-ep-edu-entries',
				),
			);
			foreach ( $repeater_groups as $g ) {
				echo '<section class="bn-card bn-ep-card"><header class="bn-ep-card-header"><h2 class="bn-ep-card-title">' . esc_html( $g['title'] ) . '</h2></header>';
				buddynext_get_template(
					'parts/profile-field-group.php',
					array(
						'field'    => $g['field'],
						'entries'  => $g['entries'],
						'group_id' => $g['group_id'],
					)
				);
				echo '</section>';
			}

			// Community Interests — interactive tag editor, kept inline because
			// the tag-list is driven by the Interactivity context and isn't a
			// generic field shape worth abstracting at this time.
			ob_start();
			?>
			<div class="bn-ep-field bn-ep-field--full">
				<label class="bn-ep-label" for="bn-ep-tag-input"><?php esc_html_e( 'Interests', 'buddynext' ); ?></label>
				<div class="bn-ep-tags-area" data-wp-on--click="actions.focusTagInput">
					<?php foreach ( $interests as $interest ) : ?>
						<span class="bn-badge bn-ep-tag" data-tone="accent">
							#<?php echo esc_html( $interest ); ?>
							<button class="bn-ep-tag-remove"
								type="button"
								<?php /* translators: %s: interest tag name */ $remove_label = sprintf( __( 'Remove interest: %s', 'buddynext' ), $interest ); ?>
								aria-label="<?php echo esc_attr( $remove_label ); ?>"
								data-interest="<?php echo esc_attr( $interest ); ?>"
								data-wp-on--click="actions.removeInterest"><?php buddynext_icon( 'x' ); ?></button>
						</span>
					<?php endforeach; ?>
					<input class="bn-ep-tag-input" type="text" id="bn-ep-tag-input" autocomplete="off"
						placeholder="<?php esc_attr_e( '+ Add interest', 'buddynext' ); ?>"
						data-wp-on--keydown="actions.addInterestOnEnter" />
				</div>
			</div>
			<?php
			$interests_html = (string) ob_get_clean();
			buddynext_get_template(
				'parts/profile-edit-section.php',
				array(
					'title'     => __( 'Community Interests', 'buddynext' ),
					'subtitle'  => __( 'Used to personalise your feed and discovery.', 'buddynext' ),
					'body_html' => $interests_html,
				)
			);

			// Privacy section — three audience selects + three toggles.
			$privacy_rows = array(
				array( 'select', 'bn_privacy_see_email', __( 'Who can see my email', 'buddynext' ), $privacy_see_email, 'bn-ep-privacy-email', '' ),
				array( 'select', 'bn_privacy_dm', __( 'Who can direct-message me', 'buddynext' ), $privacy_dm, 'bn-ep-privacy-dm', '' ),
				array( 'select', 'bn_privacy_mention', __( 'Who can @mention me in posts', 'buddynext' ), $privacy_mention, 'bn-ep-privacy-mention', '' ),
				array( 'toggle', 'bn_account_private', __( 'Private account', 'buddynext' ), $privacy_account_private, 'bn-ep-privacy-private-lbl', __( "Only approved followers see your posts. New follows arrive as requests you can accept or decline.", 'buddynext' ) ),
				array( 'toggle', 'bn_privacy_show_in_directory', __( 'Show me in the member directory', 'buddynext' ), $privacy_show_in_directory, 'bn-ep-privacy-dir-lbl', __( 'Turn off to hide from /members/.', 'buddynext' ) ),
				array( 'toggle', 'bn_privacy_search_indexable', __( 'Show my profile to search engines', 'buddynext' ), $privacy_search_indexable, 'bn-ep-privacy-search-lbl', __( 'When off, your profile carries noindex.', 'buddynext' ) ),
				array( 'toggle', 'bn_pro_hide_profile_views', __( 'Hide my profile views', 'buddynext' ), $privacy_hide_views, 'bn-ep-privacy-views-lbl', __( 'When on, your visits to other profiles are not recorded.', 'buddynext' ) ),
			);
			$privacy_html = '';
			foreach ( $privacy_rows as $r ) {
				$is_select     = 'select' === $r[0];
				$privacy_html .= $bn_capture(
					'profile-edit-privacy-row',
					array(
						'key'         => $r[1],
						'label'       => $r[2],
						'value'       => $r[3],
						'options'     => $is_select ? $privacy_audiences : array(),
						'input_id'    => $is_select ? $r[4] : '',
						'label_id'    => $is_select ? '' : $r[4],
						'description' => $r[5],
					)
				);
			}
			buddynext_get_template(
				'parts/profile-edit-section.php',
				array(
					'title'        => __( 'Privacy', 'buddynext' ),
					'subtitle'     => __( 'Control who sees what across the community.', 'buddynext' ),
					'title_id'     => 'bn-ep-privacy-title',
					'body_classes' => array( 'bn-ep-privacy-body' ),
					'body_html'    => $privacy_html,
				)
			);

			// Blocked & muted section — server-rendered lists with REST-
			// driven unblock/unmute buttons. Pulls the live state from
			// BlockService so the user can manage their relationships in
			// one place rather than re-finding people in the directory.
			$bn_blocked_ids = (array) buddynext_service( 'blocks' )->blocked_users( $user_id );
			$bn_muted_ids   = (array) buddynext_service( 'blocks' )->muted_users( $user_id );

			$bn_relations_html = '';

			if ( ! empty( $bn_blocked_ids ) || ! empty( $bn_muted_ids ) ) {
				$bn_render_row = static function ( int $target_id, string $action ): string {
					$u = get_userdata( $target_id );
					if ( ! $u ) {
						return '';
					}
					$avatar       = (string) get_avatar_url( $target_id, array( 'size' => 40 ) );
					$action_label = 'block' === $action
						? __( 'Unblock', 'buddynext' )
						: __( 'Unmute', 'buddynext' );
					return sprintf(
						'<li class="bn-ep-relation" data-user-id="%1$d" data-relation="%2$s">' .
							'<img src="%3$s" alt="" width="40" height="40" class="bn-avatar">' .
							'<span class="bn-ep-relation__name">%4$s</span>' .
							'<span class="bn-ep-relation__handle">@%5$s</span>' .
							'<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-bn-relation-remove>%6$s</button>' .
						'</li>',
						(int) $target_id,
						esc_attr( $action ),
						esc_url( $avatar ),
						esc_html( $u->display_name ),
						esc_html( $u->user_nicename ),
						esc_html( $action_label )
					);
				};

				$bn_relations_html .= '<div class="bn-ep-relations">';

				$bn_relations_html .= '<div class="bn-ep-relations__group">';
				$bn_relations_html .= '<h3 class="bn-ep-relations__title">' . esc_html__( 'Blocked', 'buddynext' ) . '</h3>';
				if ( empty( $bn_blocked_ids ) ) {
					$bn_relations_html .= '<p class="bn-ep-relations__empty">' . esc_html__( 'You haven\'t blocked anyone.', 'buddynext' ) . '</p>';
				} else {
					$bn_relations_html .= '<ul class="bn-ep-relations__list">';
					foreach ( $bn_blocked_ids as $bid ) {
						$bn_relations_html .= $bn_render_row( (int) $bid, 'block' );
					}
					$bn_relations_html .= '</ul>';
				}
				$bn_relations_html .= '</div>';

				$bn_relations_html .= '<div class="bn-ep-relations__group">';
				$bn_relations_html .= '<h3 class="bn-ep-relations__title">' . esc_html__( 'Muted', 'buddynext' ) . '</h3>';
				if ( empty( $bn_muted_ids ) ) {
					$bn_relations_html .= '<p class="bn-ep-relations__empty">' . esc_html__( 'You haven\'t muted anyone.', 'buddynext' ) . '</p>';
				} else {
					$bn_relations_html .= '<ul class="bn-ep-relations__list">';
					foreach ( $bn_muted_ids as $mid ) {
						$bn_relations_html .= $bn_render_row( (int) $mid, 'mute' );
					}
					$bn_relations_html .= '</ul>';
				}
				$bn_relations_html .= '</div>';

				$bn_relations_html .= '</div>';
			} else {
				$bn_relations_html = '<p class="bn-ep-relations__empty">' . esc_html__( 'You haven\'t blocked or muted anyone.', 'buddynext' ) . '</p>';
			}

			buddynext_get_template(
				'parts/profile-edit-section.php',
				array(
					'title'     => __( 'Blocked & muted', 'buddynext' ),
					'subtitle'  => __( 'Unblock or unmute people. New blocks are added from each member\'s card menu.', 'buddynext' ),
					'body_html' => $bn_relations_html,
				)
			);

			// Notification preferences section — four toggle rows + footer.
			$notif_rows = array(
				array( 'bn_pref_email_replies', __( 'Replies to your posts', 'buddynext' ), __( 'Email me when someone replies to a post I made.', 'buddynext' ), $pref_email_replies, 'bn-ep-pref-replies-lbl' ),
				array( 'bn_pref_email_mentions', __( 'Mentions', 'buddynext' ), __( 'Email me when someone @mentions me.', 'buddynext' ), $pref_email_mentions, 'bn-ep-pref-mentions-lbl' ),
				array( 'bn_pref_email_follows', __( 'New followers', 'buddynext' ), __( 'Email me when someone follows me.', 'buddynext' ), $pref_email_follows, 'bn-ep-pref-follows-lbl' ),
				array( 'bn_pref_email_digest', __( 'Weekly digest', 'buddynext' ), __( 'A weekly summary of activity in your community.', 'buddynext' ), $pref_email_digest, 'bn-ep-pref-digest-lbl' ),
			);
			$notif_html = '';
			foreach ( $notif_rows as $n ) {
				$notif_html .= $bn_capture(
					'profile-edit-notif-row',
					array(
						'key'         => $n[0],
						'label'       => $n[1],
						'description' => $n[2],
						'value'       => $n[3],
						'label_id'    => $n[4],
					)
				);
			}
			$notif_footer  = '<p class="bn-ep-card-footer__desc">' . esc_html__( 'Need finer control? Open the full notification settings page for per-space and per-event preferences.', 'buddynext' ) . '</p>';
			$notif_footer .= '<a class="bn-btn" data-variant="primary" data-size="sm" href="' . esc_url( $prefs_url ) . '">' . esc_html__( 'Open notification preferences', 'buddynext' ) . '</a>';
			buddynext_get_template(
				'parts/profile-edit-section.php',
				array(
					'title'        => __( 'Notification preferences', 'buddynext' ),
					'subtitle'     => __( 'Choose which emails you receive.', 'buddynext' ),
					'body_classes' => array( 'bn-ep-toggles' ),
					'body_html'    => $notif_html,
					'footer_html'  => $notif_footer,
				)
			);
			?>

			<!-- Section: Account -->
			<section class="bn-card bn-ep-card">
				<header class="bn-ep-card-header">
					<h2 class="bn-ep-card-title"><?php esc_html_e( 'Account', 'buddynext' ); ?></h2>
				</header>
				<div class="bn-ep-card-body bn-ep-account-rows">
					<!-- Profile URL row (slug field — too composite to fit the generic account-row part) -->
					<div class="bn-ep-field bn-ep-field--full bn-ep-slug-row">
						<label class="bn-ep-label" for="bn-ep-slug">
							<?php esc_html_e( 'Profile URL', 'buddynext' ); ?>
						</label>
						<div class="bn-ep-slug-field">
							<span class="bn-ep-slug-base">
								<?php echo esc_html( $people_url_base ); ?>/
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

					<?php
					// Pending-email notice block (only when there's a pending change).
					$pending_html = '';
					if ( '' !== $pending_email ) {
						$pending_html = '<div class="bn-ep-account-pending">' . sprintf(
							/* translators: %s: pending email address */
							esc_html__( 'Pending verification: %s', 'buddynext' ),
							'<strong>' . esc_html( $pending_email ) . '</strong>'
						) . '</div>';
					}

					// Build the email inline form via output-buffered HTML.
					ob_start();
					?>
					<div class="bn-ep-field bn-ep-field--full">
						<label class="bn-ep-label" for="bn-ep-new-email"><?php esc_html_e( 'New email address', 'buddynext' ); ?></label>
						<input class="bn-input" type="email" id="bn-ep-new-email" autocomplete="email" data-wp-bind--aria-invalid="!!context.errors.email" data-wp-class--bn-input--error="!!context.errors.email" />
						<span class="bn-ep-field-error" role="alert" data-wp-text="context.errors.email" data-wp-bind--hidden="!context.errors.email"></span>
					</div>
					<div class="bn-ep-account-form-actions">
						<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.requestEmailChange" data-wp-bind--disabled="context.emailChangeSubmitting"><?php esc_html_e( 'Send verification email', 'buddynext' ); ?></button>
						<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.closeEmailChange"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
					</div>
					<?php
					$email_inline_form = (string) ob_get_clean();

					buddynext_get_template(
						'parts/profile-edit-account-row.php',
						array(
							'row_id'                   => 'email',
							'label'                    => __( 'Email address', 'buddynext' ),
							'value'                    => $profile_email_raw,
							'pending_html'             => $pending_html,
							'cta_label'                => __( 'Change', 'buddynext' ),
							'cta_action'               => 'actions.openEmailChange',
							'inline_form_html'         => $email_inline_form,
							'inline_form_visible_when' => 'context.emailChangeOpen',
						)
					);

					// Build the password inline form via output-buffered HTML.
					ob_start();
					?>
					<div class="bn-ep-field bn-ep-field--full">
						<label class="bn-ep-label" for="bn-ep-current-password"><?php esc_html_e( 'Current password', 'buddynext' ); ?></label>
						<input class="bn-input" type="password" id="bn-ep-current-password" autocomplete="current-password" data-wp-bind--aria-invalid="!!context.errors.current_password" data-wp-class--bn-input--error="!!context.errors.current_password" />
						<span class="bn-ep-field-error" role="alert" data-wp-text="context.errors.current_password" data-wp-bind--hidden="!context.errors.current_password"></span>
					</div>
					<div class="bn-ep-field bn-ep-field--full">
						<label class="bn-ep-label" for="bn-ep-new-password"><?php esc_html_e( 'New password', 'buddynext' ); ?></label>
						<input class="bn-input" type="password" id="bn-ep-new-password" autocomplete="new-password" data-wp-on--input="actions.measurePasswordStrength" data-wp-bind--aria-invalid="!!context.errors.new_password" data-wp-class--bn-input--error="!!context.errors.new_password" />
						<span class="bn-ep-field-error" role="alert" data-wp-text="context.errors.new_password" data-wp-bind--hidden="!context.errors.new_password"></span>
						<div class="bn-ep-strength" aria-live="polite" data-wp-bind--data-strength="context.passwordStrength"><span class="bn-ep-strength-bar"></span><span class="bn-ep-strength-label" data-wp-text="context.passwordStrengthLabel"></span></div>
					</div>
					<div class="bn-ep-field bn-ep-field--full">
						<label class="bn-ep-label" for="bn-ep-confirm-password"><?php esc_html_e( 'Confirm new password', 'buddynext' ); ?></label>
						<input class="bn-input" type="password" id="bn-ep-confirm-password" autocomplete="new-password" data-wp-bind--aria-invalid="!!context.errors.confirm_password" data-wp-class--bn-input--error="!!context.errors.confirm_password" />
						<span class="bn-ep-field-error" role="alert" data-wp-text="context.errors.confirm_password" data-wp-bind--hidden="!context.errors.confirm_password"></span>
					</div>
					<div class="bn-ep-account-form-actions">
						<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.changePassword" data-wp-bind--disabled="context.passwordChangeSubmitting"><?php esc_html_e( 'Update password', 'buddynext' ); ?></button>
						<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.closePasswordChange"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
					</div>
					<?php
					$pw_inline = (string) ob_get_clean();

					buddynext_get_template(
						'parts/profile-edit-account-row.php',
						array(
							'row_id'                   => 'password',
							'label'                    => __( 'Password', 'buddynext' ),
							'value'                    => __( 'Change your account password.', 'buddynext' ),
							'cta_label'                => __( 'Change', 'buddynext' ),
							'cta_action'               => 'actions.openPasswordChange',
							'inline_form_html'         => $pw_inline,
							'inline_form_visible_when' => 'context.passwordChangeOpen',
						)
					);

					// Notification digest cross-link row (anchor CTA) + Sign-out row.
					$account_rows_tail = array(
						array( 'notif_digest', __( 'Notification email schedule', 'buddynext' ), __( 'Configure how often we email you.', 'buddynext' ), __( 'Open notification preferences', 'buddynext' ), '', $prefs_url, '' ),
						array( 'sign_out', __( 'Active sessions', 'buddynext' ), __( 'Sign out of every browser and device this account is signed in on.', 'buddynext' ), __( 'Sign out everywhere', 'buddynext' ), 'actions.signOutEverywhere', '', 'context.signOutSubmitting' ),
					);
					foreach ( $account_rows_tail as $a ) {
						buddynext_get_template(
							'parts/profile-edit-account-row.php',
							array(
								'row_id'       => $a[0],
								'label'        => $a[1],
								'value'        => $a[2],
								'cta_label'    => $a[3],
								'cta_action'   => $a[4],
								'cta_href'     => $a[5],
								'cta_disabled' => $a[6],
							)
						);
					}
					?>
				</div>
			</section><!-- /Account -->

			<?php
			// Danger zone.
			buddynext_get_template(
				'parts/profile-edit-danger-zone.php',
				array(
					'actions' => array(
						array(
							'id'       => 'delete-account',
							'label'    => __( 'Delete account', 'buddynext' ),
							'tone'     => 'danger',
							'size'     => 'md',
							'action'   => 'actions.openDelete',
							'modal_id' => 'bn-ep-delete',
						),
					),
				)
			);
			?>

		</main><!-- /form area -->

		<?php
		// Sidebar.
		buddynext_get_template(
			'parts/profile-edit-sidebar.php',
			array(
				'profile' => array(
					'user_id'      => $user_id,
					'display_name' => $display_name,
					'headline'     => $headline,
					'location'     => $location,
					'avatar_url'   => $avatar_url,
					'initials'     => $initials,
					'stats'        => array(
						'posts'     => $format_count( $post_count ),
						'followers' => $format_count( $follower_count ),
						'following' => $format_count( $following_count ),
					),
				),
			)
		);
		?>

	</div><!-- /bn-ep-shell -->

	<?php
	// Sticky save bar.
	buddynext_get_template(
		'parts/profile-edit-save-bar.php',
		array(
			'cancel_url' => \BuddyNext\Core\PageRouter::profile_url( $user_id ),
		)
	);
	?>

	</form><!-- /.bn-ep-form-shell -->

	<!--
		Delete-account modal — kept adjacent to its semantic owner (the
		danger-zone part). The modal renders *outside* the `<form>` so
		that clicking the confirm button doesn't implicitly submit the
		form; the danger-zone part lives *inside* the form to keep its
		button alongside the other section cards.
	-->
	<div class="bn-modal-backdrop bn-ep-delete-backdrop" role="dialog" aria-modal="true"
		aria-labelledby="bn-ep-delete-title" data-wp-bind--hidden="!context.deleteOpen">
		<div class="bn-modal__panel" data-tone="danger" data-size="sm">
			<header class="bn-modal__head">
				<h2 class="bn-modal__title" id="bn-ep-delete-title"><?php esc_html_e( 'Delete account?', 'buddynext' ); ?></h2>
				<button class="bn-modal__close" type="button"
					aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
					data-wp-on--click="actions.closeDelete"><?php buddynext_icon( 'x' ); ?></button>
			</header>
			<div class="bn-modal__body">
				<p><?php esc_html_e( 'This permanently deletes your profile, posts, replies, follows, and uploaded media. This cannot be undone.', 'buddynext' ); ?></p>
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
					<input class="bn-input" type="text" id="bn-ep-delete-confirm" autocomplete="off" spellcheck="false"
						data-wp-on--input="actions.updateDeleteText" />
				</div>
			</div>
			<footer class="bn-modal__foot">
				<button class="bn-btn" type="button" data-variant="ghost" data-size="md"
					data-wp-on--click="actions.closeDelete"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
				<button class="bn-btn" type="button" data-variant="danger" data-size="md"
					data-wp-bind--disabled="context.deleteText !== 'DELETE'"
					data-wp-on--click="actions.confirmDelete"><?php esc_html_e( 'Delete account', 'buddynext' ); ?></button>
			</footer>
		</div>
	</div>

</div><!-- /bn-ep-wrap -->
