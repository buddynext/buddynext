<?php
/**
 * Settings → Appearance.
 *
 * Theme + text-size pickers. Pure-client preferences: values live in
 * localStorage and apply instantly via the head bootstrap script
 * (assets/js/shell/font-scale.js, loaded on every hub). Nothing to POST, so this
 * section needs no Interactivity store. Relocated here from the profile editor.
 *
 * Overridable: copy to {theme}/buddynext/settings/appearance.php.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

// NOTE: No light/dark theme control here. Dark mode is owned by the active
// theme (BuddyX / BuddyX Pro / Reign expose their own color-mode toggle, which
// BuddyNext follows via the [data-bx-mode="dark"] token bridge). A second BN
// theme switch would only fight the theme's, so we keep just the BN-specific
// text-size preference.
ob_start();
?>
<div class="bn-ep-appearance">
	<div class="bn-ep-field bn-ep-field--full">
		<span class="bn-ep-label" id="bn-ep-textscale-lbl"><?php esc_html_e( 'Text size', 'buddynext' ); ?></span>
		<div class="bn-ep-segmented" role="group" aria-labelledby="bn-ep-textscale-lbl">
			<button type="button" class="bn-btn bn-ep-segmented__btn" data-variant="ghost" data-size="sm"
				data-bn-action="set-font-scale" data-scale="100">A</button>
			<button type="button" class="bn-btn bn-ep-segmented__btn" data-variant="ghost" data-size="sm"
				data-bn-action="set-font-scale" data-scale="110">A+</button>
			<button type="button" class="bn-btn bn-ep-segmented__btn" data-variant="ghost" data-size="sm"
				data-bn-action="set-font-scale" data-scale="120">A++</button>
		</div>
		<p class="bn-ep-field-help"><?php esc_html_e( 'Scales BuddyNext text on this device. Light or dark mode is set by your theme.', 'buddynext' ); ?></p>
	</div>
</div>
<?php
$bn_appearance_html = (string) ob_get_clean();
?>
<div class="bn-settings">
	<?php buddynext_get_template( 'parts/settings-nav.php', array( 'bn_settings_active' => 'appearance' ) ); ?>
	<div class="bn-settings__section">
		<?php
		buddynext_get_template(
			'parts/profile-edit-section.php',
			array(
				'title'     => __( 'Appearance', 'buddynext' ),
				'subtitle'  => __( 'Choose how BuddyNext looks for you on this device.', 'buddynext' ),
				'body_html' => $bn_appearance_html,
			)
		);
		?>
	</div>
</div>
