<?php
/**
 * BuddyNext template part: dm-request-banner.
 *
 * Shown in place of the composer when the open conversation is a pending
 * message request for the viewer. Accept moves it into the inbox (composer
 * appears on reload); Decline removes it. Both call the messages store, which
 * hits mvs/v1 /conversations/{id}/accept|/decline.
 *
 * Used by: templates/messages/native.php.
 *
 * @package BuddyNext
 *
 * @var string $display_name Optional. The requester's display name. Default ''.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_name = isset( $display_name ) ? (string) $display_name : '';
?>
<div class="bn-dm-request" role="region" aria-label="<?php esc_attr_e( 'Message request', 'buddynext' ); ?>">
	<p class="bn-dm-request__text">
		<?php
		echo esc_html(
			'' !== $bn_name
				? sprintf(
					/* translators: %s: requester display name. */
					__( '%s wants to send you a message. Accept to reply.', 'buddynext' ),
					$bn_name
				)
				: __( 'This member wants to send you a message. Accept to reply.', 'buddynext' )
		);
		?>
	</p>
	<div class="bn-dm-request__actions">
		<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.acceptRequest">
			<?php esc_html_e( 'Accept', 'buddynext' ); ?>
		</button>
		<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.declineRequest">
			<?php esc_html_e( 'Delete', 'buddynext' ); ?>
		</button>
	</div>
</div>
