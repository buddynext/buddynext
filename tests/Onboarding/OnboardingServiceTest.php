<?php
/**
 * Tests for the member onboarding wizard service.
 *
 * @package BuddyNext\Tests\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Onboarding;

use BuddyNext\Core\Installer;
use BuddyNext\Onboarding\OnboardingService;

/**
 * Verifies member onboarding step handling and completion flow.
 *
 * @covers \BuddyNext\Onboarding\OnboardingService
 */
class OnboardingServiceTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var OnboardingService
	 */
	private OnboardingService $service;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Create a fresh service and test user before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new OnboardingService();
		$this->user_id = self::factory()->user->create();
		delete_user_meta( $this->user_id, 'bn_onboarding_complete' );
		delete_user_meta( $this->user_id, 'bn_onboarding_step' );
	}

	/**
	 * New users have not completed onboarding.
	 */
	public function test_is_complete_returns_false_for_new_user(): void {
		$this->assertFalse( $this->service->is_complete( $this->user_id ) );
	}

	/**
	 * New users start at step 1.
	 */
	public function test_get_step_returns_one_for_new_user(): void {
		$this->assertSame( 1, $this->service->get_step( $this->user_id ) );
	}

	/**
	 * Step 1 saves display_name to the WP user.
	 */
	public function test_save_step1_persists_display_name(): void {
		$this->service->save_step(
			$this->user_id,
			1,
			array( 'display_name' => 'Alice Smith' )
		);
		$user = get_userdata( $this->user_id );
		$this->assertSame( 'Alice Smith', $user->display_name );
	}

	/**
	 * Saving step 1 advances the stored step to 2.
	 */
	public function test_save_step1_advances_step(): void {
		$this->service->save_step( $this->user_id, 1, array( 'display_name' => 'Alice' ) );
		$this->assertSame( 2, $this->service->get_step( $this->user_id ) );
	}

	/**
	 * Interest picks land in the 'interests' profile field (one
	 * bn_profile_values row per pick) and round-trip via get_interest_ids —
	 * never in user meta (the legacy bn_onboarding_interests store is gone).
	 */
	public function test_save_interest_ids_stores_profile_field_rows(): void {
		$cat_id = $this->create_category( 'Chess Club' );

		$stored = $this->service->save_interest_ids( $this->user_id, array( $cat_id, $cat_id, 0 ) );

		$this->assertSame( array( $cat_id ), $stored );
		$this->assertSame( array( $cat_id ), $this->service->get_interest_ids( $this->user_id ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'bn_onboarding_interests', true ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'bn_interests', true ) );
	}

	/**
	 * Step 2 no longer persists anything — it only advances the step pointer.
	 */
	public function test_save_step2_only_advances_step(): void {
		$this->service->save_step( $this->user_id, 2, array( 'interest_ids' => array( 3, 7 ) ) );
		$this->assertSame( 3, $this->service->get_step( $this->user_id ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'bn_onboarding_interests', true ) );
	}

	/**
	 * Create (or resolve) a space category for interest tests.
	 *
	 * @param string $name Category name.
	 * @return int Category ID.
	 */
	private function create_category( string $name ): int {
		$id = ( new \BuddyNext\Spaces\SpaceCategoryService() )->create( array( 'name' => $name ) );
		if ( ! is_wp_error( $id ) ) {
			return (int) $id;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_space_categories WHERE slug = %s", sanitize_title( $name ) )
		);
	}

	/**
	 * Calling skip() marks the wizard as complete without requiring all steps.
	 */
	public function test_skip_marks_complete(): void {
		$this->service->skip( $this->user_id );
		$this->assertTrue( $this->service->is_complete( $this->user_id ) );
	}

	/**
	 * Calling finish() marks the wizard as complete.
	 */
	public function test_finish_marks_complete(): void {
		$this->service->finish( $this->user_id );
		$this->assertTrue( $this->service->is_complete( $this->user_id ) );
	}

	/**
	 * Calling finish() fires buddynext_onboarding_completed with the user ID.
	 */
	public function test_finish_fires_buddynext_onboarding_completed(): void {
		$fired_user = 0;
		add_action(
			'buddynext_onboarding_completed',
			function ( int $uid ) use ( &$fired_user ): void {
				$fired_user = $uid;
			},
			10,
			1
		);
		$this->service->finish( $this->user_id );
		$this->assertSame( $this->user_id, $fired_user );
	}

	/**
	 * Calling finish() twice does not fire the action a second time.
	 */
	public function test_second_finish_does_not_double_fire(): void {
		$count = 0;
		add_action(
			'buddynext_onboarding_completed',
			function () use ( &$count ): void {
				++$count;
			}
		);
		$this->service->finish( $this->user_id );
		$this->service->finish( $this->user_id );
		$this->assertSame( 1, $count );
	}
}
