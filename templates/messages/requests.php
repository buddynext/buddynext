<?php
/**
 * DM message requests route — native BuddyNext messaging.
 *
 * Renders the native two-pane (templates/messages/native.php) pre-filtered to
 * the Requests tab. Consumes the WPMediaVerse engine at the API level only —
 * no MVS screens embedded.
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().

/**
 * Fires before the messages requests inner content.
 */
do_action( 'buddynext_messages_requests_before' );

buddynext_get_template(
	'messages/native.php',
	array(
		'active_conv_id' => 0,
		'active_tab'     => 'requests',
	)
);

/**
 * Fires after the messages requests inner content.
 */
do_action( 'buddynext_messages_requests_after' );
