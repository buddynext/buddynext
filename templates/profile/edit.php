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

defined( 'ABSPATH' ) || exit;

// Must be logged in and editing own profile (or admin).
$current_user_id = get_current_user_id();
if ( ! $current_user_id ) {
	wp_safe_redirect( wp_login_url( get_permalink() ) );
	exit;
}

if ( empty( $user_id ) || ! is_int( $user_id ) ) {
	$user_id = $current_user_id;
}

// Only own profile, or a user granted "Edit anyone's profile" (role map —
// buddynext-profile/edit-any; site admins always pass). Replaces the hard-coded
// edit_users cap so the Roles & Capabilities toggle actually governs this.
if ( $user_id !== $current_user_id && ! buddynext_can( $current_user_id, 'buddynext-profile/edit-any' ) ) {
	wp_die( esc_html__( 'You do not have permission to edit this profile.', 'buddynext' ), 403 );
}

$profile_user = get_userdata( $user_id );
if ( ! $profile_user ) {
	wp_die( esc_html__( 'Profile not found.', 'buddynext' ) );
}

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
// Resolve through the shared helper (uploaded cover -> site default -> '') so the
// edit screen matches what the profile view + directory show; reading the raw
// usermeta here left a user with no upload looking at the bare gradient even when
// a site-wide default cover is configured.
$cover_url = (string) buddynext_user_cover_url( $user_id );

// Whether the user has a *custom* uploaded avatar (vs the generated initials /
// Gravatar fallback). Drives the "Remove photo" control — there is nothing to
// remove when the avatar is the auto fallback.
$has_custom_avatar = '' !== (string) get_user_meta( $user_id, 'bn_avatar', true );

// Load profile through service — reads from bn_profile_values.
// As owner ($user_id === $user_id) ProfileService returns EVERY group/field
// (no visibility gating) so the edit form can render the full field set.
$service   = buddynext_service( 'profiles' );
$profile   = $service->get_profile( $user_id, $user_id );
$bn_groups = isset( $profile['groups'] ) && is_array( $profile['groups'] ) ? $profile['groups'] : array();

// Build flat key=>value map for the lightweight sidebar/preview vars only.
$fv = array();
foreach ( $bn_groups as $grp ) {
	if ( 'flat' === ( $grp['type'] ?? '' ) && ! empty( $grp['fields'] ) ) {
		foreach ( $grp['fields'] as $f ) {
			$fv[ $f['field_key'] ] = $f['value'] ?? '';
		}
	}
}

// Repeater entries kept in the Interactivity context so the existing JS
// store (assets/js/profile/*) can add/remove rows client-side. We seed the
// canonical work_experience/education keys it expects.
$work_entries = array();
$edu_entries  = array();
foreach ( $bn_groups as $grp ) {
	if ( 'repeater' === ( $grp['type'] ?? '' ) ) {
		if ( 'work_experience' === $grp['group_key'] ) {
			$work_entries = $grp['entries'] ?? array();
		} elseif ( 'education' === $grp['group_key'] ) {
			$edu_entries = $grp['entries'] ?? array();
		}
	}
}

// Convenience vars used by the hero + sidebar preview.
$headline = $fv['headline'] ?? '';
$bio      = $fv['bio'] ?? '';
$location = $fv['location'] ?? '';

/*
 * ── Field-level privacy helpers ────────────────────────────────────────────
 *
 * Restrictiveness ladder (contracts.visibility_resolution):
 *   public(0) < followers(1) < connections(2) < private(3)
 *
 * The admin sets each field's DEFAULT visibility; a member may only TIGHTEN
 * it. So the per-field lock selector offers ONLY options whose rank is
 * >= the admin default rank, and pre-selects the member's current effective
 * choice (clamped into range). The server re-clamps on save.
 */
