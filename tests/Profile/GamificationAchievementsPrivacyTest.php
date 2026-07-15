<?php
/**
 * Privacy gate on the public achievements route.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;

/**
 * GET /users/{id}/achievements is public, which is not the same as public to
 * everyone: it must still honour blocks and profile visibility.
 *
 * @covers \BuddyNext\Profile\GamificationAchievements
 */
class GamificationAchievementsPrivacyTest extends \WP_UnitTestCase {

	/**
	 * The member whose standing is being read.
	 *
	 * @var int
	 */
	private int $owner_id;

	/**
	 * A member the owner has blocked.
	 *
	 * @var int
	 */
	private int $blocked_id;

	/**
	 * Create the pair.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner_id   = self::factory()->user->create();
		$this->blocked_id = self::factory()->user->create();
	}

	/**
	 * Dispatch the route as a given viewer.
	 *
	 * @param int $viewer_id Viewer, or 0 for logged out.
	 * @return array<string,mixed>
	 */
	private function read_as( int $viewer_id ): array {
		wp_set_current_user( $viewer_id );

		$response = rest_get_server()->dispatch(
			new \WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->owner_id . '/achievements' )
		);

		$this->assertSame( 200, $response->get_status() );

		return (array) $response->get_data();
	}

	/**
	 * The route exists and is reachable.
	 *
	 * @return void
	 */
	public function test_route_is_registered(): void {
		$this->assertArrayHasKey(
			'/buddynext/v1/users/(?P<id>\d+)/achievements',
			rest_get_server()->get_routes( 'buddynext/v1' )
		);
	}

	/**
	 * The owner always sees their own standing.
	 *
	 * @return void
	 */
	public function test_owner_can_read_their_own(): void {
		$data = $this->read_as( $this->owner_id );

		$this->assertArrayHasKey( 'badges', $data );
		$this->assertArrayHasKey( 'standing', $data );
	}

	/**
	 * A member the owner blocked must not read their standing.
	 *
	 * The leak this pins: the route's permission_callback is __return_true and the
	 * handler only checked that the member existed, so a blocked viewer could read
	 * points, level, rank and badge history straight off the API — data the web
	 * Achievements tab would never have shown them.
	 *
	 * @return void
	 */
	public function test_blocked_viewer_gets_the_neutral_empty_shape(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $this->owner_id,
				'blocked_id' => $this->blocked_id,
			),
			array( '%d', '%d' )
		);

		$data = $this->read_as( $this->blocked_id );

		$this->assertFalse( $data['has_standing'], 'A blocked viewer must not learn the member has standing.' );
		$this->assertSame( array(), $data['standing'], 'A blocked viewer must not read points/level/rank.' );
		$this->assertSame( array(), $data['badges'], 'A blocked viewer must not read badge history.' );
	}

	/**
	 * The refusal is indistinguishable from "nothing here".
	 *
	 * A 403 would confirm the member exists and has standing worth hiding, so the
	 * gate answers 200 with the same empty shape either way.
	 *
	 * @return void
	 */
	public function test_refusal_does_not_leak_existence(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $this->owner_id,
				'blocked_id' => $this->blocked_id,
			),
			array( '%d', '%d' )
		);

		wp_set_current_user( $this->blocked_id );
		$response = rest_get_server()->dispatch(
			new \WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->owner_id . '/achievements' )
		);

		$this->assertSame( 200, $response->get_status(), 'The gate must not answer 403; that confirms the account.' );
	}

	/**
	 * An unrelated member is unaffected — so the test above pins the block, not a
	 * gate that refuses everyone.
	 *
	 * @return void
	 */
	public function test_unrelated_viewer_still_reads_normally(): void {
		$stranger = self::factory()->user->create();

		$data = $this->read_as( $stranger );

		$this->assertArrayHasKey( 'badges', $data );
		$this->assertIsArray( $data['standing'] );
	}
}
