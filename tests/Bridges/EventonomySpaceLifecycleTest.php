<?php
/**
 * BuddyNext tells Eventonomy when a Space is deleted and when a member leaves it, and says nothing to an
 * Eventonomy that cannot act on it. Own process: the contract constant and the stub function are global.
 *
 * @package BuddyNext\Tests
 */

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\EventonomySpaceLifecycle;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Bridges\EventonomySpaceLifecycle
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EventonomySpaceLifecycleTest extends \WP_UnitTestCase {

	/** @var array<int,array{0:int,1:int}> */
	public static array $cancelled = array();
	/** @var int[] */
	public static array $removed = array();

	private function stub_eventonomy( int $contract ): void {
		define( 'EVENTONOMY_HOOK_CONTRACT', $contract );
		if ( ! function_exists( 'evnm_cancel_user_registrations' ) ) {
			eval( 'function evnm_cancel_user_registrations( int $u, int $s ): array { \BuddyNext\Tests\Bridges\EventonomySpaceLifecycleTest::$cancelled[] = array( $u, $s ); return array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		add_action(
			'evnm_space_removed',
			static function ( $space_id ): void {
				self::$removed[] = (int) $space_id;
			}
		);
		( new EventonomySpaceLifecycle() )->init();
	}

	/** @return array{0:int,1:int,2:int} Space, owner, member. */
	private function space_with_member(): array {
		$owner  = self::factory()->user->create();
		$member = self::factory()->user->create();
		$id     = (int) ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Life ' . wp_generate_password( 5, false, false ),
				'slug' => 'life-' . strtolower( wp_generate_password( 8, false, false ) ),
				'type' => 'open',
			)
		);
		( new SpaceMemberService() )->join( $id, $member );
		return array( $id, $owner, $member );
	}

	public function test_a_member_who_leaves_is_passed_to_eventonomy(): void {
		$this->stub_eventonomy( 4 );
		list( $space, $owner, $member ) = $this->space_with_member();

		( new SpaceMemberService() )->leave( $space, $member );

		$this->assertSame( array( array( $member, $space ) ), self::$cancelled );
	}

	public function test_a_member_who_is_removed_is_passed_to_eventonomy(): void {
		$this->stub_eventonomy( 4 );
		list( $space, $owner, $member ) = $this->space_with_member();

		( new SpaceMemberService() )->remove( $space, $member, $owner );

		$this->assertSame( array( array( $member, $space ) ), self::$cancelled );
	}

	public function test_a_deleted_space_is_announced_once(): void {
		$this->stub_eventonomy( 4 );
		list( $space, $owner, $member ) = $this->space_with_member();

		do_action( 'buddynext_space_delete_queued', $space, $owner );
		do_action( 'buddynext_space_deleted', $space, $owner );

		$this->assertSame( array( $space ), self::$removed );
	}

	public function test_an_eventonomy_below_contract_4_hears_nothing(): void {
		$this->stub_eventonomy( 3 );
		list( $space, $owner, $member ) = $this->space_with_member();

		( new SpaceMemberService() )->leave( $space, $member );
		do_action( 'buddynext_space_deleted', $space, $owner );

		$this->assertSame( array(), self::$cancelled );
		$this->assertSame( array(), self::$removed );
	}

	public function test_a_member_who_is_banned_is_passed_to_eventonomy(): void {
		$this->stub_eventonomy( 4 );
		list( $space, $owner, $member ) = $this->space_with_member();

		( new SpaceMemberService() )->ban_from_space( $space, $member, $owner );

		$this->assertSame( array( array( $member, $space ) ), self::$cancelled );
	}

	public function test_a_new_event_in_a_private_or_secret_space_starts_at_space_members(): void {
		$this->stub_eventonomy( 4 );
		$owner = self::factory()->user->create();
		foreach ( array( 'private', 'secret' ) as $type ) {
			$id = (int) ( new SpaceService() )->create( $owner, array( 'name' => "Def $type", 'slug' => "def-$type-" . strtolower( wp_generate_password( 6, false, false ) ), 'type' => $type ) );
			$this->assertSame( 'space', apply_filters( 'evnm_new_event_default_visibility', 'public', array( 'space_id' => $id ), array() ), $type );
		}
	}

	public function test_an_open_space_a_missing_space_and_no_space_keep_the_default(): void {
		$this->stub_eventonomy( 4 );
		list( $space ) = $this->space_with_member();

		$this->assertSame( 'public', apply_filters( 'evnm_new_event_default_visibility', 'public', array( 'space_id' => $space ), array() ) );
		$this->assertSame( 'public', apply_filters( 'evnm_new_event_default_visibility', 'public', array( 'space_id' => 999999 ), array() ) );
		$this->assertSame( 'public', apply_filters( 'evnm_new_event_default_visibility', 'public', array(), array() ) );
	}
}
