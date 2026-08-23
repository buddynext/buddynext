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
 * @var bool   $is_space_owner   Required. Whether the viewer owns the space. The
 *                               identity fields (name/description/rules) are
 *                               owner-only at the service layer, so a non-owner gets
 *                               them read-only. Omitted = false (fails closed).
 * @var array  $settings_general Optional. Bundle:
 *   - `categories`       (array) Category rows for the category select.
 *   - `eligible_parents` (array) Spaces this one may be moved under, from
 *                                SpaceService::eligible_parents(). Empty = no move is
 *                                possible (sub-spaces off, or this space has children
 *                                of its own, or the actor manages no other root).
 *   - `has_subspaces`    (bool)  Whether this space has children — the reason an empty
 *                                candidate list gets an explanation instead of silence.
 *   - `sub_spaces_on`    (bool)  Whether sub-spaces are enabled for the community.
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
	// A moderator may OPEN this panel but may not SAVE it — the space's identity
	// (name, description, house rules) is owner-only at the service layer. Rendering
	// the inputs live for them was an invitation to do something the server would
	// then refuse: retype the name, press Save, receive "You do not have permission
	// to update this space." Defaults to false so a caller that forgets the flag
	// fails CLOSED (read-only) rather than open. Mirrors the Integrations panel.
	'is_space_owner'   => isset( $is_space_owner ) ? (bool) $is_space_owner : false,
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

// Parent space. Moving/detaching a sub-space was fully implemented and validated over
// REST (depth, cycles, per-parent cap, manage permission) but had no control on any
// surface, so an owner could not promote a sub-space to the top level without making
// an API call. The candidate list comes from SpaceService::eligible_parents(), which
// mirrors the service's own validation — the picker must never offer a move that the
// save would then reject.
$bn_eligible_parents = isset( $args['settings_general']['eligible_parents'] ) && is_array( $args['settings_general']['eligible_parents'] )
	? $args['settings_general']['eligible_parents']
	: array();
$bn_has_subspaces    = ! empty( $args['settings_general']['has_subspaces'] );
$bn_sub_spaces_on    = ! empty( $args['settings_general']['sub_spaces_on'] );
$bn_current_parent   = isset( $bn_space->parent_id ) ? (int) $bn_space->parent_id : 0;

// The current parent is NOT necessarily one of the move candidates: a space can sit
// under a root the actor does not manage, and that root is correctly absent from the
// candidate list. It must still appear in the select, or the control would not carry
// the current value — the browser would fall back to the first option ("Top level")
// and saving the General tab for an unrelated reason would silently DETACH the space.
$bn_parent_now = isset( $args['settings_general']['current_parent'] ) && is_array( $args['settings_general']['current_parent'] )
	? $args['settings_general']['current_parent']
	: null;

