<?php
/**
 * BuddyNext template part: settings-privacy-fields.
 *
 * The Privacy section's audience/gate selects and preference toggles —
 * relocated verbatim from the profile editor (templates/profile/edit.php).
 * Self-contained: it resolves the current user and computes every privacy
 * preference variable its markup references, then renders the audience-select
 * and toggle rows through parts/profile-edit-privacy-row.php inside a
 * parts/profile-edit-section.php card.
 *
 * Audience SELECTS save on form submit (data-wp-on--change marks the form
 * dirty); the boolean TOGGLES save immediately via actions.togglePref. Must
 * therefore be rendered inside a buddynext/profile interactive form.
 *
 * Overridable: copy to {theme}/buddynext/parts/settings-privacy-fields.php.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$user_id = get_current_user_id();

// Privacy prefs (audience selects + booleans stored in user meta).
$privacy_audiences = array(
	'everyone'    => __( 'Everyone', 'buddynext' ),
	'members'     => __( 'Members only', 'buddynext' ),
	'connections' => __( 'My connections', 'buddynext' ),
	'nobody'      => __( 'Nobody', 'buddynext' ),
);

// Profile-view / follow / connect gates use their own vocabularies (enforced
// by PrivacyService::can_view_profile / can_follow / can_connect). Kept as
// distinct option sets so each select offers only the values its gate honours.
$privacy_visibility_options = array(
	'public'      => __( 'Everyone', 'buddynext' ),
	'followers'   => __( 'My followers', 'buddynext' ),
	'connections' => __( 'My connections', 'buddynext' ),
	'private'     => __( 'Only me', 'buddynext' ),
);
$privacy_follow_options     = array(
	'everyone' => __( 'Everyone', 'buddynext' ),
	'nobody'   => __( 'Nobody', 'buddynext' ),
);
$privacy_connect_options    = array(
	'everyone'  => __( 'Everyone', 'buddynext' ),
	'followers' => __( 'My followers', 'buddynext' ),
	'nobody'    => __( 'Nobody', 'buddynext' ),
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
$privacy_hide_views      = '1' === (string) get_user_meta( $user_id, 'bn_pro_hide_profile_views', true );
$privacy_account_private = (bool) get_user_meta( $user_id, 'bn_account_private', true );

// Profile-view / follow / connect gates. Read through PrivacyService so the
// stored value (and its default fallback) match exactly what the enforcement
// reads back. Degrade to the documented defaults if the service is absent.
$privacy_service            = function_exists( 'buddynext_service' ) ? buddynext_service( 'privacy' ) : null;
$privacy_profile_visibility = ( $privacy_service && method_exists( $privacy_service, 'get_preference' ) )
	? (string) $privacy_service->get_preference( $user_id, 'profile_visibility' )
	: 'public';
$privacy_who_can_follow     = ( $privacy_service && method_exists( $privacy_service, 'get_preference' ) )
	? (string) $privacy_service->get_preference( $user_id, 'who_can_follow' )
	: 'everyone';
$privacy_who_can_connect    = ( $privacy_service && method_exists( $privacy_service, 'get_preference' ) )
	? (string) $privacy_service->get_preference( $user_id, 'who_can_connect' )
	: 'everyone';

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

// Privacy section — audience + gate selects, then toggles. Each
// select row carries its own option set (index 6); toggle rows
// pass an empty set, which the partial reads as the toggle variant.
$privacy_rows = array(
	array( 'select', 'bn_privacy_profile_visibility', __( 'Who can see my profile', 'buddynext' ), $privacy_profile_visibility, 'bn-ep-privacy-visibility', '', $privacy_visibility_options ),
	array( 'select', 'bn_privacy_who_can_follow', __( 'Who can follow me', 'buddynext' ), $privacy_who_can_follow, 'bn-ep-privacy-follow', '', $privacy_follow_options ),
	array( 'select', 'bn_privacy_who_can_connect', __( 'Who can send me connection requests', 'buddynext' ), $privacy_who_can_connect, 'bn-ep-privacy-connect', '', $privacy_connect_options ),
	array( 'select', 'bn_privacy_see_email', __( 'Who can see my email', 'buddynext' ), $privacy_see_email, 'bn-ep-privacy-email', '', $privacy_audiences ),
	array( 'select', 'bn_privacy_dm', __( 'Who can direct-message me', 'buddynext' ), $privacy_dm, 'bn-ep-privacy-dm', '', $privacy_audiences ),
	array( 'select', 'bn_privacy_mention', __( 'Who can @mention me in posts', 'buddynext' ), $privacy_mention, 'bn-ep-privacy-mention', '', $privacy_audiences ),
	array( 'toggle', 'bn_account_private', __( 'Private account', 'buddynext' ), $privacy_account_private, 'bn-ep-privacy-private-lbl', __( 'Only approved followers see your posts. New follows arrive as requests you can accept or decline.', 'buddynext' ), array() ),
	array( 'toggle', 'bn_privacy_show_in_directory', __( 'Show me in the member directory', 'buddynext' ), $privacy_show_in_directory, 'bn-ep-privacy-dir-lbl', __( 'Turn off to hide from /members/.', 'buddynext' ), array() ),
	array( 'toggle', 'bn_privacy_search_indexable', __( 'Show my profile to search engines', 'buddynext' ), $privacy_search_indexable, 'bn-ep-privacy-search-lbl', __( 'When off, your profile carries noindex.', 'buddynext' ), array() ),
	array( 'toggle', 'bn_pro_hide_profile_views', __( 'Hide my profile views', 'buddynext' ), $privacy_hide_views, 'bn-ep-privacy-views-lbl', __( 'When on, your visits to other profiles are not recorded.', 'buddynext' ), array() ),
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
			'options'     => $is_select ? (array) $r[6] : array(),
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

// ── Your data: self-service export + account deletion ───────────────────────
// Each control is gated by its Settings → Privacy option; the section is hidden
// entirely when both are off.
$bn_allow_export   = (bool) get_option( 'buddynext_allow_data_export', true );
$bn_allow_deletion = (bool) get_option( 'buddynext_allow_account_deletion', true );

if ( $bn_allow_export || $bn_allow_deletion ) {
	$data_html = '';

	if ( $bn_allow_export ) {
		$data_html .= '<div class="bn-ep-data-row">'
			. '<div class="bn-ep-data-row__text">'
			. '<strong>' . esc_html__( 'Export my data', 'buddynext' ) . '</strong>'
			. '<span>' . esc_html__( 'Download a copy of your profile, activity, and connections as a JSON file.', 'buddynext' ) . '</span>'
			. '</div>'
			. '<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-wp-on--click="actions.exportMyData">'
			. esc_html__( 'Export', 'buddynext' )
			. '</button>'
			. '</div>';
	}

	if ( $bn_allow_deletion ) {
		$data_html .= '<div class="bn-ep-data-row">'
			. '<div class="bn-ep-data-row__text">'
			. '<strong>' . esc_html__( 'Delete my account', 'buddynext' ) . '</strong>'
			. '<span>' . esc_html__( 'Permanently delete your account and remove your data. This cannot be undone.', 'buddynext' ) . '</span>'
			. '</div>'
			. '<button type="button" class="bn-btn" data-variant="danger" data-size="sm" data-wp-on--click="actions.deleteMyAccount">'
			. esc_html__( 'Delete account', 'buddynext' )
			. '</button>'
			. '</div>';
	}

	buddynext_get_template(
		'parts/profile-edit-section.php',
		array(
			'title'        => __( 'Your data', 'buddynext' ),
			'subtitle'     => __( 'Export your information or delete your account.', 'buddynext' ),
			'title_id'     => 'bn-ep-data-title',
			'body_classes' => array( 'bn-ep-data-body' ),
			'body_html'    => $data_html,
		)
	);
}
