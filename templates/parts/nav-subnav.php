<?php
/**
 * BuddyNext template part: nav-subnav — the shared one-level sub-navigation.
 *
 * The canonical SECONDARY nav, used by every surface and every integration
 * (own + 3rd party) whose Nav API parent declares children. It is a distinct
 * component from the primary tab bar (parts/nav-bar.php) — its OWN `.bn-subnav`
 * class namespace, so it never inherits or fights `.bn-tab` rules (theme-proof
 * borders, the active underline, etc.). One clean contract, no overrides.
 *
 * Always rendered into the DOM and toggled reactively (so a parent tab clicked
 * client-side can reveal it without a reload): the wrapper hides unless the
 * branch is active (state.isActiveBranch), with a matching server-side `hidden`
 * attribute to avoid a flash. Each item drives off the same single activeTab —
 * the child whose tab === activeTab is the active one (state.isActiveTab).
 *
 * @package BuddyNext\Nav
 *
 * @var \BuddyNext\Nav\NavItem[] $items         Required. Child items.
 * @var string                   $active        Optional. Active tab slug.
 * @var string[]                 $branch        Optional. Child target slugs (reveal set).
 * @var string                   $parent_target Optional. Parent's default tab target.
 * @var bool                     $hidden        Optional. Initial (server) hidden state.
 * @var string                   $tablist_label Optional. aria-label for the sub tablist.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Nav\NavItem;

$bn_sub_items = isset( $items ) && is_array( $items ) ? $items : array();
if ( empty( $bn_sub_items ) ) {
	return;
}

$bn_sub_active = isset( $active ) ? (string) $active : '';
$bn_sub_branch = isset( $branch ) && is_array( $branch ) ? $branch : array();
$bn_sub_parent = isset( $parent_target ) ? (string) $parent_target : '';
$bn_sub_hidden = ! empty( $hidden );
$bn_sub_label  = isset( $tablist_label ) && '' !== (string) $tablist_label ? (string) $tablist_label : __( 'Sub sections', 'buddynext' );

$bn_sub_ctx = esc_attr(
	(string) wp_json_encode(
		array(
			'tabSlug' => $bn_sub_parent,
			'branch'  => $bn_sub_branch,
		)
	)
);
?>
<div class="bn-subnav" role="tablist" aria-label="<?php echo esc_attr( $bn_sub_label ); ?>"
	data-wp-context='<?php echo $bn_sub_ctx; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>'
	data-wp-bind--hidden="!state.isActiveBranch"
	<?php echo $bn_sub_hidden ? 'hidden' : ''; ?>
>
	<?php
	foreach ( $bn_sub_items as $bn_sub ) :
		if ( ! ( $bn_sub instanceof NavItem ) ) {
			continue;
		}
		$bn_s_target = null !== $bn_sub->tab ? $bn_sub->tab : $bn_sub->id;
		$bn_s_count  = ( null !== $bn_sub->count_value && $bn_sub->count_value > 0 ) ? (string) $bn_sub->count_value : '';
		$bn_s_active = '' !== $bn_sub_active && ( $bn_sub_active === $bn_s_target || $bn_sub_active === $bn_sub->id );
		$bn_s_aria   = '' !== $bn_s_count ? sprintf( '%s (%s)', $bn_sub->label, $bn_s_count ) : $bn_sub->label;
		?>
		<?php if ( null !== $bn_sub->tab ) : ?>
			<button class="bn-subnav__item" role="tab" type="button"
				data-wp-context='<?php echo esc_attr( (string) wp_json_encode( array( 'tabSlug' => $bn_s_target ) ) ); ?>'
				data-wp-class--active="state.isActiveTab"
				data-wp-bind--aria-selected="state.isActiveTab"
				aria-selected="<?php echo $bn_s_active ? 'true' : 'false'; ?>"
				aria-label="<?php echo esc_attr( $bn_s_aria ); ?>"
				data-wp-on--click="actions.setTab"
				data-tab="<?php echo esc_attr( $bn_s_target ); ?>">
				<span class="bn-subnav__label"><?php echo esc_html( $bn_sub->label ); ?></span>
				<?php if ( '' !== $bn_s_count ) : ?>
					<span class="bn-subnav__count"><?php echo esc_html( $bn_s_count ); ?></span>
				<?php endif; ?>
			</button>
		<?php else : ?>
			<a class="bn-subnav__item" role="tab"
				aria-selected="<?php echo $bn_s_active ? 'true' : 'false'; ?>"
				<?php echo $bn_s_active ? 'aria-current="page"' : ''; ?>
				aria-label="<?php echo esc_attr( $bn_s_aria ); ?>"
				href="<?php echo esc_url( (string) $bn_sub->url_value ); ?>">
				<span class="bn-subnav__label"><?php echo esc_html( $bn_sub->label ); ?></span>
				<?php if ( '' !== $bn_s_count ) : ?>
					<span class="bn-subnav__count"><?php echo esc_html( $bn_s_count ); ?></span>
				<?php endif; ?>
			</a>
		<?php endif; ?>
	<?php endforeach; ?>
</div>
