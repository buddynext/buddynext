<?php
/**
 * Shared, config-driven taxonomy editor.
 *
 * One compact, premium create/edit form used by both Member Types and Space
 * Categories. The common case (Name + Colour + Save) is always visible; every
 * other control lives under a collapsed "Advanced" drawer.
 *
 * Expected $args keys (passed through buddynext_get_template):
 *   entity   string  'member-type' | 'space-category' — drives field ids/labels.
 *   title    string  Section heading.
 *   action   string  admin-post action value.
 *   nonce    string  Nonce action name.
 *   edit     array   Row being edited, or null for create.
 *   hidden   array   Extra hidden inputs (name => value), e.g. edit_id / cat_id.
 *   toggles  array   Entity-specific checkboxes: [ name, label, default ].
 *   supports array   Capability flags, e.g. [ 'has_icon' => true ].
 *   cancel   string  Optional cancel URL (rendered only in edit mode).
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

// Locals are bn-prefixed to avoid colliding with WordPress globals ($title,
// $action) inside the template scope.
$bn_entity   = isset( $entity ) ? (string) $entity : 'taxonomy';
$bn_title    = isset( $title ) ? (string) $title : '';
$bn_action   = isset( $action ) ? (string) $action : '';
$bn_nonce    = isset( $nonce ) ? (string) $nonce : '';
$bn_edit     = isset( $edit ) && is_array( $edit ) ? $edit : null;
$bn_hidden   = isset( $hidden ) && is_array( $hidden ) ? $hidden : array();
$bn_toggles  = isset( $toggles ) && is_array( $toggles ) ? $toggles : array();
$bn_supports = isset( $supports ) && is_array( $supports ) ? $supports : array();
$bn_cancel   = isset( $cancel ) ? (string) $cancel : '';

$has_icon = ! empty( $bn_supports['has_icon'] );

// Field ids are namespaced by entity so two editors can never collide.
$id_prefix = 'bn-tax-' . sanitize_html_class( $bn_entity );

$val_name       = $bn_edit ? (string) ( $bn_edit['name'] ?? '' ) : '';
$val_slug       = $bn_edit ? (string) ( $bn_edit['slug'] ?? '' ) : '';
$val_desc       = $bn_edit ? (string) ( $bn_edit['description'] ?? '' ) : '';
$val_color      = $bn_edit ? (string) ( $bn_edit['color'] ?? '#0073aa' ) : '#0073aa';
$val_text_color = $bn_edit ? (string) ( $bn_edit['text_color'] ?? '' ) : '';
$val_icon       = $bn_edit ? (string) ( $bn_edit['icon_svg'] ?? '' ) : '';
$val_sort       = $bn_edit ? (int) ( $bn_edit['sort_order'] ?? 0 ) : 0;

// The badge preview's effective text colour: an explicit override if set,
// otherwise white (JS derives a readable colour on the fly).
$preview_fg = '' !== $val_text_color ? $val_text_color : '#ffffff';
?>
<div class="bn-settings-section">
	<div class="bn-ss-header">
		<span class="bn-ss-title"><?php echo esc_html( $bn_title ); ?></span>
	</div>
	<div class="bn-ss-body">
		<form method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="bn-tax-editor"
			data-bn-tax-editor="<?php echo esc_attr( $bn_entity ); ?>">
			<?php wp_nonce_field( $bn_nonce ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $bn_action ); ?>">
			<?php foreach ( $bn_hidden as $h_name => $h_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $h_name ); ?>" value="<?php echo esc_attr( (string) $h_value ); ?>">
			<?php endforeach; ?>

			<?php /* ── Primary: Name + Colour + live preview ── */ ?>
			<div class="bn-tax-primary">
				<div class="bn-field bn-tax-name">
					<label class="bn-label" for="<?php echo esc_attr( $id_prefix . '-name' ); ?>">
						<?php esc_html_e( 'Name', 'buddynext' ); ?>
					</label>
					<input type="text"
						id="<?php echo esc_attr( $id_prefix . '-name' ); ?>"
						name="name"
						class="bn-text-input"
						value="<?php echo esc_attr( $val_name ); ?>"
						data-bn-tax-name
						placeholder="<?php esc_attr_e( 'e.g. Alumni', 'buddynext' ); ?>"
						required>
				</div>

				<div class="bn-field bn-tax-colour">
					<label class="bn-label" for="<?php echo esc_attr( $id_prefix . '-color' ); ?>">
						<?php esc_html_e( 'Colour', 'buddynext' ); ?>
					</label>
					<div class="bn-tax-colour-row">
						<input type="color"
							id="<?php echo esc_attr( $id_prefix . '-color' ); ?>"
							name="color"
							class="bn-tax-swatch"
							value="<?php echo esc_attr( $val_color ); ?>"
							data-bn-tax-color>
						<span class="bn-tax-badge-preview"
							data-bn-tax-preview
							style="background:<?php echo esc_attr( $val_color ); ?>;color:<?php echo esc_attr( $preview_fg ); ?>">
							<span class="bn-tax-badge-label" data-bn-tax-preview-label><?php echo '' !== $val_name ? esc_html( $val_name ) : esc_html__( 'Preview', 'buddynext' ); ?></span>
						</span>
					</div>
				</div>
			</div>

			<div class="bn-field bn-tax-desc">
				<label class="bn-label" for="<?php echo esc_attr( $id_prefix . '-desc' ); ?>">
					<?php esc_html_e( 'Description', 'buddynext' ); ?>
				</label>
				<textarea id="<?php echo esc_attr( $id_prefix . '-desc' ); ?>"
					name="description"
					rows="2"
					class="bn-text-input"><?php echo esc_textarea( $val_desc ); ?></textarea>
			</div>

			<?php /* ── Advanced drawer ── */ ?>
			<details class="bn-tax-advanced">
				<summary class="bn-tax-advanced__summary"><?php esc_html_e( 'Advanced', 'buddynext' ); ?></summary>
				<div class="bn-tax-advanced__body">
					<div class="bn-tax-adv-grid">
						<div class="bn-field">
							<label class="bn-label" for="<?php echo esc_attr( $id_prefix . '-slug' ); ?>">
								<?php esc_html_e( 'Slug', 'buddynext' ); ?>
							</label>
							<input type="text"
								id="<?php echo esc_attr( $id_prefix . '-slug' ); ?>"
								name="slug"
								class="bn-text-input"
								value="<?php echo esc_attr( $val_slug ); ?>"
								data-bn-tax-slug
								pattern="[a-z0-9\-]+"
								placeholder="<?php esc_attr_e( 'auto from name', 'buddynext' ); ?>">
							<span class="bn-field-hint"><?php esc_html_e( 'Lowercase letters, numbers and hyphens. Auto-filled from the name.', 'buddynext' ); ?></span>
						</div>

						<div class="bn-field">
							<label class="bn-label" for="<?php echo esc_attr( $id_prefix . '-text-color' ); ?>">
								<?php esc_html_e( 'Text colour', 'buddynext' ); ?>
							</label>
							<input type="text"
								id="<?php echo esc_attr( $id_prefix . '-text-color' ); ?>"
								name="text_color"
								class="bn-text-input"
								value="<?php echo esc_attr( $val_text_color ); ?>"
								data-bn-tax-text-color
								pattern="#?[A-Fa-f0-9]{6}"
								placeholder="<?php esc_attr_e( 'auto for contrast', 'buddynext' ); ?>">
							<span class="bn-field-hint"><?php esc_html_e( 'Leave blank to auto-derive a readable colour from the background.', 'buddynext' ); ?></span>
						</div>

						<div class="bn-field bn-tax-sort">
							<label class="bn-label" for="<?php echo esc_attr( $id_prefix . '-sort' ); ?>">
								<?php esc_html_e( 'Sort order', 'buddynext' ); ?>
							</label>
							<input type="number"
								id="<?php echo esc_attr( $id_prefix . '-sort' ); ?>"
								name="sort_order"
								class="bn-text-input"
								value="<?php echo esc_attr( (string) $val_sort ); ?>"
								min="0">
							<span class="bn-field-hint"><?php esc_html_e( 'Lower numbers appear first.', 'buddynext' ); ?></span>
						</div>
					</div>

					<?php if ( $has_icon ) : ?>
						<div class="bn-field bn-tax-icon">
							<label class="bn-label" for="<?php echo esc_attr( $id_prefix . '-icon' ); ?>">
								<?php esc_html_e( 'Icon', 'buddynext' ); ?>
							</label>
							<textarea id="<?php echo esc_attr( $id_prefix . '-icon' ); ?>"
								name="icon_svg"
								rows="3"
								class="bn-text-input bn-tax-icon-textarea"
								placeholder="<?php esc_attr_e( 'Paste an inline <svg> element (optional)', 'buddynext' ); ?>"><?php echo esc_textarea( $val_icon ); ?></textarea>
							<span class="bn-field-hint"><?php esc_html_e( 'Paste a complete <svg> element. A 24x24 viewBox using currentColor works best.', 'buddynext' ); ?></span>
						</div>
					<?php endif; ?>
				</div>
			</details>

			<?php /* ── Entity toggles ── */ ?>
			<?php if ( ! empty( $bn_toggles ) ) : ?>
				<div class="bn-field bn-tax-toggles">
					<?php
					foreach ( $bn_toggles as $toggle ) :
						$t_name    = (string) ( $toggle['name'] ?? '' );
						$t_label   = (string) ( $toggle['label'] ?? '' );
						$t_default = ! empty( $toggle['default'] );
						if ( '' === $t_name ) {
							continue;
						}
						$t_checked = $bn_edit ? ! empty( $bn_edit[ $t_name ] ) : $t_default;
						?>
						<label class="bn-check-row">
							<input type="checkbox" name="<?php echo esc_attr( $t_name ); ?>" value="1" <?php checked( $t_checked ); ?>>
							<?php echo esc_html( $t_label ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php /* ── Actions ── */ ?>
			<div class="bn-tax-actions">
				<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
					<?php echo esc_html( $bn_edit ? __( 'Save changes', 'buddynext' ) : __( 'Add', 'buddynext' ) ); ?>
				</button>
				<?php if ( $bn_edit && '' !== $bn_cancel ) : ?>
					<a class="bn-btn" data-variant="ghost" data-size="md" href="<?php echo esc_url( $bn_cancel ); ?>">
						<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</form>
	</div><!-- .bn-ss-body -->
</div><!-- .bn-settings-section -->
