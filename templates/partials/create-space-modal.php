<?php
/**
 * BuddyNext — Create-space modal partial.
 *
 * Inline modal rendered (hidden) inside the spaces directory. Opened by
 * `actions.openCreate` on the directory's Create-space CTA. Submits to
 * POST /buddynext/v1/spaces via `actions.submitCreate` in the Spaces
 * Interactivity store.
 *
 * Fields:
 *   - name        (required, max 100)
 *   - slug        (auto-derived from name; user-editable)
 *   - type        (Open / Private / Secret)
 *   - category_id (select; from bn_space_categories)
 *   - description (textarea, max 160)
 *
 * The modal is hidden by default (`hidden` attribute on the backdrop) and
 * shown by `actions.openCreate` toggling the attribute off. Closing logic
 * (Escape, backdrop click, close button) is shared with other Spaces
 * modals via the delegated handler in assets/js/spaces/store.js.
 *
 * Variables:
 *   array<object> $categories Optional. List of category rows (id, name, slug).
 *
 * Overridable: copy to {theme}/buddynext/partials/create-space-modal.php.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_csm_categories = isset( $categories ) && is_array( $categories ) ? $categories : array();
// Root spaces the current user manages — eligible parents for a sub-space.
// Empty (field hidden) unless the render site supplies them.
$bn_csm_parents = isset( $parent_spaces ) && is_array( $parent_spaces ) ? $parent_spaces : array();
// Fixed parent — when the modal is opened from a parent space's "Add sub-space"
// CTA, the parent is locked to that space (object with ->id, ->name). The picker
// is replaced by a read-only context line + a hidden parent_id, and the modal
// reframes as "Create a sub-space". Takes precedence over the dropdown.
$bn_csm_fixed_parent = isset( $fixed_parent ) && is_object( $fixed_parent ) && ! empty( $fixed_parent->id )
	? $fixed_parent
	: null;
$bn_csm_title        = null !== $bn_csm_fixed_parent
	? __( 'Create a sub-space', 'buddynext' )
	: __( 'Create a space', 'buddynext' );
?>
<div
	class="bn-modal-backdrop"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-create-space-title"
	hidden
	data-bn-modal="create-space"
>
	<div class="bn-modal__panel" data-size="md">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-create-space-title">
				<?php echo esc_html( $bn_csm_title ); ?>
			</h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-bn-modal-close
			><?php buddynext_icon( 'x' ); ?></button>
		</header>

		<form class="bn-modal__body bn-create-space-form" data-bn-create-space-form>

			<div class="bn-create-space-form__field">
				<label for="bn-create-space-name">
					<?php esc_html_e( 'Name', 'buddynext' ); ?>
					<span aria-hidden="true">*</span>
				</label>
				<input
					type="text"
					id="bn-create-space-name"
					name="name"
					class="bn-input"
					maxlength="100"
					required
					autocomplete="off"
					data-bn-create-space-name
				>
				<p class="bn-create-space-form__error" data-bn-error-for="name" hidden></p>
			</div>

			<div class="bn-create-space-form__field">
				<label for="bn-create-space-slug">
					<?php esc_html_e( 'Slug', 'buddynext' ); ?>
				</label>
				<input
					type="text"
					id="bn-create-space-slug"
					name="slug"
					class="bn-input"
					maxlength="80"
					autocomplete="off"
					data-bn-create-space-slug
				>
				<p class="bn-create-space-form__hint">
					<?php esc_html_e( 'Auto-derived from the name. Lowercase letters, numbers and hyphens only.', 'buddynext' ); ?>
				</p>
				<p class="bn-create-space-form__error" data-bn-error-for="slug" hidden></p>
			</div>

			<div class="bn-create-space-form__field">
				<label for="bn-create-space-type">
					<?php esc_html_e( 'Type', 'buddynext' ); ?>
				</label>
				<select
					id="bn-create-space-type"
					name="type"
					class="bn-select"
					required
				>
					<?php
					$bn_join_hints = array(
						'direct'  => __( 'anyone can join', 'buddynext' ),
						'request' => __( 'request to join', 'buddynext' ),
						'invite'  => __( 'invite only, hidden', 'buddynext' ),
					);
					foreach ( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->all() as $bn_type_key => $bn_type_cfg ) :
						$bn_hint  = $bn_join_hints[ $bn_type_cfg['join'] ] ?? '';
						$bn_label = $bn_type_cfg['label'] . ( '' !== $bn_hint ? ' — ' . $bn_hint : '' );
						?>
						<option value="<?php echo esc_attr( (string) $bn_type_key ); ?>"><?php echo esc_html( $bn_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="bn-create-space-form__error" data-bn-error-for="type" hidden></p>
			</div>

			<?php if ( ! empty( $bn_csm_categories ) ) : ?>
				<div class="bn-create-space-form__field">
					<label for="bn-create-space-category">
						<?php esc_html_e( 'Category', 'buddynext' ); ?>
					</label>
					<select
						id="bn-create-space-category"
						name="category_id"
						class="bn-select"
					>
						<option value="">
							<?php esc_html_e( '— Select a category —', 'buddynext' ); ?>
						</option>
						<?php foreach ( $bn_csm_categories as $bn_csm_cat ) : ?>
							<option value="<?php echo esc_attr( (string) $bn_csm_cat->id ); ?>">
								<?php echo esc_html( $bn_csm_cat->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="bn-create-space-form__error" data-bn-error-for="category_id" hidden></p>
				</div>
			<?php endif; ?>

			<?php if ( null !== $bn_csm_fixed_parent ) : ?>
				<div class="bn-create-space-form__field">
					<span class="bn-create-space-form__label-text">
						<?php esc_html_e( 'Parent space', 'buddynext' ); ?>
					</span>
					<p class="bn-create-space-form__fixed-parent">
						<?php buddynext_icon( 'layers' ); ?>
						<strong><?php echo esc_html( (string) $bn_csm_fixed_parent->name ); ?></strong>
					</p>
					<input type="hidden" name="parent_id" value="<?php echo esc_attr( (string) (int) $bn_csm_fixed_parent->id ); ?>">
				</div>
			<?php elseif ( ! empty( $bn_csm_parents ) ) : ?>
				<div class="bn-create-space-form__field">
					<label for="bn-create-space-parent">
						<?php esc_html_e( 'Parent space', 'buddynext' ); ?>
					</label>
					<select
						id="bn-create-space-parent"
						name="parent_id"
						class="bn-select"
					>
						<option value="0">
							<?php esc_html_e( '— None (top-level space) —', 'buddynext' ); ?>
						</option>
						<?php foreach ( $bn_csm_parents as $bn_csm_parent ) : ?>
							<option value="<?php echo esc_attr( (string) $bn_csm_parent->id ); ?>">
								<?php echo esc_html( $bn_csm_parent->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="bn-create-space-form__error" data-bn-error-for="parent_id" hidden></p>
				</div>
			<?php endif; ?>

			<div class="bn-create-space-form__field">
				<label for="bn-create-space-desc">
					<?php esc_html_e( 'Description', 'buddynext' ); ?>
				</label>
				<textarea
					id="bn-create-space-desc"
					name="description"
					class="bn-textarea"
					maxlength="160"
					rows="3"
				></textarea>
				<p class="bn-create-space-form__hint">
					<?php esc_html_e( '160 characters max. Shown in the directory listing.', 'buddynext' ); ?>
				</p>
				<p class="bn-create-space-form__error" data-bn-error-for="description" hidden></p>
			</div>

			<p class="bn-create-space-form__error bn-create-space-form__error--global" data-bn-error-for="_global" hidden></p>

		</form>

		<div class="bn-modal__foot">
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-size="md"
				data-bn-modal-close
			><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
			<button
				type="button"
				class="bn-btn"
				data-variant="primary"
				data-size="md"
				data-wp-on--click="actions.submitCreate"
				data-bn-create-space-submit
			><?php esc_html_e( 'Create space', 'buddynext' ); ?></button>
		</div>
	</div>
</div>
