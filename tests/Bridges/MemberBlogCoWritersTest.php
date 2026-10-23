<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * A co-written post credits its other writers on every writer's Articles tab
 * (card 10425179051): the lead author sees the co-authors, a co-author sees
 * the lead, a solo post shows nobody.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\MemberBlogBridge;

/**
 * @covers \BuddyNext\Bridges\MemberBlogBridge
 */
class MemberBlogCoWritersTest extends \WP_UnitTestCase {

	private function writers( \WP_Post $post, int $profile_uid ): array {
		$m = new \ReflectionMethod( MemberBlogBridge::class, 'co_writers' );
		$m->setAccessible( true );
		return wp_list_pluck( $m->invoke( new MemberBlogBridge(), $post, $profile_uid ), 'id' );
	}

	public function test_each_writer_sees_the_others(): void {
		$lead = self::factory()->user->create();
		$co   = self::factory()->user->create();
		$post = get_post( self::factory()->post->create( array( 'post_author' => $lead ) ) );

		$this->assertSame( array(), $this->writers( $post, $lead ), 'Solo post: no mark.' );

		$add = static fn( $w ) => array_merge( (array) $w, array( $co ) );
		add_filter( 'bpmb_post_writers', $add );
		try {
			$this->assertSame( array( $co ), $this->writers( $post, $lead ), 'Lead author tab names the co-author.' );
			$this->assertSame( array( $lead ), $this->writers( $post, $co ), 'Co-author tab names the lead.' );
		} finally {
			remove_filter( 'bpmb_post_writers', $add );
		}
	}
}
