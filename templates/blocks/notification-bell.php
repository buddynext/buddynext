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

$user_id = get_current_user_id();
// Badge = UNSEEN count (notifications since the list was last viewed), not raw
// unread. Opening the list clears the bell without marking items read, so the
// Unread tab stays populated — matching GitHub / Slack / X.
$unread_count = (int) buddynext_service( 'notifications' )->unseen_count( $user_id );
$aria_label   = sprintf(
	/* translators: %d: new (unseen) notification count */
	_n( '%d new notification', '%d new notifications', $unread_count, 'buddynext' ),
	absint( $unread_count )
);
?>
<?php
// This template renders in TWO contexts: as the buddynext/notification-bell BLOCK
// (real block render), and as plain output from the [buddynext_user_menu] shortcode
// / buddynext_header_user_menu() / any theme header user section. get_block_wrapper_attributes()
// only works inside a block render — it reads WP_Block_Supports::$block_to_render['attrs'],
// which is null outside one, raising "Trying to access array offset on null" and (with
// display_errors on) injecting the warning HTML mid-tag so `class="..." data-user-id="1">`
// leaked as visible text before the bell. Use the block wrapper only when a block is
// actually rendering; otherwise emit the same attributes by hand.
$bn_nb_attrs = array(
	'class'        => 'bn-block-notification-bell',
	'data-user-id' => (string) absint( $user_id ),
);
if ( null !== \WP_Block_Supports::$block_to_render ) {
	$bn_nb_wrapper = get_block_wrapper_attributes( $bn_nb_attrs );
} else {
	$bn_nb_wrapper = sprintf(
		'class="%s" data-user-id="%s"',
		esc_attr( $bn_nb_attrs['class'] ),
		esc_attr( $bn_nb_attrs['data-user-id'] )
	);
}
?>
<div
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both branches above escape their own output.
echo $bn_nb_wrapper;
?>
>
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
