<?php
/**
 * Block template: Notification Bell (v2 design system).
 *
 * Topbar bell icon with unread-count badge. Icon comes from the BuddyNext SVG
 * registry via buddynext_icon(); badge uses the v2 .bn-badge primitive.
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
$unread_count = (int) buddynext_service( 'notifications' )->unread_count( $user_id );
$aria_label   = sprintf(
	/* translators: %d: unread notification count */
	_n( '%d unread notification', '%d unread notifications', $unread_count, 'buddynext' ),
	absint( $unread_count )
);
?>
<div class="bn-block-notification-bell" data-user-id="<?php echo absint( $user_id ); ?>">
	<a
		href="<?php echo esc_url( \BuddyNext\Core\PageRouter::notifications_url() ); ?>"
		class="bn-notification-bell-link"
		aria-label="<?php echo esc_attr( $aria_label ); ?>"
	>
		<span class="bn-notification-bell-icon" aria-hidden="true">
			<?php buddynext_icon( 'bell' ); ?>
		</span>
		<?php if ( $unread_count > 0 ) : ?>
			<span
				class="bn-badge bn-notification-badge"
				data-tone="danger"
				aria-hidden="true"
			>
				<?php
				echo esc_html( number_format_i18n( min( $unread_count, 99 ) ) );
				echo $unread_count > 99 ? esc_html( '+' ) : '';
				?>
			</span>
		<?php endif; ?>
	</a>
</div>
