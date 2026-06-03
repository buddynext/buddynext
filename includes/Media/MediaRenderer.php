<?php
/**
 * BuddyNext-native media markup (grid / tiles) from WPMediaVerse media ids.
 *
 * Produces BuddyNext's OWN markup + classes (no WPMediaVerse JS/CSS) — image and
 * video tiles are buttons carrying `data-bn-media-id` for the BN lightbox
 * (Phase 1) to bind; audio uses a native player. URLs come from
 * MediaUrlResolver (signed, broadcast TTL).
 *
 * @package BuddyNext\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Media;

use BuddyNext\Core\IconService;

/**
 * Renders BN media grids/tiles.
 */
class MediaRenderer {

	/**
	 * Render a count-based media grid for a set of media ids.
	 *
	 * @param int[] $media_ids Ordered media ids.
	 * @return string HTML (empty string if nothing resolvable).
	 */
	public static function grid( array $media_ids ): string {
		$items = MediaUrlResolver::descriptors( $media_ids );
		if ( empty( $items ) ) {
			return '';
		}

		$count = count( $items );
		$grid  = $count >= 4 ? '4' : (string) $count;

		// Instagram/Facebook-style collage: at most four tiles are visible
		// (1 big + stacked / 2-up / 2x2); the fourth carries a "+N" overlay
		// when there are more. Every tile stays in the DOM (extras hidden via
		// CSS) so the lightbox still navigates the full set.
		$single = ( 1 === $count );
		$html   = '<div class="bn-post-card__media bn-post-card__media-grid bn-post-card__media-grid--' . esc_attr( $grid ) . '" data-bn-media-grid>';
		foreach ( $items as $i => $item ) {
			$more  = ( $count > 4 && 3 === $i ) ? ( $count - 4 ) : 0;
			$html .= self::tile( $item, $more, $single );
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render a uniform gallery grid for a profile / space "Media" surface.
	 *
	 * Unlike grid() (the 1–4 Instagram-style post layout), this is an even
	 * 3-column gallery for an arbitrary number of items. It reuses the same
	 * lightbox-bound tiles, so clicking opens the BN lightbox with gallery
	 * prev/next across the whole grid.
	 *
	 * @param int[] $media_ids Ordered media ids.
	 * @return string HTML (empty string if nothing resolvable).
	 */
	public static function gallery( array $media_ids ): string {
		$items = MediaUrlResolver::descriptors( $media_ids );
		if ( empty( $items ) ) {
			return '';
		}

		$html = '<div class="bn-media-gallery" data-bn-media-grid>';
		foreach ( $items as $item ) {
			$html .= self::tile( $item );
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render one media tile by type.
	 *
	 * @param array<string,mixed> $d    Descriptor from MediaUrlResolver.
	 * @param int                 $more  When > 0, this tile shows a "+N more"
	 *                                   overlay (collage with hidden extras).
	 * @param bool                $hires When true (single-image layout), add a
	 *                                   2x srcset so retina renders crisply.
	 * @return string
	 */
	private static function tile( array $d, int $more = 0, bool $hires = false ): string {
		$id        = (int) $d['id'];
		$type      = (string) $d['type'];
		$raw_thumb = (string) $d['thumb'];
		$full      = esc_url( (string) $d['url'] );
		$alt       = esc_attr( (string) $d['title'] );
		$more_attr = $more > 0 ? ' data-bn-media-more="' . (int) $more . '"' : '';

		if ( 'video' === $type ) {
			// Only paint a poster <img> when the engine produced a real
			// thumbnail. With no poster (e.g. no server-side frame extraction)
			// fall back to a poster-less tile — the CSS dark surface + play
			// overlay read correctly without a broken image.
			$poster = '' !== $raw_thumb
				? '<img class="bn-media-tile__img" src="' . esc_url( $raw_thumb ) . '" alt="' . $alt . '" loading="lazy" decoding="async">'
				: '';

			return '<button type="button" class="bn-media-tile bn-media-tile--video' . ( '' === $poster ? ' bn-media-tile--no-poster' : '' ) . '" '
				. 'data-bn-media-id="' . $id . '" data-media-type="video" data-media-src="' . $full . '"' . $more_attr . ' '
				. 'aria-label="' . esc_attr__( 'Play video', 'buddynext' ) . '">'
				. $poster
				. '<span class="bn-media-tile__play" aria-hidden="true">' . IconService::render( 'play', 'bn-media-tile__play-icon' ) . '</span>'
				. '</button>';
		}

		if ( 'audio' === $type ) {
			$audio_title = '' !== $alt ? '<span class="bn-media-tile__audio-title">' . $alt . '</span>' : '';
			return '<div class="bn-media-tile bn-media-tile--audio" data-bn-media-id="' . $id . '" data-media-type="audio">'
				. '<span class="bn-media-tile__audio-icon" aria-hidden="true">' . IconService::render( 'music', '' ) . '</span>'
				. $audio_title
				. '<audio class="bn-media-tile__audio-player" controls preload="none" src="' . $full . '"></audio>'
				. '</div>';
		}

		// Image (default) — button so the BN lightbox can bind. Use the poster
		// thumbnail when present, otherwise the full file (still a valid image).
		$img_src = esc_url( '' !== $raw_thumb ? $raw_thumb : (string) $d['url'] );

		// Retina: for the single, full-width layout the thumbnail can look soft
		// on 2x displays, so offer the full original as the 2x source. Collage
		// tiles are small enough that thumb_large is already crisp at 2x, so we
		// skip the heavier 2x source there.
		$srcset = '';
		if ( $hires && '' !== $raw_thumb && $full !== $img_src ) {
			$srcset = ' srcset="' . $img_src . ' 1x, ' . $full . ' 2x"';
		}

		return '<button type="button" class="bn-media-tile bn-media-tile--image" '
			. 'data-bn-media-id="' . $id . '" data-media-type="image" data-media-src="' . $full . '"' . $more_attr . ' '
			. 'aria-label="' . ( '' !== $alt ? $alt : esc_attr__( 'View image', 'buddynext' ) ) . '">'
			. '<img class="bn-media-tile__img" src="' . $img_src . '"' . $srcset . ' alt="' . $alt . '" loading="lazy" decoding="async">'
			. '</button>';
	}
}
