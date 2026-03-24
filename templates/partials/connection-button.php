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

$nonce = wp_create_nonce( 'bn-connect' );

// Build the WP Interactivity API context object (esc_attr-escaped JSON string).
$context_attr = esc_attr(
	(string) wp_json_encode(
		array(
			'userId' => $user_id,
			'status' => $bn_conn_status ?? '',
			'nonce'  => $nonce,
		)
	)
);
?>
<div
	class="bn-connection-btn-wrap"
	data-wp-interactive="buddynext/connection-button"
	data-user-id="<?php echo absint( $user_id ); ?>"
	data-wp-context="<?php echo $context_attr; // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() applied to $context_attr. ?>"
>
	<?php if ( $pending_recv ) : ?>

		<span class="bn-connect-received">
			<button
				type="button"
				class="bn-btn bn-btn--sm bn-accept"
				data-wp-on--click="actions.acceptRequest"
				data-action="bn-accept-connect"
				data-user-id="<?php echo absint( $user_id ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
			>
				<?php esc_html_e( 'Accept', 'buddynext' ); ?>
			</button>
			<button
				type="button"
				class="bn-btn bn-btn--sm bn-decline"
				data-wp-on--click="actions.declineRequest"
				data-action="bn-decline-connect"
				data-user-id="<?php echo absint( $user_id ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
			>
				<?php esc_html_e( 'Decline', 'buddynext' ); ?>
			</button>
		</span>

	<?php elseif ( 'accepted' === $bn_conn_status ) : ?>

		<button
			type="button"
			class="bn-btn bn-btn--sm bn-btn--secondary bn-connected"
			data-wp-on--click="actions.disconnect"
			data-action="bn-toggle-connect"
			data-user-id="<?php echo absint( $user_id ); ?>"
			data-status="accepted"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
		>
			<?php esc_html_e( 'Connected', 'buddynext' ); ?>
		</button>

	<?php elseif ( $pending_sent ) : ?>

		<button
			type="button"
			class="bn-btn bn-btn--sm bn-btn--secondary bn-pending"
			data-wp-on--click="actions.withdrawRequest"
			data-action="bn-toggle-connect"
			data-user-id="<?php echo absint( $user_id ); ?>"
			data-status="pending-sent"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
		>
			<?php esc_html_e( 'Pending', 'buddynext' ); ?>
		</button>

	<?php else : ?>

		<button
			type="button"
			class="bn-btn bn-btn--sm bn-btn--primary"
			data-wp-on--click="actions.sendRequest"
			data-action="bn-toggle-connect"
			data-user-id="<?php echo absint( $user_id ); ?>"
			data-status=""
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
		>
			<?php esc_html_e( 'Connect', 'buddynext' ); ?>
		</button>

	<?php endif; ?>
</div>
<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
