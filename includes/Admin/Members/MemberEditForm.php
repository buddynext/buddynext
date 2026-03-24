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
		<style>
		.bn-edit-member-back { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#0073aa; text-decoration:none; margin-bottom:16px; font-weight:500; }
		.bn-edit-member-back:hover { text-decoration:underline; }
		.bn-edit-textarea { width:100%; border:1px solid #ddd; border-radius:4px; padding:8px 10px; font-size:13px; font-family:inherit; resize:vertical; min-height:80px; box-sizing:border-box; }
		.bn-edit-textarea:focus { border-color:#0073aa; box-shadow:0 0 0 1px #0073aa; outline:none; }
		.bn-repeater-entry { border:1px solid #e9ecef; border-radius:6px; padding:14px; margin-bottom:12px; background:#fafafa; }
		.bn-repeater-entry-header { display:flex; align-items:center; justify-content:space-between; font-size:12px; font-weight:700; color:#6b7280; margin-bottom:10px; }
		.bn-repeater-remove { background:none; border:none; color:#9ca3af; cursor:pointer; font-size:14px; padding:2px 6px; line-height:1; border-radius:3px; transition:color .15s; }
		.bn-repeater-remove:hover { color:#dc2626; }
		.bn-repeater-add { margin-top:4px; background:none; border:1px dashed #d1d5db; border-radius:5px; color:#0073aa; cursor:pointer; font-size:12px; font-weight:600; padding:7px 14px; width:100%; transition:background .15s, border-color .15s; }
		.bn-repeater-add:hover { background:#f0f7fc; border-color:#0073aa; }
		.bn-member-hero { background:#fff; border:1px solid #e9ecef; border-radius:10px; padding:20px 24px; display:flex; align-items:center; gap:16px; margin-bottom:20px; }
		.bn-hero-avatar-img { width:64px; height:64px; border-radius:50%; object-fit:cover; flex-shrink:0; }
		.bn-hero-avatar-initials { width:64px !important; height:64px !important; font-size:22px !important; flex-shrink:0; }
		.bn-member-hero-info { flex:1; min-width:0; }
		.bn-hero-name { font-size:18px; font-weight:700; color:#111827; margin-bottom:4px; }
		.bn-hero-meta { display:flex; align-items:center; gap:6px; font-size:13px; flex-wrap:wrap; margin-bottom:4px; }
		.bn-hero-username { color:#6b7280; }
		.bn-hero-email { color:#6b7280; }
		.bn-hero-sep { color:#d1d5db; }
		.bn-hero-stats { font-size:12px; color:#9ca3af; display:flex; gap:6px; flex-wrap:wrap; }
		.bn-member-hero-actions { display:flex; align-items:center; gap:8px; flex-shrink:0; }
		.bn-hero-danger-btn { background:#fff; border:1px solid #fca5a5; color:#dc2626; border-radius:5px; padding:7px 14px; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
		.bn-hero-danger-btn:hover { background:#fef2f2; border-color:#dc2626; }
		.bn-hero-view-btn { background:#fff; border:1px solid #d1d5db; color:#374151; border-radius:5px; padding:7px 14px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
		.bn-hero-view-btn:hover { background:#f3f4f6; border-color:#9ca3af; color:#111827; }
		/* Tab nav */
		.bn-edit-tabs { display:flex; flex-wrap:wrap; gap:2px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:8px; padding:4px; margin-bottom:20px; }
		.bn-edit-tab { flex:0 0 auto; background:none; border:none; padding:7px 16px; font-size:13px; font-weight:500; color:#6b7280; border-radius:5px; cursor:pointer; font-family:inherit; transition:background .15s,color .15s; white-space:nowrap; }
		.bn-edit-tab.is-active { background:#fff; color:#111827; box-shadow:0 1px 3px rgba(0,0,0,.08); font-weight:600; }
		.bn-edit-tab:hover:not(.is-active) { color:#374151; background:rgba(255,255,255,.6); }
		/* Tab panels */
		.bn-tab-panel { display:none; }
		.bn-tab-panel.is-active { display:block; }
		@media (max-width: 640px) {
			.bn-member-hero { flex-direction:column; align-items:flex-start; }
			.bn-member-hero-actions { width:100%; }
			.bn-edit-tab { padding:6px 12px; font-size:12px; }
		}
		</style>

		<a href="<?php echo esc_url( $back_url ); ?>" class="bn-edit-member-back">
			&#8592; <?php esc_html_e( 'Back to Members', 'buddynext' ); ?>
		</a>

		<div class="bn-member-hero">
			<div class="bn-member-hero-avatar">
				<?php
				$avatar_url = (string) get_user_meta( $user_id, 'bn_avatar', true );
				if ( '' !== $avatar_url ) :
					?>
					<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" class="bn-hero-avatar-img">
				<?php else : ?>
					<div class="bn-avatar-initials bn-hero-avatar-initials <?php echo esc_attr( MemberDisplay::get_avatar_color( $user_id ) ); ?>">
						<?php echo esc_html( MemberDisplay::get_initials( $wp_user->display_name ) ); ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="bn-member-hero-info">
				<div class="bn-hero-name"><?php echo esc_html( $wp_user->display_name ); ?></div>
				<div class="bn-hero-meta">
					<span class="bn-hero-username">@<?php echo esc_html( $wp_user->user_login ); ?></span>
					<span class="bn-hero-sep">&middot;</span>
					<span class="bn-hero-email"><?php echo esc_html( $wp_user->user_email ); ?></span>
					<span class="bn-hero-sep">&middot;</span>
					<?php MemberDisplay::render_role_badge( ( (array) $wp_user->roles )[0] ?? 'subscriber' ); ?>
				</div>
				<div class="bn-hero-stats">
					<?php
					$last_login = (int) get_user_meta( $user_id, 'bn_last_login', true );
					$joined     = gmdate( 'M j, Y', strtotime( $wp_user->user_registered ) );
					?>
					<span><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Joined %s', 'buddynext' ), $joined ) ); ?></span>
					<span class="bn-hero-sep">&middot;</span>
					<span><?php echo esc_html( sprintf( /* translators: %s: time string */ __( 'Last login: %s', 'buddynext' ), $last_login > 0 ? MemberDisplay::human_time_diff_short( $last_login ) : __( 'Never', 'buddynext' ) ) ); ?></span>
				</div>
			</div>
			<div class="bn-member-hero-actions">
				<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $user_id ) ); ?>" target="_blank" rel="noopener noreferrer" class="bn-hero-view-btn">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
					<?php esc_html_e( 'View Profile', 'buddynext' ); ?>
				</a>
				<?php if ( buddynext_service( 'moderation' )->is_suspended( $user_id ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_unsuspend_member">
						<input type="hidden" name="user_id" value="<?php echo absint( $user_id ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'saved', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); ?>">
						<?php wp_nonce_field( 'bn_unsuspend_member' ); ?>
						<button type="submit" class="bn-btn-secondary"><?php esc_html_e( 'Unsuspend', 'buddynext' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_suspend_member">
						<input type="hidden" name="user_id" value="<?php echo absint( $user_id ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'saved', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); ?>">
						<?php wp_nonce_field( 'bn_suspend_member' ); ?>
						<button type="submit" class="bn-hero-danger-btn"><?php esc_html_e( 'Suspend Member', 'buddynext' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<?php
		/**
		 * Fires before the edit-member admin form.
		 *
		 * @param int     $user_id User ID being edited.
		 * @param WP_User $wp_user WP_User object.
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

			<?php /* ── Tab nav bar ─────────────────────────────────────── */ ?>
			<div class="bn-edit-tabs" role="tablist">
				<?php foreach ( $tab_list as $idx => $tab ) : ?>
					<button
						type="button"
						class="bn-edit-tab<?php echo 0 === $idx ? ' is-active' : ''; ?>"
						data-panel="<?php echo esc_attr( $tab['slug'] ); ?>"
						role="tab"
						aria-controls="bn-panel-<?php echo esc_attr( $tab['slug'] ); ?>"
						aria-selected="<?php echo 0 === $idx ? 'true' : 'false'; ?>"
					><?php echo esc_html( $tab['label'] ); ?></button>
				<?php endforeach; ?>
			</div>

			<?php /* ── Account tab panel ──────────────────────────────── */ ?>
			<div id="bn-panel-account" class="bn-tab-panel is-active" role="tabpanel">
				<?php
				$existing_avatar = (string) get_user_meta( $user_id, 'bn_avatar', true );
				$this->open_section( __( 'Profile Photo', 'buddynext' ) );
				?>
				<div class="bn-field-row">
					<div class="bn-label"><?php esc_html_e( 'Current Photo', 'buddynext' ); ?></div>
					<div class="bn-control">
						<?php if ( '' !== $existing_avatar ) : ?>
							<img src="<?php echo esc_url( $existing_avatar ); ?>" alt="" style="width:80px;height:80px;border-radius:50%;object-fit:cover;display:block;margin-bottom:10px;">
							<label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#dc2626;margin-bottom:8px;">
								<input type="checkbox" name="bn_remove_avatar" value="1">
								<?php esc_html_e( 'Remove current photo', 'buddynext' ); ?>
							</label>
						<?php else : ?>
							<div class="bn-avatar-initials <?php echo esc_attr( MemberDisplay::get_avatar_color( $user_id ) ); ?>" style="width:80px;height:80px;font-size:28px;margin-bottom:10px;"><?php echo esc_html( MemberDisplay::get_initials( $wp_user->display_name ) ); ?></div>
						<?php endif; ?>
						<input type="file" name="bn_avatar" accept="image/jpeg,image/png,image/gif,image/webp" style="font-size:13px;">
						<p style="font-size:11px;color:#aeaca8;margin:6px 0 0;"><?php esc_html_e( 'Max 2MB. JPEG, PNG, GIF, or WebP.', 'buddynext' ); ?></p>
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
							<img src="<?php echo esc_url( $existing_cover ); ?>" alt="" style="max-height:80px;max-width:300px;border-radius:6px;object-fit:cover;display:block;margin-bottom:10px;">
							<label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#dc2626;margin-bottom:8px;">
								<input type="checkbox" name="bn_remove_cover" value="1">
								<?php esc_html_e( 'Remove current cover', 'buddynext' ); ?>
							</label>
						<?php else : ?>
							<p style="font-size:13px;color:#9ca3af;margin:0 0 8px;"><?php esc_html_e( 'No cover photo set.', 'buddynext' ); ?></p>
						<?php endif; ?>
						<input type="file" name="bn_cover" accept="image/jpeg,image/png,image/gif,image/webp" style="font-size:13px;">
						<p style="font-size:11px;color:#aeaca8;margin:6px 0 0;"><?php esc_html_e( 'Recommended: 1500×500px, max 5MB. JPEG, PNG, GIF, or WebP.', 'buddynext' ); ?></p>
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
						<input type="email" id="bn-user-email" name="bn_user_email" value="<?php echo esc_attr( $wp_user->user_email ); ?>" class="bn-text-input regular-text">
					</div>
				</div>
				<div class="bn-field-row">
					<div class="bn-label"><label for="bn-user-role"><?php esc_html_e( 'Role', 'buddynext' ); ?></label></div>
					<div class="bn-control">
						<select id="bn-user-role" name="bn_user_role" class="bn-text-input">
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
						<input type="text" id="bn-profile-slug" name="bn_profile_slug" value="<?php echo esc_attr( $slug ); ?>" class="bn-text-input regular-text">
						<p style="font-size:11px;color:#aeaca8;margin:4px 0 0;"><?php esc_html_e( 'Leave blank to use the default (user-{id}). Must be unique.', 'buddynext' ); ?></p>
					</div>
				</div>
				<?php $this->close_section(); ?>
			</div><!-- #bn-panel-account -->

			<?php /* ── Dynamic group tab panels ───────────────────────── */ ?>
			<?php foreach ( $groups as $group ) : ?>
				<div id="bn-panel-group-<?php echo absint( $group['id'] ); ?>" class="bn-tab-panel" role="tabpanel">
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
						<div class="bn-repeater-entries" id="bn-repeater-<?php echo esc_attr( $group_key ); ?>">
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
						<button type="button" class="bn-repeater-add" data-group="<?php echo esc_attr( $group_key ); ?>">+ <?php esc_html_e( 'Add Entry', 'buddynext' ); ?></button>
						<?php $this->output_repeater_js( $group_key ); ?>
					<?php else : ?>
						<?php
						$flat_fields = $group['fields'] ?? array();
						foreach ( $flat_fields as $field ) :
							$this->render_flat_field_input( $field );
						endforeach;
						if ( empty( $flat_fields ) ) :
							echo '<p style="color:#9ca3af;font-size:12px;margin:0;">' . esc_html__( 'No fields in this group.', 'buddynext' ) . '</p>';
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
			 * @param WP_User $wp_user WP_User object.
			 */
			do_action( 'buddynext_edit_member_sections', $user_id, $wp_user );
			?>

			<div class="bn-save-bar">
				<?php submit_button( __( 'Save Profile', 'buddynext' ), 'primary bn-btn-save', 'submit', false ); ?>
			</div>
		</form>

		<script>
		(function() {
			var storageKey = 'bn-edit-tab-<?php echo absint( $user_id ); ?>';
			var tabs       = document.querySelectorAll( '.bn-edit-tab' );
			var panels     = document.querySelectorAll( '.bn-tab-panel' );

			function activate( slug ) {
				tabs.forEach( function( t ) {
					var isActive = t.dataset.panel === slug;
					t.classList.toggle( 'is-active', isActive );
					t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				} );
				panels.forEach( function( p ) {
					p.classList.toggle( 'is-active', p.id === 'bn-panel-' + slug );
				} );
				try { sessionStorage.setItem( storageKey, slug ); } catch (e) {}
			}

			tabs.forEach( function( tab ) {
				tab.addEventListener( 'click', function() { activate( tab.dataset.panel ); } );
			} );

			// Restore last active tab on page load.
			try {
				var last = sessionStorage.getItem( storageKey );
				if ( last && document.getElementById( 'bn-panel-' + last ) ) {
					activate( last );
				}
			} catch (e) {}
		}());
		</script>
		<?php
		/**
		 * Fires after the edit-member admin form.
		 *
		 * @param int     $user_id User ID being edited.
		 * @param WP_User $wp_user WP_User object.
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
					class="bn-edit-textarea"><?php echo esc_textarea( is_array( $value ) ? wp_json_encode( $value ) : $value ); ?></textarea>
		<?php elseif ( 'select' === $type ) : ?>
		<select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $key ); ?>" class="bn-text-input">
			<option value=""><?php esc_html_e( '-- Select --', 'buddynext' ); ?></option>
			<?php foreach ( $options as $opt ) : ?>
				<option value="<?php echo esc_attr( (string) $opt ); ?>" <?php selected( is_array( $value ) ? '' : $value, (string) $opt ); ?>><?php echo esc_html( (string) $opt ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php elseif ( 'multiselect' === $type ) : ?>
		<select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $key ); ?>[]" multiple class="bn-text-input">
			<?php
			$selected_vals = is_array( $value ) ? $value : (array) json_decode( $value, true );
			foreach ( $options as $opt ) :
				$is_sel = in_array( (string) $opt, array_map( 'strval', (array) $selected_vals ), true );
				?>
				<option value="<?php echo esc_attr( (string) $opt ); ?>"<?php echo $is_sel ? ' selected' : ''; ?>><?php echo esc_html( (string) $opt ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php elseif ( 'radio' === $type ) : ?>
		<div class="bn-radio-group">
			<?php foreach ( $options as $opt ) : ?>
			<label style="display:inline-flex;align-items:center;gap:4px;margin-right:12px;font-size:13px;">
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
			<label style="display:inline-flex;align-items:center;gap:4px;margin-right:12px;font-size:13px;">
				<input type="checkbox"
					name="<?php echo esc_attr( $key ); ?>[]"
					value="<?php echo esc_attr( (string) $opt ); ?>"
					<?php echo $is_chk ? 'checked' : ''; ?>>
				<?php echo esc_html( (string) $opt ); ?>
			</label>
			<?php endforeach; ?>
		</div>
		<?php elseif ( 'toggle' === $type ) : ?>
		<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">
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
			class="bn-text-input">
		<?php elseif ( 'date' === $type ) : ?>
		<input type="date"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-text-input">
		<?php elseif ( 'email' === $type ) : ?>
		<input type="email"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-text-input regular-text">
		<?php elseif ( 'url' === $type || 'social' === $type ) : ?>
		<input type="url"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-text-input regular-text">
		<?php elseif ( 'number' === $type ) : ?>
		<input type="number"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-text-input">
		<?php elseif ( 'phone' === $type ) : ?>
		<input type="tel"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
			class="bn-text-input regular-text">
		<?php else : ?>
		<input type="text"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
				class="bn-text-input regular-text">
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
					class="bn-edit-textarea"><?php echo esc_textarea( $value ); ?></textarea>
		<?php elseif ( 'url' === $type ) : ?>
		<input type="url"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				class="bn-text-input regular-text">
		<?php else : ?>
		<input type="text"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				class="bn-text-input regular-text">
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
					class="bn-edit-textarea"></textarea>
		<?php elseif ( 'url' === $type ) : ?>
		<input type="url"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value=""
				class="bn-text-input regular-text">
		<?php else : ?>
		<input type="text"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value=""
				class="bn-text-input regular-text">
		<?php endif; ?>
</div>
		<?php
	}

	/**
	 * Output inline JavaScript for a single repeater group's Add / Remove interactions.
	 *
	 * @param string $group_key Repeater group key.
	 * @return void
	 */
	private function output_repeater_js( string $group_key ): void {
		?>
<script>
( function () {
	var container = document.getElementById( 'bn-repeater-<?php echo esc_js( $group_key ); ?>' );
	var tpl       = document.getElementById( 'bn-repeater-tpl-<?php echo esc_js( $group_key ); ?>' );
	var addBtn    = document.querySelector( '[data-group="<?php echo esc_js( $group_key ); ?>"]' );
	if ( ! container || ! tpl || ! addBtn ) { return; }

	function applyIdx( node, idx ) {
		if ( node.nodeType !== 1 ) { return; }
		var attrs = [ 'id', 'name', 'for' ];
		attrs.forEach( function ( attr ) {
			var val = node.getAttribute( attr );
			if ( val && val.indexOf( '__idx__' ) !== -1 ) {
				node.setAttribute( attr, val.replace( /__idx__/g, String( idx ) ) );
			}
		} );
		node.childNodes.forEach( function ( child ) { applyIdx( child, idx ); } );
	}

	function renumber() {
		container.querySelectorAll( '.bn-repeater-entry' ).forEach( function ( entry, i ) {
			var lbl = entry.querySelector( '.bn-repeater-entry-label' );
			if ( lbl ) { lbl.textContent = '<?php echo esc_js( __( 'Entry', 'buddynext' ) ); ?> ' + ( i + 1 ); }
		} );
	}

	function bindRemove( btn ) {
		btn.addEventListener( 'click', function () {
			if ( container.querySelectorAll( '.bn-repeater-entry' ).length > 1 ) {
				btn.closest( '.bn-repeater-entry' ).remove();
				renumber();
			}
		} );
	}

	container.querySelectorAll( '.bn-repeater-remove' ).forEach( bindRemove );

	addBtn.addEventListener( 'click', function () {
		var idx      = container.querySelectorAll( '.bn-repeater-entry' ).length;
		var newEntry = document.importNode( tpl.content, true ).firstElementChild;
		applyIdx( newEntry, idx );
		var lbl = newEntry.querySelector( '.bn-repeater-entry-label' );
		if ( lbl ) { lbl.textContent = '<?php echo esc_js( __( 'Entry', 'buddynext' ) ); ?> ' + ( idx + 1 ); }
		bindRemove( newEntry.querySelector( '.bn-repeater-remove' ) );
		container.appendChild( newEntry );
	} );
}() );
</script>
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
					class="bn-text-input regular-text">
				<?php if ( '' !== $description ) : ?>
					<p style="font-size:11px;color:#aeaca8;margin:4px 0 0;"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
