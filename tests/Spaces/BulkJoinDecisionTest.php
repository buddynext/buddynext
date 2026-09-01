<?php
/**
 * Bulk approve/decline of pending space join requests.
 *
 * Exists so the notifications screen can act on a collapsed "8 people asked to
 * join" row in ONE call instead of looping the per-member endpoint from the
 * browser.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Spaces\SpaceController::decide_requests_bulk
 */
class BulkJoinDecisionTest extends \WP_UnitTestCase {

	private int $space_id = 0;
	private int $owner    = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$space = buddynext_service( 'spaces' )->create(
			$this->owner,
			array(
				'name' => 'Bulk Decisions',
				'slug' => 'bulk-decisions-' . wp_rand( 1000, 9999 ),
				'type' => 'private',
			)
		);

		$this->assertIsInt( $space, 'the fixture space must exist before the tests run' );
		$this->space_id = (int) $space;
	}

	/**
	 * Put a member into the pending state on the test space.
	 *
	 * @return int New user id.
	 */
	private function pending_member(): int {
		global $wpdb;
		$user = self::factory()->user->create();
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $this->space_id,
				'user_id'  => $user,
				'role'     => 'member',
				'status'   => 'pending',
			),
			array( '%d', '%d', '%s', '%s' )
		);
		return $user;
	}

	/**
	 * Dispatch the bulk endpoint.
	 *
	 * @param int[]  $user_ids Members to decide.
	 * @param string $decision approve|decline.
	 * @param int    $actor    Acting user.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function decide( array $user_ids, string $decision, int $actor ) {
		wp_set_current_user( $actor );
		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $this->space_id . '/members/decide-bulk' );
		$request->set_param( 'id', $this->space_id );
		$request->set_param( 'user_ids', $user_ids );
		$request->set_param( 'decision', $decision );
		return rest_do_request( $request );
	}

	/**
	 * Status of a member row.
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	private function status_of( int $user_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
				$this->space_id,
				$user_id
			)
		);
	}

	public function test_approving_several_requests_admits_every_member(): void {
		$members = array( $this->pending_member(), $this->pending_member(), $this->pending_member() );

		$response = $this->decide( $members, 'approve', $this->owner );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $members, $response->get_data()['decided'] );
		foreach ( $members as $member ) {
			$this->assertSame( 'active', $this->status_of( $member ) );
		}
	}

	public function test_declining_several_requests_refuses_every_member(): void {
		$members = array( $this->pending_member(), $this->pending_member() );

		$response = $this->decide( $members, 'decline', $this->owner );

		$this->assertSame( 200, $response->get_status() );
		foreach ( $members as $member ) {
			$this->assertNotSame( 'active', $this->status_of( $member ) );
		}
	}

	/**
	 * A request withdrawn between page load and button press must not fail the
	 * whole batch — the approvals that DID apply have to stand.
	 */
	public function test_a_partial_batch_reports_each_outcome_and_keeps_the_successes(): void {
		$real = $this->pending_member();

		$response = $this->decide( array( $real, 987654 ), 'approve', $this->owner );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'a mixed result is a success with detail' );
		$this->assertSame( array( $real ), $data['decided'] );
		$this->assertSame( 987654, $data['failed'][0]['user_id'] );
		$this->assertSame( 'active', $this->status_of( $real ), 'the real approval must stand' );
	}

	/**
	 * When nothing at all applied, that is an error rather than a hollow 200.
	 */
	public function test_a_batch_where_nothing_applies_is_an_error(): void {
		$response = $this->decide( array( 987654, 987655 ), 'approve', $this->owner );

		$this->assertInstanceOf( \WP_Error::class, $response->as_error() ?? $response );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A member who does not manage the space cannot decide for it.
	 */
	public function test_a_non_manager_cannot_decide(): void {
		$member  = $this->pending_member();
		$outside = self::factory()->user->create();

		$response = $this->decide( array( $member ), 'approve', $outside );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'pending', $this->status_of( $member ), 'the request must be untouched' );
	}

	/**
	 * The write loop is bounded.
	 */
	public function test_an_oversized_batch_is_refused(): void {
		$response = $this->decide( range( 1, 101 ), 'approve', $this->owner );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'too_many_users', $response->get_data()['code'] );
	}

	/**
	 * An unknown decision is rejected by the route, not interpreted.
	 */
	public function test_an_unknown_decision_is_rejected(): void {
		$response = $this->decide( array( $this->pending_member() ), 'banish', $this->owner );

		$this->assertSame( 400, $response->get_status() );
	}
}
