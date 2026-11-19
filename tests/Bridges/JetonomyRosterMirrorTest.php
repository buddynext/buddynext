<?php
/**
 * Syncing a space and its linked forum is one join, not one per plugin.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace {
	require_once __DIR__ . '/stubs/jetonomy-membership.php';
}

namespace BuddyNext\Tests\Bridges {

	use BuddyNext\Bridges\JetonomyBridge;
	use BuddyNext\Feed\IntegrationActivity;

	/**
	 * @covers \BuddyNext\Bridges\JetonomyBridge
	 */
	class JetonomyRosterMirrorTest extends \WP_UnitTestCase {

		public function test_a_space_member_put_on_the_forum_is_a_mirror_write(): void {
			\Jetonomy\Models\SpaceMember::reset();
			$put = new \ReflectionMethod( JetonomyBridge::class, 'put_on_forum' );
			$put->invoke( new JetonomyBridge(), 7, self::factory()->user->create(), 'member' );

			$this->assertSame( array( true ), \Jetonomy\Models\SpaceMember::$mirrored );
			$this->assertFalse( IntegrationActivity::is_mirror(), 'the flag does not outlive the write' );
		}
	}
}
