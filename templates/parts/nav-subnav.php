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
 * Rendered alongside its parent in nav-bar.php and shown by the server-side
 * `hidden` attribute only when the parent's branch is the active tab (so exactly
 * the active parent's sub-nav is visible). Each child is a clean-URL `<a href>`
 * with server-rendered `aria-current` active state — the same url+render model as
 * the primary bar; the client-nav transport handles no-reload switching.
 *
 * @package BuddyNext\Nav
 *
 * @var \BuddyNext\Nav\NavItem[] $items         Required. Child items.
 * @var string                   $active        Optional. Active tab slug.
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
$bn_sub_hidden = ! empty( $hidden );
$bn_sub_label  = isset( $tablist_label ) && '' !== (string) $tablist_label ? (string) $tablist_label : __( 'Sub sections', 'buddynext' );

?>
<div class="bn-subnav" role="tablist" aria-label="<?php echo esc_attr( $bn_sub_label ); ?>"
	<?php echo $bn_sub_hidden ? 'hidden' : ''; ?>
>
	<?php
	foreach ( $bn_sub_items as $bn_sub ) :
		if ( ! ( $bn_sub instanceof NavItem ) ) {
			continue;
		}
		$bn_s_count  = ( null !== $bn_sub->count_value && $bn_sub->count_value > 0 ) ? (string) $bn_sub->count_value : '';
		$bn_s_active = '' !== $bn_sub_active && $bn_sub_active === $bn_sub->id;
		$bn_s_aria   = '' !== $bn_s_count ? sprintf( '%s (%s)', $bn_sub->label, $bn_s_count ) : $bn_sub->label;
		?>
		<a class="bn-subnav__item" role="tab"
			aria-selected="<?php echo $bn_s_active ? 'true' : 'false'; ?>"
			<?php echo $bn_s_active ? 'aria-current="page"' : ''; ?>
			aria-label="<?php echo esc_attr( $bn_s_aria ); ?>"
			href="<?php echo esc_url( (string) $bn_sub->url_value ); ?>">
			<?php require __DIR__ . '/nav-subnav-item-inner.php'; ?>
		</a>
	<?php endforeach; ?>
</div>
