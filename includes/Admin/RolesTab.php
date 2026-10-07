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
class RolesTab extends AdminPageBase {

	/**
	 * Option holding the capability → required-role overrides.
	 */
	private const OPTION = 'bn_role_map_overrides';

	/**
	 * Editable capabilities, grouped, with friendly labels. Lists exactly
	 * PermissionService::owner_facing_abilities(): the permission map decides
	 * what is editable, this adds the wording (RolesTabTest holds the two equal).
	 *
	 * @var array<string,array<string,string>>
	 */
	private static function catalog(): array {
		return array(
			__( 'Posts & activity', 'buddynext' ) => array(
				'buddynext-feed/create-post'     => __( 'Create posts', 'buddynext' ),
				'buddynext-comments/create'      => __( 'Comment on posts', 'buddynext' ),
				'buddynext-feed/interact'        => __( 'React, share, bookmark and vote', 'buddynext' ),
				'buddynext-feed/schedule-post'   => __( 'Schedule posts', 'buddynext' ),
				'buddynext-feed/pin-post'        => __( 'Pin posts', 'buddynext' ),
				'buddynext-feed/delete-any-post' => __( "Delete anyone's post", 'buddynext' ),
			),
			// 'buddynext-spaces/manage-settings' and '…/delete' are intentionally
			// omitted: they are inherently owner-scoped (SpaceService::update()/
			// delete() gate on the space owner_id and never consult the role map),
			// so exposing them here produced dead toggles that saved but did nothing.
			__( 'Spaces', 'buddynext' )           => array(
				'buddynext-spaces/create'   => __( 'Create spaces', 'buddynext' ),
				'buddynext-spaces/join'     => __( 'Join spaces', 'buddynext' ),
				'buddynext-spaces/post'     => __( 'Post in spaces', 'buddynext' ),
				'buddynext-spaces/moderate' => __( 'Moderate spaces', 'buddynext' ),
			),
			__( 'Connections', 'buddynext' )      => array(
				'buddynext-connections/follow'  => __( 'Follow members', 'buddynext' ),
				'buddynext-connections/connect' => __( 'Send connection requests', 'buddynext' ),
			),
			__( 'Profiles', 'buddynext' )         => array(
				'buddynext-profile/edit-any' => __( "Edit anyone's profile", 'buddynext' ),
			),
			__( 'Moderation', 'buddynext' )       => array(
				'buddynext-moderation/report'       => __( 'Report content', 'buddynext' ),
				'buddynext-moderation/review-queue' => __( 'Review the report queue', 'buddynext' ),
				'buddynext-moderation/dismiss'      => __( 'Resolve reports', 'buddynext' ),
				'buddynext-moderation/issue-strike' => __( 'Issue strikes', 'buddynext' ),
				'buddynext-moderation/suspend-user' => __( 'Suspend members', 'buddynext' ),
			),
		);
	}

