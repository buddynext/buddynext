<?php
/**
 * A reserved handle is called reserved, and onboarding does not drop it silently.
 *
 * Reserved words read "Taken" / "already taken" on the profile, sign-up and
 * onboarding, which sends a member hunting for a free variant of a name nobody
 * may hold. Onboarding's finish also returned completed while keeping a
 * different handle than the one chosen.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\Handle;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Core\PageRouter::slug_refusal
 * @covers \BuddyNext\Profile\ProfileController
 * @covers \BuddyNext\Onboarding\OnboardingController::complete
 * @covers \BuddyNext\Auth\CoreRegistration::apply_policy
 */
class ReservedHandleRefusalTest extends \WP_Test_REST_TestCase {

	/**
	 * Member under test.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Fresh install and a signed-in member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->member = self::factory()->user->create( array( 'user_login' => 'support2' ) );
		wp_set_current_user( $this->member );
	}

	public function test_a_reserved_word_is_refused_as_reserved_not_taken(): void {
		$refusal = PageRouter::slug_refusal( 'support', $this->member );

		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'handle_reserved', $refusal->get_error_code() );
		$this->assertStringContainsString( 'reserved', $refusal->get_error_message() );
		$this->assertFalse( PageRouter::is_slug_available( 'support', $this->member ), 'The yes/no answer is unchanged.' );
	}

	public function test_a_held_handle_still_reads_as_taken(): void {
		$other = self::factory()->user->create( array( 'user_login' => 'held_by_other' ) );
		unset( $other );

		$this->assertSame( 'handle_taken', PageRouter::slug_refusal( 'held_by_other', $this->member )->get_error_code() );
	}

	public function test_the_profile_routes_say_reserved(): void {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/profile-slug/check' );
		$request->set_param( 'slug', 'support' );
		$data = rest_do_request( $request )->get_data();
		$this->assertFalse( $data['available'] );
		$this->assertSame( 'handle_reserved', $data['reason'] );

		$put = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile-slug' );
		$put->set_param( 'slug', 'support' );
		$response = rest_do_request( $put );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'handle_reserved', $response->get_data()['code'] );
	}

	public function test_onboarding_finish_reports_a_refused_handle_and_does_not_complete(): void {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/complete' );
		$request->set_param( 'slug', 'support' );
		$response = rest_do_request( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertStringContainsString( 'reserved', (string) $response->get_data()['message'] );
		$this->assertFalse( buddynext_service( 'onboarding' )->is_complete( $this->member ), 'The member stays on the step to pick another handle.' );
		$this->assertSame( 'support2', Handle::current( $this->member ) );
	}

	public function test_onboarding_finish_with_the_current_handle_still_completes(): void {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/complete' );
		$request->set_param( 'slug', 'support2' );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
	}

	public function test_sign_up_says_reserved(): void {
		$errors = ( new \BuddyNext\Auth\CoreRegistration() )->apply_policy( new \WP_Error(), 'support', 'someone@example.com' );

		$this->assertStringContainsString( 'reserved', (string) $errors->get_error_message( 'username_exists' ) );
	}
}
