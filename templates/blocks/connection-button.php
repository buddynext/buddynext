<?php
/**
 * Block template: Connection Button
 *
 * Renders a context-aware connection action button. States handled:
 *   null            → "Connect"           (primary CTA)
 *   pending-sent    → "Pending"           (viewer sent request, can withdraw)
 *   pending-received → "Accept / Decline" (viewer received request)
 *   accepted        → "Connected"         (mutual connection)
 *   blocked         → hidden / inert      (either party blocked the other)
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
	// Determine direction by checking who sent the request.
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

$nonce        = wp_create_nonce( 'wp_rest' );
$context_json = (string) wp_json_encode(
	array(
		'userId'  => $user_id,
		'status'  => $ctx_status,
		'nonce'   => $nonce,
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
			class="bn-btn bn-btn--sm bn-accept"
			data-wp-on--click="actions.acceptRequest"
			data-action="bn-accept-connect"
			data-user-id="<?php echo absint( $user_id ); ?>"
		>
			<?php esc_html_e( 'Accept', 'buddynext' ); ?>
		</button>
		<button
			class="bn-btn bn-btn--sm bn-decline"
			data-wp-on--click="actions.declineRequest"
			data-action="bn-decline-connect"
			data-user-id="<?php echo absint( $user_id ); ?>"
		>
			<?php esc_html_e( 'Decline', 'buddynext' ); ?>
		</button>
	</span>

	<button
		class="bn-btn bn-btn--sm bn-btn--secondary bn-connected"
		data-wp-on--click="actions.disconnect"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-bind--hidden="!state.showConnected"
		<?php echo 'accepted' === $bn_status ? '' : 'hidden'; ?>
	>
		<?php esc_html_e( 'Connected', 'buddynext' ); ?>
	</button>

	<button
		class="bn-btn bn-btn--sm bn-btn--secondary bn-pending"
		data-wp-on--click="actions.withdrawRequest"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-bind--hidden="!state.showPending"
		<?php echo $pending_sent ? '' : 'hidden'; ?>
	>
		<?php esc_html_e( 'Pending', 'buddynext' ); ?>
	</button>

	<button
		class="bn-btn bn-btn--sm bn-btn--primary"
		data-wp-on--click="actions.sendRequest"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-bind--hidden="!state.showConnect"
		<?php echo ( '' === $ctx_status ) ? '' : 'hidden'; ?>
	>
		<?php esc_html_e( 'Connect', 'buddynext' ); ?>
	</button>
</div>
