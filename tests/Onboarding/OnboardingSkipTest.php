<?php
/**
 * The no-script way out of onboarding: a plain form post that completes it.
 *
 * @package BuddyNext\Tests\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Onboarding;

use BuddyNext\Core\Installer;
use BuddyNext\Onboarding\OnboardingListener;
use WP_UnitTestCase;

/**
 * OnboardingListener::handle_skip().
 */
class OnboardingSkipTest extends WP_UnitTestCase {

	/**
	 * Create the tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * The listener wires the admin-post action, so the Skip setup form has somewhere to post.
	 *
	 * @return void
	 */
	public function test_the_skip_action_is_registered(): void {
		( new OnboardingListener() )->register();
		$this->assertNotFalse( has_action( 'admin_post_bn_onboarding_skip' ) );
	}

	/**
	 * A post without the nonce is refused and onboarding stays open.
	 *
	 * @return void
	 */
	public function test_a_post_without_the_nonce_does_not_complete_onboarding(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$_REQUEST['_wpnonce'] = 'bad';

		try {
			( new OnboardingListener() )->handle_skip();
			$this->fail( 'handle_skip() must stop on a bad nonce.' );
		} catch ( \Throwable $e ) {
			$this->assertFalse( buddynext_service( 'onboarding' )->is_complete( $user ) );
		}
	}

	/**
	 * Finishing marks the member complete, which is what the handler calls.
	 *
	 * @return void
	 */
	public function test_finish_completes_onboarding(): void {
		$user = self::factory()->user->create();
		$this->assertFalse( buddynext_service( 'onboarding' )->is_complete( $user ) );
		buddynext_service( 'onboarding' )->finish( $user );
		$this->assertTrue( buddynext_service( 'onboarding' )->is_complete( $user ) );
	}
}
