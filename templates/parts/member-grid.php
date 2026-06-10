<?php
/**
 * BuddyNext template part: member-grid.
 *
 * Reusable wrapper that renders a list of WP_Users as the cover member-card grid
 * (parts/member-directory-grid.php), building the per-member state helpers
 * (follow / online / mutual / initials), avatar tones, and member-type map
 * internally. Lets the member directory, connections, followers, and following
 * pages all share one identical premium card.
 *
 * @package BuddyNext
 *
 * @var array  $members   Required. WP_User[] to render.
 * @var int    $viewer_id Optional. Viewing user ID. Default current user.
 * @var array  $classes   Optional. Extra CSS classes for the grid.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_mg_members = isset( $members ) ? array_values( array_filter( (array) $members, 'is_object' ) ) : array();
if ( empty( $bn_mg_members ) || ! function_exists( 'buddynext_service' ) ) {
	return;
}

$bn_mg_viewer = isset( $viewer_id ) ? (int) $viewer_id : get_current_user_id();

// Member-type map (slug => data) for the per-card type badge.
$bn_mg_type_map = array();
foreach ( (array) buddynext_service( 'member_types' )->get_all_with_counts() as $bn_mg_t ) {
	if ( isset( $bn_mg_t['slug'] ) ) {
		$bn_mg_type_map[ (string) $bn_mg_t['slug'] ] = $bn_mg_t;
	}
}

$bn_mg_tones = array( 'accent', 'success', 'jetonomy', 'media', 'events', 'warn', 'danger', 'info' );

$bn_mg_initials = static function ( string $name ): string {
	$parts = array_filter( explode( ' ', $name ) );
	if ( count( $parts ) >= 2 ) {
		return mb_strtoupper( mb_substr( (string) reset( $parts ), 0, 1 ) . mb_substr( (string) end( $parts ), 0, 1 ) );
	}
	return mb_strtoupper( mb_substr( $name, 0, 2 ) );
};

$bn_mg_is_online = static function ( int $uid ) use ( $bn_mg_viewer ): bool {
	return buddynext_service( 'blocks' )->is_user_online( $bn_mg_viewer, $uid );
};

$bn_mg_mutual = static function ( int $a, int $b ): array {
	if ( 0 === $a || 0 === $b || $a === $b ) {
		return array();
	}
	return buddynext_service( 'connections' )->mutual_connections( $a, $b );
};

$bn_mg_following = static function ( int $target ) use ( $bn_mg_viewer ): bool {
	return 0 !== $bn_mg_viewer && (bool) buddynext_service( 'follows' )->is_following( $bn_mg_viewer, $target );
};

// The cards' reactive Follow / Connect / kebab actions are driven by the
// `buddynext/members` Interactivity store, so the grid must sit inside that
// region with the action context (the member directory provides this itself).
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
			'members'         => $bn_mg_members,
			'viewer_id'       => $bn_mg_viewer,
			'avatar_tones'    => $bn_mg_tones,
			'type_map'        => $bn_mg_type_map,
			'messages_base'   => \BuddyNext\Core\PageRouter::messages_url(),
			'initials_fn'     => $bn_mg_initials,
			'is_online_fn'    => $bn_mg_is_online,
			'is_following_fn' => $bn_mg_following,
			'mutual_ids_fn'   => $bn_mg_mutual,
			'classes'         => isset( $classes ) ? (array) $classes : array(),
		)
	);
	?>
</div>
<?php
// Block / report modals — opened imperatively by the card kebab menu.
buddynext_get_template( 'parts/member-block-modal.php', array( 'nonce' => $bn_mg_nonce ) );
buddynext_get_template( 'parts/member-report-modal.php', array( 'nonce' => $bn_mg_nonce ) );