$bn_parent_in_list = false;
foreach ( $bn_eligible_parents as $bn_candidate ) {
	if ( (int) $bn_candidate['id'] === $bn_current_parent ) {
		$bn_parent_in_list = true;
		break;
	}
}

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

	<?php if ( ! $args['is_space_owner'] ) : ?>
		<p class="bn-space-settings__hint bn-space-settings__hint--locked">
			<?php // Kept in step with the disabled() calls below — category and parent space are owner-only too, and the old copy did not say so. ?>
			<?php esc_html_e( 'Only the space owner can change the name, description, house rules, category and parent space. You can still manage everything else here.', 'buddynext' ); ?>
		</p>
	<?php endif; ?>

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
			<?php disabled( ! $args['is_space_owner'] ); ?>
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
			<?php disabled( ! $args['is_space_owner'] ); ?>
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
			<?php disabled( ! $args['is_space_owner'] ); ?>
		><?php echo esc_textarea( $bn_space->rules ?? '' ); ?></textarea>
		<p class="bn-space-settings__hint"><?php esc_html_e( 'One rule per line. Renders as a numbered list on the About tab.', 'buddynext' ); ?></p>
	</div>

	<?php
	// Brand colour — owner-only, a registered `color` space field (saved through
	// the field registry in settings.php). An <input type="color"> cannot hold an
	// empty value, so a paired checkbox toggles between the custom colour and the
	// default tone derived from the space id.
	$bn_brand_color  = (string) buddynext_get_space_field( (int) ( $bn_space->id ?? 0 ), 'brand_color' );
	$bn_brand_on     = '' !== $bn_brand_color;
	$bn_brand_swatch = $bn_brand_on ? $bn_brand_color : '#2563eb';
	?>
	<div class="bn-space-settings__field">
		<label for="space_brand_color"><?php esc_html_e( 'Brand colour', 'buddynext' ); ?></label>
		<label class="bn-space-settings__check">
			<input
				type="checkbox"
				name="space_brand_color_enabled"
				value="1"
				<?php checked( $bn_brand_on ); ?>
				<?php disabled( ! $args['is_space_owner'] ); ?>
			/>
			<span><?php esc_html_e( 'Use a custom brand colour for this space', 'buddynext' ); ?></span>
		</label>
		<input
			type="color"
			id="space_brand_color"
			name="space_brand_color"
			class="bn-space-settings__color"
			value="<?php echo esc_attr( $bn_brand_swatch ); ?>"
			<?php disabled( ! $args['is_space_owner'] ); ?>
		/>
		<p class="bn-space-settings__hint"><?php esc_html_e( 'Sets the space accent. Leave unchecked to use the default colour derived from the space.', 'buddynext' ); ?></p>
	</div>

	<div class="bn-space-settings__field">
		<label for="space_category_id"><?php esc_html_e( 'Category', 'buddynext' ); ?></label>
		<?php
		// Owner-only, like name / description / rules directly above. Category is
		// part of the space's identity and reach, and SpaceService::update() gates
		// it on buddynext-manage-space — the save handler's own comment says "it is
		// owner-gated, so a moderator is rejected there". Rendering it enabled
		// anyway is what made a moderator's Save fail on a control they were
		// invited to use. Disabled says the same thing before they type.
		?>
		<select name="space_category_id" id="space_category_id" class="bn-select"
			<?php disabled( ! $args['is_space_owner'] ); ?>
		>
			<option value=""><?php esc_html_e( 'Select a category', 'buddynext' ); ?></option>
			<?php foreach ( $bn_categories as $bn_cat_item ) : ?>
				<option
					value="<?php echo esc_attr( (string) $bn_cat_item->id ); ?>"
					<?php selected( (int) ( $bn_space->category_id ?? 0 ), (int) $bn_cat_item->id ); ?>
				><?php echo esc_html( $bn_cat_item->name ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<?php // Parent space. Only shown when sub-spaces are on for the community — a control for a feature the owner switched off is noise. ?>
	<?php if ( $bn_sub_spaces_on ) : ?>
		<div class="bn-space-settings__field">
			<label for="space_parent_id"><?php esc_html_e( 'Parent space', 'buddynext' ); ?></label>

			<?php if ( $bn_has_subspaces ) : ?>
				<?php
				// This space has children, so it can only ever be a root: nesting it would
				// put its children three levels deep. Say that, rather than showing a
				// picker that would be rejected.
				?>
				<select id="space_parent_id" class="bn-select" disabled>
					<option><?php esc_html_e( 'Top level (this space has sub-spaces)', 'buddynext' ); ?></option>
				</select>
				<p class="bn-space-settings__hint">
					<?php esc_html_e( 'This space has sub-spaces of its own, so it must stay at the top level. Move or remove them first if you want to nest it.', 'buddynext' ); ?>
				</p>

			<?php elseif ( empty( $bn_eligible_parents ) && $bn_current_parent <= 0 ) : ?>
				<select id="space_parent_id" class="bn-select" disabled>
					<option><?php esc_html_e( 'Top level', 'buddynext' ); ?></option>
				</select>
				<p class="bn-space-settings__hint">
					<?php esc_html_e( 'There is no other top-level space you manage that this one could sit under.', 'buddynext' ); ?>
				</p>

			<?php else : ?>
				<?php // Owner-only for the same reason as Category above — re-parenting is structural, and SpaceService::update() rejects a moderator. ?>
				<select name="space_parent_id" id="space_parent_id" class="bn-select"
					<?php disabled( ! $args['is_space_owner'] ); ?>
				>
					<option value="0" <?php selected( $bn_current_parent, 0 ); ?>>
						<?php esc_html_e( 'Top level (no parent)', 'buddynext' ); ?>
					</option>

					<?php // The current parent, when it is not itself a move candidate (a root the actor does not manage). Keeping it selectable makes "leave it where it is" a real choice; without it, saving would detach. ?>
					<?php if ( $bn_current_parent > 0 && ! $bn_parent_in_list ) : ?>
						<option value="<?php echo esc_attr( (string) $bn_current_parent ); ?>" selected>
							<?php
							echo esc_html(
								null !== $bn_parent_now && '' !== (string) ( $bn_parent_now['name'] ?? '' )
									? (string) $bn_parent_now['name']
									: __( 'Current parent space', 'buddynext' )
							);
							?>
						</option>
					<?php endif; ?>

					<?php foreach ( $bn_eligible_parents as $bn_parent ) : ?>
						<option
							value="<?php echo esc_attr( (string) $bn_parent['id'] ); ?>"
							<?php selected( $bn_current_parent, (int) $bn_parent['id'] ); ?>
						><?php echo esc_html( (string) $bn_parent['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="bn-space-settings__hint">
					<?php esc_html_e( 'Choose a space to nest this one under, or move it back to the top level. Only spaces you manage are listed. Members and content are never moved — only where the space sits.', 'buddynext' ); ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_general_after', $args );
