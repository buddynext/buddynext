<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Settings → Roles & Capabilities.
 *
 * BuddyNext gates every feature through PermissionService, which resolves a
 * capability against a required community role (member < moderator < admin).
 * That map was hardcoded, so site owners could not, say, restrict posting to
 * moderators or let all members create spaces. This tab makes the map editable:
 * each capability gets a "minimum role" selector, and the choices are saved to
 * the `bn_role_map_overrides` option which Plugin.php layers onto the defaults
 * via the native `buddynext_role_map` filter — so the change takes effect
 * everywhere (front-end + REST), not just in wp-admin.
 *
 * Site administrators (manage_options) always bypass these gates, so "Off"
 * means "admins only", never "nobody".
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use BuddyNext\Core\PermissionService;

/**
 * Renders the Roles & Capabilities matrix and saves overrides.
 */
class RolesTab {

	/**
	 * Option holding the capability → required-role overrides.
	 */
	private const OPTION = 'bn_role_map_overrides';

	/**
	 * Editable capabilities, grouped, with friendly labels. Space-scoped and
	 * always-public capabilities are intentionally omitted (they are resolved
	 * contextually, not by the global role map).
	 *
	 * @var array<string,array<string,string>>
	 */
	private const CATALOG = array(
		'Posts & activity' => array(
			'buddynext-feed/create-post'     => 'Create posts',
			'buddynext-feed/schedule-post'   => 'Schedule posts',
			'buddynext-feed/pin-post'        => 'Pin posts',
			'buddynext-feed/delete-any-post' => "Delete anyone's post",
		),
		// 'buddynext-spaces/manage-settings' and '…/delete' are intentionally
		// omitted: they are inherently owner-scoped (SpaceService::update()/
		// delete() gate on the space owner_id and never consult the role map),
		// so exposing them here produced dead toggles that saved but did nothing.
		'Spaces'           => array(
			'buddynext-spaces/create'   => 'Create spaces',
			'buddynext-spaces/join'     => 'Join spaces',
			'buddynext-spaces/post'     => 'Post in spaces',
			'buddynext-spaces/moderate' => 'Moderate spaces',
		),
		'Connections'      => array(
			'buddynext-connections/follow'  => 'Follow members',
			'buddynext-connections/connect' => 'Send connection requests',
		),
		'Profiles'         => array(
			'buddynext-profile/edit-any' => "Edit anyone's profile",
		),
		'Moderation'       => array(
			'buddynext-moderation/report'       => 'Report content',
			'buddynext-moderation/review-queue' => 'Review the report queue',
			'buddynext-moderation/issue-strike' => 'Issue strikes',
			'buddynext-moderation/suspend-user' => 'Suspend members',
		),
	);

	/**
	 * Selectable minimum-role options (value => label). '' = off (admins only).
	 *
	 * @var array<string,string>
	 */
	private const ROLE_CHOICES = array(
		'member'    => 'All members',
		'moderator' => 'Moderators & up',
		'admin'     => 'Admins only',
		''          => 'Off (site admins only)',
	);

