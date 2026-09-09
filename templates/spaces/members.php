<?php
/**
 * BuddyNext space members template.
 *
 * Renders the unified space header + right rail, then defers the roster itself to
 * the reusable, header-less parts/space-members-body.php — which OWNS the roster
 * gate and the member query. Keeping the body in a part lets other surfaces (e.g.
 * Wellbee Circles) reuse the exact same members UX behind their own header without
 * re-authoring the gate/query/grid (card 10280272637).
 *
 * Context variable:
 *   $space_id (int) — the space's primary key.
 *
 * Overridable: copy to {theme}/buddynext/spaces/members.php
 *
 * REST endpoint: GET buddynext/v1/spaces/{id}/members
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Spaces\SpaceService;

// ── Context ─────────────────────────────────────────────────────────────────────
$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( $space_id <= 0 ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ), '', array( 'response' => 404 ) );
}

// Page-level existence guard (404 for a real visitor). The body part re-hydrates the
// space for its own gate/query and simply renders nothing if the space is missing,
// so the 404 belongs here where we own the HTTP response.
$space = ( new SpaceService() )->get( $space_id );
if ( null === $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ), '', array( 'response' => 404 ) );
}

$current_user_id = get_current_user_id();
?>
<div class="bn-sh-stack">

	<!-- Unified space header + nav bar (same as every other space tab). -->
	<?php
	buddynext_get_template(
		'parts/space-header.php',
		array(
			'space_id'   => $space_id,
			'active_tab' => 'members',
		)
	);
	// Uniform right rail (same cards as every other space tab; the Members-preview
	// card self-suppresses here since the roster below is the page body).
	\BuddyNext\Sidebar\Surface::set(
		'space',
		array(
			'space_id'   => $space_id,
			'viewer_id'  => $current_user_id,
			'active_tab' => 'members',
		)
	);

	// The roster body — gate + filter + grid + pagination, in its own
	// buddynext/space-members Interactivity region.
	buddynext_get_template(
		'parts/space-members-body.php',
		array( 'space_id' => $space_id )
	);
	?>

</div><!-- .bn-sh-stack -->
