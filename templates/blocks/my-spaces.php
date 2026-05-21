<?php
/**
 * Block template: My Spaces (v2 design system).
 *
 * Sidebar widget that lists the spaces the current user belongs to. Wrapped in
 * .bn-card so it sits naturally next to other sidebar primitives.
 *
 * Variables:
 *   int $limit Maximum number of spaces to display.
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
<section class="bn-card bn-block-my-spaces">
	<h3 class="bn-block-heading"><?php esc_html_e( 'My Spaces', 'buddynext' ); ?></h3>
	<?php if ( empty( $spaces ) ) : ?>
		<div class="bn-empty-state">
			<?php buddynext_icon( 'hash' ); ?>
			<div class="bn-empty-state__title"><?php esc_html_e( 'No spaces yet', 'buddynext' ); ?></div>
			<p><?php esc_html_e( "You haven't joined any spaces yet.", 'buddynext' ); ?></p>
		</div>
	<?php else : ?>
		<ul class="bn-my-spaces-list">
			<?php foreach ( $spaces as $space ) : ?>
				<li class="bn-my-spaces-item">
					<a
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::space_url( (int) ( $space['id'] ?? 0 ) ) ); ?>"
						class="bn-my-spaces-link"
					>
						<span class="bn-avatar bn-my-spaces-avatar" data-size="sm" aria-hidden="true">
							<?php if ( ! empty( $space['avatar_url'] ) ) : ?>
								<img
									src="<?php echo esc_url( $space['avatar_url'] ); ?>"
									alt=""
									width="32"
									height="32"
									loading="lazy"
									decoding="async"
								>
							<?php endif; ?>
						</span>
						<span class="bn-my-spaces-name"><?php echo esc_html( $space['name'] ?? '' ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
