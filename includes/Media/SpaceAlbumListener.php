<?php
/**
 * Keeps space-owned albums tied to the life of their space.
 *
 * A space album is an mvs_album post whose only link to the space is the
 * `_bn_space_id` meta {@see Galleries::SPACE_META}, and whose post_author is
 * the member who happened to create it. That combination is why this listener
 * has to exist: delete the space without it and the albums survive as orphans
 * authored by a member - so they would quietly reappear in that member's own
 * profile gallery, which is the one place an owner would never think to look
 * for content they had just deleted.
 *
 * @package BuddyNext\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Media;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Removes a space's albums when the space itself goes.
 */
class SpaceAlbumListener implements ListenerInterface {

	/**
	 * Hook the space lifecycle.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'buddynext_space_deleted', array( $this, 'on_space_deleted' ) );
	}

	/**
	 * Delete every album belonging to a deleted space.
	 *
	 * Items are removed through the engine first so its own index and album-item
	 * rows are cleared, then the album post itself - the same order the REST
	 * delete uses, rather than a second opinion about what deleting an album
	 * means.
	 *
	 * @param int $space_id Space that was deleted.
	 * @return void
	 */
	public function on_space_deleted( $space_id ): void {
		$space_id = (int) $space_id;
		if ( $space_id <= 0 || ! post_type_exists( 'mvs_album' ) ) {
			return;
		}

		$albums = get_posts(
			array(
				'post_type'      => 'mvs_album',
				'post_status'    => 'any',
				'fields'         => 'ids',
				// A space's album count is small, and this runs once per space
				// deletion, so an unbounded fetch here is bounded in practice.
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Runs once on space deletion; a space holds few albums.
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-off cleanup on an indexed meta key.
				'meta_query'     => array(
					array(
						'key'   => Galleries::SPACE_META,
						'value' => $space_id,
					),
				),
			)
		);

		if ( empty( $albums ) ) {
			return;
		}

		$service = MediaClient::albums();

		foreach ( $albums as $album_id ) {
			$album_id = (int) $album_id;

			if ( $service && method_exists( $service, 'delete_all_items' ) ) {
				$service->delete_all_items( $album_id );
			}

			wp_delete_post( $album_id, true );
		}
	}
}
