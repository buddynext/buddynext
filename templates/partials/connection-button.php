<?php
/**
 * Partial: Connection request / disconnect button.
 *
 * Renders a context-aware connection action wired to the
 * buddynext/connection-button WP Interactivity API store. Designed for
 * direct PHP include inside loops (member cards, profile widgets).
 *
 * Handled states:
 *   null             — "Connect"          (primary CTA)
 *   pending-sent     — "Pending"          (viewer sent request; click to withdraw)
 *   pending-received — "Accept / Decline" (viewer received a request)
 *   accepted         — "Connected"        (mutual; click to remove)
 *   blocked          — partial returns early, renders nothing
 *
 * Expected variables (set by the caller before including this file):
 *   int $user_id  ID of the user to connect / disconnect.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$user_id = isset( $user_id ) ? (int) $user_id : 0;
if ( $user_id <= 0 ) {
	return;
}

$viewer_id = get_current_user_id();
if ( ! $viewer_id || $viewer_id === $user_id ) {
	return;
}

// Block guard — render nothing if either party has blocked the other.
if ( buddynext_service( 'blocks' )->is_blocking_either( $viewer_id, $user_id ) ) {
	return;
}

$connections_svc = buddynext_service( 'connections' );

// Resolve direction-aware pending state.
// ConnectionService::status() returns null, 'pending', 'accepted', 'declined', or 'withdrawn'.
$bn_conn_status = $connections_svc->status( $viewer_id, $user_id );
$pending_sent   = false;
$pending_recv   = false;

if ( 'pending' === $bn_conn_status ) {
	// Determine direction: did the viewer send this request, or receive it?
	$sent_ids = $connections_svc->pending_sent( $viewer_id );
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
} elseif ( 'accepted' === $bn_conn_status ) {
	$ctx_status = 'accepted';
} else {
	$ctx_status = '';
}

// Honour the target's who_can_connect privacy: do not offer a fresh Connect
// request when they forbid it (nobody, or followers-and-viewer-isn't). Existing
// Pending/Connected/Accept-Decline states still render. Block + full preference
// logic live in PrivacyService::can_connect(); the server also re-checks on send.
$bn_can_connect = true;
$bn_privacy_svc = function_exists( 'buddynext_service' ) ? buddynext_service( 'privacy' ) : null;
if ( $bn_privacy_svc && method_exists( $bn_privacy_svc, 'can_connect' ) ) {
	$bn_can_connect = (bool) $bn_privacy_svc->can_connect( $viewer_id, $user_id );
}

$nonce = wp_create_nonce( 'wp_rest' );

// The connection store builds its toasts as "@" . targetName, so pass the
// @handle (user_nicename). Without it the store falls back to "#<id>" and
// toasts read "Connected with #8" instead of "Connected with @jane".
$bn_conn_user = get_userdata( $user_id );
$bn_conn_name = $bn_conn_user ? $bn_conn_user->user_nicename : '';

// Build the WP Interactivity API context object (esc_attr-escaped JSON string).
$context_attr = esc_attr(
	(string) wp_json_encode(
		array(
			'userId'     => $user_id,
			'targetName' => $bn_conn_name,
			'status'     => $ctx_status,
			'nonce'      => $nonce,
			'restUrl'    => rest_url( 'buddynext/v1' ),
		)
	)
);
?>
<div
	class="bn-connection-btn-wrap"
	data-wp-interactive="buddynext/connection-button"
	data-user-id="<?php echo absint( $user_id ); ?>"
	data-wp-context="<?php echo $context_attr; // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() applied to $context_attr. ?>"
	data-wp-bind--data-state="state.btnState"
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
			data-wp-on--click="actions.acceptRequest"
			data-wp-bind--hidden="!state.showAcceptDecline"
			data-action="bn-accept-connect"
			data-user-id="<?php echo absint( $user_id ); ?>"
			<?php echo $pending_recv ? '' : 'hidden'; ?>
		>
			<?php esc_html_e( 'Accept', 'buddynext' ); ?>
		</button>
		<button
			type="button"
			class="bn-btn bn-decline"
			data-variant="ghost"
			data-size="sm"
			data-wp-on--click="actions.declineRequest"
			data-wp-bind--hidden="!state.showAcceptDecline"
			data-action="bn-decline-connect"
			data-user-id="<?php echo absint( $user_id ); ?>"
			<?php echo $pending_recv ? '' : 'hidden'; ?>
		>
			<?php esc_html_e( 'Decline', 'buddynext' ); ?>
		</button>
	</span>

	<button
		type="button"
		class="bn-btn bn-connected"
		data-variant="secondary"
		data-size="sm"
		data-wp-on--click="actions.disconnect"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-bind--hidden="!state.showConnected"
		<?php echo 'accepted' === $bn_conn_status ? '' : 'hidden'; ?>
	>
		<?php esc_html_e( 'Connected', 'buddynext' ); ?>
	</button>

	<button
		type="button"
		class="bn-btn bn-pending"
		data-variant="secondary"
		data-size="sm"
		data-wp-on--click="actions.withdrawRequest"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-bind--hidden="!state.showPending"
		<?php echo $pending_sent ? '' : 'hidden'; ?>
	>
		<?php esc_html_e( 'Pending', 'buddynext' ); ?>
	</button>

	<?php if ( $bn_can_connect || '' !== $ctx_status ) : ?>
	<button
		type="button"
		class="bn-btn"
		data-variant="primary"
		data-size="sm"
		data-wp-on--click="actions.sendRequest"
		data-action="bn-toggle-connect"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-bind--hidden="!state.showConnect"
		<?php echo ( '' === $ctx_status ) ? '' : 'hidden'; ?>
	>
		<?php esc_html_e( 'Connect', 'buddynext' ); ?>
	</button>
	<?php endif; ?>
</div>
<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
