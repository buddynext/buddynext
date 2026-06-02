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

		$html = '<div class="bn-post-card__media bn-post-card__media-grid bn-post-card__media-grid--' . esc_attr( $grid ) . '" data-bn-media-grid>';
		foreach ( $items as $item ) {
			$html .= self::tile( $item );
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render one media tile by type.
	 *
	 * @param array<string,mixed> $d Descriptor from MediaUrlResolver.
	 * @return string
	 */
	private static function tile( array $d ): string {
		$id    = (int) $d['id'];
		$type  = (string) $d['type'];
		$thumb = esc_url( '' !== (string) $d['thumb'] ? (string) $d['thumb'] : (string) $d['url'] );
		$full  = esc_url( (string) $d['url'] );
		$alt   = esc_attr( (string) $d['title'] );

		if ( 'video' === $type ) {
			return '<button type="button" class="bn-media-tile bn-media-tile--video" '
				. 'data-bn-media-id="' . $id . '" data-media-type="video" data-media-src="' . $full . '" '
				. 'aria-label="' . esc_attr__( 'Play video', 'buddynext' ) . '">'
				. '<img class="bn-media-tile__img" src="' . $thumb . '" alt="' . $alt . '" loading="lazy" decoding="async">'
				. '<span class="bn-media-tile__play" aria-hidden="true">' . IconService::render( 'play', 'bn-media-tile__play-icon' ) . '</span>'
				. '</button>';
		}

		if ( 'audio' === $type ) {
			return '<div class="bn-media-tile bn-media-tile--audio" data-bn-media-id="' . $id . '" data-media-type="audio">'
				. '<span class="bn-media-tile__audio-icon" aria-hidden="true">' . IconService::render( 'music', '' ) . '</span>'
				. '<audio class="bn-media-tile__audio-player" controls preload="none" src="' . $full . '"></audio>'
				. '</div>';
		}

		// Image (default) — button so the BN lightbox can bind.
		return '<button type="button" class="bn-media-tile bn-media-tile--image" '
			. 'data-bn-media-id="' . $id . '" data-media-type="image" data-media-src="' . $full . '" '
			. 'aria-label="' . ( '' !== $alt ? $alt : esc_attr__( 'View image', 'buddynext' ) ) . '">'
			. '<img class="bn-media-tile__img" src="' . $thumb . '" alt="' . $alt . '" loading="lazy" decoding="async">'
			. '</button>';
	}
}
