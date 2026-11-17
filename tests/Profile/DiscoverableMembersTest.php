<?php
/**
 * A member the directory hides is never suggested anywhere.
 *
 * "People to follow" offered a member awaiting approval and a suspended member:
 * the random fill had its own copy of the gate (no approval hold), and the
 * friends-of-friends and shared-interest sources used the hide_posts content
 * gate, which a suspension without hidden posts passes. Every suggestion source
 * now reads MemberDirectoryService::exclusion_clauses(), and the directory
 * itself no longer lists an account awaiting approval.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\MemberDirectoryService;

/**
 * @covers \BuddyNext\Profile\MemberDirectoryService::exclusion_clauses
 * @covers \BuddyNext\Sidebar\WidgetService::suggested_follows
 * @covers \BuddyNext\SocialGraph\FollowService::suggestions
 * @covers \BuddyNext\Feed\ExploreService::suggested_member_ids
 */
class DiscoverableMembersTest extends \WP_UnitTestCase {

	/**
	 * Viewer.
	 *
	 * @var int
	 */
	private int $viewer;

	/**
	 * Hidden members keyed by why.
	 *
	 * @var array<string,int>
	 */
	private array $hidden = array();

	/**
	 * A visible member.
	 *
	 * @var int
	 */
	private int $visible;

	/**
	 * Seed a viewer, a friend whose follows feed friends-of-friends, and members
	 * in every hidden state.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_cache_flush();

		$this->viewer  = self::factory()->user->create( array( 'user_login' => 'viewer_v' ) );
		$this->visible = self::factory()->user->create( array( 'user_login' => 'nina_visible' ) );

		$this->hidden['held'] = self::factory()->user->create( array( 'user_login' => 'nina_held' ) );
		update_user_meta( $this->hidden['held'], 'bn_pending_approval', '1' );

		$this->hidden['suspended'] = self::factory()->user->create( array( 'user_login' => 'nina_suspended' ) );
		buddynext_service( 'moderation' )->suspend_user( $this->hidden['suspended'], 0, 'Spam', array( 'hide_posts' => false ) );

		$this->hidden['opted_out'] = self::factory()->user->create( array( 'user_login' => 'nina_optout' ) );
		update_user_meta( $this->hidden['opted_out'], 'bn_privacy_show_in_directory', '0' );

		// Friend of the viewer follows everyone, so friends-of-friends sees them all.
		$friend  = self::factory()->user->create();
		$follows = buddynext_service( 'follows' );
		$follows->follow( $this->viewer, $friend );
		foreach ( array_merge( array( $this->visible ), array_values( $this->hidden ) ) as $id ) {
			$follows->follow( $friend, $id );
		}
		wp_cache_flush();
	}

	public function test_friends_of_friends_skip_hidden_members(): void {
		$ids = array_map( 'intval', (array) buddynext_service( 'follows' )->suggestions( $this->viewer ) );

		$this->assertContains( $this->visible, $ids );
		foreach ( $this->hidden as $why => $id ) {
			$this->assertNotContains( $id, $ids, "A {$why} member is not suggested." );
		}
	}

	public function test_people_to_follow_never_offers_a_hidden_member(): void {
		$rows = (array) buddynext_service( 'sidebar_widgets' )->suggested_follows( $this->viewer, 20 );
		$ids  = array_map( static fn( $r ): int => (int) $r->ID, $rows );

		foreach ( $this->hidden as $why => $id ) {
			$this->assertNotContains( $id, $ids, "A {$why} member is not offered." );
		}
	}

	public function test_the_random_fill_skips_hidden_members(): void {
		$fill = new \ReflectionMethod( \BuddyNext\Sidebar\WidgetService::class, 'random_discovery_fill' );
		$fill->setAccessible( true );
		$ids = (array) $fill->invoke( null, $this->viewer, 50, array( $this->viewer ) );

		$this->assertContains( $this->visible, $ids );
		foreach ( $this->hidden as $why => $id ) {
			$this->assertNotContains( $id, $ids, "A {$why} member is not in the fill." );
		}
	}

	public function test_explore_people_skip_a_held_member(): void {
		wp_set_current_user( $this->viewer );
		$ids = ( new \BuddyNext\Feed\ExploreService() )->suggested_member_ids( 20 );

		$this->assertContains( $this->visible, $ids );
		$this->assertNotContains( $this->hidden['held'], $ids );
	}

	public function test_the_directory_search_does_not_list_a_member_awaiting_approval(): void {
		$result = ( new MemberDirectoryService() )->list_members( $this->viewer, null, 20, array( 'search' => 'nina' ) );
		$ids    = array_map( static fn( array $i ): int => (int) $i['user_id'], $result['items'] );

		$this->assertContains( $this->visible, $ids );
		$this->assertNotContains( $this->hidden['held'], $ids );
	}
}
