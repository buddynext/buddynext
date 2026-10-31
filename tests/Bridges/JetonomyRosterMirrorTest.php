<?php
/**
 * Syncing a space and its linked forum is one join, not one per plugin.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace Jetonomy\Models {
	if ( ! class_exists( SpaceMember::class ) ) {
		/**
		 * Records whether each roster write ran as a mirror.
		 */
		class SpaceMember {
			/** @var bool[] */
			public static array $mirrored = array();

			public static function is_member( int $forum_id, int $user_id ): bool {
				unset( $forum_id, $user_id );
				return false;
			}

			public static function add( int $forum_id, int $user_id, string $role = 'member' ): bool {
				unset( $forum_id, $user_id, $role );
				self::$mirrored[] = \BuddyNext\Feed\IntegrationActivity::is_mirror();
				return true;
			}
		}
	}
}

namespace BuddyNext\Tests\Bridges {

	use BuddyNext\Bridges\JetonomyBridge;
	use BuddyNext\Feed\IntegrationActivity;

	/**
	 * @covers \BuddyNext\Bridges\JetonomyBridge
	 */
	class JetonomyRosterMirrorTest extends \WP_UnitTestCase {

		public function test_a_space_member_put_on_the_forum_is_a_mirror_write(): void {
			\Jetonomy\Models\SpaceMember::$mirrored = array();
			$put = new \ReflectionMethod( JetonomyBridge::class, 'put_on_forum' );
			$put->invoke( new JetonomyBridge(), 7, self::factory()->user->create(), 'member' );

			$this->assertSame( array( true ), \Jetonomy\Models\SpaceMember::$mirrored );
			$this->assertFalse( IntegrationActivity::is_mirror(), 'the flag does not outlive the write' );
		}
	}
}
