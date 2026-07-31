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
 * Owner-scoped media listings for profile and space galleries.
 */
class Galleries {

	/**
	 * Post meta that makes an album belong to a space.
	 *
	 * Presence of this key is the ONLY thing that distinguishes a space album
	 * from a personal one. It lives on the mvs_album post rather than in the
	 * engine's own store because BuddyNext already queries that CPT with
	 * WP_Query here, so a space listing is the same query with a meta clause -
	 * and because the engine's own album group_id gates on
	 * groups_is_user_member(), a BuddyPress function, for BP groups. BuddyNext
	 * spaces are not BP groups, and BuddyPress is not active after a migration.
	 */
	public const SPACE_META = '_bn_space_id';

	/**
	 * The space an album belongs to, or 0 when it is a personal album.
	 *
	 * @param int $album_id Album (mvs_album) id.
	 */
	public static function album_space( int $album_id ): int {
		return $album_id > 0 ? (int) get_post_meta( $album_id, self::SPACE_META, true ) : 0;
	}

	/**
	 * Whether a member may create an album in a space.
	 *
	 * Members by default, matching who can already post there - a space that
	 * lets you post but not make an album would be an odd line to draw. An owner
	 * who runs a curated space can restrict it to admins and moderators with the
	 * per-space `album_creators` field; uploading INTO an existing album stays
	 * open to members either way, which is the shape people know from elsewhere.
	 *
	 * @param int $space_id  Space id.
	 * @param int $viewer_id Viewer user id.
	 */
	public static function can_create_space_album( int $space_id, int $viewer_id ): bool {
		if ( $space_id <= 0 || $viewer_id <= 0 ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$context    = array( 'space_id' => $space_id );
		$is_staff   = function_exists( 'buddynext_can' )
			&& ( (bool) buddynext_can( $viewer_id, 'buddynext-manage-space', $context )
				|| (bool) buddynext_can( $viewer_id, 'buddynext-moderate-space', $context ) );
		$restricted = 'admins' === (string) ( function_exists( 'buddynext_get_space_field' )
			? buddynext_get_space_field( $space_id, 'album_creators' )
			: '' );

		if ( $restricted ) {
			return $is_staff;
		}

		return $is_staff
			|| ( new \BuddyNext\Spaces\SpaceMemberService() )->is_member( $space_id, $viewer_id );
	}

	/**
	 * Make an album belong to a space.
	 *
	 * The one supported way to create the association, so a caller outside this
	 * plugin - the importer, bringing a BuddyBoss group album across - does not
	 * have to know the meta key or guess at the privacy rule that goes with it.
	 *
	 * Passing space 0 detaches the album and makes it personal again.
	 *
	 * @param int $album_id Album (mvs_album) id.
	 * @param int $space_id Space id, or 0 to detach.
	 * @return bool Whether the association changed.
	 */
	public static function assign_album_to_space( int $album_id, int $space_id ): bool {
		if ( $album_id <= 0 || 'mvs_album' !== get_post_type( $album_id ) ) {
			return false;
		}

		if ( $space_id <= 0 ) {
			return (bool) delete_post_meta( $album_id, self::SPACE_META );
		}

		update_post_meta( $album_id, self::SPACE_META, $space_id );

		return true;
	}

	/**
	 * Space albums a media item belongs to.
	 *
	 * Used to tell a member what removing a photo is about to affect. A photo
	 * shared into a space stays the member's own - they may delete it - but it
	 * is also sitting in somebody's shared album, and finding that out
	 * afterwards is how a space's gallery quietly develops holes.
	 *
	 * @param int $media_id Media id.
	 * @return array<int,array{id:int,title:string,space_id:int}>
	 */
	public static function space_albums_for_media( int $media_id ): array {
		$albums = MediaClient::albums();
		if ( ! $albums || ! method_exists( $albums, 'albums_for_media' ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) $albums->albums_for_media( $media_id ) as $album_id ) {
			$space_id = self::album_space( (int) $album_id );
			if ( $space_id > 0 ) {
				$out[] = array(
					'id'       => (int) $album_id,
					'title'    => (string) get_the_title( (int) $album_id ),
					'space_id' => $space_id,
				);
			}
		}

		return $out;
	}

	/**
	 * Albums belonging to a space that the viewer may see (newest first).
	 *
	 * The space decides the audience, so this gates once on the space rather
	 * than per album: every album in a space shares that space's visibility.
	 *
	 * @param int $space_id  Space id.
	 * @param int $viewer_id Viewer user id (0 = logged out).
	 * @param int $limit     Max albums.
	 * @param int $offset    Pagination offset.
	 * @return array<int,array<string,mixed>> Ordered album summaries.
	 */
	public static function space_albums( int $space_id, int $viewer_id, int $limit = 24, int $offset = 0 ): array {
		if ( $space_id <= 0 || ! post_type_exists( 'mvs_album' ) ) {
			return array();
		}

		if ( ! self::can_view_space( $space_id, $viewer_id ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'mvs_album',
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => max( 1, $limit ),
				'offset'         => max( 0, $offset ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed meta lookup; a space's album count is small.
				'meta_query'     => array(
					array(
						'key'   => self::SPACE_META,
						'value' => $space_id,
					),
				),
			)
		);

		$out = array();
		foreach ( (array) $query->posts as $album_id ) {
			$out[] = self::album_summary( (int) $album_id );
		}

		return $out;
	}

	/**
	 * Whether the viewer may see a space at all.
	 *
	 * One place, so every album surface asks the same question. Falls back to
	 * "visible" only for a space that cannot be resolved, which is the same
	 * shape the rest of the space UI uses.
	 *
	 * @param int $space_id  Space id.
	 * @param int $viewer_id Viewer user id.
	 */
	public static function can_view_space( int $space_id, int $viewer_id ): bool {
		if ( $space_id <= 0 || ! function_exists( 'buddynext_service' ) ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$space = buddynext_service( 'spaces' )->get( $space_id );
		if ( null === $space ) {
			return false;
		}

		// The registry already answers this question for every other space
		// surface - an open space's content is readable by anyone, anything else
		// is members-only. Asking it here rather than re-deriving the rule keeps
		// albums in step with the feed and the member list if the rule changes.
		if ( ! \BuddyNext\Spaces\SpaceTypeRegistry::instance()->content_requires_membership( (string) $space['type'] ) ) {
			return true;
		}

		return $viewer_id > 0
			&& ( new \BuddyNext\Spaces\SpaceMemberService() )->is_member( $space_id, $viewer_id );
	}

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
				// A space album is authored by whoever created it, so without this
				// the space's albums would appear in that member's own profile
				// grid - and would still be sitting there after the space itself
				// was deleted.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small per-author set.
				'meta_query'     => array(
					array(
						'key'     => self::SPACE_META,
						'compare' => 'NOT EXISTS',
					),
				),
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
		// A space album's audience is the SPACE, always. The engine's privacy
		// seam knows nothing about spaces, so asking it would judge a private
		// space's album on its own privacy field and read it as public.
		$space_id = self::album_space( $album_id );
		if ( $space_id > 0 ) {
			return self::can_view_space( $space_id, $viewer_id );
		}

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
			'space_id'    => self::album_space( $album_id ),
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
