<?php
/**
 * Partial: Space content gate card.
 *
 * The informational "you cannot read this space's content" card, shown when a
 * viewer may not read a private/plan-gated space's posts. Extracted from
 * spaces/home.php so the space-home tab body and the PUBLIC feed entry
 * (SpaceNav::render_feed) render the identical gate instead of two copies that
 * could drift.
 *
 * The card is purely informational: the space hero (rendered in the header)
 * owns the single primary CTA for every state (guest "Log in to join", pending
 * "Request pending", "Request to join"), so this deliberately carries no button
 * — one primary CTA per page, matching how Facebook/LinkedIn present a gated
 * group. A surface that renders this WITHOUT the hero (e.g. a Pro theme's own
 * space homepage) supplies its own join CTA.
 *
 * Variables:
 *   bool   $gate_is_plan Whether the gate is a required membership plan (vs private).
 *   string $gate_plan    Plan name, when $gate_is_plan (may be '').
 *   bool   $is_invited   Whether the viewer has a pending invitation to accept.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$gate_is_plan = ! empty( $gate_is_plan );
$gate_plan    = isset( $gate_plan ) ? (string) $gate_plan : '';
$is_invited   = ! empty( $is_invited );
?>
<div class="bn-card bn-sh-gate">
	<div class="bn-sh-gate__icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></div>
	<h2 class="bn-sh-gate__title">
		<?php
		echo $gate_is_plan
			? esc_html__( 'This space needs a plan', 'buddynext' )
			: esc_html__( 'This is a private space', 'buddynext' );
		?>
	</h2>
	<p class="bn-sh-gate__lede">
		<?php
		if ( $gate_is_plan ) {
			printf(
				/* translators: %s: membership plan name. */
				esc_html__( 'You are a member of this space. Reading and posting here is included with %s.', 'buddynext' ),
				esc_html( $gate_plan )
			);
		} elseif ( $is_invited ) {
			esc_html_e( 'Accept the invitation above to read posts and participate.', 'buddynext' );
		} else {
			esc_html_e( 'Join to read posts and participate in discussions.', 'buddynext' );
		}
		?>
	</p>
</div>
