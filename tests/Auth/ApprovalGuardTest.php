<?php
/**
 * Tests for the admin-approval hold.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\ApprovalGuard;
use WP_Error;

/**
 * Admin-approval hold: the password door and the application-password door.
 *
 * @covers \BuddyNext\Auth\ApprovalGuard
 */
class ApprovalGuardTest extends \WP_UnitTestCase {

	/**
	 * Guard under test.
	 *
	 * @var ApprovalGuard
	 */
	private ApprovalGuard $guard;

	/**
	 * An account still awaiting administrator approval.
	 *
	 * @var int
	 */
	private int $pending_id;

	/**
	 * An account past the hold.
	 *
	 * @var int
	 */
	private int $approved_id;

	/**
	 * Create one held account and one approved account.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->guard       = new ApprovalGuard();
		$this->pending_id  = self::factory()->user->create();
		$this->approved_id = self::factory()->user->create();
		update_user_meta( $this->pending_id, 'bn_pending_approval', 1 );
	}

	/**
	 * Make core willing to resolve an application password inside the suite.
	 *
	 * Core requires SSL (wp_is_application_passwords_supported()) and treats only
	 * API requests as eligible. Neither holds in PHPUnit, and both refuse before
	 * our guard is ever consulted — so without this the tests below would pass for
	 * the wrong reason.
	 *
	 * @return void
	 */
	private function enable_application_passwords(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
	}

	/**
	 * The password door: refused, as it always was.
	 */
	public function test_password_signin_is_refused_while_pending(): void {
		$result = $this->guard->refuse_pending_approval( get_user_by( 'id', $this->pending_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bn_pending_approval', $result->get_error_code() );
	}

	/**
	 * An approved account passes the password door untouched.
	 *
	 * @return void
	 */
	public function test_password_signin_is_allowed_once_approved(): void {
		$user   = get_user_by( 'id', $this->approved_id );
		$result = $this->guard->refuse_pending_approval( $user );

		$this->assertSame( $user, $result );
	}

	/**
	 * The application-password door — the one that was open.
	 *
	 * A REST client sending Authorization: Basic never runs the `authenticate`
	 * chain: core resolves the credential on determine_current_user via
	 * wp_validate_application_password(), which calls
	 * wp_authenticate_application_password() directly. So the hold's old
	 * wp_authenticate_user binding never fired for a mobile client, and an account
	 * held for approval could mint an app password and use it indefinitely while
	 * the owner's approval queue said otherwise.
	 */
	public function test_application_password_is_refused_while_pending(): void {
		$error = new WP_Error();

		$this->guard->refuse_pending_approval_app_password( $error, get_user_by( 'id', $this->pending_id ) );

		$this->assertTrue(
			$error->has_errors(),
			'An account awaiting approval must not be able to authenticate with an application password.'
		);
		$this->assertSame( 'bn_pending_approval', $error->get_error_code() );
	}

	/**
	 * An approved account is not refused an application password.
	 *
	 * @return void
	 */
	public function test_application_password_is_allowed_once_approved(): void {
		$error = new WP_Error();

		$this->guard->refuse_pending_approval_app_password( $error, get_user_by( 'id', $this->approved_id ) );

		$this->assertFalse( $error->has_errors(), 'An approved account must not be refused.' );
	}

	/**
	 * Both doors read the same meta, so they cannot drift apart.
	 */
	public function test_both_doors_agree(): void {
		$this->assertTrue( $this->guard->is_pending( $this->pending_id ) );
		$this->assertFalse( $this->guard->is_pending( $this->approved_id ) );

		$error = new WP_Error();
		$this->guard->refuse_pending_approval_app_password( $error, get_user_by( 'id', $this->pending_id ) );

		$this->assertInstanceOf( WP_Error::class, $this->guard->refuse_pending_approval( get_user_by( 'id', $this->pending_id ) ) );
		$this->assertTrue( $error->has_errors() );
	}

	/**
	 * End to end, through core, with a real credential.
	 *
	 * The unit tests above call our callback directly, which proves the decision
	 * but not that core ever asks us for it — and "correct callback, never
	 * consulted" is precisely the bug being fixed. This mints a genuine
	 * application password and hands it to core's own
	 * wp_authenticate_application_password(), the function
	 * wp_validate_application_password() calls for a REST Authorization: Basic
	 * request. Refusal here means refusal for a mobile client.
	 */
	public function test_core_refuses_a_real_application_password_while_pending(): void {
		$this->guard->register();
		$this->enable_application_passwords();

		$user     = get_user_by( 'id', $this->pending_id );
		$created  = \WP_Application_Passwords::create_new_application_password( $this->pending_id, array( 'name' => 'buddynext-app-test' ) );
		$password = $created[0];

		$result = wp_authenticate_application_password( null, $user->user_login, $password );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'Core must refuse a valid application password for an account awaiting approval.'
		);
		$this->assertSame( 'bn_pending_approval', $result->get_error_code() );
	}

	/**
	 * The same credential works once the hold is lifted — so the test above is
	 * pinning the hold, not a broken fixture.
	 */
	public function test_core_accepts_a_real_application_password_once_approved(): void {
		$this->guard->register();
		$this->enable_application_passwords();

		$user     = get_user_by( 'id', $this->approved_id );
		$created  = \WP_Application_Passwords::create_new_application_password( $this->approved_id, array( 'name' => 'buddynext-app-test' ) );
		$password = $created[0];

		$result = wp_authenticate_application_password( null, $user->user_login, $password );

		$this->assertInstanceOf(
			\WP_User::class,
			$result,
			'An approved account must still be able to use its application password.'
		);
		$this->assertSame( $this->approved_id, $result->ID );
	}

	/**
	 * The binding itself, not just the callback.
	 *
	 * A callback that is correct but unhooked is the exact shape of the bug this
	 * class fixes, so assert core's own app-password action carries it.
	 */
	public function test_register_binds_both_chains(): void {
		$this->guard->register();

		$this->assertNotFalse(
			has_filter( 'wp_authenticate_user', array( $this->guard, 'refuse_pending_approval' ) ),
			'The password chain must carry the hold.'
		);
		$this->assertNotFalse(
			has_action( 'wp_authenticate_application_password_errors', array( $this->guard, 'refuse_pending_approval_app_password' ) ),
			'The application-password chain must carry the hold; it is the door a mobile client uses.'
		);
	}
}
