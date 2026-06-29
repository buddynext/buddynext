<?php
/**
 * BuddyNext template part: space-settings-panel-moderation.
 *
 * Renders the "Moderation" panel — self-contained form with its own nonce.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space               Required. Space row.
 * @var array  $moderation_settings Required. Bundle:
 *   - `banned_words`          (string)
 *   - `space_id`              (int)
 *   - `space_url`             (string) Cancel-link URL.
 * @var array  $classes             Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_moderation_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_moderation_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_moderation_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_moderation_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'               => isset( $space ) ? $space : null,
	'moderation_settings' => isset( $moderation_settings ) && is_array( $moderation_settings ) ? $moderation_settings : array(),
	'classes'             => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_moderation_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}

$bn_settings     = (array) $args['moderation_settings'];
$bn_space_id     = isset( $bn_settings['space_id'] ) ? (int) $bn_settings['space_id'] : 0;
$bn_space_url    = isset( $bn_settings['space_url'] ) ? (string) $bn_settings['space_url'] : '';
$bn_banned_words = isset( $bn_settings['banned_words'] ) ? (string) $bn_settings['banned_words'] : '';

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_moderation_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_space_settings_panel_moderation_before', $args );
?>
<form method="post" action="" class="bn-space-settings__form" data-wp-on--input="actions.savebarMarkDirty" data-wp-on--change="actions.savebarMarkDirty">
	<?php wp_nonce_field( 'bn_space_moderation_' . $bn_space_id, 'bn_space_moderation_nonce' ); ?>

	<div class="<?php echo esc_attr( $bn_class ); ?>">
		<header class="bn-space-settings__panel-head">
			<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Moderation', 'buddynext' ); ?></h2>
			<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Control what members can post in this space.', 'buddynext' ); ?></p>
		</header>

		<div class="bn-space-settings__field">
			<label for="bn_banned_words"><?php esc_html_e( 'Banned words', 'buddynext' ); ?></label>
			<textarea
				id="bn_banned_words"
				name="banned_words"
				class="bn-textarea"
				rows="6"
				placeholder="<?php esc_attr_e( 'One word or phrase per line', 'buddynext' ); ?>"
			><?php echo esc_textarea( $bn_banned_words ); ?></textarea>
			<p class="bn-space-settings__hint">
				<?php esc_html_e( 'Posts containing these words will be held for review. One word or phrase per line.', 'buddynext' ); ?>
			</p>
		</div>
	</div>

	<?php // Save is handled by the shared sticky save bar in templates/spaces/settings.php. ?>
</form>
<?php
do_action( 'buddynext_part_space_settings_panel_moderation_after', $args );
