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

$nonce = wp_create_nonce( 'bn-connect' );
?>
<div
	class="bn-block-connection-button"
	data-wp-interactive="buddynext/connection-button"
	data-user-id="<?php echo absint( $user_id ); ?>"
	data-wp-context='{"userId":<?php echo absint( $user_id ); ?>,"status":<?php echo wp_json_encode( $bn_status ?? '' ); ?>}'
>
	<?php if ( $pending_recv ) : ?>
		<span class="bn-connect-received">
			<button
				class="bn-btn bn-btn--sm bn-accept"
				data-wp-on--click="actions.acceptRequest"
				data-action="bn-accept-connect"
				data-user-id="<?php echo absint( $user_id ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
			>
				<?php esc_html_e( 'Accept', 'buddynext' ); ?>
			</button>
			<button
				class="bn-btn bn-btn--sm bn-decline"
				data-wp-on--click="actions.declineRequest"
				data-action="bn-decline-connect"
				data-user-id="<?php echo absint( $user_id ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
			>
				<?php esc_html_e( 'Decline', 'buddynext' ); ?>
			</button>
		</span>
	<?php elseif ( 'accepted' === $bn_status ) : ?>
		<button
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
