<?php
/**
 * Block template: Connection Button
 *
 * Variables:
 *   int $user_id WordPress user ID to connect/disconnect
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$user_id   = $user_id ?? 0;
$viewer_id = get_current_user_id();

if ( ! $user_id || ! $viewer_id || $viewer_id === $user_id ) {
	return;
}

// ConnectionService::status() returns null, pending, accepted, declined, or withdrawn.
$bn_status = buddynext_service( 'connections' )->status( $viewer_id, $user_id );

$label = '';
$class = 'bn-btn--primary';

if ( 'accepted' === $bn_status ) {
	$label = __( 'Connected', 'buddynext' );
	$class = 'bn-btn--secondary bn-connected';
} elseif ( 'pending' === $bn_status ) {
	$label = __( 'Pending', 'buddynext' );
	$class = 'bn-btn--secondary bn-pending';
} else {
	$label = __( 'Connect', 'buddynext' );
}
?>
<div class="bn-block-connection-button" data-user-id="<?php echo absint( $user_id ); ?>">
	<button class="bn-btn bn-btn--sm <?php echo esc_attr( $class ); ?>"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-status="<?php echo esc_attr( $bn_status ?? '' ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'buddynext_connect_' . $user_id ) ); ?>">
		<?php echo esc_html( $label ); ?>
	</button>
</div>
