<?php
/**
 * DM conversation list route — native BuddyNext messaging.
 *
 * BuddyNext owns the /messages/ UI and renders its own two-pane (rail + thread)
 * via templates/messages/native.php, consuming the WPMediaVerse engine at the
 * API level only (no MVS screens embedded). When the engine is inactive,
 * native.php shows the dependency notice.
 *
 * Visual canon: docs/v2 Plans/v2/dm-list.html
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().

/**
 * Fires before the messages list inner content.
 */
do_action( 'buddynext_messages_list_before' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
if ( ! in_array( $bn_tab, array( 'all', 'unread', 'requests' ), true ) ) {
	$bn_tab = 'all';
}

buddynext_get_template(
	'messages/native.php',
	array(
		'active_conv_id' => 0,
		'active_tab'     => $bn_tab,
	)
);

/**
 * Fires after the messages list inner content.
 */
do_action( 'buddynext_messages_list_after' );
