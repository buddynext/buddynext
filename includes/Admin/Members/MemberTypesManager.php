<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin tab: Member Types.
 *
 * Handles the "Member Types" tab under Members admin.
 * Provides a CRUD table of type definitions and an inline create/edit form.
 * The member-type field on the Edit Member view is also rendered here.
 *
 * @package BuddyNext\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Members;

/**
 * Renders and processes the Member Types admin tab.
 */
class MemberTypesManager {

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_save_member_type', array( $this, 'handle_save' ) );
		add_action( 'admin_post_bn_delete_member_type', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_bn_assign_member_type', array( $this, 'handle_assign' ) );
		add_action( 'buddynext_after_edit_member_form', array( $this, 'render_member_type_field' ), 10, 1 );
	}

	// ── Save handler ──────────────────────────────────────────────────────────

	/**
	 * Handle create / update type form submission.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		check_admin_referer( 'bn_save_member_type' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage member types.', 'buddynext' ) );
		}

		$service = buddynext_service( 'member_types' );

		$data = array(
			'slug'        => sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) ),
			'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'color'       => sanitize_hex_color( wp_unslash( $_POST['color'] ?? '#0073aa' ) ) ?? '#0073aa',
			'text_color'  => sanitize_hex_color( wp_unslash( $_POST['text_color'] ?? '#ffffff' ) ) ?? '#ffffff',
			'icon_svg'    => wp_kses( wp_unslash( $_POST['icon_svg'] ?? '' ), $this->allowed_svg_tags() ),
			'sort_order'  => absint( $_POST['sort_order'] ?? 0 ),
			'show_in_dir' => ! empty( $_POST['show_in_dir'] ),
			'self_select' => ! empty( $_POST['self_select'] ),
		);

		$edit_id = absint( $_POST['edit_id'] ?? 0 );

		if ( $edit_id > 0 ) {
			$result = $service->update( $edit_id, $data );
		} else {
			$result = $service->create( $data );
		}

		$redirect = add_query_arg(
			array(
				'page' => 'buddynext-members',
				'tab'  => 'member-types',
				'msg'  => is_wp_error( $result ) ? 'error' : 'saved',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle delete type form submission.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		check_admin_referer( 'bn_delete_member_type' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage member types.', 'buddynext' ) );
		}

		$type_id = absint( $_POST['type_id'] ?? 0 );

		if ( $type_id > 0 ) {
			buddynext_service( 'member_types' )->delete( $type_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'member-types',
					'msg'  => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle assigning a type to a member from the Edit Member view.
	 *
	 * @return void
	 */
	public function handle_assign(): void {
		check_admin_referer( 'bn_assign_member_type' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to assign member types.', 'buddynext' ) );
		}

		$user_id   = absint( $_POST['user_id'] ?? 0 );
		$type_slug = sanitize_key( wp_unslash( $_POST['type_slug'] ?? '' ) );
		$service   = buddynext_service( 'member_types' );

		if ( '' === $type_slug || 'none' === $type_slug ) {
			$service->remove_user_type( $user_id );
		} else {
			$type = $service->get_by_slug( $type_slug );
			if ( $type ) {
				$service->assign_type( $user_id, (int) $type['id'], get_current_user_id() );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'buddynext-members',
					'action'  => 'edit',
					'user_id' => $user_id,
					'msg'     => 'type_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Tab render ────────────────────────────────────────────────────────────

	/**
	 * Render the Member Types admin tab.
	 *
	 * @return void
	 */
	public function render_member_types_tab(): void {
		$service = buddynext_service( 'member_types' );
		$types   = $service->get_all_with_counts();

		$base_url  = admin_url( 'admin.php?page=buddynext-members&tab=member-types' );
		$edit_id   = absint( $_GET['edit_type'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_type = $edit_id > 0 ? $service->get_by_id( $edit_id ) : null;

		// Flash messages.
		$msg = sanitize_key( $_GET['msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>

		<?php if ( 'saved' === $msg ) : ?>
			<div class="bn-notice bn-notice-success">
				<?php esc_html_e( 'Member type saved.', 'buddynext' ); ?>
			</div>
		<?php elseif ( 'deleted' === $msg ) : ?>
			<div class="bn-notice bn-notice-success">
				<?php esc_html_e( 'Member type deleted.', 'buddynext' ); ?>
			</div>
		<?php elseif ( 'error' === $msg ) : ?>
			<div class="bn-notice bn-notice-error">
				<?php esc_html_e( 'Could not save member type. Check that the slug is unique.', 'buddynext' ); ?>
			</div>
		<?php endif; ?>

		<style>
		.bn-type-badge-preview{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:10px;font-size:12px;font-weight:700;line-height:1.4}
		.bn-type-badge-preview svg{width:13px;height:13px;flex-shrink:0}
		.bn-type-form{background:#fff;border:1px solid #e9ecef;border-radius:8px;padding:20px;margin-bottom:16px}
		.bn-type-form h3{margin:0 0 16px;font-size:14px;font-weight:700;color:#111827}
		.bn-type-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
		.bn-type-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
		.bn-type-full{grid-column:1/-1}
		.bn-color-row{display:flex;align-items:center;gap:8px}
		.bn-color-row input[type="color"]{width:36px;height:36px;border:1px solid #ddd;border-radius:4px;padding:2px;cursor:pointer;background:none}
		.bn-icon-preview{margin-top:8px;width:32px;height:32px;color:#0073aa}
		.bn-icon-preview svg{width:100%;height:100%}
		.bn-type-actions{display:flex;gap:8px;align-items:center;margin-top:16px}
		.bn-notice{padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;font-weight:500}
		.bn-notice-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
		.bn-notice-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
		@media(max-width:782px){.bn-type-grid,.bn-type-grid-3{grid-template-columns:1fr}}
		</style>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			/* Auto-generate slug from name */
			var nameInput = document.getElementById('bn-type-name');
			var slugInput = document.getElementById('bn-type-slug');
			if (nameInput && slugInput && !slugInput.value) {
				nameInput.addEventListener('input', function() {
					slugInput.value = nameInput.value
						.toLowerCase()
						.replace(/[^a-z0-9]+/g, '-')
						.replace(/^-|-$/g, '');
				});
			}

			/* Live badge preview */
			function updatePreview() {
				var badge = document.getElementById('bn-badge-preview');
				if (!badge) return;
				var name = document.getElementById('bn-type-name') ? document.getElementById('bn-type-name').value : '';
				var bg   = document.getElementById('bn-type-color') ? document.getElementById('bn-type-color').value : '#0073aa';
				var fg   = document.getElementById('bn-type-text-color') ? document.getElementById('bn-type-text-color').value : '#ffffff';
				badge.style.background = bg;
				badge.style.color      = fg;
				badge.querySelector('.bn-badge-label').textContent = name || '<?php esc_html_e( 'Preview', 'buddynext' ); ?>';
			}
			['bn-type-name','bn-type-color','bn-type-text-color'].forEach(function(id) {
				var el = document.getElementById(id);
				if (el) el.addEventListener('input', updatePreview);
			});
			updatePreview();

			/* More-menu toggles */
			document.querySelectorAll('.bn-more-btn').forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.stopPropagation();
					var menu = btn.closest('.bn-more-menu');
					document.querySelectorAll('.bn-more-menu.open').forEach(function(m) { if (m !== menu) m.classList.remove('open'); });
					menu.classList.toggle('open');
				});
			});
			document.addEventListener('click', function() {
				document.querySelectorAll('.bn-more-menu.open').forEach(function(m) { m.classList.remove('open'); });
			});
		});

		// Delegated confirm handler — replaces inline confirm dialogs (F2 compliance).
		document.addEventListener('click', function (e) {
			var t = e.target.closest('[data-bn-confirm]');
			if (!t) return;
			if (!window.confirm(t.dataset.bnConfirm)) {
				e.preventDefault();
				e.stopImmediatePropagation();
			}
		}, true);
		</script>

		<?php /* ── Create / Edit form ── */ ?>
		<div class="bn-type-form">
			<h3><?php echo $edit_type ? esc_html__( 'Edit Member Type', 'buddynext' ) : esc_html__( 'Add Member Type', 'buddynext' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bn_save_member_type' ); ?>
				<input type="hidden" name="action"  value="bn_save_member_type">
				<input type="hidden" name="edit_id" value="<?php echo $edit_type ? esc_attr( $edit_type['id'] ) : '0'; ?>">

				<div class="bn-type-grid">
					<div class="bn-field">
						<label class="bn-label" for="bn-type-name"><?php esc_html_e( 'Name', 'buddynext' ); ?></label>
						<input type="text" id="bn-type-name" name="name"
							value="<?php echo $edit_type ? esc_attr( $edit_type['name'] ) : ''; ?>"
							class="bn-text-input" style="width:100%;max-width:300px"
							placeholder="<?php esc_attr_e( 'e.g. Alumni', 'buddynext' ); ?>" required>
					</div>
					<div class="bn-field">
						<label class="bn-label" for="bn-type-slug"><?php esc_html_e( 'Slug', 'buddynext' ); ?></label>
						<input type="text" id="bn-type-slug" name="slug"
							value="<?php echo $edit_type ? esc_attr( $edit_type['slug'] ) : ''; ?>"
							class="bn-text-input" style="width:100%;max-width:220px"
							placeholder="<?php esc_attr_e( 'e.g. alumni', 'buddynext' ); ?>"
							pattern="[a-z0-9-]+" required>
						<span class="bn-field-hint"><?php esc_html_e( 'Lowercase letters, numbers and hyphens only.', 'buddynext' ); ?></span>
					</div>
				</div>

				<div class="bn-field bn-type-full" style="margin-top:12px">
					<label class="bn-label" for="bn-type-desc"><?php esc_html_e( 'Description', 'buddynext' ); ?></label>
					<textarea id="bn-type-desc" name="description" rows="2"
						class="bn-text-input" style="width:100%;max-width:580px;resize:vertical"><?php echo $edit_type ? esc_textarea( $edit_type['description'] ) : ''; ?></textarea>
				</div>

				<div class="bn-type-grid-3" style="margin-top:12px">
					<div class="bn-field">
						<label class="bn-label" for="bn-type-color"><?php esc_html_e( 'Badge Background', 'buddynext' ); ?></label>
						<div class="bn-color-row">
							<input type="color" id="bn-type-color" name="color"
								value="<?php echo $edit_type ? esc_attr( $edit_type['color'] ) : '#0073aa'; ?>">
							<span class="bn-field-hint" style="margin:0"><?php esc_html_e( 'Background colour', 'buddynext' ); ?></span>
						</div>
					</div>
					<div class="bn-field">
						<label class="bn-label" for="bn-type-text-color"><?php esc_html_e( 'Badge Text', 'buddynext' ); ?></label>
						<div class="bn-color-row">
							<input type="color" id="bn-type-text-color" name="text_color"
								value="<?php echo $edit_type ? esc_attr( $edit_type['text_color'] ) : '#ffffff'; ?>">
							<span class="bn-field-hint" style="margin:0"><?php esc_html_e( 'Text colour', 'buddynext' ); ?></span>
						</div>
					</div>
					<div class="bn-field">
						<label class="bn-label"><?php esc_html_e( 'Live Preview', 'buddynext' ); ?></label>
						<span id="bn-badge-preview" class="bn-type-badge-preview"
							style="background:<?php echo $edit_type ? esc_attr( $edit_type['color'] ) : '#0073aa'; ?>;color:<?php echo $edit_type ? esc_attr( $edit_type['text_color'] ) : '#ffffff'; ?>">
							<span class="bn-badge-label"><?php echo $edit_type ? esc_html( $edit_type['name'] ) : esc_html__( 'Preview', 'buddynext' ); ?></span>
						</span>
					</div>
				</div>

				<div class="bn-field" style="margin-top:12px">
					<label class="bn-label" for="bn-type-icon"><?php esc_html_e( 'Icon SVG', 'buddynext' ); ?></label>
					<textarea id="bn-type-icon" name="icon_svg" rows="3"
						class="bn-text-input" style="width:100%;max-width:580px;resize:vertical;font-family:monospace;font-size:12px"
						placeholder="<?php esc_attr_e( 'Paste an inline SVG here (optional)', 'buddynext' ); ?>"><?php echo $edit_type ? esc_textarea( $edit_type['icon_svg'] ) : ''; ?></textarea>
					<span class="bn-field-hint"><?php esc_html_e( 'Paste a complete <svg> element. 24×24 viewBox recommended. Uses currentColor for theme compatibility.', 'buddynext' ); ?></span>
				</div>

				<div class="bn-type-grid" style="margin-top:12px">
					<div class="bn-field">
						<label class="bn-label" for="bn-type-sort"><?php esc_html_e( 'Sort Order', 'buddynext' ); ?></label>
						<input type="number" id="bn-type-sort" name="sort_order"
							value="<?php echo $edit_type ? esc_attr( $edit_type['sort_order'] ) : '0'; ?>"
							min="0" class="bn-text-input" style="width:80px">
					</div>
					<div class="bn-field" style="padding-top:22px">
						<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;color:#374151">
							<input type="checkbox" name="show_in_dir" value="1"
								<?php checked( $edit_type ? (bool) $edit_type['show_in_dir'] : true ); ?>>
							<?php esc_html_e( 'Show as directory filter tab', 'buddynext' ); ?>
						</label>
						<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;color:#374151;margin-top:8px">
							<input type="checkbox" name="self_select" value="1"
								<?php checked( $edit_type ? (bool) $edit_type['self_select'] : false ); ?>>
							<?php esc_html_e( 'Allow members to self-assign', 'buddynext' ); ?>
						</label>
					</div>
				</div>

				<div class="bn-type-actions">
					<?php submit_button( $edit_type ? __( 'Update Type', 'buddynext' ) : __( 'Add Type', 'buddynext' ), 'primary bn-btn-save', 'submit', false ); ?>
					<?php if ( $edit_type ) : ?>
						<a href="<?php echo esc_url( $base_url ); ?>" class="bn-btn"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</div>

		<?php /* ── Types list ── */ ?>
		<?php if ( empty( $types ) ) : ?>
			<p style="color:#6b7280;font-size:13px;padding:12px 0"><?php esc_html_e( 'No member types defined yet. Add your first type above.', 'buddynext' ); ?></p>
		<?php else : ?>
			<div class="bn-data-table">
				<div class="bn-table-header">
					<strong style="font-size:14px;color:#111827"><?php esc_html_e( 'Defined Types', 'buddynext' ); ?></strong>
					<span class="bn-badge" style="background:#e8f4fb;color:#0073aa"><?php echo esc_html( (string) count( $types ) ); ?></span>
				</div>
				<table class="bn-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Type', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Members', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Directory', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Self-Select', 'buddynext' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $types as $t ) : ?>
						<tr>
							<td>
								<span class="bn-type-badge-preview"
									style="background:<?php echo esc_attr( $t['color'] ); ?>;color:<?php echo esc_attr( $t['text_color'] ); ?>">
									<?php if ( '' !== (string) $t['icon_svg'] ) : ?>
										<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses on save ?>
										<?php echo $t['icon_svg']; ?>
									<?php endif; ?>
									<span class="bn-badge-label"><?php echo esc_html( $t['name'] ); ?></span>
								</span>
							</td>
							<td><code style="font-size:12px;color:#6b7280"><?php echo esc_html( $t['slug'] ); ?></code></td>
							<td><strong><?php echo esc_html( number_format_i18n( (int) ( $t['member_count'] ?? 0 ) ) ); ?></strong></td>
							<td>
								<?php if ( (bool) $t['show_in_dir'] ) : ?>
									<span class="bn-badge bn-badge-active"><?php esc_html_e( 'Yes', 'buddynext' ); ?></span>
								<?php else : ?>
									<span class="bn-badge bn-badge-private"><?php esc_html_e( 'No', 'buddynext' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( (bool) $t['self_select'] ) : ?>
									<span class="bn-badge bn-badge-active"><?php esc_html_e( 'On', 'buddynext' ); ?></span>
								<?php else : ?>
									<span class="bn-badge bn-badge-private"><?php esc_html_e( 'Off', 'buddynext' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<div class="bn-row-actions">
									<a href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'page' => 'buddynext-members',
												'tab'  => 'member-types',
												'edit_type' => $t['id'],
											),
											admin_url( 'admin.php' )
										)
									);
									?>
												"
										class="bn-action-link"><?php esc_html_e( 'Edit', 'buddynext' ); ?></a>
									<div class="bn-more-menu">
										<button class="bn-more-btn" type="button" aria-label="<?php esc_attr_e( 'More actions', 'buddynext' ); ?>"><?php echo \BuddyNext\Core\IconService::render( 'more-horizontal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
										<div class="bn-more-dropdown">
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<?php wp_nonce_field( 'bn_delete_member_type' ); ?>
												<input type="hidden" name="action"  value="bn_delete_member_type">
												<input type="hidden" name="type_id" value="<?php echo esc_attr( $t['id'] ); ?>">
												<?php
												/* translators: 1: member type name, 2: number of assignments */
												$confirm_msg = esc_attr( sprintf( __( 'Delete "%1$s"? All %2$d member assignments will be removed.', 'buddynext' ), $t['name'], (int) ( $t['member_count'] ?? 0 ) ) );
												?>
												<button type="submit" class="bn-dropdown-item bn-dropdown-danger"
													data-bn-confirm="<?php echo $confirm_msg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() applied above ?>">
													<?php esc_html_e( 'Delete', 'buddynext' ); ?>
												</button>
											</form>
										</div>
									</div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
	}

	// ── Edit-member type field ────────────────────────────────────────────────

	/**
	 * Render the Member Type dropdown inside the Edit Member form.
	 *
	 * @param int $user_id The user being edited.
	 * @return void
	 */
	public function render_member_type_field( int $user_id ): void {
		$service      = buddynext_service( 'member_types' );
		$all_types    = $service->get_all();
		$current_type = $service->get_user_type( $user_id );
		$current_slug = $current_type ? (string) $current_type['slug'] : '';
		?>
		<div class="bn-field-row" style="margin-bottom:16px">
			<div class="bn-label"><label for="bn-member-type-select"><?php esc_html_e( 'Member Type', 'buddynext' ); ?></label></div>
			<div class="bn-control">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
					<?php wp_nonce_field( 'bn_assign_member_type' ); ?>
					<input type="hidden" name="action"  value="bn_assign_member_type">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">

					<select id="bn-member-type-select" name="type_slug" class="bn-text-input" style="min-width:200px">
						<option value="none"><?php esc_html_e( '— No type —', 'buddynext' ); ?></option>
						<?php foreach ( $all_types as $t ) : ?>
							<option value="<?php echo esc_attr( $t['slug'] ); ?>"
								<?php selected( $current_slug, $t['slug'] ); ?>>
								<?php echo esc_html( $t['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<?php submit_button( __( 'Save Type', 'buddynext' ), 'secondary', 'submit', false ); ?>

					<?php if ( $current_type ) : ?>
						<span class="bn-type-badge-preview"
							style="background:<?php echo esc_attr( $current_type['color'] ); ?>;color:<?php echo esc_attr( $current_type['text_color'] ); ?>">
							<?php echo esc_html( $current_type['name'] ); ?>
						</span>
					<?php endif; ?>
				</form>
				<span class="bn-field-hint">
					<?php esc_html_e( 'Assigning a new type replaces any existing type for this member.', 'buddynext' ); ?>
				</span>
			</div>
		</div>
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Allowed HTML tags for SVG icon sanitisation.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function allowed_svg_tags(): array {
		return array(
			'svg'      => array(
				'xmlns'           => true,
				'viewbox'         => true,
				'width'           => true,
				'height'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
			),
			'path'     => array(
				'd'               => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
			'circle'   => array(
				'cx'           => true,
				'cy'           => true,
				'r'            => true,
				'fill'         => true,
				'stroke'       => true,
				'stroke-width' => true,
			),
			'rect'     => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'fill'   => true,
			),
			'polyline' => array(
				'points'       => true,
				'fill'         => true,
				'stroke'       => true,
				'stroke-width' => true,
			),
			'line'     => array(
				'x1'           => true,
				'y1'           => true,
				'x2'           => true,
				'y2'           => true,
				'stroke'       => true,
				'stroke-width' => true,
			),
			'g'        => array(
				'fill'   => true,
				'stroke' => true,
			),
		);
	}
}
