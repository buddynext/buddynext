<?php
/**
 * A handle must be usable: mentionable characters, sane length.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\Handle;

/**
 * sanitize_title() PERCENT-ENCODES anything outside Latin, so an emoji became
 * %f0%9f%98%80 and a non-Latin script became a run of %-escapes. Both were
 * accepted with a 200, produced a double-encoded profile URL, and were
 * unmentionable — mention_regex() stops at `%`, so the member could never be
 * @mentioned again. There was also no length bound at either end: one character
 * and eighty both passed.
 *
 * @covers \BuddyNext\Profile\Handle::validate
 */
class HandleValidationTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * @param string $handle Handle.
	 * @return string
	 */
	private function code( string $handle ): string {
		$result = Handle::validate( $handle );
		return is_wp_error( $result ) ? $result->get_error_code() : '';
	}

	public function test_a_normal_handle_is_valid(): void {
		$this->assertTrue( Handle::validate( 'varun-d' ) );
		$this->assertTrue( Handle::validate( 'ana' ) );
		$this->assertTrue( Handle::validate( 'under_score' ) );
		$this->assertTrue( Handle::validate( 'a1b2c3' ) );
	}

	/**
	 * The case that produced an unmentionable member.
	 *
	 * @return void
	 */
	public function test_percent_encoded_handles_are_refused(): void {
		$emoji = sanitize_title( '😀' );

		$this->assertStringContainsString( '%', $emoji, 'Precondition: sanitize_title percent-encodes it.' );
		$this->assertFalse( Handle::is_safe( $emoji ), 'Precondition: it cannot round-trip the mention parser.' );
		$this->assertSame( 'handle_unusable_characters', $this->code( $emoji ) );
	}

	public function test_length_bounds_are_enforced_at_both_ends(): void {
		$this->assertSame( 'handle_too_short', $this->code( str_repeat( 'a', Handle::MIN_LENGTH - 1 ) ) );
		$this->assertSame( 'handle_too_long', $this->code( str_repeat( 'a', Handle::MAX_LENGTH + 1 ) ) );
		$this->assertTrue( Handle::validate( str_repeat( 'a', Handle::MIN_LENGTH ) ) );
		$this->assertTrue( Handle::validate( str_repeat( 'a', Handle::MAX_LENGTH ) ) );
	}

	/**
	 * The ceiling that actually matters: wp_users.user_nicename is varchar(50),
	 * so a longer handle would be truncated by MySQL rather than refused.
	 *
	 * @return void
	 */
	public function test_the_maximum_fits_the_nicename_column(): void {
		$this->assertLessThanOrEqual( 50, Handle::MAX_LENGTH );
	}

	public function test_an_owner_can_change_the_length_bounds(): void {
		add_filter( 'buddynext_handle_length_bounds', static fn(): array => array( 2, 8 ) );

		$this->assertTrue( Handle::validate( 'jo' ), 'A shorter floor must be honoured.' );
		$this->assertSame( 'handle_too_long', $this->code( 'nine-char' ) );
	}

	/**
	 * The charset is NOT filterable — widening it produces handles nobody can
	 * @mention, which is the whole point of the constraint.
	 *
	 * @return void
	 */
	public function test_the_charset_holds_even_with_permissive_length_bounds(): void {
		add_filter( 'buddynext_handle_length_bounds', static fn(): array => array( 1, 200 ) );

		$this->assertSame( 'handle_unusable_characters', $this->code( sanitize_title( '日本語' ) ) );
	}

	/**
	 * The single funnel every writer uses must refuse an unusable handle too, so
	 * the admin editor and onboarding cannot create what REST refuses.
	 *
	 * @return void
	 */
	public function test_the_availability_funnel_refuses_an_unusable_handle(): void {
		$user = self::factory()->user->create();

		$this->assertFalse( PageRouter::is_slug_available( sanitize_title( '😀' ), $user ) );
		$this->assertFalse( PageRouter::is_slug_available( 'ab', $user ) );
		$this->assertTrue( PageRouter::is_slug_available( 'a-fine-handle', $user ) );
	}
}
