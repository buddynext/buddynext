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

// The real composer is the modal on the activity feed. This block is a
// placement-anywhere entry point (sidebars, landing pages), so its trigger
// links to the feed surface rather than relying on a data-action that no JS
// binds and a composer modal that does not exist off the feed.
$compose_url = \BuddyNext\Core\PageRouter::activity_url();
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
		<a
			href="<?php echo esc_url( $compose_url ); ?>"
			class="bn-input bn-composer-trigger"
			aria-label="<?php echo esc_attr( $placeholder ); ?>"
		>
			<?php echo esc_html( $placeholder ); ?>
		</a>
	</div>
</div>
