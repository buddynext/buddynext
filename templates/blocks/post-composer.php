<?php
/**
 * Block template: Post Composer
 *
 * Variables:
 *   string $placeholder Input placeholder text
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$placeholder = $placeholder ?? '';
if ( '' === $placeholder ) {
	$placeholder = __( "What's on your mind?", 'buddynext' );
}
$user_id = get_current_user_id();
$nonce   = wp_create_nonce( 'buddynext_post' );
?>
<div class="bn-block-post-composer">
	<?php echo get_avatar( $user_id, 36, '', '', array( 'class' => 'bn-avatar' ) ); ?>
	<div class="bn-composer-input-wrap">
		<button class="bn-composer-trigger" type="button"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			aria-label="<?php echo esc_attr( $placeholder ); ?>">
			<?php echo esc_html( $placeholder ); ?>
		</button>
	</div>
</div>
