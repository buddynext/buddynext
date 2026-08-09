<?php
/**
 * Block template: Trending Hashtags (v2 design system).
 *
 * Sidebar widget — ranked list of trending tags. Wrapped in .bn-card; post
 * counts use the .bn-badge primitive.
 *
 * Variables:
 *   int    $count   Number of hashtags to display.
 *   string $display 'list' | 'cloud'.
 *   int    $hours   Rolling window in hours. Default 168 (7 days).
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$count = $count ?? 8;
$hours = isset( $hours ) ? (int) $hours : 24 * 7;

/*
 * Three layers used to disagree about this block's own vocabulary: block.json
 * and the editor both said `cloud`, this whitelist said `pills`, and the only
 * styled variant in CSS was `--pills`. So choosing Cloud in the editor produced
 * a value this line rejected, silently fell back to `list`, and the one variant
 * that had real styles could not be reached from any UI at all. `cloud` wins
 * because it is what block.json, the editor and the library spec already say.
 *
 * No `pills` alias, deliberately. It looks like the careful thing to add, and
 * it is unreachable: block.json declares enum ["list","cloud"], so WordPress
 * replaces any other saved value with the default BEFORE the render callback
 * runs - verified by placing a block with display:"pills", which arrives here
 * as "list". That enum has always been there, so no site can ever have had a
 * working `pills` value to preserve.
 */
$display = (string) ( $display ?? 'list' );
$display = in_array( $display, array( 'list', 'cloud' ), true ) ? $display : 'list';

$hashtags = buddynext_service( 'hashtags' )->get_trending( $count, $hours );
?>
<section
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes its own output.
	echo get_block_wrapper_attributes(
		array(
			'class'        => 'bn-card bn-block-trending-hashtags bn-block-trending-hashtags--' . $display,
			'data-display' => $display,
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>
	<h3 class="bn-block-heading"><?php esc_html_e( 'Trending', 'buddynext' ); ?></h3>
	<?php if ( empty( $hashtags ) ) : ?>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'trending',
				'title' => __( 'Nothing trending yet', 'buddynext' ),
				'body'  => __( 'Hashtags from new posts will appear here.', 'buddynext' ),
			)
		);
		?>
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
