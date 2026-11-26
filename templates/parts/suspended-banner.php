<?php
/**
 * BuddyNext template part: suspended-banner.
 *
 * One notice for a suspended member on every community page: they can read, they cannot act, and here is the way to
 * the reason and the appeal. Prints nothing for anyone else (and nothing on the account-status page, which says it
 * in full).
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$bn_sb_user = get_current_user_id();
if ( $bn_sb_user <= 0 || ! buddynext_service( 'moderation' )->is_suspended( $bn_sb_user ) ) {
	return;
}
$bn_sb_status_url = \BuddyNext\Core\PageRouter::account_status_url();
if ( untrailingslashit( (string) strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ) ) === untrailingslashit( (string) wp_parse_url( $bn_sb_status_url, PHP_URL_PATH ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	return;
}
?>
<div class="bn-notice bn-notice--error bn-suspended-banner" role="status">
	<span><?php esc_html_e( 'Your account is suspended. You can read the community, but you cannot post or react until it is lifted.', 'buddynext' ); ?></span>
	<a class="bn-notice__cta" href="<?php echo esc_url( $bn_sb_status_url ); ?>"><?php esc_html_e( 'See why and appeal', 'buddynext' ); ?></a>
</div>
