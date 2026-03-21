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

// Possible values: null | 'connected' | 'pending_sent' | 'pending_received'.
$bn_status = buddynext_service( 'connections' )->status( $viewer_id, $user_id );

$label = '';
$class = 'bn-btn--primary';

switch ( $bn_status ) {
	case 'connected':
		$label = __( 'Connected', 'buddynext' );
		$class = 'bn-btn--secondary bn-connected';
		break;
	case 'pending_sent':
		$label = __( 'Request sent', 'buddynext' );
		$class = 'bn-btn--secondary bn-pending';
		break;
	case 'pending_received':
		$label = __( 'Accept request', 'buddynext' );
		$class = 'bn-btn--primary';
		break;
	default:
		$label = __( 'Connect', 'buddynext' );
		break;
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