$bn_vis_labels = array(
	'public'      => __( 'Public', 'buddynext' ),
	'followers'   => __( 'Followers', 'buddynext' ),
	'connections' => __( 'Connections', 'buddynext' ),
	'private'     => __( 'Only me', 'buddynext' ),
);
$bn_vis_rank   = array(
	'public'      => 0,
	'followers'   => 1,
	'connections' => 2,
	'private'     => 3,
);

/**
 * Normalise an arbitrary value to a known visibility slug.
 *
 * @param mixed  $value    Candidate slug.
 * @param string $fallback Slug returned when $value is unknown.
 * @return string One of public|followers|connections|private.
 */
$bn_vis_norm = static function ( $value, string $fallback = 'public' ) use ( $bn_vis_rank ): string {
	$value = is_string( $value ) ? $value : '';
	return isset( $bn_vis_rank[ $value ] ) ? $value : $fallback;
};

/**
 * Build the compact per-field privacy <select> (the "lock" dropdown).
 *
 * Only options EQUAL-OR-MORE restrictive than the admin default are offered
 * (members can tighten, never loosen). Pre-selects the current effective
 * choice. The control is escaped and emitted as a string.
 *
 * @param string $name           Input name attribute (e.g. headline__visibility).
 * @param string $admin_default  Admin-set default visibility for the field.
 * @param string $current        Member's current effective visibility.
 * @param string $select_id      DOM id for the <select>.
 * @return string Rendered HTML.
 */
$bn_privacy_select = static function ( string $name, string $admin_default, string $current, string $select_id ) use ( $bn_vis_labels, $bn_vis_rank, $bn_vis_norm ): string {
	$admin_default = $bn_vis_norm( $admin_default, 'public' );
	$current       = $bn_vis_norm( $current, $admin_default );
	$min_rank      = $bn_vis_rank[ $admin_default ];

	// Member choice can never be looser than the admin default — clamp the
	// pre-selected value up to the default if a stale looser value slipped in.
	if ( $bn_vis_rank[ $current ] < $min_rank ) {
		$current = $admin_default;
	}

	$options_html = '';
	foreach ( $bn_vis_labels as $slug => $label ) {
		if ( $bn_vis_rank[ $slug ] < $min_rank ) {
			continue;
		}
		$options_html .= sprintf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $slug ),
			selected( $current, $slug, false ),
			esc_html( $label )
		);
	}

	$lock_icon = \BuddyNext\Core\IconService::render( 'lock', 'bn-ep-vis-lock' );

	return sprintf(
		'<span class="bn-ep-field-vis" data-bn-vis>' .
			'<label class="bn-ep-field-vis__label" for="%1$s">%2$s<span class="screen-reader-text">%3$s</span></label>' .
			'<select class="bn-input bn-ep-field-vis__select" id="%1$s" name="%4$s" data-wp-on--change="actions.markDirty">%5$s</select>' .
		'</span>',
		esc_attr( $select_id ),
		$lock_icon, // Already escaped by IconService (wp_kses'd).
		esc_html__( 'Who can see this field', 'buddynext' ),
		esc_attr( $name ),
		$options_html // Built from escaped pieces above.
	);
};

$profile_url = \BuddyNext\Core\PageRouter::profile_url( $user_id );

// Stats for preview widget — read through the services (no SQL here). The
// follow counts come from FollowService, which filters status = 'approved';
// the old inline queries omitted that, so private accounts counted pending
// follow requests as followers.
$post_count      = ( new \BuddyNext\Feed\PostService() )->user_post_count( $user_id );
$follower_count  = buddynext_service( 'follows' )->follower_count( $user_id );
$following_count = buddynext_service( 'follows' )->following_count( $user_id );

$format_count = static function ( int $n ): string {
	if ( $n >= 1000 ) {
		return round( $n / 1000, 1 ) . 'k';
	}
	return (string) $n;
};

$rest_nonce = wp_create_nonce( 'wp_rest' );

/**
 * Fires before the profile edit inner content.
 *
 * @param int $user_id Profile being edited.
 */
