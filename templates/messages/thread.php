<?php
/**
 * DM Thread route — bridge wrapper.
 *
 * Per docs/specs/features/07-direct-messaging.md and
 * docs/specs/WPMediaVerse-DM-Integration-Requirements.md, BuddyNext is the UI
 * shell only; the conversation list, thread pane, and composer are owned and
 * rendered by WPMediaVerse via the `buddynext_render_messages` action
 * (WPMediaVerseBridge::render_messages()). That action prints the two-pane
 * `mvs/messaging` Interactivity UI which contains the full thread + composer.
 *
 * The `/messages/{id}/` route therefore renders the SAME bridge UI as
 * `/messages/`; the only addition is a deep-link hint so the MVS store opens
 * the requested conversation on hydration. The MVS store's onInit callback
 * reads `window.location.hash` (`#mvs-chat/{id}`) — see
 * wpmediaverse/assets/js/messaging.js — so we set that hash before the module
 * hydrates and also dispatch the `mvs-open-conversation` event the same store
 * listens for, covering both fresh-load and already-mounted cases.
 *
 * The previous native thread template (split-pane + parts/dm-*.php) bound to an
 * empty `buddynext/messages` Interactivity store and could send nothing; it has
 * been retired in favour of this single bridge path.
 *
 * If WPMediaVerse is not active, the spec-sanctioned dependency notice is shown
 * (no inert controls, no fatal).
 *
 * Visual canon: docs/v2 Plans/v2/dm-thread.html
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().

// Detect WPMediaVerse via several signals so a single upstream class rename
// does not break BuddyNext's dependency notice. The dependency is met when ANY
// of these is true: the canonical Plugin class exists, MVS_VERSION is defined,
// or the buddynext_render_messages action has a listener (the bridge attaches
// one).
$mvs_active = (
	class_exists( 'WPMediaVerse\Core\Plugin' )
	|| defined( 'MVS_VERSION' )
	|| has_action( 'buddynext_render_messages' )
);

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
?>
<div class="bn-messages-content" data-bn-main-edge="true">

	<?php if ( ! $mvs_active ) : ?>

		<div class="bn-card bn-dm-dep-notice" role="status">
			<div class="bn-dm-dep-notice__head">
				<span class="bn-dm-dep-notice__icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
				<span class="bn-badge" data-tone="warn"><?php esc_html_e( 'Dependency required', 'buddynext' ); ?></span>
			</div>
			<h2 class="bn-dm-dep-notice__title"><?php esc_html_e( 'Direct messaging requires WPMediaVerse', 'buddynext' ); ?></h2>
			<p class="bn-dm-dep-notice__body">
				<?php esc_html_e( 'Install and activate the WPMediaVerse plugin to enable direct messaging in BuddyNext.', 'buddynext' ); ?>
			</p>
		</div>

	<?php else : ?>

		<?php
		if ( $conv_id > 0 ) {
			// Pre-seed the MVS deep-link hash before the messaging module
			// hydrates so its onInit opens the requested conversation, and
			// dispatch the event the store also listens for (already-mounted
			// case). Both are no-ops if the store/plugin is absent.
			$deep_link = wp_json_encode( '#mvs-chat/' . $conv_id );
			$conv_json = wp_json_encode( $conv_id );
			wp_print_inline_script_tag(
				'(function(){' .
				'try{if(!window.location.hash){window.location.replace(window.location.pathname+window.location.search+' . $deep_link . ');}}catch(e){}' .
				'document.addEventListener("DOMContentLoaded",function(){' .
				'document.dispatchEvent(new CustomEvent("mvs-open-conversation",{detail:{conversationId:' . $conv_json . '}}));' .
				'});' .
				'})();',
				array( 'id' => 'bn-dm-deeplink' )
			);
		}

		/**
		 * Render the WPMediaVerse messaging UI inside BuddyNext's hub shell.
		 *
		 * Hooked by WPMediaVerseBridge::render_messages() which prints the MVS
		 * two-pane chat UI (conversation list + thread + composer).
		 *
		 * @since 0.1.0
		 */
		do_action( 'buddynext_render_messages' );
		?>

	<?php endif; ?>

</div><!-- /.bn-messages-content -->

<?php
/**
 * Fires after the messages thread inner content.
 *
 * @param int $conv_id Conversation ID requested by the route.
 */
do_action( 'buddynext_messages_thread_after', $conv_id );
