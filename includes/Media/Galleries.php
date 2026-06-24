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
