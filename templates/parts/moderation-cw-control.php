<?php
/**
 * Shared moderator content-warning control (Interactivity surfaces).
 *
 * Used by every front-end moderation surface that renders a report row - the
 * community-admin panel (templates/community-admin.php) and the space-level
 * moderation panel (templates/spaces/moderation.php). Both drive the same
 * buddynext/moderation Interactivity store, whose setCwType / setContentWarning
 * / clearContentWarning actions read objectId + cwType from the ROW's
 * data-wp-context. So each caller only has to (a) put objectId / objectType /
 * cwType / cwHasWarning on the row context and (b) include this part inside a
 * post report row. wp-admin uses its own form-POST control (no Interactivity),
 * so it is not routed through here.
 *
 * One control, one place: the four surfaces share this markup instead of each
 * carrying its own copy, so a change to the picker lands everywhere at once.
 *
 * @package BuddyNext
 *
 * @var array $args {
 *     @type string $cw_type Currently-applied warning type (defaults 'nsfw').
 *     @type bool   $cw_has  Whether a warning is already applied.
 * }
 */

defined( 'ABSPATH' ) || exit;

$bn_cw_type = (string) ( $args['cw_type'] ?? 'nsfw' );
$bn_cw_has  = (bool) ( $args['cw_has'] ?? false );
$bn_cw_opts = array(
	'nsfw'     => __( 'NSFW', 'buddynext' ),
	'spoilers' => __( 'Spoilers', 'buddynext' ),
	'violence' => __( 'Violence', 'buddynext' ),
	'language' => __( 'Strong language', 'buddynext' ),
);
?>
<select class="bn-cw-control__type" aria-label="<?php esc_attr_e( 'Content warning type', 'buddynext' ); ?>" data-wp-on--change="actions.setCwType">
	<?php foreach ( $bn_cw_opts as $bn_cw_key => $bn_cw_label ) : ?>
		<option value="<?php echo esc_attr( $bn_cw_key ); ?>" <?php selected( $bn_cw_type, $bn_cw_key ); ?>><?php echo esc_html( $bn_cw_label ); ?></option>
	<?php endforeach; ?>
</select>
<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-wp-on--click="actions.setContentWarning">
	<?php echo $bn_cw_has ? esc_html__( 'Update warning', 'buddynext' ) : esc_html__( 'Add warning', 'buddynext' ); ?>
</button>
<?php if ( $bn_cw_has ) : ?>
<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.clearContentWarning">
	<?php esc_html_e( 'Clear warning', 'buddynext' ); ?>
</button>
<?php endif; ?>
