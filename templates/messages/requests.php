<?php
/**
 * DM Requests route — bridge wrapper.
 *
 * Per docs/specs/features/07-direct-messaging.md and
 * docs/specs/WPMediaVerse-DM-Integration-Requirements.md, BuddyNext is the UI
 * shell only. Message requests (unknown senders) are a first-class part of the
 * WPMediaVerse messaging UI: the conversation list rendered by
 * WPMediaVerseBridge::render_messages() includes an "All / Unread / Requests"
 * tab set, and the active conversation pane shows an accept/decline banner for
 * pending requests (wpmediaverse/templates/partials/chat-list.php +
 * chat-conversation.php, driven by the `mvs/messaging` store's acceptRequest /
 * declineRequest actions).
 *
 * The `/messages/requests/` route therefore renders the SAME bridge UI as
 * `/messages/` and pre-selects the Requests tab on hydration by activating the
 * MVS tab button once it mounts. No native request markup is emitted.
 *
 * The previous native requests template bound Accept / Decline / Block buttons
 * to an empty `buddynext/messages` Interactivity store and could action
 * nothing; it has been retired in favour of this single bridge path.
 *
 * If WPMediaVerse is not active, the spec-sanctioned dependency notice is shown
 * (no inert controls, no fatal).
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().

// Detect WPMediaVerse via several signals (see thread.php for rationale).
$mvs_active = (
	class_exists( 'WPMediaVerse\Core\Plugin' )
	|| defined( 'MVS_VERSION' )
	|| has_action( 'buddynext_render_messages' )
);

/**
 * Fires before the messages requests inner content.
 */
do_action( 'buddynext_messages_requests_before' );
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
		// Pre-select the Requests tab once the MVS chat list mounts. The tab is
		// the chat-list button bound to state.isTabRequests; we activate it via
		// a native click so the store's own setTab action runs (no MVS edits).
		// All guards are no-ops if the markup/store is absent.
		wp_print_inline_script_tag(
			'(function(){' .
			'var tries=0;' .
			'function pick(){' .
			'var btns=document.querySelectorAll(".mvs-chat-tabs__tab");' .
			'if(btns&&btns.length>=3){btns[2].click();return;}' .
			'if(tries++<40){setTimeout(pick,100);}' .
			'}' .
			'document.addEventListener("DOMContentLoaded",pick);' .
			'})();',
			array( 'id' => 'bn-dm-requests-tab' )
		);

		/**
		 * Render the WPMediaVerse messaging UI inside BuddyNext's hub shell.
		 *
		 * Hooked by WPMediaVerseBridge::render_messages(). The rendered UI
		 * exposes the Requests tab and the accept/decline request banner.
		 *
		 * @since 0.1.0
		 */
		do_action( 'buddynext_render_messages' );
		?>

	<?php endif; ?>

</div><!-- /.bn-messages-content -->

<?php
/**
 * Fires after the messages requests inner content.
 */
do_action( 'buddynext_messages_requests_after' );
