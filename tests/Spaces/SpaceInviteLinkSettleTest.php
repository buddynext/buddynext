<?php
/**
 * A new member who opened a Space's invite link joins that Space when onboarding is off.
 *
 * With onboarding on, the welcome wizard joins them at its end (covered in SpaceInviteLinkFlowTest).
 * With it off nothing used to read the saved invite, so the member signed up and was never joined.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Auth\AuthController;
use BuddyNext\Auth\VerificationListener;
use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceInviteLinkService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Spaces\SpaceInviteLinkService::settle_pending
 * @covers \BuddyNext\Auth\AuthController::post_register_redirect
 * @covers \BuddyNext\Auth\VerificationListener::join_invited_spaces
 */
class SpaceInviteLinkSettleTest extends \WP_UnitTestCase {

	private int $space_id;
	private string $token;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$owner          = self::factory()->user->create();
		$this->space_id = (int) ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Settle Space',
				'slug' => 'settle-space',
				'type' => 'private',
			)
		);
		$this->token = (string) ( new SpaceInviteLinkService() )->create( $this->space_id, $owner, '7d', 0 )['token'];
	}

	public function tear_down(): void {
		delete_option( 'buddynext_features' );
		delete_option( 'buddynext_onboarding_gate_since' );
		parent::tear_down();
	}

	private function member_with_saved_invite( string $token = '' ): int {
		$user_id = self::factory()->user->create();
		update_user_meta(
			$user_id,
			SpaceInviteLinkService::PENDING_KEY,
			array( array( 'space_id' => $this->space_id, 'token' => '' === $token ? $this->token : $token, 'primed_at' => time() ) )
		);
		return $user_id;
	}

	private function onboarding( bool $on ): void {
		update_option( 'buddynext_features', array( 'onboarding' => $on ) );
		update_option( 'buddynext_onboarding_gate_since', 1 );
	}

	private function status( int $user_id ): string {
		return (string) ( new SpaceMemberService() )->get_status( $this->space_id, $user_id );
	}

	public function test_signing_up_with_onboarding_off_joins_the_space_and_lands_in_it(): void {
		$this->onboarding( false );
		$user_id = $this->member_with_saved_invite();

		$url = AuthController::post_register_redirect( $user_id );

		$this->assertSame( 'active', $this->status( $user_id ) );
		$this->assertStringContainsString( 'settle-space', $url );
		$this->assertSame( '', (string) get_user_meta( $user_id, SpaceInviteLinkService::PENDING_KEY, true ) );
	}

	public function test_signing_up_with_onboarding_on_leaves_the_join_to_the_wizard(): void {
		$this->onboarding( true );
		$user_id = $this->member_with_saved_invite();

		AuthController::post_register_redirect( $user_id );

		$this->assertNotSame( 'active', $this->status( $user_id ) );
		$this->assertNotEmpty( get_user_meta( $user_id, SpaceInviteLinkService::PENDING_KEY, true ) );
	}

	public function test_an_expired_or_reset_link_joins_nobody_and_the_list_is_still_cleared(): void {
		$this->onboarding( false );
		$user_id = $this->member_with_saved_invite( 'not-the-current-token' );

		$url = AuthController::post_register_redirect( $user_id );

		$this->assertNotSame( 'active', $this->status( $user_id ) );
		$this->assertStringNotContainsString( 'settle-space', $url );
		$this->assertSame( '', (string) get_user_meta( $user_id, SpaceInviteLinkService::PENDING_KEY, true ) );
	}

	public function test_verifying_the_email_with_onboarding_off_joins_the_space(): void {
		$this->onboarding( false );
		$user_id = $this->member_with_saved_invite();

		do_action( 'buddynext_email_verified', $user_id );

		$this->assertSame( 'active', $this->status( $user_id ) );
	}

	public function test_verifying_the_email_with_onboarding_on_leaves_the_join_to_the_wizard(): void {
		$this->onboarding( true );
		$user_id = $this->member_with_saved_invite();

		do_action( 'buddynext_email_verified', $user_id );

		$this->assertNotSame( 'active', $this->status( $user_id ) );
	}
}
