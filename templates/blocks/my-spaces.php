<?php
/**
 * Block template: My Spaces
 *
 * Variables:
 *   int $limit Maximum number of spaces to display
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$limit = $limit ?? 10;

if ( ! is_user_logged_in() ) {
	return;
}

$user_id = get_current_user_id();
$spaces  = buddynext_service( 'spaces' )->list_spaces(
	array(
		'per_page' => $limit,
		'member'   => $user_id,
	)
);
?>
<div class="bn-block-my-spaces">
	<h3 class="bn-block-heading"><?php esc_html_e( 'My Spaces', 'buddynext' ); ?></h3>
	<?php if ( empty( $spaces ) ) : ?>
		<p class="bn-empty"><?php esc_html_e( 'You haven\'t joined any spaces yet.', 'buddynext' ); ?></p>
	<?php else : ?>
		<ul class="bn-my-spaces-list">
			<?php foreach ( $spaces as $space ) : ?>
				<li class="bn-my-spaces-item">
					<a href="<?php echo esc_url( home_url( '/spaces/' . rawurlencode( $space['slug'] ?? '' ) . '/' ) ); ?>" class="bn-my-spaces-link">
						<?php if ( ! empty( $space['avatar_url'] ) ) : ?>
							<img src="<?php echo esc_url( $space['avatar_url'] ); ?>" alt="" class="bn-space-avatar bn-space-avatar--sm" width="32" height="32" loading="lazy">
						<?php else : ?>
							<span class="bn-space-avatar bn-space-avatar--sm bn-space-avatar--placeholder"></span>
						<?php endif; ?>
						<span class="bn-my-spaces-name"><?php echo esc_html( $space['name'] ?? '' ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
