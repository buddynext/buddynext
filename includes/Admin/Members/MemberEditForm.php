<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * BuddyNext edit-member form renderer.
 *
 * Renders the full edit-member admin view including the hero header,
 * profile field sections, and all repeater UI.
 *
 * @package BuddyNext\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Members;

use BuddyNext\Admin\Members\MemberDisplay;

/**
 * Renders the edit-member admin view for a single user.
 */
class MemberEditForm {

	/**
	 * Render the member edit view for a single user.
	 *
	 * Called when ?view=edit-member&user_id=X is present.
	 *
	 * @return void
	 */
	public function render_edit_member_view(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = absint( wp_unslash( $_GET['user_id'] ?? 0 ) );
		$wp_user = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $wp_user || $user_id <= 0 ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'User not found.', 'buddynext' ) . '</p></div>';
			return;
		}

		$back_url = admin_url( 'admin.php?page=buddynext-members' );
		$profile  = buddynext_service( 'profiles' )->get_profile( $user_id, $user_id );
		$groups   = $profile['groups'] ?? array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved = absint( wp_unslash( $_GET['saved'] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_error = sanitize_key( wp_unslash( $_GET['bn_error'] ?? '' ) );
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Profile updated successfully.', 'buddynext' ) . '</p></div>';
		}
		if ( 'avatar_size' === $bn_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Photo not saved: file exceeds the 2MB limit.', 'buddynext' ) . '</p></div>';
		} elseif ( 'avatar_type' === $bn_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Photo not saved: only JPEG, PNG, GIF, or WebP files are allowed.', 'buddynext' ) . '</p></div>';
		} elseif ( 'slug_taken' === $bn_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Profile URL slug is already in use. Please choose a different one.', 'buddynext' ) . '</p></div>';
		} elseif ( 'cover_size' === $bn_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Cover photo not saved: file exceeds the 5MB limit.', 'buddynext' ) . '</p></div>';
		} elseif ( 'cover_type' === $bn_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Cover photo not saved: only JPEG, PNG, GIF, or WebP files are allowed.', 'buddynext' ) . '</p></div>';
		}
		?>

		<a href="<?php echo esc_url( $back_url ); ?>" class="bn-edit-member-back">
			<?php echo \BuddyNext\Core\IconService::render( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php esc_html_e( 'Back to Members', 'buddynext' ); ?>
		</a>

		<div class="bn-member-hero">
			<?php
			$avatar_url = (string) get_user_meta( $user_id, 'bn_avatar', true );
			if ( '' !== $avatar_url ) :
				?>
				<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" class="bn-avatar" data-size="xl">
			<?php else : ?>
				<div class="bn-avatar bn-avatar-initials <?php echo esc_attr( MemberDisplay::get_avatar_color( $user_id ) ); ?>" data-size="xl" aria-hidden="true">
					<?php echo esc_html( MemberDisplay::get_initials( $wp_user->display_name ) ); ?>
				</div>
			<?php endif; ?>
			<div class="bn-member-hero-info">
				<div class="bn-hero-name"><?php echo esc_html( $wp_user->display_name ); ?></div>
				<div class="bn-hero-meta">
					<span class="bn-hero-username">@<?php echo esc_html( $wp_user->user_login ); ?></span>
					<span class="bn-hero-sep" aria-hidden="true">&middot;</span>
					<span class="bn-hero-email"><?php echo esc_html( $wp_user->user_email ); ?></span>
					<span class="bn-hero-sep" aria-hidden="true">&middot;</span>
					<?php MemberDisplay::render_role_badge( ( (array) $wp_user->roles )[0] ?? 'subscriber' ); ?>
				</div>
				<div class="bn-hero-stats">
					<?php
					$last_login    = (int) get_user_meta( $user_id, 'bn_last_login', true );
					$joined        = gmdate( 'M j, Y', strtotime( $wp_user->user_registered ) );
					$joined_iso    = gmdate( 'c', strtotime( $wp_user->user_registered ) );
					$last_login_iso = $last_login > 0 ? gmdate( 'c', $last_login ) : '';
					?>
					<span>
						<?php
						printf(
							/* translators: %s: formatted join date wrapped in a <time> element */
							esc_html__( 'Joined %s', 'buddynext' ),
							'<time datetime="' . esc_attr( $joined_iso ) . '">' . esc_html( $joined ) . '</time>'
						);
						?>
					</span>
					<span class="bn-hero-sep" aria-hidden="true">&middot;</span>
					<span>
						<?php if ( $last_login > 0 ) : ?>
							<?php
							printf(
								/* translators: %s: relative time of last login wrapped in a <time> element */
								esc_html__( 'Last login: %s', 'buddynext' ),
								'<time datetime="' . esc_attr( $last_login_iso ) . '">' . esc_html( MemberDisplay::human_time_diff_short( $last_login ) ) . '</time>'
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %s: literal "Never" string */
								esc_html__( 'Last login: %s', 'buddynext' ),
								esc_html__( 'Never', 'buddynext' )
							);
							?>
						<?php endif; ?>
					</span>
				</div>
			</div>
			<div class="bn-member-hero-actions">
				<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $user_id ) ); ?>"
					target="_blank"
					rel="noopener noreferrer"
					class="bn-btn"
					data-variant="secondary"
					data-size="sm">
					<?php echo \BuddyNext\Core\IconService::render( 'external-link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'View Profile', 'buddynext' ); ?>
				</a>
				<?php if ( buddynext_service( 'moderation' )->is_suspended( $user_id ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_unsuspend_member">
						<input type="hidden" name="user_id" value="<?php echo absint( $user_id ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'saved', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); ?>">
						<?php wp_nonce_field( 'bn_unsuspend_member' ); ?>
						<button type="submit" class="bn-btn" data-variant="secondary" data-size="sm">
							<?php esc_html_e( 'Unsuspend', 'buddynext' ); ?>
						</button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_suspend_member">
						<input type="hidden" name="user_id" value="<?php echo absint( $user_id ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'saved', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); ?>">
						<?php wp_nonce_field( 'bn_suspend_member' ); ?>
						<button type="submit" class="bn-btn" data-variant="danger" data-size="sm">
							<?php esc_html_e( 'Suspend Member', 'buddynext' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<?php
		/**
		 * Fires before the edit-member admin form.
		 *
		 * @param int     $user_id User ID being edited.
		 * @param \WP_User $wp_user WP_User object.
		 */
		do_action( 'buddynext_before_edit_member_form', $user_id, $wp_user );

		// Build tab list: fixed Account tab first, then one per profile group.
		$tab_list = array(
			array(
				'slug'  => 'account',
				'label' => __( 'Account', 'buddynext' ),
			),
		);
		foreach ( $groups as $group ) {
			$tab_list[] = array(
				'slug'  => 'group-' . absint( $group['id'] ),
				'label' => $group['label'],
			);
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="bn_save_member_profile">
			<input type="hidden" name="user_id" value="<?php echo absint( $user_id ); ?>">
			<?php wp_nonce_field( 'bn_save_member_profile' ); ?>

			<?php /* ── Tab nav bar (uses v2 .bn-tabs primitive) ────────── */ ?>
			<div class="bn-tabs bn-members-edit-tabs"
				role="tablist"
				data-bn-edit-tabs
				data-user-id="<?php echo absint( $user_id ); ?>">
				<?php foreach ( $tab_list as $idx => $tab ) : ?>
					<button
						type="button"
						class="bn-tab"
						data-panel="<?php echo esc_attr( $tab['slug'] ); ?>"
						role="tab"
						id="bn-edit-tab-<?php echo esc_attr( $tab['slug'] ); ?>"
						aria-controls="bn-panel-<?php echo esc_attr( $tab['slug'] ); ?>"
						aria-selected="<?php echo 0 === $idx ? 'true' : 'false'; ?>"
						tabindex="<?php echo 0 === $idx ? '0' : '-1'; ?>"
					><?php echo esc_html( $tab['label'] ); ?></button>
				<?php endforeach; ?>
			</div>

			<?php /* ── Account tab panel ──────────────────────────────── */ ?>
			<div id="bn-panel-account" class="bn-tab-panel is-active" role="tabpanel" aria-labelledby="bn-edit-tab-account">
				<?php
				$existing_avatar = (string) get_user_meta( $user_id, 'bn_avatar', true );
				$this->open_section( __( 'Profile Photo', 'buddynext' ) );
				?>
				<div class="bn-field-row">
					<div class="bn-label"><?php esc_html_e( 'Current Photo', 'buddynext' ); ?></div>
					<div class="bn-control">
						<?php if ( '' !== $existing_avatar ) : ?>
							<img src="<?php echo esc_url( $existing_avatar ); ?>" alt="" class="bn-avatar-preview">
							<label class="bn-edit-remove-toggle" for="bn-remove-avatar">
								<input type="checkbox" id="bn-remove-avatar" name="bn_remove_avatar" value="1">
								<?php esc_html_e( 'Remove current photo', 'buddynext' ); ?>
							</label>
						<?php else : ?>
							<div class="bn-avatar bn-avatar-initials bn-avatar-placeholder <?php echo esc_attr( MemberDisplay::get_avatar_color( $user_id ) ); ?>" aria-hidden="true">
								<?php echo esc_html( MemberDisplay::get_initials( $wp_user->display_name ) ); ?>
							</div>
						<?php endif; ?>
						<label for="bn-avatar-upload" class="screen-reader-text"><?php esc_html_e( 'Upload new profile photo', 'buddynext' ); ?></label>
						<input type="file" id="bn-avatar-upload" name="bn_avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="bn-edit-file-input">
						<p class="bn-edit-hint"><?php esc_html_e( 'Max 2MB. JPEG, PNG, GIF, or WebP.', 'buddynext' ); ?></p>
					</div>
				</div>
				<?php
				$this->close_section();

				// ── Cover Photo ───────────────────────────────────────────────
				$existing_cover = (string) get_user_meta( $user_id, 'buddynext_cover_url', true );
				$this->open_section( __( 'Cover Photo', 'buddynext' ) );
				?>
				<div class="bn-field-row">
					<div class="bn-label"><?php esc_html_e( 'Current Cover', 'buddynext' ); ?></div>
					<div class="bn-control">
						<?php if ( '' !== $existing_cover ) : ?>
							<img src="<?php echo esc_url( $existing_cover ); ?>" alt="" class="bn-cover-preview">
							<label class="bn-edit-remove-toggle" for="bn-remove-cover">
								<input type="checkbox" id="bn-remove-cover" name="bn_remove_cover" value="1">
								<?php esc_html_e( 'Remove current cover', 'buddynext' ); ?>
							</label>
						<?php else : ?>
							<p class="bn-edit-empty"><?php esc_html_e( 'No cover photo set.', 'buddynext' ); ?></p>
						<?php endif; ?>
						<label for="bn-cover-upload" class="screen-reader-text"><?php esc_html_e( 'Upload new cover photo', 'buddynext' ); ?></label>
						<input type="file" id="bn-cover-upload" name="bn_cover" accept="image/jpeg,image/png,image/gif,image/webp" class="bn-edit-file-input">
						<p class="bn-edit-hint"><?php esc_html_e( 'Recommended: 1500x500px, max 5MB. JPEG, PNG, GIF, or WebP.', 'buddynext' ); ?></p>
					</div>
				</div>
				<?php
				$this->close_section();

				$slug         = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
				$all_roles    = wp_roles()->get_names();
				$current_role = ( (array) $wp_user->roles )[0] ?? '';
				$this->open_section( __( 'Account', 'buddynext' ) );
				$this->render_text_row(
					'display_name',
					__( 'Display Name', 'buddynext' ),
					$wp_user->display_name,
					__( 'Shown publicly across the community.', 'buddynext' )
				);
				?>
				<div class="bn-field-row">
					<div class="bn-label"><label for="bn-user-email"><?php esc_html_e( 'Email Address', 'buddynext' ); ?></label></div>
					<div class="bn-control">
						<input type="email" id="bn-user-email" name="bn_user_email" value="<?php echo esc_attr( $wp_user->user_email ); ?>" class="bn-input">
					</div>
				</div>
				<div class="bn-field-row">
					<div class="bn-label"><label for="bn-user-role"><?php esc_html_e( 'Role', 'buddynext' ); ?></label></div>
					<div class="bn-control">
						<select id="bn-user-role" name="bn_user_role" class="bn-select">
							<?php foreach ( $all_roles as $role_key => $role_label ) : ?>
								<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $current_role, $role_key ); ?>>
									<?php echo esc_html( translate_user_role( $role_label ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="bn-field-row">
					<div class="bn-label"><label for="bn-profile-slug"><?php esc_html_e( 'Profile URL Slug', 'buddynext' ); ?></label></div>
					<div class="bn-control">
						<input type="text" id="bn-profile-slug" name="bn_profile_slug" value="<?php echo esc_attr( $slug ); ?>" class="bn-input">
						<p class="bn-edit-hint"><?php esc_html_e( 'Leave blank to use the default (user-{id}). Must be unique.', 'buddynext' ); ?></p>
					</div>
				</div>
				<?php $this->close_section(); ?>
			</div><!-- #bn-panel-account -->

			<?php /* ── Dynamic group tab panels ───────────────────────── */ ?>
			<?php foreach ( $groups as $group ) : ?>
				<div id="bn-panel-group-<?php echo absint( $group['id'] ); ?>"
					class="bn-tab-panel"
					role="tabpanel"
					aria-labelledby="bn-edit-tab-group-<?php echo absint( $group['id'] ); ?>">
					<?php
					$this->open_section( esc_html( $group['label'] ) );

					if ( 'repeater' === $group['type'] ) :
						$entries    = $group['entries'] ?? array();
						$group_key  = $group['group_key'];
						$group_id   = (int) $group['id'];
						$field_defs = $this->get_group_field_defs( $group_id );
						if ( empty( $entries ) ) {
							$entries = array( array() );
						}
						?>
						<div class="bn-repeater-entries"
							id="bn-repeater-<?php echo esc_attr( $group_key ); ?>"
							data-bn-repeater="<?php echo esc_attr( $group_key ); ?>">
						<?php foreach ( $entries as $e_idx => $entry_fields ) : ?>
							<div class="bn-repeater-entry">
								<div class="bn-repeater-entry-header">
									<span class="bn-repeater-entry-label">
										<?php echo esc_html( sprintf( /* translators: %d: entry number */ __( 'Entry %d', 'buddynext' ), (int) $e_idx + 1 ) ); ?>
									</span>
									<?php if ( $e_idx > 0 ) : ?>
										<button type="button" class="bn-repeater-remove" aria-label="<?php esc_attr_e( 'Remove entry', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
									<?php endif; ?>
								</div>
								<?php
								foreach ( $entry_fields as $entry_field ) :
									$this->render_repeater_field_input( $group_key, $e_idx, $entry_field );
								endforeach;
								if ( empty( $entry_fields ) ) :
									foreach ( $field_defs as $field_def ) :
										$this->render_repeater_field_input(
											$group_key,
											$e_idx,
											array(
												'field_key' => $field_def['field_key'],
												'label' => $field_def['label'],
												'type'  => $field_def['type'],
												'value' => null,
											)
										);
									endforeach;
								endif;
								?>
							</div>
						<?php endforeach; ?>
						</div>
						<template id="bn-repeater-tpl-<?php echo esc_attr( $group_key ); ?>">
							<div class="bn-repeater-entry">
								<div class="bn-repeater-entry-header">
									<span class="bn-repeater-entry-label"></span>
									<button type="button" class="bn-repeater-remove" aria-label="<?php esc_attr_e( 'Remove entry', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
								</div>
								<?php foreach ( $field_defs as $field_def ) : ?>
									<?php $this->render_repeater_field_template( $group_key, $field_def ); ?>
								<?php endforeach; ?>
							</div>
						</template>
						<button type="button"
							class="bn-repeater-add"
							data-bn-repeater-add="<?php echo esc_attr( $group_key ); ?>">
							<?php esc_html_e( '+ Add Entry', 'buddynext' ); ?>
						</button>
					<?php else : ?>
						<?php
						$flat_fields = $group['fields'] ?? array();
						foreach ( $flat_fields as $field ) :
							$this->render_flat_field_input( $field );
						endforeach;
						if ( empty( $flat_fields ) ) :
							echo '<p class="bn-edit-empty">' . esc_html__( 'No fields in this group.', 'buddynext' ) . '</p>';
						endif;
						?>
					<?php endif; ?>

					<?php $this->close_section(); ?>
				</div><!-- #bn-panel-group-<?php echo absint( $group['id'] ); ?> -->
			<?php endforeach; ?>

			<?php
			/**
			 * Fires after all profile group panels inside the edit-member form.
			 *
			 * @param int     $user_id User ID being edited.
			 * @param \WP_User $wp_user WP_User object.
			 */
			do_action( 'buddynext_edit_member_sections', $user_id, $wp_user );
			?>

			<div class="bn-save-bar">
				<?php submit_button( __( 'Save Profile', 'buddynext' ), 'primary bn-btn-save', 'submit', false ); ?>
			</div>
		</form>
		<?php
		/**
		 * Fires after the edit-member admin form.
		 *
		 * @param int     $user_id User ID being edited.
		 * @param \WP_User $wp_user WP_User object.
		 */
		do_action( 'buddynext_after_edit_member_form', $user_id, $wp_user );
	}

	/**
	 * Render an editable input for a flat profile field.
	 *
	 * @param array<string, mixed> $field Field data including field_key, label, type, value.
	 * @return void
	 */
	private function render_flat_field_input( array $field ): void {
		$key     = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
		$label   = (string) ( $field['label'] ?? $key );
		$type    = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
		$raw_val = $field['value'] ?? '';
		$value   = is_array( $raw_val ) ? $raw_val : (string) $raw_val;
		$options = array();
		if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
			$options = $field['options'];
		}

		$input_id = 'bn-pf-' . $key;
		?>
<div class="bn-field-row">
	<div class="bn-label"><label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label></div>
	<div class="bn-control">
			<?php if ( 'textarea' === $type ) : ?>
		<textarea id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					class="bn-textarea"><?php echo esc_textarea( is_array( $value ) ? wp_json_encode( $value ) : $value ); ?></textarea>
		<?php elseif ( 'select' === $type ) : ?>
		<select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $key ); ?>" class="bn-select">
			<option value=""><?php esc_html_e( '-- Select --', 'buddynext' ); ?></option>
			<?php foreach ( $options as $opt ) : ?>
				<option value="<?php echo esc_attr( (string) $opt ); ?>" <?php selected( is_array( $value ) ? '' : $value, (string) $opt ); ?>><?php echo esc_html( (string) $opt ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php elseif ( 'multiselect' === $type ) : ?>
		<select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $key ); ?>[]" multiple class="bn-select">
			<?php
			$selected_vals = is_array( $value ) ? $value : (array) json_decode( $value, true );
			foreach ( $options as $opt ) :
				$is_sel = in_array( (string) $opt, array_map( 'strval', (array) $selected_vals ), true );
				?>
				<option value="<?php echo esc_attr( (string) $opt ); ?>"<?php echo $is_sel ? ' selected' : ''; ?>><?php echo esc_html( (string) $opt ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php elseif ( 'radio' === $type ) : ?>
		<div class="bn-radio-group" role="radiogroup">
			<?php foreach ( $options as $opt ) : ?>
			<label>
				<input type="radio"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( (string) $opt ); ?>"
					<?php checked( is_array( $value ) ? '' : $value, (string) $opt ); ?>>
				<?php echo esc_html( (string) $opt ); ?>
			</label>
		<?php endforeach; ?>
		</div>
		<?php elseif ( 'checkbox' === $type ) : ?>
		<div class="bn-checkbox-group">
			<?php
			$checked_vals = is_array( $value ) ? $value : (array) json_decode( $value, true );
			foreach ( $options as $opt ) :
				$is_chk = in_array( (string) $opt, array_map( 'strval', (array) $checked_vals ), true );
				?>
			<label>
				<input type="checkbox"
					name="<?php echo esc_attr( $key ); ?>[]"
					value="<?php echo esc_attr( (string) $opt ); ?>"
					<?php echo $is_chk ? 'checked' : ''; ?>>
				<?php echo esc_html( (string) $opt ); ?>
			</label>
			<?php endforeach; ?>
		</div>
		<?php elseif ( 'toggle' === $type ) : ?>
		<label class="bn-toggle-inline" for="<?php echo esc_attr( $input_id ); ?>">
			<input type="checkbox"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				value="1"
				<?php checked( is_array( $value ) ? '' : $value, '1' ); ?>>
			<?php esc_html_e( 'Yes', 'buddynext' ); ?>
		</label>
		<?php elseif ( 'rating' === $type ) : ?>
		<input type="number"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			min="1" max="5" step="1"
			class="bn-input">
		<?php elseif ( 'date' === $type ) : ?>
		<input type="date"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-input">
		<?php elseif ( 'email' === $type ) : ?>
		<input type="email"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-input">
		<?php elseif ( 'url' === $type || 'social' === $type ) : ?>
		<input type="url"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-input">
		<?php elseif ( 'number' === $type ) : ?>
		<input type="number"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-input">
		<?php elseif ( 'phone' === $type ) : ?>
		<input type="tel"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-input">
		<?php else : ?>
		<input type="text"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
				class="bn-input">
		<?php endif; ?>
		</div>
</div>
		<?php
	}

	/**
	 * Render an editable input for a repeater entry field.
	 *
	 * Input name follows the shape: group_key[entry_index][field_key].
	 *
	 * @param string               $group_key  The parent group's group_key.
	 * @param int                  $entry_idx  Zero-based entry index.
	 * @param array<string, mixed> $field      Field data: field_key, label, type, value.
	 * @return void
	 */
	private function render_repeater_field_input( string $group_key, int $entry_idx, array $field ): void {
		$key      = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
		$label    = (string) ( $field['label'] ?? $key );
		$type     = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
		$value    = (string) ( $field['value'] ?? '' );
		$name     = esc_attr( $group_key ) . '[' . absint( $entry_idx ) . '][' . esc_attr( $key ) . ']';
		$input_id = 'bn-pf-' . esc_attr( $group_key ) . '-' . absint( $entry_idx ) . '-' . esc_attr( $key );
		?>
<div class="bn-field">
	<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<?php if ( 'textarea' === $type ) : ?>
		<textarea id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					class="bn-textarea"><?php echo esc_textarea( $value ); ?></textarea>
		<?php elseif ( 'url' === $type ) : ?>
		<input type="url"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				class="bn-input">
		<?php else : ?>
		<input type="text"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				class="bn-input">
		<?php endif; ?>
</div>
		<?php
	}

	/**
	 * Return field definitions for a given group ID (used for blank repeater rows).
	 *
	 * @param int $group_id Group ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_group_field_defs( int $group_id ): array {
		$all_groups = buddynext_service( 'profiles' )->get_fields();

		foreach ( $all_groups as $group ) {
			if ( (int) $group['id'] === $group_id ) {
				return $group['fields'] ?? array();
			}
		}

		return array();
	}

	/**
	 * Render a blank repeater field input for use inside a <template> element.
	 * Uses the literal string __idx__ as the entry-index placeholder so that
	 * JavaScript can replace it with the real index when cloning the template.
	 *
	 * @param string               $group_key Group key.
	 * @param array<string, mixed> $field     Field definition: field_key, label, type.
	 * @return void
	 */
	private function render_repeater_field_template( string $group_key, array $field ): void {
		$key      = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
		$label    = (string) ( $field['label'] ?? $key );
		$type     = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
		$name     = esc_attr( $group_key ) . '[__idx__][' . esc_attr( $key ) . ']';
		$input_id = 'bn-pf-' . esc_attr( $group_key ) . '-__idx__-' . esc_attr( $key );
		?>
<div class="bn-field">
	<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<?php if ( 'textarea' === $type ) : ?>
		<textarea id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					class="bn-textarea"></textarea>
		<?php elseif ( 'url' === $type ) : ?>
		<input type="url"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value=""
				class="bn-input">
		<?php else : ?>
		<input type="text"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value=""
				class="bn-input">
		<?php endif; ?>
</div>
		<?php
	}

	/**
	 * Open a settings card section.
	 *
	 * Delegates to AdminPageBase via the parent Members controller context.
	 * Since MemberEditForm is standalone, it replicates the section markup directly.
	 *
	 * @param string $title Section title.
	 * @return void
	 */
	private function open_section( string $title ): void {
		echo '<div class="bn-settings-section"><div class="bn-ss-header"><span class="bn-ss-title">' . esc_html( $title ) . '</span></div><div class="bn-ss-body">';
	}

	/**
	 * Close a settings card section.
	 *
	 * @return void
	 */
	private function close_section(): void {
		echo '</div></div><!-- .bn-settings-section -->';
	}

	/**
	 * Render a single text input row inside a settings card.
	 *
	 * @param string $name        Input name attribute.
	 * @param string $label       Row label.
	 * @param string $value       Current value.
	 * @param string $description Optional description shown below the input.
	 * @return void
	 */
	private function render_text_row( string $name, string $label, string $value, string $description = '' ): void {
		$input_id = 'bn-field-' . sanitize_key( $name );
		?>
		<div class="bn-field-row">
			<div class="bn-label"><label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label></div>
			<div class="bn-control">
				<input type="text"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="bn-input">
				<?php if ( '' !== $description ) : ?>
					<p class="bn-edit-hint"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
