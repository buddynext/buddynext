<?php
/**
 * An Eventonomy without the 2.0.0 hook contract never asks the membership question, so BuddyNext registers
 * nothing for it. Runs in its own process because the constant cannot be redefined.
 *
 * @package BuddyNext\Tests
 */

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\EventonomyAccess;

/**
 * @covers \BuddyNext\Bridges\EventonomyAccess::init
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EventonomyAccessOlderContractTest extends \WP_UnitTestCase {

	public function test_nothing_is_registered_without_the_contract_constant(): void {
		$this->assertFalse( defined( 'EVENTONOMY_HOOK_CONTRACT' ), 'Precondition: an Eventonomy older than 2.0.0.' );
		( new EventonomyAccess() )->init();

		$this->assertFalse( has_filter( 'evnm_user_is_event_member' ) );
	}

	public function test_nothing_is_registered_for_a_contract_below_two(): void {
		define( 'EVENTONOMY_HOOK_CONTRACT', 1 );
		( new EventonomyAccess() )->init();

		$this->assertFalse( has_filter( 'evnm_user_is_event_member' ) );
	}
}
