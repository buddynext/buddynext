<?php
/**
 * The preview words a blocked viewer gets on a Space-members event.
 *
 * @package BuddyNext\Tests
 */

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\EventonomyTeaser;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

if ( ! defined( 'EVENTONOMY_HOOK_CONTRACT' ) ) {
	define( 'EVENTONOMY_HOOK_CONTRACT', 4 );
}

/**
 * @covers \BuddyNext\Bridges\EventonomyTeaser
 */
class EventonomyTeaserTest extends \WP_UnitTestCase {

	private int $owner;
	private int $viewer;

	public function set_up(): void {
		parent::set_up();
		$this->owner  = self::factory()->user->create();
		$this->viewer = self::factory()->user->create();
		add_filter(
			'evnm_event_permalink',
			static fn( $url, $id ) => home_url( '/events/e' . (int) $id . '/' ),
			10,
			2
		);
	}

	private function space( string $type, string $ability = '' ): int {
		global $wpdb;
		$id = (int) ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Trail Club',
				'slug' => 'tc-' . strtolower( wp_generate_password( 8, false, false ) ),
				'type' => $type,
			)
		);
		if ( '' !== $ability ) {
			$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'required_ability' => $ability ), array( 'id' => $id ) );
			wp_cache_flush();
		}
		return $id;
	}

	private function ask( int $space, int $user, string $level = 'space' ) {
		return ( new EventonomyTeaser() )->teaser( null, array( 'id' => 9, 'space_id' => $space ), $user, $level );
	}

	public function test_a_guest_can_join_or_log_in_and_both_come_back_to_the_event(): void {
		$t = $this->ask( $this->space( 'open' ), 0 );

		$this->assertSame( 'This event is for Trail Club members', $t['heading'] );
		$this->assertSame( 'Join Trail Club', $t['cta_label'] );
		$this->assertStringContainsString( rawurlencode( home_url( '/events/e9/' ) ), $t['cta_url'] );
		$this->assertSame( 'Log in', $t['secondary_label'] );
		$this->assertStringContainsString( rawurlencode( home_url( '/events/e9/' ) ), $t['secondary_url'] );
	}

	public function test_a_signed_in_person_on_an_open_space_joins_it_and_comes_back(): void {
		$space = $this->space( 'open' );
		$t     = $this->ask( $space, $this->viewer );

		$this->assertSame( 'Join Trail Club', $t['cta_label'] );
		$this->assertStringContainsString( 'return_for=event', $t['cta_url'] );
		$this->assertStringContainsString( 'return_to=' . rawurlencode( home_url( '/events/e9/' ) ), $t['cta_url'] );
	}

	public function test_a_private_space_asks_to_join_and_then_says_the_request_is_waiting(): void {
		$space = $this->space( 'private' );
		$t     = $this->ask( $space, $this->viewer );
		$this->assertSame( 'Request to join Trail Club', $t['cta_label'] );

		( new SpaceMemberService() )->request_join( $space, $this->viewer );
		$t = $this->ask( $space, $this->viewer );

		$this->assertSame( 'Your request is waiting for approval', $t['heading'] );
		$this->assertTrue( $t['cta_disabled'] );
		$this->assertSame( 'Cancel request', $t['secondary_label'] );
	}

	public function test_a_secret_space_and_a_missing_space_stay_not_found(): void {
		$this->assertNull( $this->ask( $this->space( 'secret' ), $this->viewer ) );
		$this->assertNull( $this->ask( $this->space( 'secret' ), 0 ) );
		$this->assertNull( $this->ask( 999999, $this->viewer ) );
	}

	public function test_a_plan_gated_space_is_left_for_pro_and_other_levels_are_untouched(): void {
		$gated = $this->space( 'open', 'some-plan' );
		$this->assertNull( $this->ask( $gated, $this->viewer ), 'Free leaves the plan wording to Pro.' );
		$this->assertSame( 'kept', ( new EventonomyTeaser() )->teaser( 'kept', array( 'space_id' => $gated ), $this->viewer, 'members_only' ) );
	}

	public function test_nothing_in_the_preview_carries_event_details(): void {
		$t = $this->ask( $this->space( 'open' ), $this->viewer );
		$this->assertSame( array( 'heading', 'message', 'cta_label', 'cta_url' ), array_keys( $t ) );
	}
}
