<?php
/**
 * Erasing a member refreshes the cached member count of their spaces.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\MemberCleanupService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * The purge cleared the cache group 'bn_spaces', which nothing reads; spaces are
 * cached in 'buddynext_spaces', so a deleted member's spaces kept the old count.
 */
class MemberCleanupSpaceCacheTest extends \WP_UnitTestCase {

	public function test_the_cached_space_is_dropped_when_a_member_is_purged(): void {
		$owner    = self::factory()->user->create();
		$member   = self::factory()->user->create();
		$spaces   = new SpaceService();
		$space_id = $spaces->create(
			$owner,
			array(
				'name' => 'Cache space',
				'slug' => 'cache-space',
				'type' => 'open',
			)
		);
		$this->assertIsInt( $space_id );
		$this->assertTrue( true === ( new SpaceMemberService() )->join( $space_id, $member ) );

		$before = $spaces->get( $space_id );
		$this->assertIsArray( $before, 'Primes the cache.' );

		( new MemberCleanupService() )->purge_user_relations( $member, 'delete' );

		$after = $spaces->get( $space_id );
		$this->assertSame( (int) $before['member_count'] - 1, (int) $after['member_count'] );
	}
}
