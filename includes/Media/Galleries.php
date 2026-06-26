<?php
/**
 * Profile / space gallery reads from WPMediaVerse (API-level only).
 *
 * BuddyNext owns the gallery UX; this adapter is the single seam that turns a
 * "show this owner's media" request into engine calls. Privacy is delegated to
 * the engine: MediaRepository::query_by_author() already hides `private` media
 * from non-owner / non-admin viewers, so BuddyNext never has to filter rows
 * itself (and private media cannot leak into a profile grid).
 *
 * @package BuddyNext\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Media;

/**
 * Owner-scoped media listings for profile (and later space) galleries.
 */
class Galleries {

	/**
	 * Ordered media ids owned by a user, visible to the viewer.
	 *
	 * Newest first. Private media is included only when the viewer is the
	 * owner or a moderator (the engine decides — see query_by_author()).
	 * Resolved ids are prefetched so the subsequent MediaRenderer pass does
	 * not issue a query per tile.
	 *
	 * @param int $owner_id  Media owner user id.
	 * @param int $viewer_id Current viewer user id (0 = logged out).
	 * @param int $limit     Max rows.
	 * @param int $offset    Offset for pagination / Load More.
	 * @return int[] Ordered media ids (empty when none / engine absent).
	 */
	public static function user_media_ids( int $owner_id, int $viewer_id, int $limit = 24, int $offset = 0 ): array {
		$repo = MediaClient::repo();
		if ( ! $repo || ! method_exists( $repo, 'query_by_author' ) ) {
			return array();
		}

		$rows = $repo->query_by_author(
			$owner_id,
			array(
				'viewer_id' => $viewer_id,
				'limit'     => max( 1, $limit ),
				'offset'    => max( 0, $offset ),
				'status'    => 'publish',
			)
		);

		$ids = array();
		foreach ( (array) $rows as $row ) {
			$id = isset( $row['media_id'] ) ? (int) $row['media_id'] : 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( $ids && method_exists( $repo, 'prefetch' ) ) {
			$repo->prefetch( $ids );
		}

		return $ids;
	}

	/**
	 * Count of media owned by a user that the viewer may see.
	 *
	 * Mirrors query_by_author()'s privacy rule so the profile "Media" count
	 * badge matches the grid: owner / moderator see all; everyone else sees
	 * the non-private total.
	 *
	 * @param int $owner_id  Media owner user id.
	 * @param int $viewer_id Current viewer user id.
	 * @return int
	 */
	public static function user_media_count( int $owner_id, int $viewer_id ): int {
		$repo = MediaClient::repo();
		if ( ! $repo || ! method_exists( $repo, 'query_count' ) ) {
			return 0;
		}

		return (int) $repo->query_count(
			array(
				'author_id' => $owner_id,
				'status'    => 'publish',
				'privacy'   => self::can_see_private( $owner_id, $viewer_id ) ? 'any' : 'hide_private',
				'viewer_id' => $viewer_id,
			)
		);
	}

	/**
	 * Albums owned by a user that the viewer may see (newest first).
	 *
	 * The mvs_album CPT is registered, so the owner list is a core WP_Query; each
	 * album is then privacy-filtered per viewer through the engine privacy seam
	 * (album privacy is stored against the album id in the media repo). Album
	 * counts per user are small, so post-query filtering is acceptable.
	 *
	 * @param int $owner_id  Album owner user id.
	 * @param int $viewer_id Viewer user id (0 = logged out).
	 * @param int $limit     Max albums.
	 * @param int $offset    Pagination offset.
	 * @return array<int,array<string,mixed>> Ordered album summaries.
	 */
	public static function user_albums( int $owner_id, int $viewer_id, int $limit = 24, int $offset = 0 ): array {
		if ( $owner_id <= 0 || ! post_type_exists( 'mvs_album' ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'mvs_album',
				'author'         => $owner_id,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => max( 1, $limit ),
				'offset'         => max( 0, $offset ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( (array) $query->posts as $album_id ) {
			$album_id = (int) $album_id;
			if ( self::can_view_album( $album_id, $viewer_id ) ) {
				$out[] = self::album_summary( $album_id );
			}
		}

		return $out;
	}

	/**
	 * Whether the viewer may see a given album (engine privacy seam).
	 *
	 * @param int $album_id  Album (mvs_album) id.
	 * @param int $viewer_id Viewer user id.
	 * @return bool
	 */
	public static function can_view_album( int $album_id, int $viewer_id ): bool {
		$privacy = MediaClient::privacy();
		if ( ! $privacy || ! method_exists( $privacy, 'can_view' ) ) {
			// Fail closed for non-owners; owners always see their own.
			return $viewer_id > 0 && (int) get_post_field( 'post_author', $album_id ) === $viewer_id;
		}
		return (bool) $privacy->can_view( $album_id, $viewer_id );
	}

	/**
	 * Lightweight album summary for cards / list responses.
	 *
	 * @param int $album_id Album id.
	 * @return array<string,mixed> { id, title, description, privacy, media_count, cover_url, owner }.
	 */
	public static function album_summary( int $album_id ): array {
		$albums = MediaClient::albums();
		$repo   = MediaClient::repo();

		$privacy = ( $repo && method_exists( $repo, 'get' ) ) ? (string) $repo->get( $album_id, 'privacy' ) : '';

		return array(
			'id'          => $album_id,
			'title'       => (string) get_the_title( $album_id ),
			'description' => (string) get_post_field( 'post_excerpt', $album_id ),
			'privacy'     => '' !== $privacy ? $privacy : 'public',
			'owner'       => (int) get_post_field( 'post_author', $album_id ),
			'media_count' => ( $albums && method_exists( $albums, 'get_item_count' ) ) ? (int) $albums->get_item_count( $album_id ) : 0,
			'cover_url'   => ( $albums && method_exists( $albums, 'get_cover_url' ) ) ? (string) $albums->get_cover_url( $album_id, 'large' ) : '',
		);
	}

	/**
	 * Ordered media ids in an album (a page of them).
	 *
	 * @param int $album_id Album id.
	 * @param int $limit    Max ids.
	 * @param int $offset   Offset.
	 * @return int[] Ordered media ids.
	 */
	public static function album_media_ids( int $album_id, int $limit = 24, int $offset = 0 ): array {
		$albums = MediaClient::albums();
		if ( ! $albums || ! method_exists( $albums, 'get_items' ) ) {
			return array();
		}
		$items = (array) $albums->get_items( $album_id );
		$ids   = array();
		foreach ( $items as $item ) {
			$id = isset( $item['media_id'] ) ? (int) $item['media_id'] : 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_slice( $ids, max( 0, $offset ), max( 1, $limit ) );
	}

	/**
	 * Whether the viewer may see the owner's private media.
	 *
	 * @param int $owner_id  Owner user id.
	 * @param int $viewer_id Viewer user id.
	 * @return bool
	 */
	private static function can_see_private( int $owner_id, int $viewer_id ): bool {
		if ( $viewer_id > 0 && $viewer_id === $owner_id ) {
			return true;
		}
		// moderate_mvs_media is registered by WPMediaVerse (MediaCapabilities); this
		// media bridge reuses that plugin's capability rather than minting its own.
		return $viewer_id > 0 && user_can( $viewer_id, 'moderate_mvs_media' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- capability owned by the WPMediaVerse companion plugin.
	}
}
