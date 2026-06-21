<?php
/**
 * BuddyNext interactive media lightbox shell.
 *
 * Printed once in the footer on BuddyNext front-end pages. BN-native UX (no
 * WPMediaVerse JS/CSS). Left pane = media stage (image/video + gallery nav);
 * right pane = interaction panel (author, views, reactions, favorite/share/
 * download/open, comments). The panel's per-media data is populated by
 * assets/js/media/lightbox.js, which calls the engine REST routes
 * (mvs/v1/media/{id}/reactions|comments|favorite|view) — API-level only.
 *
 * The reaction row is static (the six types never change); JS only toggles the
 * active state + counts. Emoji render as images via buddynext_get_emoji() so
 * BuddyNext never emits raw emoji characters in markup.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_lb_reactions = array(
	'like'  => __( 'Like', 'buddynext' ),
	'love'  => __( 'Love', 'buddynext' ),
	'haha'  => __( 'Haha', 'buddynext' ),
	'wow'   => __( 'Wow', 'buddynext' ),
	'sad'   => __( 'Sad', 'buddynext' ),
	'angry' => __( 'Angry', 'buddynext' ),
);
?>
<div class="bn-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Media viewer', 'buddynext' ); ?>" hidden>
	<button type="button" class="bn-lightbox__backdrop" data-bn-lb-close aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"></button>
	<div class="bn-lightbox__dialog">

		<div class="bn-lightbox__stage">
			<button type="button" class="bn-lightbox__nav bn-lightbox__nav--prev" data-bn-lb-prev aria-label="<?php esc_attr_e( 'Previous', 'buddynext' ); ?>"><?php buddynext_icon( 'chevron-left' ); ?></button>
			<div class="bn-lightbox__media-wrap" data-bn-lb-stage></div>
			<button type="button" class="bn-lightbox__nav bn-lightbox__nav--next" data-bn-lb-next aria-label="<?php esc_attr_e( 'Next', 'buddynext' ); ?>"><?php buddynext_icon( 'chevron-right' ); ?></button>

			<?php // Private DM media has no social layer, so the side panel is dropped (.bn-lightbox--dm) and the media goes full-bleed. These floating controls over the stage carry the only chrome a 1:1 image needs: sender, download, close. Populated by assets/js/media/lightbox.js. ?>
			<div class="bn-lightbox__dm-chrome">
				<div class="bn-lightbox__dm-author" data-bn-lb-dm-author></div>
				<span class="bn-lightbox__dm-spacer"></span>
				<a class="bn-lightbox__dm-btn" data-bn-lb-dm-download download target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'Download', 'buddynext' ); ?>"><?php buddynext_icon( 'download' ); ?></a>
				<button type="button" class="bn-lightbox__dm-btn" data-bn-lb-close aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
			</div>
		</div>

		<aside class="bn-lightbox__panel">
			<header class="bn-lightbox__panel-head">
				<div class="bn-lightbox__author" data-bn-lb-author></div>
				<button type="button" class="bn-lightbox__close" data-bn-lb-close aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
			</header>

			<div class="bn-lightbox__panel-body">
				<p class="bn-lightbox__views" data-bn-lb-views></p>

				<div class="bn-lightbox__reactions" role="group" aria-label="<?php esc_attr_e( 'React to this media', 'buddynext' ); ?>">
					<?php foreach ( $bn_lb_reactions as $bn_lb_slug => $bn_lb_label ) : ?>
						<button type="button" class="bn-lightbox__reaction" data-reaction="<?php echo esc_attr( $bn_lb_slug ); ?>" aria-label="<?php echo esc_attr( $bn_lb_label ); ?>" aria-pressed="false">
							<?php echo buddynext_get_emoji( $bn_lb_slug, 'bn-lightbox__reaction-emoji' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="bn-lightbox__reaction-count" data-bn-lb-reaction-count hidden>0</span>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="bn-lightbox__actions">
					<button type="button" class="bn-lightbox__action" data-bn-lb-favorite aria-pressed="false">
						<?php buddynext_icon( 'heart' ); ?><span><?php esc_html_e( 'Favorite', 'buddynext' ); ?></span>
					</button>
					<button type="button" class="bn-lightbox__action" data-bn-lb-share>
						<?php buddynext_icon( 'share' ); ?><span><?php esc_html_e( 'Share', 'buddynext' ); ?></span>
					</button>
					<a class="bn-lightbox__action" data-bn-lb-download download target="_blank" rel="noopener">
						<?php buddynext_icon( 'download' ); ?><span><?php esc_html_e( 'Download', 'buddynext' ); ?></span>
					</a>
					<a class="bn-lightbox__action" data-bn-lb-open target="_blank" rel="noopener">
						<?php buddynext_icon( 'external-link' ); ?><span><?php esc_html_e( 'Open', 'buddynext' ); ?></span>
					</a>
				</div>

				<div class="bn-lightbox__comments" data-bn-lb-comments aria-live="polite"></div>
			</div>

			<form class="bn-lightbox__comment-form" data-bn-lb-comment-form>
				<input type="text" class="bn-lightbox__comment-input" data-bn-lb-comment-input placeholder="<?php esc_attr_e( 'Add a comment…', 'buddynext' ); ?>" autocomplete="off">
				<button type="submit" class="bn-btn" data-variant="primary" data-size="sm"><?php esc_html_e( 'Post', 'buddynext' ); ?></button>
			</form>
		</aside>
	</div>
</div>
