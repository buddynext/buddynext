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
use BuddyNext\Core\PageRouter;

/**
 * Renders BN media grids/tiles.
 */
class MediaRenderer {

	/**
	 * Render a count-based media grid for a set of media ids.
	 *
	 * @param int[] $media_ids      Ordered media ids.
	 * @param int   $source_post_id Post the media belongs to (0 when unknown).
	 *                              Threaded onto each tile as `data-post-id` so
	 *                              the lightbox Share opens the post's rich Share
	 *                              modal instead of copying the page URL.
	 * @return string HTML (empty string if nothing resolvable).
	 */
	public static function grid( array $media_ids, int $source_post_id = 0 ): string {
		$items = MediaUrlResolver::descriptors( $media_ids );
		if ( empty( $items ) ) {
			return '';
		}

		// Every tile in a post-card grid belongs to the same post, so the
		// permalink is resolved once here rather than per media lookup.
		$permalink = $source_post_id > 0 ? PageRouter::post_url( $source_post_id ) : '';

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
			$html .= self::tile( $item, $more, $single, $source_post_id, $permalink );
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
	 * @param int[]             $media_ids    Ordered media ids.
	 * @param array<string,int> $source_scope Optional scope to constrain the
	 *                                        media->source-post lookup to an
	 *                                        index-backed subset of bn_posts.
	 *                                        Keys: `user_id` (profile/album
	 *                                        gallery) or `space_id` (space
	 *                                        gallery). Without it the lookup
	 *                                        still runs but scans more rows.
	 * @return string HTML (empty string if nothing resolvable).
	 */
	public static function gallery( array $media_ids, array $source_scope = array() ): string {
		$items = MediaUrlResolver::descriptors( $media_ids );
		if ( empty( $items ) ) {
			return '';
		}

		// A gallery mixes media from posts, direct uploads, and albums, so the
		// source post differs per tile (and may be absent). Batch the whole set
		// into ONE query (no per-tile lookup) so the lightbox Share can open the
		// originating post's Share modal where a post exists.
		$resolved_ids = array();
		foreach ( $items as $item ) {
			$resolved_ids[] = (int) $item['id'];
		}
		$map = self::source_post_map( $resolved_ids, $source_scope );

		$html = '<div class="bn-media-gallery" data-bn-media-grid>';
		foreach ( $items as $item ) {
			$mid       = (int) $item['id'];
			$post_id   = isset( $map[ $mid ] ) ? (int) $map[ $mid ]['post_id'] : 0;
			$permalink = isset( $map[ $mid ] ) ? (string) $map[ $mid ]['permalink'] : '';
			$html     .= self::tile( $item, 0, false, $post_id, $permalink );
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Map a bounded set of media ids to the BuddyNext post each came from.
	 *
	 * One query for the whole gallery (never per tile). Media stores its source
	 * post in `bn_posts.media_ids` (JSON array); JSON_CONTAINS does an exact
	 * array-element match (a LIKE would false-match 5 against 50/15). When a
	 * scope is supplied the query is narrowed with the `user_feed` / `space_feed`
	 * index first, so it stays sargable at 100k+ posts. Newest post wins when a
	 * media id appears in more than one post.
	 *
	 * @param int[]             $media_ids Resolved media ids (already bounded by
	 *                                     the gallery page size).
	 * @param array<string,int> $scope     Optional { user_id | space_id }.
	 * @return array<int,array{post_id:int,permalink:string}> media_id => source.
	 */
	private static function source_post_map( array $media_ids, array $scope = array() ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $media_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$scope_user  = isset( $scope['user_id'] ) ? absint( $scope['user_id'] ) : 0;
		$scope_space = isset( $scope['space_id'] ) ? absint( $scope['space_id'] ) : 0;

		$cache_key   = 'src_post:' . md5( $scope_user . ':' . $scope_space . ':' . implode( ',', $ids ) );
		$cache_group = 'bn_media';
		$cached      = wp_cache_get( $cache_key, $cache_group );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$where  = "status = 'published' AND media_ids IS NOT NULL AND media_ids <> '' AND media_ids <> '[]' AND JSON_VALID(media_ids)";
		$params = array();
		if ( $scope_user > 0 ) {
			$where   .= ' AND user_id = %d';
			$params[] = $scope_user;
		} elseif ( $scope_space > 0 ) {
			$where   .= ' AND space_id = %d';
			$params[] = $scope_space;
		}

		$json_clauses = array();
		foreach ( $ids as $mid ) {
			$json_clauses[] = 'JSON_CONTAINS(media_ids, %s)';
			$params[]       = (string) $mid;
		}

		$sql = "SELECT id, media_ids FROM {$wpdb->prefix}bn_posts
			WHERE {$where} AND ( " . implode( ' OR ', $json_clauses ) . ' )
			ORDER BY created_at DESC';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$want = array_flip( $ids );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['id'];
			$decoded = json_decode( (string) $row['media_ids'], true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $mid ) {
				$mid = absint( $mid );
				// Rows are newest-first, so the first post to claim a media id
				// wins and later (older) posts do not overwrite it.
				if ( $mid > 0 && isset( $want[ $mid ] ) && ! isset( $map[ $mid ] ) ) {
					$map[ $mid ] = array(
						'post_id'   => $post_id,
						'permalink' => PageRouter::post_url( $post_id ),
					);
				}
			}
		}

		// Media->post is effectively immutable once attached, so a short cache
		// is safe; each distinct gallery page keys its own entry.
		wp_cache_set( $cache_key, $map, $cache_group, HOUR_IN_SECONDS );

		return $map;
	}

	/**
	 * Render one media tile by type.
	 *
	 * @param array<string,mixed> $d    Descriptor from MediaUrlResolver.
	 * @param int                 $more  When > 0, this tile shows a "+N more"
	 *                                   overlay (collage with hidden extras).
	 * @param bool                $hires When true (single-image layout), add a
	 *                                   2x srcset so retina renders crisply.
	 * @param int                 $post_id   Source post id (0 when the media did
	 *                                        not come from a post). Emitted as
	 *                                        `data-post-id` so the lightbox Share
	 *                                        opens that post's Share modal.
	 * @param string              $permalink Source post permalink (optional).
	 * @return string
	 */
	private static function tile( array $d, int $more = 0, bool $hires = false, int $post_id = 0, string $permalink = '' ): string {
		$id        = (int) $d['id'];
		$type      = (string) $d['type'];
		$raw_thumb = (string) $d['thumb'];
		$full      = esc_url( (string) $d['url'] );
		$alt       = esc_attr( (string) $d['title'] );
		$more_attr = $more > 0 ? ' data-bn-media-more="' . (int) $more . '"' : '';

		// Source-post link — only when the media actually came from a post, so
		// the lightbox can thread a real post id into the Share modal (and omit
		// it for orphan uploads, which fall back to Copy link).
		$post_attr = '';
		if ( $post_id > 0 ) {
			$post_attr = ' data-post-id="' . $post_id . '"';
			if ( '' !== $permalink ) {
				$post_attr .= ' data-post-permalink="' . esc_url( $permalink ) . '"';
			}
		}

		if ( 'video' === $type ) {
			// Only paint a poster <img> when the engine produced a real
			// thumbnail. With no poster (e.g. no server-side frame extraction)
			// fall back to a poster-less tile — the CSS dark surface + play
			// overlay read correctly without a broken image.
			$poster = '' !== $raw_thumb
				? '<img class="bn-media-tile__img" src="' . esc_url( $raw_thumb ) . '" alt="' . $alt . '" loading="lazy" decoding="async">'
				: '';

			return '<button type="button" class="bn-media-tile bn-media-tile--video' . ( '' === $poster ? ' bn-media-tile--no-poster' : '' ) . '" '
				. 'data-bn-media-id="' . $id . '" data-media-type="video" data-media-src="' . $full . '"' . $more_attr . $post_attr . ' '
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
			. 'data-bn-media-id="' . $id . '" data-media-type="image" data-media-src="' . $full . '"' . $more_attr . $post_attr . ' '
			. 'aria-label="' . ( '' !== $alt ? $alt : esc_attr__( 'View image', 'buddynext' ) ) . '">'
			. '<img class="bn-media-tile__img" src="' . $img_src . '"' . $srcset . ' alt="' . $alt . '" loading="lazy" decoding="async">'
			. '</button>';
	}
}
