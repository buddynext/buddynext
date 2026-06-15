<?php
/**
 * BuddyNext template part: space-settings-panel-general.
 *
 * Renders the "General" panel inside the space settings shell.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space            Required. Space row (from `bn_spaces`).
 * @var array  $settings_general Optional. Bundle:
 *   - `categories` (array)        Category rows for the category select.
 * @var array  $classes          Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_general_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_general_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_general_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_general_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'            => isset( $space ) ? $space : null,
	'settings_general' => isset( $settings_general ) && is_array( $settings_general ) ? $settings_general : array(),
	'classes'          => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_general_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}

$bn_categories = isset( $args['settings_general']['categories'] ) && is_array( $args['settings_general']['categories'] )
	? $args['settings_general']['categories']
	: array();

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_general_classes', $bn_classes, $args );
$bn_class   = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

do_action( 'buddynext_part_space_settings_panel_general_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<header class="bn-space-settings__panel-head">
		<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'General', 'buddynext' ); ?></h2>
		<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Basic information about your space.', 'buddynext' ); ?></p>
	</header>

	<div class="bn-space-settings__field">
		<label for="bn_space_icon"><?php esc_html_e( 'Space icon', 'buddynext' ); ?></label>
		<div class="bn-space-settings__upload">
			<div class="bn-space-settings__upload-current" aria-hidden="true">
				<?php if ( ! empty( $bn_space->avatar_url ) ) : ?>
					<img src="<?php echo esc_url( $bn_space->avatar_url ); ?>" alt="">
				<?php else : ?>
					<?php echo wp_kses( bn_space_category_icon( $bn_space->category_slug ?? '' ), \BuddyNext\Core\IconService::allowed_tags() ); ?>
				<?php endif; ?>
			</div>
			<?php // Category-icon fallback the JS restores into the preview when the icon is removed. ?>
			<template class="bn-space-settings__upload-fallback"><?php echo wp_kses( bn_space_category_icon( $bn_space->category_slug ?? '' ), \BuddyNext\Core\IconService::allowed_tags() ); ?></template>
			<div class="bn-space-settings__upload-actions">
				<button type="button" class="bn-btn" data-variant="secondary" data-size="md" id="bn_space_icon">
					<?php esc_html_e( 'Upload image', 'buddynext' ); ?>
				</button>
				<button type="button" class="bn-btn" data-variant="ghost" data-size="md" data-bn-icon-remove<?php echo empty( $bn_space->avatar_url ) ? ' hidden' : ''; ?>>
					<?php esc_html_e( 'Remove', 'buddynext' ); ?>
				</button>
				<p class="bn-space-settings__hint"><?php esc_html_e( 'Or pick an icon based on the category.', 'buddynext' ); ?></p>
			</div>
		</div>
	</div>

	<div class="bn-space-settings__field" data-bn-cover-field>
		<label><?php esc_html_e( 'Cover image', 'buddynext' ); ?></label>
		<div
			class="bn-space-settings__cover<?php echo ! empty( $bn_space->cover_image_url ) ? ' has-image' : ''; ?>"
			data-bn-cover-preview
			role="button"
			tabindex="0"
			aria-label="<?php esc_attr_e( 'Upload cover photo', 'buddynext' ); ?>"
			<?php if ( ! empty( $bn_space->cover_image_url ) ) : ?>
				style="background-image:url('<?php echo esc_url( $bn_space->cover_image_url ); ?>');background-size:cover;background-position:center;"
			<?php endif; ?>
		>
			<span class="bn-space-settings__cover-empty"<?php echo ! empty( $bn_space->cover_image_url ) ? ' hidden' : ''; ?>>
				<?php buddynext_icon( 'image' ); ?> <?php esc_html_e( 'Upload cover photo', 'buddynext' ); ?>
			</span>
		</div>
		<input
			type="hidden"
			name="space_cover_image_url"
			data-bn-cover-input
			value="<?php echo esc_attr( $bn_space->cover_image_url ?? '' ); ?>"
		>
		<div class="bn-space-settings__cover-actions">
			<?php // The drop-zone above is the upload/replace target; the action row only carries Remove. ?>
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-size="sm"
				data-bn-cover-remove
				<?php echo empty( $bn_space->cover_image_url ) ? 'hidden' : ''; ?>
			>
				<?php esc_html_e( 'Remove', 'buddynext' ); ?>
			</button>
		</div>
		<p class="bn-space-settings__hint"><?php esc_html_e( 'Recommended 1500×500. Falls back to a gradient when empty.', 'buddynext' ); ?></p>
	</div>

	<div class="bn-space-settings__field">
		<label for="space_name"><?php esc_html_e( 'Space name', 'buddynext' ); ?> <span aria-hidden="true">*</span></label>
		<input
			type="text"
			id="space_name"
			name="space_name"
			class="bn-input"
			value="<?php echo esc_attr( $bn_space->name ?? '' ); ?>"
			required
			maxlength="100"
		>
	</div>

	<div class="bn-space-settings__field">
		<label for="space_description"><?php esc_html_e( 'Description', 'buddynext' ); ?></label>
		<textarea
			id="space_description"
			name="space_description"
			class="bn-textarea"
			maxlength="160"
			rows="3"
		><?php echo esc_textarea( $bn_space->description ?? '' ); ?></textarea>
		<p class="bn-space-settings__hint"><?php esc_html_e( '160 characters max. Shown in the spaces directory.', 'buddynext' ); ?></p>
	</div>

	<div class="bn-space-settings__field">
		<label for="space_rules"><?php esc_html_e( 'House rules', 'buddynext' ); ?></label>
		<textarea
			id="space_rules"
			name="space_rules"
			class="bn-textarea"
			rows="6"
			placeholder="<?php esc_attr_e( "Be kind\nNo spam\nStay on topic", 'buddynext' ); ?>"
		><?php echo esc_textarea( $bn_space->rules ?? '' ); ?></textarea>
		<p class="bn-space-settings__hint"><?php esc_html_e( 'One rule per line. Renders as a numbered list on the About tab.', 'buddynext' ); ?></p>
	</div>

	<div class="bn-space-settings__field">
		<label for="space_category_id"><?php esc_html_e( 'Category', 'buddynext' ); ?></label>
		<select name="space_category_id" id="space_category_id" class="bn-select">
			<option value=""><?php esc_html_e( 'Select a category', 'buddynext' ); ?></option>
			<?php foreach ( $bn_categories as $bn_cat_item ) : ?>
				<option
					value="<?php echo esc_attr( (string) $bn_cat_item->id ); ?>"
					<?php selected( (int) ( $bn_space->category_id ?? 0 ), (int) $bn_cat_item->id ); ?>
				><?php echo esc_html( $bn_cat_item->name ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_general_after', $args );
