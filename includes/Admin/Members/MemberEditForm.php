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

use BuddyNext\Admin\AdminPageBase;
use BuddyNext\Admin\Members\MemberDisplay;

/**
 * Renders the edit-member admin view for a single user.
 */
class MemberEditForm {

	/**
	 * How long a rejected save's field messages survive.
	 *
	 * The save is a POST that redirects, so the messages have to cross one request
	 * to reach this screen. Long enough that a slow redirect never loses them,
	 * short enough that a stale set cannot surface against a later edit.
	 */
	public const SAVE_ERROR_TTL = 60;

	/**
	 * Transient key holding a rejected save's messages for one editor + one member.
	 *
	 * Keyed by BOTH ids: two admins editing at the same time must not read each
	 * other's errors, and one admin moving between members must not be shown the
	 * previous member's.
	 *
	 * Lives here rather than on Members because this is the class that renders the
	 * messages; Members already depends on this one to render the view, so the key
	 * adds no new direction to the dependency.
	 *
	 * @since 1.1.5
	 *
	 * @param int $editor_id Admin who submitted the form.
	 * @param int $member_id Member being edited.
	 * @return string
	 */
	public static function save_error_transient_key( int $editor_id, int $member_id ): string {
		return sprintf( 'bn_member_save_err_%d_%d', $editor_id, $member_id );
	}

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
			AdminPageBase::render_notice( __( 'User not found.', 'buddynext' ), 'error' );
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
			AdminPageBase::render_notice( __( 'Profile updated successfully.', 'buddynext' ), 'success' );
		}

		/*
		 * An eight-branch if/elseif chain that only ever picked a string was a
		 * lookup table wearing a conditional — and the shape is why the raw markup
		 * multiplied here: adding a failure reason meant copy-pasting a whole
		 * notice div, so the tenth one was a copy of the ninth. A map means the
		 * next reason is one line and cannot render differently from its
		 * neighbours.
		 */
		$bn_error_messages = array(
			'avatar_size'     => __( 'Photo not saved: file exceeds the 2MB limit.', 'buddynext' ),
			'avatar_type'     => __( 'Photo not saved: only JPEG, PNG, GIF, or WebP files are allowed.', 'buddynext' ),
			'slug_taken'      => __( 'Profile URL slug is already in use. Please choose a different one.', 'buddynext' ),
			'cover_size'      => __( 'Cover photo not saved: file exceeds the 5MB limit.', 'buddynext' ),
			'cover_type'      => __( 'Cover photo not saved: only JPEG, PNG, GIF, or WebP files are allowed.', 'buddynext' ),
			'email_taken'     => __( 'Not saved: that email address is already in use by another account.', 'buddynext' ),
			'email_invalid'   => __( 'Not saved: please enter a valid email address.', 'buddynext' ),
			'role_invalid'    => __( 'Not saved: the selected role is not valid.', 'buddynext' ),
			// Plural and absolute on purpose: the save is atomic, so one bad field
			// means none of the profile fields were written. "Some changes were not
			// saved" would be the same false reassurance this replaces.
			'profile_invalid' => __( 'Profile fields were not saved. Nothing on this form was changed - fix the problems below and save again.', 'buddynext' ),
		);

		if ( isset( $bn_error_messages[ $bn_error ] ) ) {
			AdminPageBase::render_notice( $bn_error_messages[ $bn_error ], 'error' );
		}

		/*
		 * A rejected profile save carries per-field messages, and they are the whole
		 * point: save_profile() is atomic, so a rejection dropped the ENTIRE edit,
		 * and "Not saved" without naming the field leaves the admin re-submitting
		 * the same form to find out which one. The generic line above says what
		 * happened; this says what to fix.
		 */
		if ( 'profile_invalid' === $bn_error ) {
			$bn_save_error = get_transient( self::save_error_transient_key( get_current_user_id(), $user_id ) );
			delete_transient( self::save_error_transient_key( get_current_user_id(), $user_id ) );

			if ( is_array( $bn_save_error ) ) {
				$bn_lines = array();

				// Field KEYS are how save_profile() reports, and they are not what the
				// admin is looking at: this form is labelled "QA Pro Location", so
				// "qa_pro_location" makes them translate before they can act. Resolve
				// against the same $groups the form itself was built from, so the
				// error names the field exactly as the input above it does.
				$bn_labels = array();
				foreach ( $groups as $bn_lgroup ) {
					foreach ( (array) ( $bn_lgroup['fields'] ?? array() ) as $bn_lfield ) {
						$bn_lkey = (string) ( $bn_lfield['field_key'] ?? '' );
						if ( '' !== $bn_lkey ) {
							$bn_labels[ $bn_lkey ] = (string) ( $bn_lfield['label'] ?? $bn_lkey );
						}
					}
				}

				foreach ( (array) ( $bn_save_error['fields'] ?? array() ) as $bn_fk => $bn_msg ) {
					// Repeater failures are keyed group[0][field_key]; the inner key is
					// the one with a label. Falling back to the raw key is still an
					// attribution, which is the point.
					$bn_inner = ( preg_match( '/\[([a-z0-9_]+)\]$/i', (string) $bn_fk, $bn_m ) === 1 ) ? $bn_m[1] : (string) $bn_fk;
					$bn_label = $bn_labels[ $bn_inner ] ?? $bn_inner;
					$bn_msg   = (string) $bn_msg;

					// save_profile()'s own messages already open with the field label
					// ("Website must be a valid URL."), so prefixing the label would
					// print it twice. Bold it where it already stands instead, and only
					// prepend it when the message does not name the field — which is the
					// safeguard's generic rejection, the one case with no attribution of
					// its own.
					$bn_lines[] = str_starts_with( $bn_msg, $bn_label )
						? '<strong>' . esc_html( $bn_label ) . '</strong>' . esc_html( substr( $bn_msg, strlen( $bn_label ) ) )
						: '<strong>' . esc_html( $bn_label ) . '</strong>: ' . esc_html( $bn_msg );
				}

				// The safeguard can reject without naming a field even after the
				// per-value re-check (a phrase that only trips when values are
				// joined). Its own message is then all there is, and it is better
				// than nothing.
				if ( empty( $bn_lines ) && '' !== (string) ( $bn_save_error['message'] ?? '' ) ) {
					$bn_lines[] = esc_html( (string) $bn_save_error['message'] );
				}

				if ( ! empty( $bn_lines ) ) {
					AdminPageBase::render_notice( implode( '<br>', $bn_lines ), 'error', true );
				}
			}
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
					<?php // The PUBLIC handle (bn_profile_slug ?: user_nicename), never user_login: a login is a credential, and the two differ whenever it held a space, a dot or an email — so this showed the owner a handle that does not work when typed. ?>
					<span class="bn-hero-username">@<?php echo esc_html( \BuddyNext\Core\PageRouter::member_handle( (int) $wp_user->ID ) ); ?></span>
					<span class="bn-hero-sep" aria-hidden="true">&middot;</span>
					<span class="bn-hero-email"><?php echo esc_html( $wp_user->user_email ); ?></span>
					<span class="bn-hero-sep" aria-hidden="true">&middot;</span>
					<?php MemberDisplay::render_role_badge( ( (array) $wp_user->roles )[0] ?? 'subscriber' ); ?>
				</div>
				<div class="bn-hero-stats">
					<?php
					$last_login     = (int) get_user_meta( $user_id, 'bn_last_login', true );
					$joined         = gmdate( 'M j, Y', strtotime( $wp_user->user_registered ) );
					$joined_iso     = gmdate( 'c', strtotime( $wp_user->user_registered ) );
					$last_login_iso = $last_login > 0 ? gmdate( 'c', $last_login ) : '';
					?>
					<span>
						<?php
						printf(
							/* translators: %s: formatted join date. */
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
								/* translators: %s: formatted last-login time, or the word for Never. */
								esc_html__( 'Last login: %s', 'buddynext' ),
								'<time datetime="' . esc_attr( $last_login_iso ) . '">' . esc_html( MemberDisplay::human_time_diff_short( $last_login ) ) . '</time>'
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %s: formatted last-login time, or the word for Never. */
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
				<?php
				// Verify control: only when the feature is on AND this member is not
				// already verified. A button that reports success without changing
				// anything is worse than no button - and "Verified" as a state the
				// admin can read is the other half of what they came here for.
				$bn_verify_on       = buddynext_feature_enabled( 'verification' );
				$bn_member_verified = (bool) get_user_meta( $user_id, 'buddynext_email_verified', true );
				?>
				<?php if ( $bn_verify_on && $bn_member_verified ) : ?>
					<span class="bn-badge" data-variant="success">
						<?php esc_html_e( 'Email verified', 'buddynext' ); ?>
					</span>
				<?php elseif ( $bn_verify_on ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_verify_member">
						<input type="hidden" name="user_id" value="<?php echo absint( $user_id ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'saved', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); ?>">
						<?php wp_nonce_field( 'bn_verify_member' ); ?>
						<button type="submit" class="bn-btn" data-variant="secondary" data-size="sm">
							<?php esc_html_e( 'Mark email verified', 'buddynext' ); ?>
						</button>
					</form>
				<?php endif; ?>

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
							<p class="bn-edit-remove-note" id="bn-remove-avatar-note" role="status" hidden>
								<?php esc_html_e( 'This profile photo will be removed when you save.', 'buddynext' ); ?>
							</p>
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
				$existing_cover = ( new \BuddyNext\Profile\AvatarService() )->get_cover_url( (int) $user_id );
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
							<p class="bn-edit-remove-note" id="bn-remove-cover-note" role="status" hidden>
								<?php esc_html_e( 'This cover photo will be removed when you save.', 'buddynext' ); ?>
							</p>
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

				<?php
				/**
				 * Fires inside the Account tab of the edit-member form.
				 *
				 * Renders member-level administrative sections — member type, membership,
				 * labels — beside Role and Email, which are the same kind of fact about
				 * the member rather than profile content.
				 *
				 * This deliberately sits INSIDE the Account panel. It previously fired
				 * after the group loop and therefore outside every tab panel, so the
				 * sections stayed on screen whichever tab was open and read as though they
				 * belonged to Work Experience, Education, Skills and every other field
				 * group in turn.
				 *
				 * @param int      $user_id User ID being edited.
				 * @param \WP_User $wp_user WP_User object.
				 */
				do_action( 'buddynext_edit_member_sections', $user_id, $wp_user );
				?>
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
									// Defensive: entries are packed field-array lists (per-entry
									// privacy travels in the group's parallel entry_visibility
									// array). Skip anything that isn't a field array so the
									// array-typed renderer never receives a stray element. Mirrors
									// the guard in profile/edit.php and profile/view.php.
									if ( ! is_array( $entry_field ) || ! isset( $entry_field['field_key'] ) ) {
										continue;
									}
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


			<div class="bn-save-bar">
				<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save Profile', 'buddynext' ); ?></button>
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
		$key   = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
		$label = (string) ( $field['label'] ?? $key );
		$type  = sanitize_key( (string) ( $field['type'] ?? 'text' ) );

		// value_raw, not value: view_value() reduces a date to its display form
		// ("36 years old", "1990") as it enters the payload, so an edit input reading
		// `value` prefills with prose or nothing. value_raw is the real stored date and
		// is owner-only - which works here because render_edit_member_view() asks for
		// the member's OWN view (get_profile( $user_id, $user_id )). Same pattern as
		// templates/profile/edit.php.
		$raw_val = $field['value_raw'] ?? ( $field['value'] ?? '' );

		// A boolean's control is a checkbox carrying its own label; printing the row
		// label beside it would say the same thing twice. Every other type takes the
		// row label, with `for` pointing at whatever id the renderer actually used -
		// asked for, not re-derived, because the group types render a <fieldset> with
		// no element carrying that id at all.
		$self_labelling = 'boolean' === $type;
		$label_for      = \BuddyNext\Profile\FieldType::has_labelable_control( $type )
			? ' for="' . esc_attr( \BuddyNext\Profile\FieldType::input_id( $key ) ) . '"'
			: '';
		?>
<div class="bn-field-row">
	<div class="bn-label">
		<?php if ( ! $self_labelling ) : ?>
			<label<?php echo $label_for; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>><?php echo esc_html( $label ); ?></label>
		<?php endif; ?>
	</div>
	<div class="bn-control">
		<?php
		// One renderer for every type, the same one the member's own editor calls.
		// Returns markup it has escaped itself.
		echo \BuddyNext\Profile\FieldType::render_input( $field, $raw_val, $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
		?>
	</div>
</div>
		<?php
	}

	/**
	 * Render an editable input for a repeater entry field.
	 *
	 * Input name follows the shape: group_key[entry_index][field_key].
	 *
	 * @param string               $group_key The parent group's group_key.
	 * @param int                  $entry_idx Zero-based entry index.
	 * @param array<string, mixed> $field     Field data: field_key, label, type, value.
	 * @return void
	 */
	private function render_repeater_field_input( string $group_key, int $entry_idx, array $field ): void {
		$this->render_repeater_row( $group_key, (string) absint( $entry_idx ), $field );
	}

	/**
	 * Render one repeater sub-field row.
	 *
	 * Shared by the saved entries and by the blank `<template>` a new entry is
	 * cloned from. Those were two hand-written copies of the same markup, which is
	 * how they came to disagree: the type list they each understood was small
	 * (textarea and url), so a `date` sub-field and a `boolean` sub-field both fell
	 * through to a plain text box, and a fix applied to one copy would not have
	 * reached the other. A row added by the admin and a row already saved must be
	 * the same control.
	 *
	 * @param string               $group_key The parent group's group_key.
	 * @param string               $entry_idx Entry index, or the literal `__idx__`
	 *                                        placeholder for the blank template.
	 * @param array<string, mixed> $field     Field data: field_key, label, type, value.
	 * @param bool                 $blank     Render with no value (template row).
	 * @return void
	 */
	private function render_repeater_row( string $group_key, string $entry_idx, array $field, bool $blank = false ): void {
		$key   = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
		$label = (string) ( $field['label'] ?? $key );
		$type  = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
		// See render_flat_field_input() - repeater date sub-fields reduce identically.
		$value = $blank ? '' : ( $field['value_raw'] ?? ( $field['value'] ?? '' ) );
		$name  = $group_key . '[' . $entry_idx . '][' . $key . ']';

		$self_labelling = 'boolean' === $type;
		$label_for      = \BuddyNext\Profile\FieldType::has_labelable_control( $type )
			? ' for="' . esc_attr( \BuddyNext\Profile\FieldType::input_id( $name ) ) . '"'
			: '';
		?>
<div class="bn-field">
		<?php if ( ! $self_labelling ) : ?>
	<label<?php echo $label_for; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>><?php echo esc_html( $label ); ?></label>
	<?php endif; ?>
		<?php
		// Escaped by the renderer, same contract as the flat branch.
		echo \BuddyNext\Profile\FieldType::render_input( $field, $value, $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
		?>
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
		$this->render_repeater_row( $group_key, '__idx__', $field, true );
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
