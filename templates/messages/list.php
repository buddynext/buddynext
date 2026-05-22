<?php
/**
 * DM Conversation List — BuddyNext hub wrapper for WPMediaVerse chat.
 *
 * BuddyNext is the UI shell only. The conversation list, thread, composer,
 * and all real-time messaging logic are owned by WPMediaVerse and rendered
 * via the buddynext_render_messages action hooked in WPMediaVerseBridge.
 *
 * If WPMediaVerse is not active the template shows a dependency notice
 * composed from v2 primitives (.bn-card + .bn-badge[data-tone="warn"]).
 *
 * Aligned with docs/v2 Plans/v2/dm-list.html — two-pane layout via .bn-split.
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	wp_safe_redirect( wp_login_url( \BuddyNext\Core\PageRouter::messages_url() ) );
	exit;
}

// Detect WPMediaVerse via several signals so a single class-rename in the
// upstream plugin does not break BuddyNext's dependency notice. We accept
// the dependency as met when ANY of these are true: the canonical Plugin
// class exists, MVS_VERSION is defined, or the buddynext_render_messages
// action has any listener registered (the bridge attaches one).
$mvs_active = (
	class_exists( 'WPMediaVerse\Core\Plugin' )
	|| defined( 'MVS_VERSION' )
	|| has_action( 'buddynext_render_messages' )
);

/**
 * Fires before the messages list inner content.
 */
do_action( 'buddynext_messages_list_before' );
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
			/**
			 * Render the WPMediaVerse messaging UI inside BuddyNext's hub shell.
			 *
			 * Hooked by WPMediaVerseBridge::render_messages() which includes
			 * the MVS chat partials (conversation list + thread + composer).
			 *
			 * @since 0.1.0
			 */
			do_action( 'buddynext_render_messages' );
			?>

		<?php endif; ?>

	</div><!-- /.bn-messages-content -->

<?php
/**
 * Fires after the messages list inner content.
 */
do_action( 'buddynext_messages_list_after' );
?>