	/**
	 * Register hooks + the tab.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_roles_save', array( $this, 'handle_save' ) );

		AdminHub::register_tab(
			'settings',
			'roles',
			__( 'Roles & Capabilities', 'buddynext' ),
			array( $this, 'render_page' ),
			array( 'group' => __( 'Advanced', 'buddynext' ) )
		);
	}

	/**
	 * Render the matrix.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_roles_flag = isset( $_GET['bn_roles'] ) ? sanitize_key( wp_unslash( $_GET['bn_roles'] ) ) : '';
		if ( 'error' === $bn_roles_flag ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not save role permissions. Please try again.', 'buddynext' ) . '</p></div>';
		} elseif ( '' !== $bn_roles_flag ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Role permissions saved.', 'buddynext' ) . '</p></div>';
		}

		$current = PermissionService::get_role_map();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-admin-hub__form-bare">
			<input type="hidden" name="action" value="bn_roles_save">
			<?php wp_nonce_field( 'bn_roles_save' ); ?>

			<div class="bn-settings-section">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Roles & Capabilities', 'buddynext' ); ?></span>
				</div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc">
						<?php esc_html_e( 'Choose the minimum community role required for each action. Roles are ranked Member → Moderator → Admin; a higher role inherits everything below it. Site administrators always have full access.', 'buddynext' ); ?>
					</p>

					<?php foreach ( self::CATALOG as $group => $caps ) : ?>
						<h3 class="bn-roles-group"><?php echo esc_html( $group ); ?></h3>
						<table class="widefat striped bn-roles-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Action', 'buddynext' ); ?></th>
									<th><?php esc_html_e( 'Minimum role', 'buddynext' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $caps as $cap => $label ) : ?>
									<?php $value = array_key_exists( $cap, $current ) ? (string) ( $current[ $cap ] ?? '' ) : 'member'; ?>
									<tr>
										<td><?php echo esc_html( $label ); ?></td>
										<td>
											<select name="cap[<?php echo esc_attr( $cap ); ?>]" class="bn-select">
												<?php foreach ( self::ROLE_CHOICES as $rv => $rl ) : ?>
													<option value="<?php echo esc_attr( $rv ); ?>" <?php selected( $value, $rv ); ?>>
														<?php echo esc_html( $rl ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endforeach; ?>

					<p>
						<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save permissions', 'buddynext' ); ?></button>
						<button type="submit" name="bn_reset" value="1" class="bn-btn" data-variant="secondary"
							data-bn-confirm="<?php esc_attr_e( 'Reset every capability to its default role?', 'buddynext' ); ?>"
							data-bn-confirm-tone="warning">
							<?php esc_html_e( 'Reset to defaults', 'buddynext' ); ?>
						</button>
					</p>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Persist the submitted matrix to the overrides option.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_roles_save' );

		// Reset wipes the override option entirely → defaults take over.
		if ( ! empty( $_POST['bn_reset'] ) ) {
			delete_option( self::OPTION );
			$this->redirect_back();
		}

		$valid_caps = array();
		foreach ( self::CATALOG as $caps ) {
			$valid_caps = array_merge( $valid_caps, array_keys( $caps ) );
		}
		$valid_roles = array( 'member', 'moderator', 'admin' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$submitted = isset( $_POST['cap'] ) && is_array( $_POST['cap'] ) ? wp_unslash( $_POST['cap'] ) : array();

		$overrides = array();
		foreach ( $submitted as $cap => $role ) {
			$cap = (string) $cap;
			if ( ! in_array( $cap, $valid_caps, true ) ) {
				continue; // Never write a capability outside the catalog.
			}
			$role = sanitize_key( (string) $role );
			// Empty/unknown role → null = "off (admins only)".
			$overrides[ $cap ] = in_array( $role, $valid_roles, true ) ? $role : null;
		}

		// Only report success when the write actually persisted. update_option()
		// returns false both on a genuine DB failure AND on a harmless no-op
		// (resubmitting unchanged values), so treat "already equal" as success and
		// flag only a real write failure.
		$current = get_option( self::OPTION, array() );
		$ok      = ( $current === $overrides ) || update_option( self::OPTION, $overrides, false );
		$this->redirect_back( $ok );
	}

	/**
	 * Redirect back to the Roles tab with a saved flag.
	 *
	 * @return void
	 */
	private function redirect_back( bool $ok = true ): void {
		// Resolve through the canonical placement map: the roles tab is registered
		// under the 'settings' section but relocated to the Members page
		// (page=buddynext-members), so a hardcoded page=buddynext landed on the
		// General tab. tab_url() follows the remap to the page the tab renders on.
		wp_safe_redirect( AdminHub::tab_url( 'settings', 'roles', array( 'bn_roles' => $ok ? '1' : 'error' ) ) );
		exit;
	}
}
