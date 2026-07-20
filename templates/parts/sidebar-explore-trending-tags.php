<?php
/**
 * BuddyNext template part: sidebar-explore-trending-tags.
 *
 * Self-chromed "Trending tags" card for the Explore aside. Extracted
 * verbatim from the former `templates/feed/parts/explore-aside.php`
 * (trending-tags block) so ExploreSidebarProvider can render it as a
 * `chrome => false` widget descriptor — markup, gating, and data source
 * unchanged, only the data now arrives via the provider's render closure
 * instead of the partial fetching it inline.
 *
 * @package BuddyNext
 * @since   1.6.0
 *
 * @var array<int,array<string,mixed>> $bn_trending Rows from HashtagService::trending(). Empty renders nothing.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$bn_trending = isset( $bn_trending ) ? (array) $bn_trending : array();

if ( empty( $bn_trending ) ) {
	return;
}

$bn_activity = trailingslashit( PageRouter::activity_url() );
ob_start();
?>
<ol class="bn-ex-trend">
	<?php
	$bn_rank = 0;
	foreach ( $bn_trending as $bn_tag ) :
		$bn_tag_slug  = (string) ( $bn_tag['slug'] ?? '' );
		$bn_tag_count = (int) ( $bn_tag['post_count'] ?? 0 );
		if ( '' === $bn_tag_slug ) {
			continue;
		}
		++$bn_rank;
		$bn_tag_url = esc_url( $bn_activity . 'hashtag/' . rawurlencode( $bn_tag_slug ) . '/' );
		?>
		<li class="bn-ex-trend__row">
			<span class="bn-ex-trend__rank"><?php echo esc_html( number_format_i18n( $bn_rank ) ); ?></span>
			<a class="bn-ex-trend__info" href="<?php echo $bn_tag_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() applied above. ?>">
				<span class="bn-ex-trend__tag">#<?php echo esc_html( $bn_tag_slug ); ?></span>
				<span class="bn-ex-trend__stat">
					<?php
					/* translators: %s: formatted post count. */
					echo esc_html( sprintf( _n( '%s post', '%s posts', $bn_tag_count, 'buddynext' ), number_format_i18n( $bn_tag_count ) ) );
					?>
				</span>
			</a>
		</li>
	<?php endforeach; ?>
</ol>
<?php
buddynext_get_template(
	'parts/sidebar-card.php',
	array(
		'id'         => 'explore-trending',
		'title'      => __( 'Trending tags', 'buddynext' ),
		'title_icon' => 'trending-up',
		'body_html'  => (string) ob_get_clean(),
	)
);
