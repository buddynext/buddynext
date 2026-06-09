<?php
/**
 * BuddyNext template part: dm-msg-actions.
 *
 * The per-message hover action bar (Reply, …). Rendered inside each
 * server-rendered message (parts/dm-message.php) and once into a
 * <template id="bn-dm-msg-actions-tpl"> in templates/messages/native.php so the
 * messages store can clone it onto client-rendered (sent/polled) bubbles.
 *
 * Clicks are handled by the delegated `actions.onThreadClick` on the log
 * container — buttons here carry a `data-bn-action` verb, not a `data-wp-on`
 * directive, because the Interactivity API does not hydrate nodes appended at
 * runtime.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="bn-dm-msg__actions" role="group" aria-label="<?php esc_attr_e( 'Message actions', 'buddynext' ); ?>">
	<button type="button" class="bn-dm-msg__action" data-bn-action="reply" aria-label="<?php esc_attr_e( 'Reply', 'buddynext' ); ?>">
		<?php buddynext_icon( 'reply' ); ?>
	</button>
</div>
