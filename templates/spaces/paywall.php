<?php
/**
 * BuddyNext template part: gated-space paywall.
 *
 * Rendered when a logged-in viewer is a non-member of a space that requires a
 * membership tier they do not hold. Shows a blurred/locked preview, the
 * admin-configured description, and a "Become a Member" CTA.
 *
 * Two CTA modes:
 *   - External (spec default): the CTA is an anchor pointing OUT to wherever
 *     the site sells access (WooCommerce, a Stripe payment link, etc.).
 *   - First-party checkout (Pro, only when a Stripe price is linked to the
 *     tier): the CTA is a button that the Spaces Interactivity store wires to
 *     POST buddynext-pro/v1/me/checkout and redirect to the returned Stripe
 *     Checkout URL.
 *
 * This part is theme-overridable at
 * {child-theme}/buddynext/spaces/paywall.php and emits no inline styles — see
 * the `.bn-paywall*` rules in assets/css/bn-spaces.css (OKLCH --bn-* tokens).
 *
 * @package BuddyNext
 *
 * @var int    $space_id   Required. Space the viewer was denied.
 * @var string $heading    Optional. Paywall heading. Has a sensible default.
 * @var string $description Optional. Body copy below the heading.
 * @var string $cta_url    Optional. External CTA href (external mode).
 * @var string $cta_label  Required. CTA button/link label.
 * @var string $tier_slug  Optional. Required membership tier slug.
 * @var string $tier_name  Optional. Human-readable tier name.
 * @var bool   $checkout   Optional. When true, render the first-party Stripe
 *                         checkout button instead of an external link.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pw_space_id  = isset( $space_id ) ? (int) $space_id : 0;
$bn_pw_heading   = isset( $heading ) && '' !== (string) $heading
	? (string) $heading
	: __( 'This space is available to members only.', 'buddynext' );
$bn_pw_desc      = isset( $description ) ? (string) $description : '';
$bn_pw_cta_url   = isset( $cta_url ) ? (string) $cta_url : '';
$bn_pw_cta_label = isset( $cta_label ) && '' !== (string) $cta_label
	? (string) $cta_label
	: __( 'Become a Member', 'buddynext' );
$bn_pw_tier_slug = isset( $tier_slug ) ? (string) $tier_slug : '';
$bn_pw_tier_name = isset( $tier_name ) ? (string) $tier_name : '';
$bn_pw_checkout  = isset( $checkout ) ? (bool) $checkout : false;
?>
<div class="bn-paywall" data-space-id="<?php echo esc_attr( (string) $bn_pw_space_id ); ?>" role="region" aria-label="<?php esc_attr_e( 'Members-only space', 'buddynext' ); ?>">
	<div class="bn-paywall__preview" aria-hidden="true">
		<span class="bn-paywall__lock"><?php buddynext_icon( 'lock' ); ?></span>
	</div>
	<div class="bn-paywall__body">
		<h2 class="bn-paywall__heading"><?php echo esc_html( $bn_pw_heading ); ?></h2>

		<?php if ( '' !== $bn_pw_tier_name ) : ?>
			<p class="bn-paywall__tier">
				<?php
				/* translators: %s: membership tier name. */
				printf( esc_html__( 'Requires the %s membership.', 'buddynext' ), '<strong>' . esc_html( $bn_pw_tier_name ) . '</strong>' );
				?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $bn_pw_desc ) : ?>
			<p class="bn-paywall__description"><?php echo esc_html( $bn_pw_desc ); ?></p>
		<?php endif; ?>

		<?php if ( $bn_pw_checkout && '' !== $bn_pw_tier_slug ) : ?>
			<button
				type="button"
				class="bn-btn bn-paywall__cta"
				data-variant="primary"
				data-bn-paywall-checkout
				data-tier-slug="<?php echo esc_attr( $bn_pw_tier_slug ); ?>"
				data-wp-on--click="actions.startCheckout"
			><?php buddynext_icon( 'sparkles' ); ?> <?php echo esc_html( $bn_pw_cta_label ); ?></button>
		<?php elseif ( '' !== $bn_pw_cta_url ) : ?>
			<a
				href="<?php echo esc_url( $bn_pw_cta_url ); ?>"
				class="bn-btn bn-paywall__cta"
				data-variant="primary"
			><?php buddynext_icon( 'sparkles' ); ?> <?php echo esc_html( $bn_pw_cta_label ); ?></a>
		<?php else : ?>
			<p class="bn-paywall__unconfigured"><?php esc_html_e( 'Membership purchase is not configured yet. Please check back soon.', 'buddynext' ); ?></p>
		<?php endif; ?>
	</div>
</div>
<?php
