<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Profile owner action bar partial.
 *
 * Renders a horizontal bar of owner-specific actions below the profile cover.
 * Visible only when the viewer is the profile owner or a site administrator.
 * Extensible via the `buddynext_profile_owner_actions` filter.
 *
 * Context variables (required):
 *   $user_id         int   ID of the profile being viewed.
 *   $is_own_profile  bool  True when viewer == profile owner.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! $is_own_profile && ! current_user_can( 'edit_users' ) ) {
	return;
}

$edit_url = \BuddyNext\Core\PageRouter::edit_profile_url();

/**
 * Default actions for the profile owner action bar.
 *
 * Each action is an array with keys:
 *   key    string  Machine key — unique identifier.
 *   label  string  Translated display label.
 *   url    string  Destination URL.
 *   icon   string  Inline SVG string (without <svg> wrapper — just the path/shape).
 *
 * @param array[] $actions  Default actions array.
 * @param int     $user_id  Profile owner's user ID.
 */
$bn_owner_actions = apply_filters(
	'buddynext_profile_owner_actions',
	array(
		array(
			'key'   => 'edit_profile',
			'label' => __( 'Edit Profile', 'buddynext' ),
			'url'   => $edit_url,
			'icon'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
		),
		array(
			'key'   => 'edit_avatar',
			'label' => __( 'Edit Avatar', 'buddynext' ),
			'url'   => $edit_url . '#avatar',
			'icon'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		),
		array(
			'key'   => 'edit_cover',
			'label' => __( 'Edit Cover', 'buddynext' ),
			'url'   => $edit_url . '#cover',
			'icon'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
		),
	),
	$user_id
);

if ( empty( $bn_owner_actions ) ) {
	return;
}
?>

<style id="bn-profile-actions-bar-css">
.bn-profile-actions-bar {
	background: var(--bg, #fff);
	border-bottom: 1px solid var(--border, #e8e8e5);
	padding: 0 var(--s8, 32px);
}
.bn-profile-actions-bar-inner {
	max-width: 1200px;
	margin: 0 auto;
	display: flex;
	align-items: center;
	gap: var(--s2, 8px);
	height: 48px;
	overflow-x: auto;
	scrollbar-width: none;
	-ms-overflow-style: none;
}
.bn-profile-actions-bar-inner::-webkit-scrollbar { display: none; }
.bn-owner-action {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 14px;
	border-radius: 6px;
	font-size: 13px;
	font-weight: 500;
	color: var(--text-2, #787774);
	text-decoration: none;
	background: var(--bg-subtle, #f8f8f7);
	border: 1px solid var(--border, #e8e8e5);
	white-space: nowrap;
	flex-shrink: 0;
	transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.bn-owner-action:first-child {
	color: var(--brand, #0073aa);
	border-color: var(--brand, #0073aa);
	background: var(--brand-light, #e8f4fb);
}
.bn-owner-action:hover {
	background: var(--bg-hover, #f1f1f0);
	color: var(--text-1, #37352f);
	border-color: var(--border, #e8e8e5);
}
.bn-owner-action:first-child:hover {
	background: var(--brand, #0073aa);
	color: #fff;
}
@media (max-width: 640px) {
	.bn-profile-actions-bar { padding: 0 var(--s2, 8px); }
}
</style>

<div class="bn-profile-actions-bar" role="navigation" aria-label="<?php esc_attr_e( 'Profile management', 'buddynext' ); ?>">
	<div class="bn-profile-actions-bar-inner">
		<?php foreach ( $bn_owner_actions as $bn_action ) : ?>
			<a href="<?php echo esc_url( $bn_action['url'] ); ?>"
				class="bn-owner-action bn-owner-action--<?php echo esc_attr( $bn_action['key'] ); ?>"
				data-action-key="<?php echo esc_attr( $bn_action['key'] ); ?>">
				<?php echo wp_kses( $bn_action['icon'], array( 'svg' => array( 'width' => true, 'height' => true, 'viewBox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true ), 'path' => array( 'd' => true ), 'circle' => array( 'cx' => true, 'cy' => true, 'r' => true ), 'polyline' => array( 'points' => true ), 'rect' => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true ) ) ); ?>
				<?php echo esc_html( $bn_action['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</div>
</div>
