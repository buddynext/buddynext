<?php
/**
 * A profile slug may not take a URL that already resolves to someone else.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;

/**
 * is_slug_available() and resolve_user() are a matched pair: one decides who owns
 * a URL, the other decides who may take one. They disagreed — resolve_user()
 * resolves by bn_profile_slug, then user-{id}, then user_nicename, while the
 * availability check tested only the first. So every member without a custom slug
 * (the default for a whole site) read as "available" and another member could
 * claim their profile URL (Basecamp 10251987462).
 *
 * @covers \BuddyNext\Core\PageRouter::is_slug_available
 * @covers \BuddyNext\Core\PageRouter::reserved_profile_slugs
 */
class ProfileSlugUniquenessTest extends \WP_UnitTestCase {

	private int $victim;
	private int $other;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->victim = self::factory()->user->create( array( 'user_login' => 'victim_handle' ) );
		$this->other  = self::factory()->user->create( array( 'user_login' => 'other_member' ) );
	}

	/**
	 * @param int $user_id User.
	 * @return string
	 */
	private function nicename( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? (string) $user->user_nicename : '';
	}

	public function test_another_members_nicename_is_not_available(): void {
		$this->assertFalse(
			PageRouter::is_slug_available( $this->nicename( $this->victim ), $this->other ),
			'A handle that already routes to someone must not be claimable.'
		);
	}

	/**
	 * The nicename stays a live fallback in resolve_user() even after that member
	 * sets a custom slug, so their old URL still reaches them — handing it to
	 * someone else would silently redirect it.
	 *
	 * @return void
	 */
	public function test_a_nicename_stays_blocked_after_that_member_sets_a_custom_slug(): void {
		update_user_meta( $this->victim, 'bn_profile_slug', 'a-new-handle' );

		$this->assertFalse( PageRouter::is_slug_available( $this->nicename( $this->victim ), $this->other ) );
	}

	/**
	 * The guard must not lock a member out of their own handle.
	 *
	 * @return void
	 */
	public function test_a_member_may_claim_their_own_nicename(): void {
		$this->assertTrue( PageRouter::is_slug_available( $this->nicename( $this->victim ), $this->victim ) );
	}

	public function test_another_members_custom_slug_is_not_available(): void {
		update_user_meta( $this->victim, 'bn_profile_slug', 'taken-handle' );

		$this->assertFalse( PageRouter::is_slug_available( 'taken-handle', $this->other ) );
	}

	public function test_reserved_words_are_refused(): void {
		foreach ( PageRouter::reserved_profile_slugs() as $reserved ) {
			$this->assertFalse(
				PageRouter::is_slug_available( $reserved, $this->other ),
				"Reserved slug '{$reserved}' must be refused."
			);
		}
	}

	public function test_an_owner_can_change_the_reserved_list(): void {
		add_filter( 'buddynext_reserved_profile_slugs', static fn(): array => array( 'brandname' ) );

		$this->assertFalse( PageRouter::is_slug_available( 'brandname', $this->other ) );
		$this->assertTrue( PageRouter::is_slug_available( 'edit', $this->other ), 'A word the owner dropped must become claimable.' );
	}

	/**
	 * The point is uniqueness, not refusal — a genuinely free handle still works.
	 *
	 * @return void
	 */
	public function test_a_free_handle_is_still_available(): void {
		$this->assertTrue( PageRouter::is_slug_available( 'a-genuinely-free-handle', $this->other ) );
	}

	public function test_another_users_user_id_pattern_is_refused(): void {
		$this->assertFalse( PageRouter::is_slug_available( 'user-' . $this->victim, $this->other ) );
		$this->assertTrue( PageRouter::is_slug_available( 'user-' . $this->other, $this->other ) );
	}
}
