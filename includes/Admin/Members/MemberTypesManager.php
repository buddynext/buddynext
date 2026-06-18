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

		<?php /* ── Types list (rendered first; the add/edit form is below, matching Spaces → Categories) ── */ ?>
		<?php if ( empty( $types ) ) : ?>
			<div class="bn-settings-section"><div class="bn-ss-body">
				<p class="bn-type-empty"><?php esc_html_e( 'No member types defined yet. Add your first type below.', 'buddynext' ); ?></p>
			</div></div>
		<?php else : ?>
			<div class="bn-settings-section">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Defined Types', 'buddynext' ); ?></span>
					<span class="bn-ss-count"><?php echo esc_html( (string) count( $types ) ); ?></span>
				</div>
				<div class="bn-ss-body">
				<table class="bn-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Type', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Members', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Directory', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Self-Select', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $types as $t ) : ?>
						<tr>
							<td>
								<span class="bn-type-badge-preview"
									style="background:<?php echo esc_attr( $t['color'] ); ?>;color:<?php echo esc_attr( $t['text_color'] ); ?>">
									<?php if ( '' !== (string) $t['icon_svg'] ) : ?>
										<?php echo $t['icon_svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses on save. ?>
									<?php endif; ?>
									<span class="bn-badge-label"><?php echo esc_html( $t['name'] ); ?></span>
								</span>
							</td>
							<td><code class="bn-type-slug-code"><?php echo esc_html( $t['slug'] ); ?></code></td>
							<td><strong><?php echo esc_html( number_format_i18n( (int) ( $t['member_count'] ?? 0 ) ) ); ?></strong></td>
							<td>
								<?php if ( (bool) $t['show_in_dir'] ) : ?>
									<span class="bn-badge" data-tone="success"><?php esc_html_e( 'Yes', 'buddynext' ); ?></span>
								<?php else : ?>
									<span class="bn-badge" data-tone="neutral"><?php esc_html_e( 'No', 'buddynext' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( (bool) $t['self_select'] ) : ?>
									<span class="bn-badge" data-tone="success"><?php esc_html_e( 'On', 'buddynext' ); ?></span>
								<?php else : ?>
									<span class="bn-badge" data-tone="neutral"><?php esc_html_e( 'Off', 'buddynext' ); ?></span>
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
										) . '#bn-member-type-form'
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
												<input type="hidden" name="type_id" value="<?php echo esc_attr( (string) $t['id'] ); ?>">
												<?php
												/* translators: 1: member type name, 2: number of assignments */
												$confirm_msg = sprintf( __( 'Delete "%1$s"? All %2$d member assignments will be removed.', 'buddynext' ), $t['name'], (int) ( $t['member_count'] ?? 0 ) );
												?>
												<button type="submit" class="bn-dropdown-item bn-dropdown-danger"
													data-bn-confirm="<?php echo esc_attr( $confirm_msg ); ?>">
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
				</div><!-- .bn-ss-body -->
			</div><!-- .bn-settings-section -->
		<?php endif; ?>

		<?php /* ── Create / Edit form (below the list; anchored so the row "Edit" links scroll here — matches Spaces → Categories) ── */ ?>
		<div id="bn-member-type-form">
		<?php
		buddynext_get_template(
			'parts/taxonomy-editor.php',
			array(
				'entity'   => 'member-type',
				'title'    => $edit_type ? __( 'Edit Member Type', 'buddynext' ) : __( 'Add Member Type', 'buddynext' ),
				'action'   => 'bn_save_member_type',
				'nonce'    => 'bn_save_member_type',
				'edit'     => $edit_type,
				'hidden'   => array( 'edit_id' => $edit_type ? (string) $edit_type['id'] : '0' ),
				'toggles'  => array(
					array(
						'name'    => 'show_in_dir',
						'label'   => __( 'Show as directory filter tab', 'buddynext' ),
						'default' => true,
					),
					array(
						'name'    => 'self_select',
						'label'   => __( 'Allow members to self-assign', 'buddynext' ),
						'default' => false,
					),
				),
				'supports' => array( 'has_icon' => true ),
				'cancel'   => $base_url,
			)
		);
		?>
		</div>
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
		<div class="bn-field-row bn-member-type-field">
			<div class="bn-label"><label for="bn-member-type-select"><?php esc_html_e( 'Member Type', 'buddynext' ); ?></label></div>
			<div class="bn-control">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="bn-member-type-form">
					<?php wp_nonce_field( 'bn_assign_member_type' ); ?>
					<input type="hidden" name="action"  value="bn_assign_member_type">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">

					<select id="bn-member-type-select" name="type_slug" class="bn-text-input bn-member-type-select">
						<option value="none"><?php esc_html_e( '- No type -', 'buddynext' ); ?></option>
						<?php foreach ( $all_types as $t ) : ?>
							<option value="<?php echo esc_attr( $t['slug'] ); ?>"
								<?php selected( $current_slug, $t['slug'] ); ?>>
								<?php echo esc_html( $t['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save Type', 'buddynext' ); ?></button>

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
