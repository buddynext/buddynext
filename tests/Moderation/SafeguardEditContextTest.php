<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The safeguard filter must tell an extension whether it is on a CREATE or an EDIT.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\SafeguardService;
use WP_UnitTestCase;

/**
 * A member at the hourly post cap could not edit their own posts.
 *
 * Free draws the create-vs-edit line correctly for its OWN gates: check_content() (the edit
 * path) deliberately skips rate-limit / duplicate / new-member, because those are all
 * "how much have you posted lately" questions and an edit is not a new post.
 *
 * But it still fired `buddynext_safeguard_check` with no way to tell the two apart. Pro's
 * rules engine hooks that filter and ran its whole rule set — including the anti-flood rate
 * limit — on every edit. Result: an author who hit the cap got a 403 on Save and could not
 * edit any existing post, not even the ones that tripped the cap.
 *
 * These tests pin the ARGUMENT, not the internals: the filter must receive 'create' from
 * check() and 'edit' from check_content(). Without the 5th argument both tests below fail
 * on the assertion that the context arrived at all.
 *
 * @covers \BuddyNext\Moderation\SafeguardService
 */
class SafeguardEditContextTest extends WP_UnitTestCase {

	/**
	 * Contexts the filter was invoked with, in order.
	 *
	 * @var array<int, string>
	 */
	private array $seen = array();

	/**
	 * Fresh schema + a spy on the seam.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();

		$this->seen = array();

		add_filter(
			'buddynext_safeguard_check',
			function ( $result, $user_id, $content, $link_url, $context = 'MISSING' ) {
				unset( $user_id, $content, $link_url );
				$this->seen[] = (string) $context;

				return $result;
			},
			10,
			5
		);
	}

	/**
	 * Creating a post tells the extension it is a create.
	 *
	 * @return void
	 */
	public function test_create_path_passes_create_context(): void {
		$user_id = self::factory()->user->create();

		( new SafeguardService() )->check( $user_id, 'A brand new post.', '' );

		$this->assertSame(
			array( 'create' ),
			$this->seen,
			'The safeguard filter did not receive the create context.'
		);
	}

	/**
	 * Editing a post tells the extension it is an edit.
	 *
	 * This is the one that was broken. An extension cannot honour "do not rate-limit an
	 * edit" if nothing ever tells it that an edit is what is happening.
	 *
	 * @return void
	 */
	public function test_edit_path_passes_edit_context(): void {
		$user_id = self::factory()->user->create();

		( new SafeguardService() )->check_content( 'The same post, reworded.', '', $user_id );

		$this->assertSame(
			array( 'edit' ),
			$this->seen,
			'The safeguard filter did not receive the edit context, so a rate-limit rule hooked to it cannot tell an edit from a new post and will reject the edit.'
		);
	}

	/**
	 * An extension that blocks on edit is still honoured — the context is informational,
	 * not a bypass. Editing must not become a moderation blind spot.
	 *
	 * @return void
	 */
	public function test_an_extension_can_still_block_an_edit(): void {
		add_filter(
			'buddynext_safeguard_check',
			static function ( $result, $user_id, $content, $link_url, $context = 'create' ) {
				unset( $user_id, $link_url, $context );

				return str_contains( $content, 'forbidden' )
					? new \WP_Error( 'blocked_by_extension', 'Blocked.' )
					: $result;
			},
			20,
			5
		);

		$user_id = self::factory()->user->create();

		$ok = ( new SafeguardService() )->check_content( 'a perfectly fine edit', '', $user_id );
		$this->assertTrue( $ok, 'A clean edit was blocked.' );

		$blocked = ( new SafeguardService() )->check_content( 'now with forbidden words', '', $user_id );
		$this->assertWPError(
			$blocked,
			'A content rule stopped applying to edits. The create/edit context must narrow WHICH rules run, never disable the seam.'
		);
	}
}
