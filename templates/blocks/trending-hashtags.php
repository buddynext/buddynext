<?php
/**
 * Block template: Trending Hashtags
 *
 * Variables:
 *   int    $count   Number of hashtags to display
 *   string $display 'list' | 'pills'
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$count   = $count ?? 10;
$display = $display ?? 'list';

$hashtags = buddynext_service( 'hashtags' )->get_trending( $count );
?>
<div class="bn-block-trending-hashtags bn-block-trending-hashtags--<?php echo esc_attr( $display ); ?>">
	<h3 class="bn-block-heading"><?php esc_html_e( 'Trending', 'buddynext' ); ?></h3>
	<?php if ( empty( $hashtags ) ) : ?>
		<p class="bn-empty"><?php esc_html_e( 'No trending hashtags yet.', 'buddynext' ); ?></p>
	<?php else : ?>
		<ul class="bn-hashtag-list">
			<?php foreach ( $hashtags as $idx => $bn_tag ) : ?>
				<li class="bn-hashtag-item">
					<a href="<?php echo esc_url( home_url( '/hashtag/' . rawurlencode( $bn_tag['slug'] ) ) ); ?>" class="bn-hashtag-link">
						<span class="bn-hashtag-rank"><?php echo absint( $idx + 1 ); ?></span>
						<span class="bn-hashtag-name">#<?php echo esc_html( $bn_tag['slug'] ); ?></span>
						<span class="bn-hashtag-count"><?php echo absint( $bn_tag['post_count'] ?? 0 ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
