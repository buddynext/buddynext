<?php
/**
 * Block template: Connection Button (v2 design system).
 *
 * Renders a context-aware connection action button using the v2 attribute API
 * (.bn-btn[data-variant][data-size]). Five visible states are handled:
 *
 *   not-connected     → primary    "Connect"          (sends request)
 *   pending-sent      → ghost      "Cancel request"   (aria-pressed=true; withdraws)
 *   pending-received  → primary    "Accept" +
 *                       ghost      "Decline"          (cluster)
 *   accepted          → secondary  "Connected"        (aria-pressed=true; disconnects)
 *   blocked           → not rendered                  (hard block guard)
 *
 * Variables:
 *   int $user_id WordPress user ID to connect / disconnect.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$user_id   = $user_id ?? 0;
$viewer_id = get_current_user_id();

if ( ! $user_id || ! $viewer_id || $viewer_id === $user_id ) {
	return;
}

// Hard block guard — render nothing if either party has blocked the other.
if ( buddynext_service( 'blocks' )->is_blocked( $viewer_id, $user_id ) ) {
	return;
}

// Resolve direction-aware state.
// ConnectionService::status() returns null, 'pending', 'accepted', 'declined', or 'withdrawn'.
$bn_status    = buddynext_service( 'connections' )->status( $viewer_id, $user_id );
$pending_sent = false;
$pending_recv = false;

if ( 'pending' === $bn_status ) {
	$sent_ids = buddynext_service( 'connections' )->pending_sent( $viewer_id );
	if ( in_array( $user_id, $sent_ids, true ) ) {
		$pending_sent = true;
	} else {
		$pending_recv = true;
	}
}

// Build direction-aware status for the Interactivity API context.
if ( $pending_sent ) {
	$ctx_status = 'pending-sent';
} elseif ( $pending_recv ) {
	$ctx_status = 'pending-received';
} elseif ( 'accepted' === $bn_status ) {
	$ctx_status = 'accepted';
} else {
	$ctx_status = '';
}

$context_json = (string) wp_json_encode(
	array(
		'userId'  => $user_id,
		'status'  => $ctx_status,
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'restUrl' => rest_url( 'buddynext/v1' ),
	)
);
?>
<div
	class="bn-block-connection-button"
	data-wp-interactive="buddynext/connection-button"
	data-user-id="<?php echo absint( $user_id ); ?>"
	data-wp-context="<?php echo esc_attr( $context_json ); ?>"
>
	<span
		class="bn-connect-received"
		data-wp-bind--hidden="!state.showAcceptDecline"
		<?php echo $pending_recv ? '' : 'hidden'; ?>
	>
		<button
			type="button"
			class="bn-btn bn-accept"
			data-variant="primary"
			data-size="sm"
			data-action="bn-accept-connect"
			data-user-id="<?php echo absint( $user_id ); ?>"
			data-wp-on--click="actions.acceptRequest"
			aria-label="<?php esc_attr_e( 'Accept connection request', 'buddynext' ); ?>"
		>
			<?php esc_html_e( 'Accept', 'buddynext' ); ?>
		</button>
		<button
			type="button"
			class="bn-btn bn-decline"
			data-variant="ghost"
			data-size="sm"
			data-action="bn-decline-connect"
			data-user-id="<?php echo absint( $user_id ); ?>"
			data-wp-on--click="actions.declineRequest"
			aria-label="<?php esc_attr_e( 'Decline connection request', 'buddynext' ); ?>"
		>
			<?php esc_html_e( 'Decline', 'buddynext' ); ?>
		</button>
	</span>

	<button
		type="button"
		class="bn-btn bn-connected"
		data-variant="secondary"
		data-size="sm"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-on--click="actions.disconnect"
		data-wp-bind--hidden="!state.showConnected"
		aria-pressed="true"
		aria-label="<?php esc_attr_e( 'Disconnect from user', 'buddynext' ); ?>"
		<?php echo 'accepted' === $bn_status ? '' : 'hidden'; ?>
	>
		<?php esc_html_e( 'Connected', 'buddynext' ); ?>
	</button>

	<button
		type="button"
		class="bn-btn bn-pending"
		data-variant="ghost"
		data-size="sm"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-on--click="actions.withdrawRequest"
		data-wp-bind--hidden="!state.showPending"
		aria-pressed="true"
		aria-label="<?php esc_attr_e( 'Cancel connection request', 'buddynext' ); ?>"
		<?php echo $pending_sent ? '' : 'hidden'; ?>
	>
		<?php esc_html_e( 'Cancel request', 'buddynext' ); ?>
	</button>

	<button
		type="button"
		class="bn-btn bn-connect"
		data-variant="primary"
		data-size="sm"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-on--click="actions.sendRequest"
		data-wp-bind--hidden="!state.showConnect"
		aria-pressed="false"
		aria-label="<?php esc_attr_e( 'Send connection request', 'buddynext' ); ?>"
		<?php echo ( '' === $ctx_status ) ? '' : 'hidden'; ?>
	>
		<?php esc_html_e( 'Connect', 'buddynext' ); ?>
	</button>
</div>
