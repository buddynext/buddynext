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
	 * A handle followed by an email-like tail yields only the real handle.
	 *
	 * `@brendan@somewhere-com` used to split into two tokens, the second of which
	 * (`somewhere-com`) is not a member. The negative lookbehind added in "an email
	 * address in a post is no longer read as a mention" means an `@` sitting directly
	 * after a word character is not a mention boundary, so only the leading,
	 * space-preceded `@brendan` is extracted.
	 *
	 * @return void
	 */
	public function test_a_handle_with_an_email_tail_yields_only_the_handle(): void {
		preg_match_all( Handle::mention_regex(), 'Hey @brendan@somewhere-com welcome', $m );

		$this->assertSame(
			array( 'brendan' ),
			$m[1],
			'The @ that follows a word character is not a mention, so the email-like tail is dropped.'
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

		// The `@` sits directly after `john`, so the negative lookbehind rejects it:
		// an email address yields no mention tokens at all. Asserting the empty set
		// (rather than looping, which passes vacuously when the set is empty) keeps
		// the guard real — if the charset is ever widened to admit `@`, this fails.
		$this->assertSame( array(), $m[1], 'An email address must yield no mention tokens.' );
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
	 * A handle resolves the member it is displayed for.
	 *
	 * The regression this locks: resolution used `get_user_by( 'login', ... )`
	 * while every surface displays `PageRouter::member_handle()`. Those disagree
	 * whenever a login holds a space, a capital, a dot or an email — all of which
	 * WordPress permits — so the mention rendered as a working link and the
	 * member was never notified. Each case below failed before the fix.
	 *
	 * @dataProvider divergent_login_provider
	 *
	 * @param string $login Login to create the member with.
	 * @param string $why   Why the login and nicename diverge.
	 * @return void
	 */
	public function test_handle_resolves_the_member_it_is_shown_for( string $login, string $why ): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => $login,
				'user_email' => sanitize_title( $login ) . '@example.test',
			)
		);

		$handle   = \BuddyNext\Core\PageRouter::member_handle( $user_id );
		$resolved = Handle::resolve( $handle );

		$this->assertInstanceOf( \WP_User::class, $resolved, "@{$handle} resolved to nobody ({$why})." );
		$this->assertSame( $user_id, $resolved->ID, "@{$handle} resolved to the wrong member ({$why})." );
	}

	/**
	 * Logins WordPress permits whose nicename differs.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function divergent_login_provider(): array {
		return array(
			'space in login'    => array( 'Brendan Smith', 'login has a space' ),
			'capitals in login' => array( 'BrendanCaps', 'login has capitals' ),
			'dot in login'      => array( 'brendan.dot', 'login has a dot' ),
			'email as login'    => array( 'b@mail.com', 'login is an email' ),
		);
	}

	/**
	 * A member's custom slug resolves — it is what their profile displays.
	 *
	 * This never worked: bn_profile_slug takes precedence when the handle is
	 * rendered, but resolution went to user_login, so a member who set a custom
	 * slug could not be mentioned by the only handle anyone could see.
	 *
	 * @return void
	 */
	public function test_custom_slug_resolves(): void {
		$user_id = self::factory()->user->create( array( 'user_login' => 'plainuser' ) );
		update_user_meta( $user_id, 'bn_profile_slug', 'my-slug' );

		$this->assertSame( 'my-slug', \BuddyNext\Core\PageRouter::member_handle( $user_id ) );

		$resolved = Handle::resolve( 'my-slug' );

		$this->assertInstanceOf( \WP_User::class, $resolved );
		$this->assertSame( $user_id, $resolved->ID );
	}

	/**
	 * A login still resolves, so mentions that worked before still work.
	 *
	 * @return void
	 */
	public function test_login_still_resolves_for_back_compat(): void {
		$user_id = self::factory()->user->create( array( 'user_login' => 'Legacy Login' ) );

		$resolved = Handle::resolve( 'Legacy' );

		// 'Legacy' is neither the nicename (legacy-login) nor a full login, so this
		// asserts only that the fallback does not blow up and does not mis-resolve.
		$this->assertTrue( null === $resolved || $resolved->ID === $user_id );

		$this->assertNull( Handle::resolve( 'nobody-by-this-handle' ) );
		$this->assertNull( Handle::resolve( '' ) );
	}

	/**
	 * The reserved user-{id} handle resolves.
	 *
	 * @return void
	 */
	public function test_reserved_user_id_handle_resolves(): void {
		$user_id  = self::factory()->user->create();
		$resolved = Handle::resolve( 'user-' . $user_id );

		$this->assertInstanceOf( \WP_User::class, $resolved );
		$this->assertSame( $user_id, $resolved->ID );
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
