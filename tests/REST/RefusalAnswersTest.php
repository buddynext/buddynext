<?php
/**
 * The answers the front end needs to explain a refusal and clear a stale row.
 *
 * restFetch() resolves with { ok: false }, and each store reads the status and
 * message from there. A stale row (someone else got there first) has to answer
 * 404, or the store cannot tell it from a failure worth retrying.
 *
 * @package BuddyNext\Tests\REST
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\FieldType;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Spaces\SpaceController::remove_member
 * @covers \BuddyNext\SocialGraph\ConnectionController::withdraw_request
 * @covers \BuddyNext\Moderation\ModerationController::issue_strike
 * @covers \BuddyNext\Profile\FieldType::member_type_once_hint
 */
class RefusalAnswersTest extends \WP_UnitTestCase {

	private int $admin_id;
	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member_id = self::factory()->user->create();
		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch as a user.
	 *
	 * @param int    $user_id User.
	 * @param string $method  HTTP method.
	 * @param string $route   Route.
	 * @return \WP_REST_Response
	 */
	private function call( int $user_id, string $method, string $route ) {
		wp_set_current_user( $user_id );
		return rest_do_request( new \WP_REST_Request( $method, $route ) );
	}

	public function test_removing_someone_already_gone_is_a_stale_row_not_a_failure(): void {
		$space_id = (int) ( new SpaceService() )->create(
			$this->admin_id,
			array(
				'name' => 'Stale Row',
				'slug' => 'stale-row',
				'type' => 'open',
			)
		);
		( new SpaceMemberService() )->join( $space_id, $this->member_id );

		$route = '/buddynext/v1/spaces/' . $space_id . '/members/' . $this->member_id;
		$this->assertSame( 200, $this->call( $this->admin_id, 'DELETE', $route )->get_status(), 'A real member is removed.' );

		$again = $this->call( $this->admin_id, 'DELETE', $route );
		$this->assertSame( 404, $again->get_status() );
		$this->assertSame( 'not_a_member', $again->get_data()['code'] );
	}

	public function test_withdrawing_a_request_that_no_longer_exists_answers_404(): void {
		$other = self::factory()->user->create();
		$res   = $this->call( $this->member_id, 'DELETE', '/buddynext/v1/users/' . $other . '/connect' );

		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'not_connected', $res->get_data()['code'] );
	}

	public function test_a_strike_says_whether_the_member_ended_up_suspended(): void {
		wp_set_current_user( $this->admin_id );
		$req = new \WP_REST_Request( 'POST', '/buddynext/v1/users/' . $this->member_id . '/strikes' );
		$req->set_param( 'reason', 'First strike' );
		$res = rest_do_request( $req );

		$this->assertSame( 201, $res->get_status() );
		$this->assertFalse( $res->get_data()['suspended'], 'One strike is below the auto-suspend threshold.' );

		update_option( 'buddynext_strike_suspend_threshold', 2 );
		$req = new \WP_REST_Request( 'POST', '/buddynext/v1/users/' . $this->member_id . '/strikes' );
		$req->set_param( 'reason', 'Second strike' );
		$this->assertTrue( rest_do_request( $req )->get_data()['suspended'], 'Reaching the threshold suspends.' );
	}

	public function test_the_once_hint_names_only_pick_once_types(): void {
		$service = buddynext_service( 'member_types' );
		$this->assertStringNotContainsString( 'Keeper', FieldType::member_type_once_hint() );

		$service->create(
			array(
				'name'        => 'Keeper',
				'slug'        => 'keeper',
				'self_select' => 2,
			)
		);
		$service->create(
			array(
				'name'        => 'Switcher',
				'slug'        => 'switcher',
				'self_select' => 1,
			)
		);

		$hint = FieldType::member_type_once_hint();
		$this->assertStringContainsString( 'Keeper', $hint );
		$this->assertStringNotContainsString( 'Switcher', $hint );
	}

	public function test_a_deleted_account_leaves_the_empty_line_not_an_empty_list(): void {
		global $wpdb;
		$blocks = buddynext_service( 'blocks' );
		$target = self::factory()->user->create();
		$blocks->block( $this->member_id, $target );
		// The row outlives the account when a user is removed by other means.
		$wpdb->delete( $wpdb->users, array( 'ID' => $target ) );
		clean_user_cache( $target );

		wp_set_current_user( $this->member_id );
		ob_start();
		buddynext_get_template( 'parts/settings-relations.php' );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<ul class="bn-ep-relations__list"', $html );
		$this->assertStringContainsString( "You haven't blocked anyone.", wp_specialchars_decode( $html, ENT_QUOTES ) );
	}

	public function test_a_list_row_carries_its_toast_and_empty_line(): void {
		buddynext_service( 'blocks' )->block( $this->member_id, $this->admin_id );

		wp_set_current_user( $this->member_id );
		ob_start();
		buddynext_get_template( 'parts/settings-relations.php' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-bn-done="Unblocked @', $html );
		$this->assertStringContainsString( 'data-bn-empty="You haven&#039;t blocked anyone."', $html );
	}

	public function test_handle_row_is_read_only_when_the_owner_fixes_handles(): void {
		wp_set_current_user( $this->member_id );
		ob_start();
		buddynext_get_template( 'parts/settings-account-fields.php' );
		$this->assertStringContainsString( 'id="bn-ep-slug"', (string) ob_get_clean(), 'Editable by default.' );

		add_filter( 'buddynext_members_can_change_handle', '__return_false' );
		ob_start();
		buddynext_get_template( 'parts/settings-account-fields.php' );
		$html = (string) ob_get_clean();
		remove_filter( 'buddynext_members_can_change_handle', '__return_false' );

		$this->assertStringNotContainsString( 'id="bn-ep-slug"', $html );
		$this->assertStringContainsString( 'Handles cannot be changed on this community.', $html );
	}
}
