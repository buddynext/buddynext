<?php
/**
 * Block template: Space Directory
 *
 * Variables:
 *   int    $per_page Number of spaces to display
 *   string $layout   'grid' | 'list'
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$bn_per_page = $per_page ?? 12;
$layout      = $layout ?? 'grid';

$spaces   = buddynext_service( 'spaces' )->list_spaces(
	array(
		'per_page' => $bn_per_page,
		'type'     => 'open',
	)
);
$has_more = false;
?>
<div class="bn-block-space-directory bn-block-space-directory--<?php echo esc_attr( $layout ); ?>">
	<h3 class="bn-block-heading"><?php esc_html_e( 'Spaces', 'buddynext' ); ?></h3>
	<?php if ( empty( $spaces ) ) : ?>
		<p class="bn-empty"><?php esc_html_e( 'No spaces found.', 'buddynext' ); ?></p>
	<?php else : ?>
		<ul class="bn-space-list">
			<?php foreach ( $spaces as $space ) : ?>
				<li class="bn-space-item">
					<a href="<?php echo esc_url( home_url( '/spaces/' . rawurlencode( $space['slug'] ?? '' ) . '/' ) ); ?>" class="bn-space-link">
						<?php if ( ! empty( $space['avatar_url'] ) ) : ?>
							<img src="<?php echo esc_url( $space['avatar_url'] ); ?>" alt="" class="bn-space-avatar" width="40" height="40" loading="lazy">
						<?php else : ?>
							<span class="bn-space-avatar bn-space-avatar--placeholder"></span>
						<?php endif; ?>
						<span class="bn-space-name"><?php echo esc_html( $space['name'] ?? '' ); ?></span>
						<span class="bn-space-count">
							<?php
							$bn_count = absint( $space['member_count'] ?? 0 );
							printf(
								/* translators: %d: member count */
								esc_html( _n( '%d member', '%d members', $bn_count, 'buddynext' ) ),
								absint( $bn_count )
							);
							?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $has_more ) : ?>
			<button class="bn-load-more" data-block="space-directory" data-page="2" data-per-page="<?php echo absint( $bn_per_page ); ?>">
				<?php esc_html_e( 'Load more', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
	<?php endif; ?>
</div>
