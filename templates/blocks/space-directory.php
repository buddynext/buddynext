<?php
/**
 * Block template: Space Directory (v2 design system).
 *
 * Compact directory of open spaces — wrapped in .bn-card and each row uses the
 * .bn-avatar primitive. Pagination uses the .bn-btn ghost variant.
 *
 * Variables:
 *   int    $per_page Number of spaces to display.
 *   string $layout   'grid' | 'list'.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$bn_per_page = $per_page ?? 12;
$layout      = $layout ?? 'grid';
$layout      = in_array( $layout, array( 'grid', 'list' ), true ) ? $layout : 'grid';

$spaces   = buddynext_service( 'spaces' )->list_spaces(
	array(
		'per_page' => $bn_per_page,
		'type'     => 'open',
	)
);
$has_more = false;
?>
<section
	class="bn-card bn-block-space-directory bn-block-space-directory--<?php echo esc_attr( $layout ); ?>"
	data-layout="<?php echo esc_attr( $layout ); ?>"
>
	<h3 class="bn-block-heading"><?php esc_html_e( 'Spaces', 'buddynext' ); ?></h3>
	<?php if ( empty( $spaces ) ) : ?>
		<div class="bn-empty-state">
			<?php buddynext_icon( 'hash' ); ?>
			<div class="bn-empty-state__title"><?php esc_html_e( 'No spaces yet', 'buddynext' ); ?></div>
			<p><?php esc_html_e( 'Open spaces will appear here once they are created.', 'buddynext' ); ?></p>
		</div>
	<?php else : ?>
		<ul class="bn-space-list">
			<?php foreach ( $spaces as $space ) : ?>
				<li class="bn-space-item">
					<a
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::space_url( (int) ( $space['id'] ?? 0 ) ) ); ?>"
						class="bn-space-link"
					>
						<span class="bn-avatar bn-space-list__avatar" data-size="md" aria-hidden="true">
							<?php if ( ! empty( $space['avatar_url'] ) ) : ?>
								<img
									src="<?php echo esc_url( $space['avatar_url'] ); ?>"
									alt=""
									width="40"
									height="40"
									loading="lazy"
									decoding="async"
								>
							<?php endif; ?>
						</span>
						<span class="bn-space-name"><?php echo esc_html( $space['name'] ?? '' ); ?></span>
						<span class="bn-badge bn-space-count" data-tone="info">
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
			<button
				type="button"
				class="bn-btn bn-load-more"
				data-variant="ghost"
				data-size="sm"
				data-block="space-directory"
				data-page="2"
				data-per-page="<?php echo absint( $bn_per_page ); ?>"
			>
				<?php esc_html_e( 'Load more', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
	<?php endif; ?>
</section>
