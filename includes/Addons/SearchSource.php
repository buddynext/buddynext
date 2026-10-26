<?php
/**
 * Addon module: content in community search.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

/**
 * An addon whose content appears in community search.
 *
 * Index and remove through {@see \BuddyNext\Search\SearchService}. BuddyNext asks
 * this module for a result's link and for the next items to re-index when the
 * owner switches search on.
 *
 * @api
 * @since 1.2.7
 */
interface SearchSource {

	/**
	 * The search object types this module indexes.
	 *
	 * @return string[]
	 */
	public function search_types(): array;

	/**
	 * The link for a result of one of its types, or '' when it is not that type.
	 *
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @return string
	 */
	public function search_url( string $type, int $object_id ): string;

	/**
	 * The ids of the next items to re-index, ascending.
	 *
	 * @param int $after_id Only ids greater than this.
	 * @param int $limit    Most ids to return.
	 * @return int[]
	 */
	public function reindex_ids( int $after_id, int $limit ): array;

	/**
	 * Index one item (called with new feed cards switched off).
	 *
	 * @param int $id Item id.
	 * @return void
	 */
	public function reindex_item( int $id ): void;
}
