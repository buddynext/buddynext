<?php
/**
 * BuddyNext template part: sidebar-trending-topics.
 *
 * Self-chromed "Trending Topics" sidebar card. Extracted verbatim from the
 * former `templates/partials/sidebar.php` so FeedSidebarProvider can render
 * it as a `chrome => false` widget descriptor — markup and gating are
 * unchanged, only the data now arrives via the provider's render closure
 * instead of the partial preparing it inline.
 *
 * @package BuddyNext
 *
 * @var array<int,object> $sbar_trending Rows from WidgetService::trending_hashtags(). Empty renders nothing.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$sbar_trending = isset( $sbar_trending ) ? (array) $sbar_trending : array();

// Nothing was tagged in the window: no card, on every sidebar that uses this.
if ( empty( $sbar_trending ) ) {
	return;
}
?>
<div class="bn-sidebar-card">
	<div class="bn-sidebar-card__header">
		<?php esc_html_e( 'Trending Topics', 'buddynext' ); ?>
		<span class="bn-sidebar-card__caption"><?php esc_html_e( 'This week', 'buddynext' ); ?></span>
	</div>
	<div class="bn-sidebar-card__body">
			<?php foreach ( $sbar_trending as $sbar_tag ) : ?>
				<div class="bn-sbar-row">
					<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::hashtag_feed_url( (string) $sbar_tag->slug ) ); ?>"
						class="bn-sbar-row__name">
						#<?php echo esc_html( $sbar_tag->slug ); ?>
					</a>
					<span class="bn-sbar-row__meta">
						<?php
						$sbar_tag_count = (int) $sbar_tag->post_count;
						printf(
							/* translators: %s: formatted post count. */
							esc_html( _n( '%s post', '%s posts', $sbar_tag_count, 'buddynext' ) ),
							esc_html( number_format_i18n( $sbar_tag_count ) )
						);
						?>
					</span>
				</div>
			<?php endforeach; ?>
	</div>
</div>
