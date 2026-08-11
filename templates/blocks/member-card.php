<?php
/**
 * Block template: Featured Member.
 *
 * Renders ONE member using the same card the members directory renders. It
 * delegates to `parts/member-directory-grid.php` rather than to
 * `parts/member-card.php` directly, because the grid part is where per-member
 * state (follow, connection, mutuals, presence, muted) is resolved — the card
 * part is documented as a pure render unit and expects that work already done.
 * Going through the grid means a featured card and a directory card show the
 * same badges, the same action cluster and the same states, by construction.
 *
 * This block previously carried its own copy of the card markup, which is how
 * the featured space card ended up rendering an empty colour band while the
 * directory card beside it was fine. One implementation, no drift.
 *
 * Variables:
 *   int    $user_id      Member ID to display.
 *   string $size         'full' (default) or 'compact' — compact drops the cover band.
 *   bool   $show_actions Render Follow / Connect. Default true.
 *   bool   $show_stats   Render the mutual-connections line. Default true.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$user_id = isset( $user_id ) ? (int) $user_id : 0;

if ( $user_id <= 0 ) {
	return;
}

$bn_fm_member = get_userdata( $user_id );

if ( ! $bn_fm_member ) {
	return;
}

$bn_fm_viewer = get_current_user_id();

/*
 * The grid part resolves per-member state through four callables. The
 * directory builds them over page-wide maps so a grid of 24 costs no
 * per-card queries; here the "page" is a single member, so each closure
 * answers for that one id and the cost is the same O(1) shape.
 */
$bn_fm_directory = buddynext_service( 'member_directory' );
$bn_fm_online    = $bn_fm_directory ? $bn_fm_directory->online_among( array( $user_id ) ) : array();
$bn_fm_mutuals   = ( $bn_fm_viewer > 0 && $bn_fm_directory )
	? $bn_fm_directory->mutual_peers_for_page( $bn_fm_viewer, array( $user_id ) )
	: array();

$bn_fm_is_online = static function ( int $id ) use ( $bn_fm_online ): bool {
	return ! empty( $bn_fm_online[ $id ] );
};

$bn_fm_mutual_ids = static function ( int $viewer, int $target ) use ( $bn_fm_mutuals ): array {
	return ( 0 === $viewer || 0 === $target || $viewer === $target )
		? array()
		: ( $bn_fm_mutuals[ $target ] ?? array() );
};

$bn_fm_is_following = static function ( int $target ) use ( $bn_fm_viewer ): bool {
	if ( $bn_fm_viewer <= 0 ) {
		return false;
	}
	$bn_fm_follows = buddynext_service( 'follows' );

	return $bn_fm_follows ? $bn_fm_follows->is_following( $bn_fm_viewer, $target ) : false;
};

// Member-type map, keyed by slug the way the grid part expects, so a featured
// member shows the same type badge the directory gives them.
$bn_fm_type_map = array();
foreach ( (array) buddynext_service( 'member_types' )->get_all_with_counts() as $bn_fm_type ) {
	if ( isset( $bn_fm_type['slug'] ) ) {
		$bn_fm_type_map[ (string) $bn_fm_type['slug'] ] = $bn_fm_type;
	}
}

// The buddynext/members Interactivity store wrapper (so this card's Follow / Connect
// / kebab directives resolve and the @buddynext/members module loads) is applied by
// the block's render callback via wrap_block_output() — this delegating template has
// no root element of its own, and adding one here would double-wrap the block.
buddynext_get_template(
	'parts/member-directory-grid.php',
	array(
		'members'         => array( $bn_fm_member ),
		'viewer_id'       => $bn_fm_viewer,
		'view_mode'       => 'grid',
		'avatar_tones'    => array( 'accent', 'success', 'jetonomy', 'media', 'events', 'warn', 'danger', 'info' ),
		'type_map'        => $bn_fm_type_map,
		'messages_base'   => PageRouter::messages_url(),
		'is_online_fn'    => $bn_fm_is_online,
		'is_following_fn' => $bn_fm_is_following,
		'mutual_ids_fn'   => $bn_fm_mutual_ids,
		// One card, not a directory page: the modifier lets the grid collapse to
		// a single column so the card fills a sidebar instead of sitting in a
		// 3-up track with two empty cells beside it.
		'classes'         => array( 'bn-md-grid--single' ),
		'compact'         => 'compact' === ( isset( $size ) ? (string) $size : 'full' ),
		'show_actions'    => ! isset( $show_actions ) || (bool) $show_actions,
		'show_stats'      => ! isset( $show_stats ) || (bool) $show_stats,
	)
);