do_action( 'buddynext_profile_edit_before', isset( $user_id ) ? (int) $user_id : 0 );
?>
<div class="bn-ep-wrap"
	data-wp-interactive="buddynext/profile"
	<?php // When editing someone else's profile (edit-any), the store saves to that user's REST route instead of /me/profile. Absent/0 = editing own. ?>
	<?php if ( $user_id !== $current_user_id ) : ?>
		data-bn-profile-user="<?php echo absint( $user_id ); ?>"
	<?php endif; ?>
	data-wp-init="callbacks.initEditGuard"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			// Profile-only context: account / slug / privacy / 2FA state moved to
			// the Settings hub. saveProfile reads the work/edu repeaters (guarded
			// in store.js) and the flat field inputs that are on the page (every
			// dynamic profile field, including Skills / Interests, is a flat input).
			'userId'      => $user_id,
			'restNonce'   => $rest_nonce,
			'saved'       => false,
			'saving'      => false,
			'isDirty'     => false,
			'errors'      => (object) array(),
			'profileUrl'  => $profile_url,
			'workEntries' => array_values( $work_entries ),
			'eduEntries'  => array_values( $edu_entries ),
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
			<a class="bn-btn bn-ep-settings-link" data-variant="ghost" data-size="sm" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::settings_url() ); ?>">
				<?php esc_html_e( 'Account & settings', 'buddynext' ); ?>
				<?php buddynext_icon( 'chevron-right' ); ?>
			</a>
		</header>

		<!-- Main form column -->
		<main class="bn-ep-form">

			<?php
			// Hero card.
			buddynext_get_template(
				'parts/profile-edit-hero.php',
				array(
					'profile_user_id'   => $user_id,
					'display_name'      => $display_name,
					'headline'          => $headline,
					'username'          => $user_login_str,
					'avatar_url'        => $avatar_url,
					'cover_url'         => $cover_url,
					'initials'          => $initials,
					'has_custom_avatar' => $has_custom_avatar,
				)
			);

			// ── Dynamic profile fields ──────────────────────────────────
			// Every admin-defined group/field is rendered here through the
			// single field-type engine (contracts.field_type_engine), so any
			// field type works edit→display→search without per-type template
			// code. Each field carries a compact lock privacy selector; the
			// member can only TIGHTEN a field below its admin default.
			foreach ( $bn_groups as $bn_group ) {
				$bn_gkey   = isset( $bn_group['group_key'] ) ? (string) $bn_group['group_key'] : '';
				$bn_gtype  = isset( $bn_group['type'] ) ? (string) $bn_group['type'] : 'flat';
				$bn_glabel = isset( $bn_group['label'] ) && '' !== (string) $bn_group['label']
					? (string) $bn_group['label']
					: ucwords( str_replace( '_', ' ', $bn_gkey ) );

				if ( '' === $bn_gkey ) {
					continue;
				}

				if ( 'repeater' === $bn_gtype ) {
					// Repeater group: render each saved entry's sub-fields via
					// the engine, plus ONE per-entry privacy lock that reuses
					// the existing `group_key[n][_visibility]` save contract.
					$bn_entries  = isset( $bn_group['entries'] ) && is_array( $bn_group['entries'] ) ? $bn_group['entries'] : array();
					$bn_gdefault = $bn_vis_norm( $bn_group['visibility'] ?? 'public', 'public' );

					// Required sub-field keys for this group, so a JS-added row
					// (buildEntryNode) can mirror the same asterisk the server
					// renders — data-driven from is_required, never hardcoded.
					$bn_req_keys = array();
					foreach ( $bn_entries as $bn_schema_entry ) {
						if ( ! is_array( $bn_schema_entry ) ) {
							continue;
						}
						foreach ( $bn_schema_entry as $bn_schema_field ) {
							if ( is_array( $bn_schema_field ) && ! empty( $bn_schema_field['is_required'] ) && ! empty( $bn_schema_field['field_key'] ) ) {
								$bn_req_keys[ (string) $bn_schema_field['field_key'] ] = true;
							}
						}
					}

					$bn_rep_html = '<div class="bn-ep-card-body" id="' . esc_attr( 'bn-ep-' . str_replace( '_', '-', $bn_gkey ) . '-entries' ) . '" data-bn-required-fields="' . esc_attr( implode( ',', array_keys( $bn_req_keys ) ) ) . '">';

					foreach ( $bn_entries as $bn_idx => $bn_entry ) {
						$bn_idx_int = (int) $bn_idx;
						$bn_fields  = is_array( $bn_entry ) ? $bn_entry : array();

						$bn_rep_html .= '<div class="bn-ep-repeater-entry" data-entry-index="' . esc_attr( (string) $bn_idx_int ) . '">';
						$bn_rep_html .= '<header class="bn-ep-repeater-header"><span class="bn-ep-repeater-num">' . absint( $bn_idx_int + 1 ) . '</span>';
						$bn_rep_html .= '<button class="bn-btn bn-ep-repeater-remove" type="button" data-variant="ghost" data-size="sm" data-group="' . esc_attr( $bn_gkey ) . '" data-entry-index="' . esc_attr( (string) $bn_idx_int ) . '" data-wp-on--click="actions.removeEntry" aria-label="' . esc_attr__( 'Remove this entry', 'buddynext' ) . '">' . \BuddyNext\Core\IconService::render( 'x' ) . '</button>';
						$bn_rep_html .= '</header>';

						$bn_rep_html .= '<div class="bn-ep-grid">';
						foreach ( $bn_fields as $bn_field ) {
							if ( ! is_array( $bn_field ) || empty( $bn_field['field_key'] ) ) {
								continue;
							}
							$bn_fkey  = (string) $bn_field['field_key'];
							$bn_ftype = isset( $bn_field['type'] ) ? (string) $bn_field['type'] : 'text';
							$bn_name  = $bn_gkey . '[' . $bn_idx_int . '][' . $bn_fkey . ']';
							$bn_label = isset( $bn_field['label'] ) ? (string) $bn_field['label'] : ucwords( str_replace( '_', ' ', $bn_fkey ) );
							$bn_ctrl  = \BuddyNext\Profile\FieldType::render_input( $bn_field, $bn_field['value'] ?? '', $bn_name );

							// Visible required marker, mirroring the flat-field branch so
							// repeater sub-fields show the same asterisk (the control already
							// carries the HTML `required` attribute via FieldType).
							$bn_sf_required = ! empty( $bn_field['is_required'] )
								? ' <span class="bn-ep-required" aria-hidden="true">*</span>'
								: '';

							// A boolean's control is already self-labelling (the checkbox
							// carries its own label), so it gets a full-width row with no
							// redundant outer label.
							if ( 'boolean' === $bn_ftype ) {
								$bn_rep_html .= '<div class="bn-ep-field bn-ep-field--full">' . $bn_ctrl . '</div>';
							} else {
								$bn_rep_html .= '<div class="bn-ep-field"><label class="bn-ep-label" for="' . esc_attr( 'bn-ep-' . str_replace( '_', '-', $bn_fkey ) . '-' . $bn_idx_int ) . '">' . esc_html( $bn_label ) . $bn_sf_required . '</label>' . $bn_ctrl . '</div>';
							}
						}
						$bn_rep_html .= '</div>';

						// Per-entry privacy lock (reuses the _visibility control).
						$bn_entry_vis = $bn_vis_norm(
							$bn_entry['_visibility'] ?? ( $bn_group['entry_visibility'] ?? $bn_gdefault ),
							$bn_gdefault
						);
						$bn_rep_html .= '<div class="bn-ep-field bn-ep-field--full bn-ep-repeater-vis">';
						$bn_rep_html .= $bn_privacy_select(
							$bn_gkey . '[' . $bn_idx_int . '][_visibility]',
							$bn_gdefault,
							$bn_entry_vis,
							'bn-ep-' . str_replace( '_', '-', $bn_gkey ) . '-vis-' . $bn_idx_int
						);
						$bn_rep_html .= '</div>';

						$bn_rep_html .= '</div>';
					}

					$bn_rep_html .= '</div>';
					$bn_rep_html .= '<footer class="bn-ep-card-footer"><button class="bn-btn bn-ep-add-entry" type="button" data-variant="ghost" data-size="sm" data-group="' . esc_attr( $bn_gkey ) . '" data-wp-on--click="actions.addEntry">' . \BuddyNext\Core\IconService::render( 'plus' ) . '<span>' . esc_html__( 'Add entry', 'buddynext' ) . '</span></button></footer>';

					echo '<section class="bn-card bn-ep-card"><header class="bn-ep-card-header"><h2 class="bn-ep-card-title">' . esc_html( $bn_glabel ) . '</h2></header>';
					// Repeater body markup is assembled from individually escaped
					// pieces above (FieldType output is escaped per its contract).
					echo $bn_rep_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '</section>';
					continue;
				}

				// Flat group: render each field's input via the engine + lock.
				$bn_fields = isset( $bn_group['fields'] ) && is_array( $bn_group['fields'] ) ? $bn_group['fields'] : array();
				if ( empty( $bn_fields ) ) {
					continue;
				}

				$bn_gvis_default = $bn_vis_norm( $bn_group['visibility'] ?? 'public', 'public' );
				$bn_body_html    = '<div class="bn-ep-grid">';

				foreach ( $bn_fields as $bn_field ) {
					if ( ! is_array( $bn_field ) || empty( $bn_field['field_key'] ) ) {
						continue;
					}
					$bn_fkey = (string) $bn_field['field_key'];
					// `headline` is edited inline in the hero card (parts/profile-edit-hero.php),
					// which already renders an <input name="headline">. Rendering it again here
					// puts a second same-named input on the page; the save collector
					// (querySelectorAll('input[name]') in profile/store.js) then reads the empty
					// duplicate and blanks the headline on save. Skip it — the hero owns headline.
					if ( 'headline' === $bn_fkey ) {
						continue;
					}
					$bn_ftype  = isset( $bn_field['type'] ) ? (string) $bn_field['type'] : 'text';
					$bn_label  = isset( $bn_field['label'] ) ? (string) $bn_field['label'] : ucwords( str_replace( '_', ' ', $bn_fkey ) );
					$bn_inp_id = 'bn-ep-' . str_replace( '_', '-', $bn_fkey );

					// Wide controls (textarea) take the full row.
					$bn_field_cls = 'bn-ep-field';
					if ( in_array( $bn_ftype, array( 'textarea', 'multiselect', 'radio' ), true ) ) {
						$bn_field_cls .= ' bn-ep-field--full';
					}

					// Field value control via the engine.
					$bn_control = \BuddyNext\Profile\FieldType::render_input( $bn_field, $bn_field['value'] ?? '', $bn_fkey );

					// Admin default = field's own visibility (falls back to the
					// group default, then public). Current = member's effective
					// choice; ProfileService surfaces it on the field row when
					// present (entry_visibility / visibility), else the default.
					$bn_admin_default = $bn_vis_norm(
						$bn_field['field_visibility'] ?? ( $bn_field['visibility'] ?? $bn_gvis_default ),
						$bn_gvis_default
					);
					$bn_current       = $bn_vis_norm(
						$bn_field['entry_visibility'] ?? ( $bn_field['effective_visibility'] ?? $bn_admin_default ),
						$bn_admin_default
					);

					$bn_privacy_html = $bn_privacy_select(
						$bn_fkey . '__visibility',
						$bn_admin_default,
						$bn_current,
						$bn_inp_id . '-vis'
					);

					// Required marker on the label (mirrors the hero's display-name field).
					$bn_is_required = ! empty( $bn_field['is_required'] );
					$bn_req_mark    = $bn_is_required
						? ' <span class="bn-ep-required" aria-hidden="true">*</span>'
						: '';

					// Per-field inline error slot — reactively shown by the
					// buddynext/profile Interactivity store, which writes
					// context.errors[ field_key ] both on client-side required
					// validation (store.js saveProfile) and on a 422 from the
					// server. Keyed by the field_key so JS and PHP agree.
					$bn_err_id   = 'bn-ep-error-' . esc_attr( $bn_fkey );
					$bn_err_html = '<span class="bn-ep-field-error" id="' . $bn_err_id . '" role="alert"'
						. ' data-wp-text="context.errors.' . esc_attr( $bn_fkey ) . '"'
						. ' data-wp-bind--hidden="!context.errors.' . esc_attr( $bn_fkey ) . '"></span>';

					$bn_body_html .= '<div class="' . esc_attr( $bn_field_cls ) . '"'
						. ' data-wp-class--bn-ep-field--error="context.errors.' . esc_attr( $bn_fkey ) . '">';
					$bn_body_html .= '<div class="bn-ep-field-head"><label class="bn-ep-label" for="' . esc_attr( $bn_inp_id ) . '">' . esc_html( $bn_label ) . $bn_req_mark . '</label>' . $bn_privacy_html . '</div>';
					$bn_body_html .= $bn_control;
					$bn_body_html .= $bn_err_html;
					$bn_body_html .= '</div>';
				}

				$bn_body_html .= '</div>';

				buddynext_get_template(
					'parts/profile-edit-section.php',
					array(
						'title'     => $bn_glabel,
						'body_html' => $bn_body_html,
					)
				);
			}

			// Member-type self-select — only when self-select types exist (own
			// profile only; this template renders for the owner). Wired to the
			// buddynext/profile store's setMemberType action, which PUTs to
			// /users/{id}/member-type; the endpoint enforces the self_select gate.
			$bn_mt_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'member_types' ) : null;
			if ( $bn_mt_service && method_exists( $bn_mt_service, 'get_all' ) ) {
				$bn_self_types = array_values(
					array_filter(
						(array) $bn_mt_service->get_all(),
						static function ( $t ) {
							return ! empty( $t['self_select'] );
						}
					)
				);

				if ( ! empty( $bn_self_types ) ) {
					$bn_current_type = method_exists( $bn_mt_service, 'get_user_type' ) ? $bn_mt_service->get_user_type( $user_id ) : null;
					$bn_current_slug = ( is_array( $bn_current_type ) && isset( $bn_current_type['slug'] ) ) ? (string) $bn_current_type['slug'] : '';

					$bn_mt_html  = '<label class="bn-ep-label" for="bn-ep-member-type">' . esc_html__( 'Your member type', 'buddynext' ) . '</label>';
					$bn_mt_html .= '<select class="bn-input" id="bn-ep-member-type" data-user-id="' . esc_attr( (string) $user_id ) . '" data-wp-on--change="actions.setMemberType">';
					$bn_mt_html .= '<option value="">' . esc_html__( '— None —', 'buddynext' ) . '</option>';
					foreach ( $bn_self_types as $bn_t ) {
						$bn_mt_html .= sprintf(
							'<option value="%s"%s>%s</option>',
							esc_attr( (string) ( $bn_t['slug'] ?? '' ) ),
							selected( $bn_current_slug, (string) ( $bn_t['slug'] ?? '' ), false ),
							esc_html( (string) ( $bn_t['name'] ?? $bn_t['slug'] ?? '' ) )
						);
					}
					$bn_mt_html .= '</select>';

					buddynext_get_template(
						'parts/profile-edit-section.php',
						array(
							'title'     => __( 'Member type', 'buddynext' ),
							'subtitle'  => __( 'Pick the type that best describes you. Saved instantly.', 'buddynext' ),
							'title_id'  => 'bn-ep-member-type-title',
							'body_html' => $bn_mt_html,
						)
					);
				}
			}

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

</div><!-- /bn-ep-wrap -->
