<?php
/**
 * Fixture search source.
 *
 * @package BuddyNext\Dev\AddonFixture
 */

declare( strict_types=1 );

namespace BuddyNext\Dev\AddonFixture\Modules;

use BuddyNext\Addons\SearchSource;

/**
 * Three items, ids 1-3.
 */
final class Search implements SearchSource {

	/**
	 * Ids passed to reindex_item, in order (tests read it).
	 *
	 * @var int[]
	 */
	public static array $reindexed = array();

	/**
	 * Types.
	 *
	 * @return string[]
	 */
	public function search_types(): array {
		return array( 'fixture_item' );
	}

	/**
	 * Result link.
	 *
	 * @param string $type      Type.
	 * @param int    $object_id Id.
	 * @return string
	 */
	public function search_url( string $type, int $object_id ): string {
		return 'https://example.test/fixture/' . $object_id;
	}

	/**
	 * Next ids.
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit    Batch.
	 * @return int[]
	 */
	public function reindex_ids( int $after_id, int $limit ): array {
		return array_slice( array_values( array_filter( array( 1, 2, 3 ), static fn( int $id ): bool => $id > $after_id ) ), 0, $limit );
	}

	/**
	 * Index one.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function reindex_item( int $id ): void {
		self::$reindexed[] = $id;
	}
}
