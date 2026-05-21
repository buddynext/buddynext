<?php
/**
 * Block template: Post Composer (v2 design system).
 *
 * Compact "what's on your mind" entry card derived from v2/home-feed.html.
 * Wrapped in .bn-card; viewer avatar uses the .bn-avatar primitive; the
 * input-shaped trigger uses .bn-input so it lines up with other form fields
 * in the surface vocabulary.
 *
 * Variables:
 *   string $placeholder Input placeholder text.
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
$user_id    = get_current_user_id();
$avatar_url = (string) get_avatar_url( $user_id, array( 'size' => 72 ) );
$nonce      = wp_create_nonce( 'buddynext_post' );
?>
<div class="bn-card bn-block-post-composer">
	<span class="bn-avatar bn-block-post-composer__avatar" data-size="md" aria-hidden="true">
		<?php if ( '' !== $avatar_url ) : ?>
			<img
				src="<?php echo esc_url( $avatar_url ); ?>"
				alt=""
				width="36"
				height="36"
				loading="lazy"
				decoding="async"
			>
		<?php endif; ?>
	</span>
	<div class="bn-composer-input-wrap">
		<button
			type="button"
			class="bn-input bn-composer-trigger"
			data-action="bn-open-composer"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			aria-label="<?php echo esc_attr( $placeholder ); ?>"
		>
			<?php echo esc_html( $placeholder ); ?>
		</button>
	</div>
</div>
