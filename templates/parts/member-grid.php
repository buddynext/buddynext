<?php
/**
 * BuddyNext template part: member-grid.
 *
 * Renders a list of WP_Users as the shared cover member-card grid
 * (parts/member-directory-grid.php) wrapped in the `buddynext/members`
 * Interactivity region — so the cards' reactive Follow / Connect / kebab
 * actions and the block / report modals work outside the directory page.
 *
 * Per-member state (follow / online / mutual / type) is resolved inside
 * member-directory-grid.php, so a caller only passes the members + viewer.
 * Used by the connections, followers, and following profile pages.
 *
 * @package BuddyNext
 *
 * @var array $members   Required. WP_User[] to render.
 * @var int   $viewer_id Optional. Viewing user ID. Default current user.
 * @var array $classes   Optional. Extra CSS classes for the grid.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_mg_members = isset( $members ) ? array_values( array_filter( (array) $members, 'is_object' ) ) : array();
if ( empty( $bn_mg_members ) ) {
	return;
}

$bn_mg_nonce = wp_create_nonce( 'wp_rest' );
$bn_mg_ctx   = wp_json_encode(
	array(
		'restNonce'        => $bn_mg_nonce,
		'restUrl'          => esc_url_raw( rest_url( 'buddynext/v1' ) ),
		'peopleUrl'        => \BuddyNext\Core\PageRouter::people_url(),
		'blockTargetId'    => 0,
		'blockTargetName'  => '',
		'blockConfirmOpen' => false,
		'blockSubmitting'  => false,
		'reportOpen'       => false,
		'reportTargetType' => 'user',
		'reportTargetId'   => 0,
		'reportReason'     => 'spam',
		'reportNotes'      => '',
		'reportSubmitting' => false,
	)
);
if ( false === $bn_mg_ctx ) {
	$bn_mg_ctx = '{}';
}
?>
<div data-wp-interactive="buddynext/members" data-wp-context="<?php echo esc_attr( (string) $bn_mg_ctx ); ?>">
	<?php
	buddynext_get_template(
		'parts/member-directory-grid.php',
		array(
			'members'   => $bn_mg_members,
			'viewer_id' => isset( $viewer_id ) ? (int) $viewer_id : get_current_user_id(),
			'classes'   => isset( $classes ) ? (array) $classes : array(),
		)
	);
	?>
</div>
<?php
// Block / report modals — opened imperatively by the card kebab menu.
buddynext_get_template( 'parts/member-block-modal.php', array( 'nonce' => $bn_mg_nonce ) );
buddynext_get_template( 'parts/member-report-modal.php', array( 'nonce' => $bn_mg_nonce ) );
