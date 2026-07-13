<?php
/**
 * An invite is bound to the address it was sent to. It is not a bearer token.
 *
 * Owner decision, 2026-07-13. Before this, `bn_invites.email` was written at issue
 * time and read in exactly one place — to address the outgoing mail. Nothing ever
 * compared it to the person redeeming, so anyone holding the token could register
 * any address on an invite-only site AND burn the real invitee's invite.
 *
 * Two gates, because there are two ways in:
 *   1. RegistrationPolicy::check_access()  — only consulted in `invite` mode.
 *   2. RegistrationService::redeem_invite() — runs in EVERY mode, which is why an
 *      OPEN-registration site could auto-join a stranger into a PRIVATE space from
 *      a leaked space-scoped token.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\RegistrationPolicy;
use BuddyNext\Auth\RegistrationService;
use BuddyNext\Onboarding\InviteService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * An invite may only be spent by the address it names.
 *
 * @covers \BuddyNext\Onboarding\InviteService::is_for_email
 * @covers \BuddyNext\Auth\RegistrationPolicy::check_access
 */
class InviteBindingTest extends WP_UnitTestCase {

	private const INVITED  = 'alice@example.test';
	private const STRANGER = 'mallory@example.test';

	/**
	 * Reset registration mode after every case — it is a site-wide option.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_reg_mode' );
		parent::tear_down();
	}

	/**
	 * Issue an invite and return [token, invite_id].
	 *
	 * @param int $space_id Optional space to bind the invite to.
	 * @return array{0:string,1:int}
	 */
	private function issue_invite( int $space_id = 0 ): array {
		global $wpdb;

		$invites = new InviteService();
		$id      = $invites->create( self::INVITED, 'Alice', 7, $space_id );
		$token   = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT token FROM {$wpdb->prefix}bn_invites WHERE id = %d", $id )
		);

		return array( $token, (int) $id );
	}

	/**
	 * The exploit: a stranger presents the invitee's token on an invite-only site.
	 *
	 * @return void
	 */
	public function test_stranger_cannot_redeem_someone_elses_invite(): void {
		update_option( 'buddynext_reg_mode', 'invite' );
		update_option( 'users_can_register', 1 );
		list( $token ) = $this->issue_invite();

		$result = ( new RegistrationPolicy() )->check_access(
			self::STRANGER,
			$token,
			RegistrationPolicy::SOURCE_FORM
		);

		$this->assertWPError( $result, 'A stranger must not pass the invite gate with someone else’s token.' );
		$this->assertSame( 'bn_reg_invite_email', $result->get_error_code() );
	}

	/**
	 * The half that locked the invitee out: a refused attempt must NOT burn the invite.
	 *
	 * @return void
	 */
	public function test_a_refused_attempt_does_not_burn_the_invite(): void {
		global $wpdb;

		update_option( 'buddynext_reg_mode', 'invite' );
		update_option( 'users_can_register', 1 );
		list( $token, $invite_id ) = $this->issue_invite();

		( new RegistrationPolicy() )->check_access( self::STRANGER, $token, RegistrationPolicy::SOURCE_FORM );

		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_invites WHERE id = %d", $invite_id )
		);

		$this->assertSame( 'pending', $status, 'A refused attempt must leave the invite usable by the real invitee.' );
	}

	/**
	 * The invitee must still be able to use their own invite.
	 *
	 * @return void
	 */
	public function test_the_invited_address_still_passes(): void {
		update_option( 'buddynext_reg_mode', 'invite' );
		update_option( 'users_can_register', 1 );
		list( $token ) = $this->issue_invite();

		$result = ( new RegistrationPolicy() )->check_access(
			self::INVITED,
			$token,
			RegistrationPolicy::SOURCE_FORM
		);

		$this->assertNotWPError( $result, 'The invited address must still be able to redeem its own invite.' );
	}

	/**
	 * Addresses are compared the way people type them, not the way they were stored.
	 *
	 * @return void
	 */
	public function test_matching_is_case_and_whitespace_insensitive(): void {
		$invites = new InviteService();
		$invite  = array( 'email' => self::INVITED );

		$this->assertTrue( $invites->is_for_email( $invite, '  ALICE@Example.TEST  ' ) );
		$this->assertFalse( $invites->is_for_email( $invite, self::STRANGER ) );
	}

	/**
	 * An invite with no address is not a skeleton key.
	 *
	 * @return void
	 */
	public function test_an_invite_with_no_email_matches_nobody(): void {
		$invites = new InviteService();

		$this->assertFalse( $invites->is_for_email( array( 'email' => '' ), self::INVITED ) );
		$this->assertFalse( $invites->is_for_email( array(), self::INVITED ) );
	}

	/**
	 * The second vector: on an OPEN site the policy gate never inspects the invite,
	 * so a leaked space-scoped token must be stopped at redemption instead — or a
	 * stranger silently lands inside a PRIVATE space.
	 *
	 * @return void
	 */
	public function test_leaked_token_does_not_join_a_stranger_to_a_private_space(): void {
		global $wpdb;

		update_option( 'buddynext_reg_mode', 'open' );
		update_option( 'users_can_register', 1 );

		$owner    = self::factory()->user->create();
		$space_id = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Bound Invite Private Space',
				'slug' => 'bound-invite-private-space',
				'type' => 'private',
			)
		);
		$this->assertIsInt( $space_id );

		list( $token ) = $this->issue_invite( $space_id );

		$created = ( new RegistrationService() )->create(
			array(
				'email'        => self::STRANGER,
				'password'     => wp_generate_password( 16 ),
				'invite'       => $token,
				'display_name' => 'Mallory',
			)
		);
		$this->assertNotWPError( $created, 'An open site still lets anyone register — that is not the bug.' );

		$user_id = (int) ( is_array( $created ) ? ( $created['user_id'] ?? 0 ) : $created );
		$joined  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
				$space_id,
				$user_id
			)
		);

		$this->assertSame( 0, $joined, 'A leaked token must never join a stranger to the private space it points at.' );
	}
}
