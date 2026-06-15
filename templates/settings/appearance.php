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

ob_start();
?>
<div class="bn-ep-appearance">
	<div class="bn-ep-field bn-ep-field--full">
		<span class="bn-ep-label" id="bn-ep-theme-lbl"><?php esc_html_e( 'Theme', 'buddynext' ); ?></span>
		<div class="bn-ep-segmented" role="group" aria-labelledby="bn-ep-theme-lbl">
			<button type="button" class="bn-btn bn-ep-segmented__btn" data-variant="ghost" data-size="sm"
				data-bn-action="set-theme" data-theme="light"><?php esc_html_e( 'Light', 'buddynext' ); ?></button>
			<button type="button" class="bn-btn bn-ep-segmented__btn" data-variant="ghost" data-size="sm"
				data-bn-action="set-theme" data-theme="dark"><?php esc_html_e( 'Dark', 'buddynext' ); ?></button>
			<button type="button" class="bn-btn bn-ep-segmented__btn" data-variant="ghost" data-size="sm"
				data-bn-action="set-theme" data-theme="auto"><?php esc_html_e( 'Auto', 'buddynext' ); ?></button>
		</div>
		<p class="bn-ep-field-help"><?php esc_html_e( 'Auto follows your system setting and switches when it changes.', 'buddynext' ); ?></p>
	</div>
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
