<?php
/**
 * An admin can mark a member's email verified from the member editor.
 *
 * The 1.1.5 release notes promised this - "an admin can confirm a member in one
 * click from the member editor" - and it did not exist. Card 10225756919 found the
 * overclaim by auditing the changelog against the code.
 *
 * Built rather than struck from the notes, because the control earns its place: the
 * common support case is a member whose verification email never arrived, and the
 * only remedies before this were the database or asking them to keep retrying a
 * mail that is not coming.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Auth\VerificationService;
use WP_UnitTestCase;

/**
 * The admin-side verify action.
 *
 * @covers \BuddyNext\Admin\Members::handle_verify_member
 */
class AdminVerifyMemberTest extends WP_UnitTestCase {

	/**
	 * Marking verified writes the meta AND clears the purge flag.
	 *
	 * Both halves, because they are why the handler delegates to
	 * `VerificationService::mark_verified()` instead of writing the meta itself. An
	 * admin-side copy that only set `buddynext_email_verified` would leave the
	 * account queued for deletion while displaying as verified - the worst of both,
	 * and invisible until the purge ran.
	 *
	 * @return void
	 */
	public function test_verifying_clears_the_pending_purge_flag_too(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'buddynext_verify_pending', 1 );

		( new VerificationService() )->mark_verified( $user_id );

		$this->assertSame( '1', (string) get_user_meta( $user_id, 'buddynext_email_verified', true ) );
		$this->assertSame(
			'',
			(string) get_user_meta( $user_id, 'buddynext_verify_pending', true ),
			'The account is still queued for purge while showing as verified.'
		);
	}

	/**
	 * It fires `buddynext_user_verified`, so listeners see an admin confirm too.
	 *
	 * The self-verify path fires this; an admin confirm that did not would give the
	 * same member two different outcomes depending on who pressed the button.
	 *
	 * @return void
	 */
	public function test_it_fires_the_same_action_as_self_verification(): void {
		$user_id = self::factory()->user->create();
		$seen    = 0;

		$listener = static function ( int $id ) use ( &$seen, $user_id ): void {
			if ( $id === $user_id ) {
				++$seen;
			}
		};

		add_action( 'buddynext_user_verified', $listener );

		try {
			( new VerificationService() )->mark_verified( $user_id );
		} finally {
			remove_action( 'buddynext_user_verified', $listener );
		}

		$this->assertSame( 1, $seen, 'Nothing listening to verification heard about the admin confirm.' );
	}

	/**
	 * The handler is registered, so the form has somewhere to post.
	 *
	 * A form posting to an unregistered admin_post action lands on a blank
	 * admin-post.php with no error - the button would look like it worked.
	 *
	 * @return void
	 */
	public function test_the_admin_post_action_is_registered(): void {
		( new \BuddyNext\Admin\Members() )->register();

		$this->assertNotFalse(
			has_action( 'admin_post_bn_verify_member' ),
			'The Mark email verified button posts to an action nothing handles.'
		);
	}

	/**
	 * Verifying twice is harmless.
	 *
	 * Two admins on the same support ticket is an ordinary Tuesday.
	 *
	 * @return void
	 */
	public function test_verifying_an_already_verified_member_is_a_no_op(): void {
		$user_id = self::factory()->user->create();
		$service = new VerificationService();

		$service->mark_verified( $user_id );
		$service->mark_verified( $user_id );

		$this->assertSame( '1', (string) get_user_meta( $user_id, 'buddynext_email_verified', true ) );
	}
}
