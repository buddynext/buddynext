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
 * buddynext_get_template() imports each passed key as its own variable; there
 * is no $args array (reading one silently fell back to the defaults, so a post
 * that already carried a warning showed "Add warning" and no Clear).
 *
 * @var string $cw_type Currently-applied warning type (defaults 'nsfw').
 * @var bool   $cw_has  Whether a warning is already applied.
 */

defined( 'ABSPATH' ) || exit;

$bn_cw_type = isset( $cw_type ) && '' !== (string) $cw_type ? (string) $cw_type : 'nsfw';
$bn_cw_has  = ! empty( $cw_has );
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
<?php
// The row context's cwHasWarning is the live truth: the store flips it after
// Add, Update or Clear succeeds. Bind to it rather than deciding once on the
// server, or the control keeps offering the old action until a reload.
?>
<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-wp-on--click="actions.setContentWarning">
	<span data-wp-bind--hidden="context.cwHasWarning"<?php echo $bn_cw_has ? ' hidden' : ''; ?>><?php esc_html_e( 'Add warning', 'buddynext' ); ?></span>
	<span data-wp-bind--hidden="!context.cwHasWarning"<?php echo $bn_cw_has ? '' : ' hidden'; ?>><?php esc_html_e( 'Update warning', 'buddynext' ); ?></span>
</button>
<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.clearContentWarning" data-wp-bind--hidden="!context.cwHasWarning"<?php echo $bn_cw_has ? '' : ' hidden'; ?>>
	<?php esc_html_e( 'Clear warning', 'buddynext' ); ?>
</button>