	/**
	 * Selectable minimum-role options (value => label). '' = off (admins only).
	 *
	 * @return array<string,string>
	 */
	private static function role_choices(): array {
		return array(
			'member'    => __( 'All members', 'buddynext' ),
			'moderator' => __( 'Moderators & up', 'buddynext' ),
			'admin'     => __( 'Admins only', 'buddynext' ),
			''          => __( 'Off (site admins only)', 'buddynext' ),
		);
	}

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
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Roles & Capabilities', 'buddynext' );
	}

	/**
	 * Suppress the base chrome subtitle — the explanatory copy already lives
	 * inline as the section's own lead paragraph (S5: no card header, its
	 * title would only repeat the page H1).
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return '';
	}

	/**
	 * Render the matrix.
	 *
	 * @return void
	 */
	protected function render_content(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_roles_flag = isset( $_GET['bn_roles'] ) ? sanitize_key( wp_unslash( $_GET['bn_roles'] ) ) : '';
		if ( 'error' === $bn_roles_flag ) {
			self::render_notice( __( 'Could not save role permissions. Try again.', 'buddynext' ), 'error' );
		} elseif ( 'restored' === $bn_roles_flag ) {
			self::render_notice( __( 'Role permissions restored to their defaults.', 'buddynext' ), 'success' );
		} elseif ( '' !== $bn_roles_flag ) {
			self::render_notice( __( 'Role permissions saved.', 'buddynext' ), 'success' );
		}

		$current = PermissionService::get_role_map();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-admin-hub__form-bare">
			<input type="hidden" name="action" value="bn_roles_save">
			<?php wp_nonce_field( 'bn_roles_save' ); ?>

			<?php // S5: no card header — its title would only repeat the page H1 ("Roles & Capabilities"). ?>
			<div class="bn-settings-section">
				<div class="bn-ss-body">
					<p class="bn-av-section-desc">
						<?php esc_html_e( 'Choose the minimum community role required for each action. Roles are ranked Member → Moderator → Admin; a higher role inherits everything below it. Site administrators always have full access.', 'buddynext' ); ?>
					</p>

					<?php foreach ( self::catalog() as $group => $caps ) : ?>
						<h3 class="bn-roles-group"><?php echo esc_html( $group ); ?></h3>
						<table class="widefat bn-roles-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Action', 'buddynext' ); ?></th>
									<th><?php esc_html_e( 'Minimum role', 'buddynext' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $caps as $cap => $label ) : ?>
									<?php $value = self::role_for( $current, $cap ); ?>
									<tr>
										<td><?php echo esc_html( $label ); ?></td>
										<td>
											<?php
											// One select per capability row, the capability name living in
											// the cell beside it - so every one of them announced as the
											// same unnamed dropdown. Named with the capability it governs.
											?>
											<select name="cap[<?php echo esc_attr( $cap ); ?>]" class="bn-select"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: capability name. */ __( 'Minimum role for: %s', 'buddynext' ), (string) $label ) ); ?>">
												<?php foreach ( self::role_choices() as $rv => $rl ) : ?>
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
				</div>
			</div>

			<?php $this->render_save_bar( __( 'Save changes', 'buddynext' ) ); ?>
		</form>
		<?php
		// Restore defaults: the same form and confirm as every settings tab, listing
		// each capability a reset would change as "now X -> default Y".
		$changes = array();
		$labels  = self::role_choices();
		$after   = self::map_after_reset();
		foreach ( self::catalog() as $caps ) {
			foreach ( $caps as $cap => $label ) {
				$now = self::role_for( $current, $cap );
				$def = self::role_for( $after, $cap );
				if ( $now !== $def ) {
					$changes[] = array(
						'key'     => $cap,
						'label'   => $label,
						'current' => $labels[ $now ] ?? $now,
						'default' => $labels[ $def ] ?? $def,
					);
				}
			}
		}
		$total = array_sum( array_map( 'count', self::catalog() ) );
		$this->render_restore_form(
			array(
				'action'   => 'bn_roles_save',
				'bn_reset' => '1',
			),
			'bn_roles_save',
			$changes,
			$total - count( $changes ),
			__( 'Only the role permissions on this tab are reset.', 'buddynext' )
		);
	}

	/**
	 * The role a capability requires in a role map, as a role_choices() key.
	 * A capability missing from the map is open to all members; null is "off".
	 *
	 * @param array<string, string|null> $map Role map.
	 * @param string                     $cap Capability.
	 * @return string
	 */
	private static function role_for( array $map, string $cap ): string {
		return array_key_exists( $cap, $map ) ? (string) ( $map[ $cap ] ?? '' ) : 'member';
	}

	/**
	 * The role map as it will be once the overrides are deleted, which is exactly
	 * what Restore defaults does.
	 *
	 * @return array<string, string|null>
	 */
	private static function map_after_reset(): array {
		add_filter( 'pre_option_' . self::OPTION, '__return_empty_array' );
		$map = PermissionService::build_role_map();
		remove_filter( 'pre_option_' . self::OPTION, '__return_empty_array' );
		return $map;
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
			$this->redirect_back( true, 'restored' );
		}

		$valid_caps = array();
		foreach ( self::catalog() as $caps ) {
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
	 * Redirect back to the Roles tab with a result flag.
	 *
	 * @param bool   $ok   Whether the write succeeded.
	 * @param string $flag bn_roles value on success: '1' saved, 'restored' reset.
	 * @return void
	 */
	private function redirect_back( bool $ok = true, string $flag = '1' ): void {
		// Resolve through the canonical placement map: the roles tab is registered
		// under the 'settings' section but relocated to the Members page
		// (page=buddynext-members), so a hardcoded page=buddynext landed on the
		// General tab. tab_url() follows the remap to the page the tab renders on.
		wp_safe_redirect( AdminHub::tab_url( 'settings', 'roles', array( 'bn_roles' => $ok ? $flag : 'error' ) ) );
		exit;
	}
}
