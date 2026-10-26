<?php
/**
 * Fixture space-link source.
 *
 * @package BuddyNext\Dev\AddonFixture
 */

declare( strict_types=1 );

namespace BuddyNext\Dev\AddonFixture\Modules;

use BuddyNext\Addons\SpaceLinkSource;

/**
 * "Groups" whose rosters a test sets in `$rosters` (group id => user ids).
 */
final class Links implements SpaceLinkSource {

	/**
	 * Roster per group id.
	 *
	 * @var array<int,int[]>
	 */
	public static array $rosters = array();

	/**
	 * Types.
	 *
	 * @return string[]
	 */
	public function link_types(): array {
		return array( 'group' );
	}

	/**
	 * Name and URL; empty for group 999 (deleted).
	 *
	 * @param string $type      Type.
	 * @param int    $object_id Group.
	 * @return array{name:string,url:string}
	 */
	public function link_label( string $type, int $object_id ): array {
		return 999 === $object_id ? array(
			'name' => '',
			'url'  => '',
		) : array(
			'name' => 'Group ' . $object_id,
			'url'  => 'https://example.test/group/' . $object_id,
		);
	}

	/**
	 * One page of a roster.
	 *
	 * @param string $type      Type.
	 * @param int    $object_id Group.
	 * @param int    $after     Cursor.
	 * @param int    $limit     Page size.
	 * @return int[]
	 */
	public function link_roster( string $type, int $object_id, int $after, int $limit ): array {
		$ids = array_filter( self::$rosters[ $object_id ] ?? array(), static fn( int $id ): bool => $id > $after );
		sort( $ids );
		return array_slice( $ids, 0, $limit );
	}

	/**
	 * Whether the user is on the roster.
	 *
	 * @param string $type      Type.
	 * @param int    $object_id Group.
	 * @param int    $user_id   Member.
	 * @return bool
	 */
	public function link_grants_access( string $type, int $object_id, int $user_id ): bool {
		return in_array( $user_id, self::$rosters[ $object_id ] ?? array(), true );
	}
}
