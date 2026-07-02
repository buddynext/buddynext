<?php
/**
 * BuddyNext template part: space-settings-panel-permissions.
 *
 * Renders the "Permissions" panel inside the space settings shell. Self-
 * contained — emits its own `<form>`, nonce, and save row.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space                Required. Space row.
 * @var array  $permissions_settings Required. Bundle:
 *   - `who_can_post`          (string) 'members'|'mods'|'owner'
 *   - `who_can_invite`        (string) 'members'|'mods'|'owner'
 *   - `require_join_approval` (bool)
 *   - `space_id`              (int)
 *   - `space_url`             (string) Cancel-link URL.
 * @var array  $classes              Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_permissions_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_permissions_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_permissions_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_permissions_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'                => isset( $space ) ? $space : null,
	'permissions_settings' => isset( $permissions_settings ) && is_array( $permissions_settings ) ? $permissions_settings : array(),
	'classes'              => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_permissions_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}

$bn_settings              = (array) $args['permissions_settings'];
$bn_space_id              = isset( $bn_settings['space_id'] ) ? (int) $bn_settings['space_id'] : 0;
$bn_space_url             = isset( $bn_settings['space_url'] ) ? (string) $bn_settings['space_url'] : '';
$bn_who_can_post          = isset( $bn_settings['who_can_post'] ) ? (string) $bn_settings['who_can_post'] : 'members';
$bn_who_can_invite        = isset( $bn_settings['who_can_invite'] ) ? (string) $bn_settings['who_can_invite'] : 'mods';
$bn_require_join_approval = ! empty( $bn_settings['require_join_approval'] );
$bn_auto_join_on_signup   = ! empty( $bn_settings['auto_join_on_signup'] );
$bn_auto_join_types       = isset( $bn_settings['auto_join_member_types'] ) ? (array) $bn_settings['auto_join_member_types'] : array();
// Member types are resolved here (settings render only), never in the always-on
// field registration. Empty list = the filter UI is hidden (nothing to limit by).
$bn_member_types = buddynext_service( 'member_types' )->get_all();

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_permissions_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_space_settings_panel_permissions_before', $args );
?>
<form
	method="post"
	action=""
	class="bn-space-settings__form"
	data-bn-settings-permissions-form
	data-space-id="<?php echo esc_attr( (string) $bn_space_id ); ?>"
	data-wp-on--input="actions.savebarMarkDirty"
	data-wp-on--change="actions.savebarMarkDirty"
>
	<?php wp_nonce_field( 'bn_space_permissions_' . $bn_space_id, 'bn_space_permissions_nonce' ); ?>

	<div class="<?php echo esc_attr( $bn_class ); ?>">
		<header class="bn-space-settings__panel-head">
			<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Permissions', 'buddynext' ); ?></h2>
			<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Who can post, invite, and join this space.', 'buddynext' ); ?></p>
		</header>

		<div class="bn-space-settings__field">
			<label for="bn_who_can_post"><?php esc_html_e( 'Who can post', 'buddynext' ); ?></label>
			<select id="bn_who_can_post" name="who_can_post" class="bn-select">
				<option value="members" <?php selected( $bn_who_can_post, 'members' ); ?>>
					<?php esc_html_e( 'All members', 'buddynext' ); ?>
				</option>
				<option value="mods" <?php selected( $bn_who_can_post, 'mods' ); ?>>
					<?php esc_html_e( 'Moderators and owner only', 'buddynext' ); ?>
				</option>
				<option value="owner" <?php selected( $bn_who_can_post, 'owner' ); ?>>
					<?php esc_html_e( 'Owner only (announcements)', 'buddynext' ); ?>
				</option>
			</select>
		</div>

		<div class="bn-space-settings__field">
			<label for="bn_who_can_invite"><?php esc_html_e( 'Who can invite new members', 'buddynext' ); ?></label>
			<select id="bn_who_can_invite" name="who_can_invite" class="bn-select">
				<option value="members" <?php selected( $bn_who_can_invite, 'members' ); ?>>
					<?php esc_html_e( 'All members', 'buddynext' ); ?>
				</option>
				<option value="mods" <?php selected( $bn_who_can_invite, 'mods' ); ?>>
					<?php esc_html_e( 'Moderators and owner', 'buddynext' ); ?>
				</option>
				<option value="owner" <?php selected( $bn_who_can_invite, 'owner' ); ?>>
					<?php esc_html_e( 'Owner only', 'buddynext' ); ?>
				</option>
			</select>
		</div>

		<div class="bn-toggle-row">
			<div class="bn-toggle-row__copy">
				<div class="bn-toggle-row__label"><?php esc_html_e( 'Require approval to join', 'buddynext' ); ?></div>
				<div class="bn-toggle-row__desc"><?php esc_html_e( 'New join requests go to the owner/mod queue.', 'buddynext' ); ?></div>
			</div>
			<label class="bn-space-settings__toggle-shell">
				<input type="checkbox" class="bn-space-settings__toggle-input" name="require_join_approval" value="1" <?php checked( $bn_require_join_approval ); ?>>
				<span class="bn-toggle" aria-hidden="true"></span>
			</label>
		</div>

		<div class="bn-toggle-row">
			<div class="bn-toggle-row__copy">
				<div class="bn-toggle-row__label"><?php esc_html_e( 'Auto-join new members', 'buddynext' ); ?></div>
				<div class="bn-toggle-row__desc"><?php esc_html_e( 'New members are added to this space automatically. They can leave at any time.', 'buddynext' ); ?></div>
			</div>
			<label class="bn-space-settings__toggle-shell">
				<input type="checkbox" class="bn-space-settings__toggle-input" name="auto_join_on_signup" value="1" <?php checked( $bn_auto_join_on_signup ); ?>>
				<span class="bn-toggle" aria-hidden="true"></span>
			</label>
		</div>

		<?php // Sub-option of auto-join: limit it to member types. Only meaningful when the toggle above is on. ?>
		<?php if ( ! empty( $bn_member_types ) ) : ?>
			<div class="bn-space-settings__field bn-space-settings__field--sub">
				<label><?php esc_html_e( 'Limit auto-join to member types', 'buddynext' ); ?></label>
				<p class="bn-space-settings__hint"><?php esc_html_e( 'Leave all unchecked to auto-join every new member. Tick types to auto-join only members assigned that type. Only applies when auto-join is on.', 'buddynext' ); ?></p>
				<div class="bn-checkbox-grid">
					<?php foreach ( $bn_member_types as $bn_mt ) : ?>
						<?php $bn_mt_slug = isset( $bn_mt['slug'] ) ? (string) $bn_mt['slug'] : ''; ?>
						<?php
						if ( '' === $bn_mt_slug ) {
							continue; }
						?>
						<label class="bn-checkbox-row">
							<input type="checkbox" name="auto_join_member_types[]" value="<?php echo esc_attr( $bn_mt_slug ); ?>" <?php checked( in_array( $bn_mt_slug, $bn_auto_join_types, true ) ); ?>>
							<span><?php echo esc_html( isset( $bn_mt['name'] ) ? (string) $bn_mt['name'] : $bn_mt_slug ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

	</div>

	<?php // Save is handled by the shared sticky save bar in templates/spaces/settings.php. ?>
</form>
<?php
do_action( 'buddynext_part_space_settings_panel_permissions_after', $args );
