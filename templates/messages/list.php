<?php
/**
 * DM Conversation List — BuddyNext hub wrapper for WPMediaVerse chat.
 *
 * BuddyNext is the UI shell only. The conversation list, thread, composer,
 * and all real-time messaging logic are owned by WPMediaVerse and rendered
 * via the buddynext_render_messages action hooked in WPMediaVerseBridge.
 *
 * If WPMediaVerse is not active the template shows a dependency notice.
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

$mvs_active = class_exists( 'WPMediaVerse\Core\Plugin' );

$bn_nav_active = 'messages';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div class="bn-hub-shell">

	<div class="bn-messages-content">

		<?php if ( ! $mvs_active ) : ?>

			<div class="bn-dependency-notice">
				<div class="bn-dependency-notice-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></div>
				<div class="bn-dependency-notice-title"><?php esc_html_e( 'Direct messaging requires WPMediaVerse', 'buddynext' ); ?></div>
				<p class="bn-dependency-notice-body">
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

	<?php buddynext_get_template( 'partials/sidebar.php' ); ?>

</div><!-- /.bn-hub-shell -->
