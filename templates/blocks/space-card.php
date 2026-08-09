<?php
/**
 * Block template: Featured Space.
 *
 * Renders ONE space using the same card the spaces directory renders —
 * `parts/space-directory-card.php` — so a card featured in a sidebar and a card
 * in the directory grid can never drift apart.
 *
 * It used to carry its own 130-line copy of that markup, and the two had
 * already diverged: the copy showed an emblem only when the space had an
 * uploaded avatar (no category-icon or letter fallback, so a space without one
 * rendered as an empty colour band), had no description fallback, and hid its
 * only call to action from logged-out visitors — the very people a featured
 * card on a landing page is meant to convert.
 *
 * Variables:
 *   int    $space_id    Space ID to display.
 *   string $size        'full' (default) or 'compact' — compact drops the cover band.
 *   bool   $show_action Render the join/manage action. Default true.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$space_id = isset( $space_id ) ? (int) $space_id : 0;

if ( $space_id <= 0 ) {
	return;
}

$bn_fs_space = buddynext_service( 'spaces' )->get( $space_id );

if ( ! $bn_fs_space ) {
	return;
}

$bn_fs_viewer = get_current_user_id();

// The part reads the viewer's membership to decide which action to show
// (Join / Request to join / Open). Resolve it here rather than letting the part
// guess: a guest simply has none, which is what makes the card render its
// public "View space" affordance instead of nothing at all.
$bn_fs_membership = null;
if ( $bn_fs_viewer > 0 ) {
	// Same API the directory uses to build its membership map, asked for one id.
	$bn_fs_map        = ( new \BuddyNext\Spaces\SpaceMemberService() )->membership_map( $bn_fs_viewer, array( $space_id ) );
	$bn_fs_membership = $bn_fs_map[ $space_id ] ?? null;
}

// Category map for the emblem's category-icon rung, keyed the way the part
// expects. Same source as the directory so the icon matches there too.
$bn_fs_cats   = array();
$bn_fs_cat_id = isset( $bn_fs_space['category_id'] ) ? (int) $bn_fs_space['category_id'] : 0;
if ( $bn_fs_cat_id > 0 ) {
	foreach ( buddynext_service( 'spaces' )->categories_with_counts( 0, true ) as $bn_fs_cat_row ) {
		if ( (int) $bn_fs_cat_row['id'] === $bn_fs_cat_id ) {
			$bn_fs_cats[ $bn_fs_cat_id ] = $bn_fs_cat_row;
			break;
		}
	}
}

buddynext_get_template(
	'parts/space-directory-card.php',
	array(
		'space'           => $bn_fs_space,
		'membership'      => $bn_fs_membership,
		'current_user_id' => $bn_fs_viewer,
		'cat_by_id'       => $bn_fs_cats,
		'subspace_count'  => 0,
		'compact'         => 'compact' === ( isset( $size ) ? (string) $size : 'full' ),
		'show_action'     => ! isset( $show_action ) || (bool) $show_action,
	)
);
