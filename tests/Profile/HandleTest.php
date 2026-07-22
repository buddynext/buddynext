<?php
/**
 * Tests for the member handle contract.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\Handle;

/**
 * The character set every mention parser and the composer typeahead share.
 *
 * @covers \BuddyNext\Profile\Handle
 */
class HandleTest extends \WP_UnitTestCase {

	/**
	 * A well-formed handle round-trips.
	 *
	 * @return void
	 */
	public function test_ordinary_handles_are_safe(): void {
		foreach ( array( 'brendan', 'a-b', 'jane_doe', 'user2026', 'A-Z_09' ) as $handle ) {
			$this->assertTrue( Handle::is_safe( $handle ), "{$handle} should be mentionable." );
		}
	}

	/**
	 * Handles carrying characters the parsers stop at are NOT safe.
	 *
	 * `@` is the customer-reported case: an email written into user_nicename by a
	 * migration. `.` and `+` are the same class of imported value.
	 *
	 * @return void
	 */
	public function test_imported_handles_are_not_safe(): void {
		foreach ( array( 'brendan@somewhere-com', 'a.b', 'jose+news', 'has space', '' ) as $handle ) {
			$this->assertFalse( Handle::is_safe( $handle ), "{$handle} must not be treated as mentionable." );
		}
	}

	/**
	 * The mention regex stops at the second `@` — which is the bug, stated.
	 *
	 * This is why the member could not be mentioned: the handle the profile shows
	 * is not the token the parser extracts, so the lookup was always going to miss.
	 *
	 * @return void
	 */
	public function test_regex_splits_an_email_handle_into_fragments(): void {
		preg_match_all( Handle::mention_regex(), 'Hey @brendan@somewhere-com welcome', $m );

		$this->assertSame(
			array( 'brendan', 'somewhere-com' ),
			$m[1],
			'An @ inside the handle ends the token, so neither fragment is the member.'
		);
	}

	/**
	 * A repaired handle is a single token again.
	 *
	 * @return void
	 */
	public function test_regex_reads_a_repaired_handle_whole(): void {
		preg_match_all( Handle::mention_regex(), 'Hey @brendansomewhere-com welcome', $m );

		$this->assertSame( array( 'brendansomewhere-com' ), $m[1] );
	}

	/**
	 * An email address in post text is NOT a mention.
	 *
	 * The guard against the tempting "fix": widening the set to admit `@` would
	 * make every address anyone writes a mention attempt. If this ever fails, the
	 * charset has been widened and mentions will start firing on email addresses.
	 *
	 * @return void
	 */
	public function test_an_email_address_does_not_become_a_mention(): void {
		preg_match_all( Handle::mention_regex(), 'email me at john@example.com', $m );

		foreach ( $m[1] as $token ) {
			$this->assertStringNotContainsString( '@', $token, 'A mention token must never span an @.' );
		}
	}

	/**
	 * Repair produces what WordPress itself would have written.
	 *
	 * @return void
	 */
	public function test_make_safe_matches_core_sanitisation(): void {
		$this->assertSame( 'brendansomewhere-com', Handle::make_safe( 'brendan@somewhere-com' ) );
		$this->assertSame( 'a-bcorp-com', Handle::make_safe( 'a.b@corp-com' ) );
		$this->assertTrue( Handle::is_safe( Handle::make_safe( 'jose+news@mail-com' ) ) );
	}

	/**
	 * A handle of only foreign characters cannot be repaired, and says so.
	 *
	 * Returning '' rather than guessing matters: writing an empty nicename would
	 * break the member's profile URL outright, which is worse than the fault being
	 * repaired.
	 *
	 * @return void
	 */
	public function test_unrepairable_handle_returns_empty_rather_than_guessing(): void {
		$this->assertSame( '', Handle::make_safe( '@@@' ) );
		$this->assertSame( '', Handle::make_safe( '' ) );
	}

	/**
	 * Repairing is idempotent — a good handle is left exactly alone.
	 *
	 * @return void
	 */
	public function test_repair_leaves_a_good_handle_untouched(): void {
		$this->assertSame( 'brendan', Handle::make_safe( 'brendan' ) );
		$this->assertSame( 'a-b_c9', Handle::make_safe( 'a-b_c9' ) );
	}

	/**
	 * WordPress cannot itself produce an unmentionable nicename.
	 *
	 * This is what makes the fault an imported-data problem rather than one of
	 * ours: core sanitises the handle on the way in, so only a direct DB write can
	 * introduce it. If this ever fails, some path is creating broken handles and
	 * the repair command would be treating a symptom.
	 *
	 * @return void
	 */
	public function test_core_user_creation_cannot_produce_an_unsafe_handle(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'brendan@somewhere.com',
				'user_email' => 'handle-probe@example.test',
			)
		);

		$nicename = get_userdata( $user_id )->user_nicename;

		$this->assertTrue(
			Handle::is_safe( $nicename ),
			"WordPress produced an unmentionable nicename ({$nicename}) — the fault is not import-only."
		);
	}
}
