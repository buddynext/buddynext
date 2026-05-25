<?php
/**
 * BuddyNext template part: space-settings-panel-notifications.
 *
 * Renders the "Notifications" panel — self-contained form with its own nonce.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space                  Required. Space row.
 * @var array  $notification_settings  Required. Bundle:
 *   - `default_notification_pref` (string) 'all'|'mentions'|'none'
 *   - `space_id`                  (int)
 *   - `space_url`                 (string) Cancel-link URL.
 * @var array  $classes                Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_notifications_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_notifications_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_notifications_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_notifications_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'                 => isset( $space ) ? $space : null,
	'notification_settings' => isset( $notification_settings ) && is_array( $notification_settings ) ? $notification_settings : array(),
	'classes'               => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_notifications_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}

$bn_settings                  = (array) $args['notification_settings'];
$bn_space_id                  = isset( $bn_settings['space_id'] ) ? (int) $bn_settings['space_id'] : 0;
$bn_space_url                 = isset( $bn_settings['space_url'] ) ? (string) $bn_settings['space_url'] : '';
$bn_default_notification_pref = isset( $bn_settings['default_notification_pref'] ) ? (string) $bn_settings['default_notification_pref'] : 'all';

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_notifications_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_space_settings_panel_notifications_before', $args );
?>
<form method="post" action="" class="bn-space-settings__form">
	<?php wp_nonce_field( 'bn_space_notifications_' . $bn_space_id, 'bn_space_notifications_nonce' ); ?>

	<div class="<?php echo esc_attr( $bn_class ); ?>">
		<header class="bn-space-settings__panel-head">
			<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Notifications', 'buddynext' ); ?></h2>
			<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Default notification setting applied when a new member joins this space.', 'buddynext' ); ?></p>
		</header>

		<div class="bn-space-settings__field">
			<label for="bn_default_notification_pref"><?php esc_html_e( 'Default notification preference for new members', 'buddynext' ); ?></label>
			<select
				id="bn_default_notification_pref"
				name="default_notification_pref"
				class="bn-select"
			>
				<option value="all" <?php selected( $bn_default_notification_pref, 'all' ); ?>>
					<?php esc_html_e( 'All activity', 'buddynext' ); ?>
				</option>
				<option value="mentions" <?php selected( $bn_default_notification_pref, 'mentions' ); ?>>
					<?php esc_html_e( 'Mentions only', 'buddynext' ); ?>
				</option>
				<option value="none" <?php selected( $bn_default_notification_pref, 'none' ); ?>>
					<?php esc_html_e( 'None', 'buddynext' ); ?>
				</option>
			</select>
			<p class="bn-space-settings__hint">
				<?php esc_html_e( 'Individual members can override this in their own notification settings.', 'buddynext' ); ?>
			</p>
		</div>
	</div>

	<div class="bn-space-settings__save-row">
		<a href="<?php echo esc_url( $bn_space_url ); ?>" class="bn-btn" data-variant="ghost" data-size="md">
			<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
		</a>
		<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
			<?php esc_html_e( 'Save changes', 'buddynext' ); ?>
		</button>
	</div>
</form>
<?php
do_action( 'buddynext_part_space_settings_panel_notifications_after', $args );
