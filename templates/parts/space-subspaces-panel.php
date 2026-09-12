<?php
/**
 * BuddyNext — Sub-spaces panel (space tab).
 *
 * The viewport-independent home for a space's children. Their only entry point
 * used to be a card in the right sidebar, which is `display: none` below 1024px
 * — so on every phone and tablet a parent space offered no path at all to its
 * own sub-spaces, and a manager could not even create one (the "Add sub-space"
 * CTA lives in that same hidden card). This tab is reachable at every width.
 *
 * The list is already visibility-scoped by SpaceService::get_subspaces(), so a
 * secret child the viewer may not see never reaches this template.
 *
 * Context variables:
 *   $space_id       (int)   — parent space id.
 *   $viewer_id      (int)   — viewer user id (0 = logged out).
 *   $subspaces      (array) — visible children (hydrated space rows).
 *   $membership_map (array) — viewer's membership per child, keyed by space id.
 *   $cat_by_id      (array) — category map (id => row) for the card's badge.
 *   $can_manage     (bool)  — viewer may add a sub-space here.
 *   $sub_max        (int)   — per-parent sub-space cap (0 = unlimited).
 *   $sub_used       (int)   — children already created, counted against the cap.
 *
 * Each child renders the SAME card as the top-level directory
 * (parts/space-directory-card.php) in its compact variant, so a sub-space carries
 * the identical privacy badge and Join / Request / Member control — one card
 * source, not a second hand-rolled one that drifts.
 *
 * Overridable: copy to {theme}/buddynext/parts/space-subspaces-panel.php
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_sp_space_id    = isset( $space_id ) ? absint( $space_id ) : 0;
$bn_sp_viewer_id   = isset( $viewer_id ) ? absint( $viewer_id ) : 0;
$bn_sp_subspaces   = isset( $subspaces ) && is_array( $subspaces ) ? $subspaces : array();
$bn_sp_memberships = isset( $membership_map ) && is_array( $membership_map ) ? $membership_map : array();
$bn_sp_cat_by_id   = isset( $cat_by_id ) && is_array( $cat_by_id ) ? $cat_by_id : array();
$bn_sp_can_manage  = ! empty( $can_manage );

// Per-parent cap (Settings -> Spaces -> "Max Sub-Spaces"). 0 = unlimited. $sub_used is
// counted with count_subspaces() upstream - every child, including ones this viewer
// cannot see - because that is the total the create path enforces against. Counting the
// visible list here would tell a manager there is room when the server will refuse.
$bn_sp_sub_max  = isset( $sub_max ) ? absint( $sub_max ) : 0;
$bn_sp_sub_used = isset( $sub_used ) ? absint( $sub_used ) : 0;
$bn_sp_sub_full = $bn_sp_sub_max > 0 && $bn_sp_sub_used >= $bn_sp_sub_max;

if ( $bn_sp_space_id <= 0 ) {
	return;
}

// The child cards carry the directory's Join / Request / Leave controls, which are
// WP Interactivity actions in the buddynext/spaces store — so the panel root
// declares that region and hands it the same REST nonce + base URL the directory
// region does, or the buttons render inert.
$bn_sp_region_context = (string) wp_json_encode(
	array(
		'restNonce' => wp_create_nonce( 'wp_rest' ),
		'restUrl'   => rest_url( 'buddynext/v1' ),
	)
);
?>

<div class="bn-space-subspaces"
	data-wp-interactive="buddynext/spaces"
	data-wp-context='<?php echo esc_attr( $bn_sp_region_context ); ?>'
>

	<?php if ( ! empty( $bn_sp_subspaces ) ) : ?>

		<div class="bn-sd-grid bn-space-subspaces__grid" role="list" data-bn-sd-grid>
			<?php
			foreach ( $bn_sp_subspaces as $bn_sp_sub ) :
				if ( ! is_array( $bn_sp_sub ) || '' === (string) ( $bn_sp_sub['slug'] ?? '' ) ) {
					continue;
				}
				buddynext_get_template(
					'parts/space-directory-card.php',
					array(
						'space'           => $bn_sp_sub,
						'membership'      => $bn_sp_memberships[ (int) ( $bn_sp_sub['id'] ?? 0 ) ] ?? null,
						'current_user_id' => $bn_sp_viewer_id,
						'cat_by_id'       => $bn_sp_cat_by_id,
						'compact'         => true,
					)
				);
			endforeach;
			?>
		</div>

	<?php elseif ( $bn_sp_can_manage ) : ?>

		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'layers',
				'title' => __( 'No sub-spaces yet', 'buddynext' ),
				'body'  => __( 'Organize this space into focused sub-spaces members can join on their own.', 'buddynext' ),
			)
		);
		?>

	<?php endif; ?>

	<?php if ( $bn_sp_can_manage ) : ?>
		<div class="bn-space-subspaces__cta"><?php // The buddynext/spaces region is declared on the panel root above. ?>
			<?php if ( $bn_sp_sub_max > 0 ) : ?>
				<p class="bn-space-subspaces__capacity">
					<?php
					printf(
						/* translators: 1: sub-spaces already created, 2: maximum allowed. */
						esc_html__( '%1$d of %2$d sub-spaces used', 'buddynext' ),
						(int) $bn_sp_sub_used,
						(int) $bn_sp_sub_max
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $bn_sp_sub_full ) : ?>
				<button
					type="button"
					class="bn-btn bn-space-subspaces__add"
					data-variant="primary"
					disabled
					aria-describedby="bn-sp-sub-full"
				>
					<?php buddynext_icon( 'plus' ); ?>
					<?php esc_html_e( 'Add sub-space', 'buddynext' ); ?>
				</button>
				<p class="bn-space-subspaces__full" id="bn-sp-sub-full">
					<?php esc_html_e( 'This space has reached its sub-space limit.', 'buddynext' ); ?>
				</p>
			<?php else : ?>
				<button
					type="button"
					class="bn-btn bn-space-subspaces__add"
					data-variant="primary"
					data-wp-on--click="actions.openCreate"
					data-bn-create-space-trigger
				>
					<?php buddynext_icon( 'plus' ); ?>
					<?php esc_html_e( 'Add sub-space', 'buddynext' ); ?>
				</button>
			<?php endif; ?>
		</div>
	<?php endif; ?>

</div>
