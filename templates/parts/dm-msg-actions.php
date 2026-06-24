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

$bn_react_types = class_exists( '\BuddyNext\Reactions\ReactionService' )
	? (array) \BuddyNext\Reactions\ReactionService::reaction_types()
	: array( 'like', 'love', 'haha', 'wow', 'sad', 'angry' );
?>
<div class="bn-dm-msg__actions" role="group" aria-label="<?php esc_attr_e( 'Message actions', 'buddynext' ); ?>">
	<div class="bn-dm-msg__react-wrap">
		<button type="button" class="bn-dm-msg__action" data-bn-action="react" aria-label="<?php esc_attr_e( 'React', 'buddynext' ); ?>" aria-haspopup="true">
			<?php buddynext_icon( 'smile' ); ?>
		</button>
		<div class="bn-dm-msg__react-pop" role="menu" aria-label="<?php esc_attr_e( 'Choose a reaction', 'buddynext' ); ?>">
			<?php
			foreach ( $bn_react_types as $bn_slug ) :
				$bn_slug  = (string) $bn_slug;
				$bn_glyph = '' !== $bn_slug ? \BuddyNext\Core\IconService::render_emoji( $bn_slug ) : '';
				// Only offer reactions that have a bundled glyph — never a blank button.
				if ( '' === $bn_glyph ) {
					continue;
				}
				?>
				<button type="button" class="bn-dm-msg__react-opt" data-bn-action="react-pick" data-slug="<?php echo esc_attr( $bn_slug ); ?>" aria-label="<?php echo esc_attr( ucfirst( $bn_slug ) ); ?>">
					<?php echo $bn_glyph; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-sanitized SVG from IconService. ?>
				</button>
			<?php endforeach; ?>
		</div>
	</div>
	<button type="button" class="bn-dm-msg__action" data-bn-action="reply" aria-label="<?php esc_attr_e( 'Reply', 'buddynext' ); ?>">
		<?php buddynext_icon( 'reply' ); ?>
	</button>
	<?php // Report — hidden on your own messages via CSS (.bn-dm-msg.is-mine). Files an object_type=message report into the moderation queue. ?>
	<button type="button" class="bn-dm-msg__action bn-dm-msg__action--report" data-bn-action="report" aria-label="<?php esc_attr_e( 'Report message', 'buddynext' ); ?>">
		<?php buddynext_icon( 'flag' ); ?>
	</button>
	<?php
	// Unsend / Delete — your OWN messages only (shown via CSS on .bn-dm-msg.is-mine;
	// the engine enforces sender-only, so a recipient never sees these). Unsend
	// recalls a recent message (removed for everyone within a time window); Delete
	// leaves a "message deleted" tombstone for everyone, any time.
	?>
	<button type="button" class="bn-dm-msg__action bn-dm-msg__action--own" data-bn-action="unsend" aria-label="<?php esc_attr_e( 'Unsend message', 'buddynext' ); ?>">
		<?php buddynext_icon( 'x-circle' ); ?>
	</button>
	<button type="button" class="bn-dm-msg__action bn-dm-msg__action--own bn-dm-msg__action--danger" data-bn-action="delete" aria-label="<?php esc_attr_e( 'Delete message', 'buddynext' ); ?>">
		<?php buddynext_icon( 'trash' ); ?>
	</button>
</div>
