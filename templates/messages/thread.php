<?php
/**
 * DM thread route — native BuddyNext messaging.
 *
 * Renders the same native two-pane as /messages/ (templates/messages/native.php)
 * with the requested conversation opened. Consumes the WPMediaVerse engine at
 * the API level only — no MVS screens embedded.
 *
 * Visual canon: docs/v2 Plans/v2/dm-thread.html
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().

// Conversation requested by the path (/messages/{id}/) maps to bn_conv_id.
$conv_id = (int) get_query_var( 'bn_conv_id', 0 );
if ( $conv_id <= 0 ) {
	// Defensive: legacy/query-string callers may still pass ?conversation=.
	$conv_id = absint( $_GET['conversation'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Fires before the messages thread inner content.
 *
 * @param int $conv_id Conversation ID requested by the route.
 */
do_action( 'buddynext_messages_thread_before', $conv_id );

buddynext_get_template(
	'messages/native.php',
	array(
		'active_conv_id' => $conv_id,
		'active_tab'     => 'all',
	)
);

/**
 * Fires after the messages thread inner content.
 *
 * @param int $conv_id Conversation ID requested by the route.
 */
do_action( 'buddynext_messages_thread_after', $conv_id );
