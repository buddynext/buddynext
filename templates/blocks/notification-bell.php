<?php
/**
 * Block template: Notification Bell
 *
 * No block variables — always renders for the current user.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$user_id      = get_current_user_id();
$unread_count = buddynext_service( 'notifications' )->unread_count( $user_id );
$aria_label   = sprintf(
	/* translators: %d: unread notification count */
	_n( '%d unread notification', '%d unread notifications', $unread_count, 'buddynext' ),
	absint( $unread_count )
);
?>
<div class="bn-block-notification-bell" data-user-id="<?php echo absint( $user_id ); ?>">
	<a href="<?php echo esc_url( home_url( '/notifications/' ) ); ?>"
		class="bn-notification-bell-link"
		aria-label="<?php echo esc_attr( $aria_label ); ?>">
		<span class="bn-notification-bell-icon" aria-hidden="true">
			<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M10 2a6 6 0 0 0-6 6v3l-1.5 2.5A1 1 0 0 0 3.382 15H16.618a1 1 0 0 0 .882-1.5L16 11V8a6 6 0 0 0-6-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
				<path d="M8 15a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
			</svg>
		</span>
		<?php if ( $unread_count > 0 ) : ?>
			<span class="bn-notification-badge" aria-hidden="true">
				<?php echo absint( min( $unread_count, 99 ) ); ?>
				<?php echo $unread_count > 99 ? esc_html( '+' ) : ''; ?>
			</span>
		<?php endif; ?>
	</a>
</div>
