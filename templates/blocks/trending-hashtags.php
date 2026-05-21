<?php
/**
 * Block template: Trending Hashtags (v2 design system).
 *
 * Sidebar widget — ranked list of trending tags. Wrapped in .bn-card; post
 * counts use the .bn-badge primitive.
 *
 * Variables:
 *   int    $count   Number of hashtags to display.
 *   string $display 'list' | 'pills'.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$count   = $count ?? 10;
$display = $display ?? 'list';
$display = in_array( $display, array( 'list', 'pills' ), true ) ? $display : 'list';

$hashtags = buddynext_service( 'hashtags' )->get_trending( $count );
?>
<section
	class="bn-card bn-block-trending-hashtags bn-block-trending-hashtags--<?php echo esc_attr( $display ); ?>"
	data-display="<?php echo esc_attr( $display ); ?>"
>
	<h3 class="bn-block-heading"><?php esc_html_e( 'Trending', 'buddynext' ); ?></h3>
	<?php if ( empty( $hashtags ) ) : ?>
		<div class="bn-empty-state">
			<?php buddynext_icon( 'trending' ); ?>
			<div class="bn-empty-state__title"><?php esc_html_e( 'Nothing trending yet', 'buddynext' ); ?></div>
			<p><?php esc_html_e( 'Hashtags from new posts will appear here.', 'buddynext' ); ?></p>
		</div>
	<?php else : ?>
		<ul class="bn-hashtag-list">
			<?php foreach ( $hashtags as $idx => $bn_tag ) : ?>
				<li class="bn-hashtag-item">
					<a
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::hashtag_feed_url( $bn_tag['slug'] ) ); ?>"
						class="bn-hashtag-link"
					>
						<span class="bn-hashtag-rank" aria-hidden="true"><?php echo absint( $idx + 1 ); ?></span>
						<span class="bn-hashtag-name">#<?php echo esc_html( $bn_tag['slug'] ); ?></span>
						<span class="bn-badge bn-hashtag-count" data-tone="accent">
							<?php echo esc_html( number_format_i18n( (int) ( $bn_tag['post_count'] ?? 0 ) ) ); ?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
