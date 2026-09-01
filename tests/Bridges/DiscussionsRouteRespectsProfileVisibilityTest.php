<?php
/**
 * The member-discussions route must honour profile visibility.
 *
 * Reproduces card 10227862965 as a code flow.
 *
 * `GET /buddynext/v1/members/{id}/discussions` is registered with
 * `permission_callback => '__return_true'` and its handler checks one thing: that
 * the user id resolves. It never asks `PrivacyService::can_view_profile()`.
 *
 * `ProfileController` — the route right next to it — does ask, and says why:
 *
 *     // SECURITY: gate the read with the canonical profile-visibility check …
 *     // Return the same 404 as a missing user so existence isn't leaked either.
 *
 * So a private profile 404s on `/users/{id}/profile` and, for the same viewer,
 * hands back discussion titles, URLs, space names, reputation and trust level on
 * `/members/{id}/discussions`. The privacy setting is honoured on one surface and
 * ignored on the one beside it.
 *
 * ## Asserted as a comparison, not a fixed expectation
 *
 * The test asks the two routes the same question with the same viewer and
 * requires them to agree. Written that way because "what a private profile should
 * return" is a product decision that may change; "these two must not disagree" is
 * the invariant, and it stays true whichever way the decision goes.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Discussions route versus profile visibility.
 *
 * @covers \BuddyNext\Bridges\JetonomyBridge::rest_member_discussions
 */
class DiscussionsRouteRespectsProfileVisibilityTest extends WP_UnitTestCase {

	/**
	 * The member with a private profile.
	 *
	 * @var int
	 */
	private int $owner = 0;

	/**
	 * A stranger — logged in, no relationship to the owner.
	 *
	 * @var int
	 */
	private int $stranger = 0;

	/**
	 * A private member and an unrelated viewer, with the routes registered.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init' );

		$this->owner    = self::factory()->user->create();
		$this->stranger = self::factory()->user->create();

		$privacy = buddynext_service( 'privacy' );
		$privacy->set_preference( $this->owner, 'profile_visibility', 'private' );

		// The first draft wrote a meta key of its own invention, left the owner
		// public, and every assertion passed against two routes that agreed at 200.
		$this->assertFalse(
			$privacy->can_view_profile( $this->stranger, $this->owner ),
			'Fixture: the owner must actually be private, or this test compares two open doors.'
		);
	}

	/**
	 * Dispatch a route as a given viewer and return [status, data].
	 *
	 * @param string $route   REST route.
	 * @param int    $viewer  Viewer user id (0 for logged out).
	 * @return array{0:int,1:array<string,mixed>}
	 */
	private function get_as( string $route, int $viewer ): array {
		wp_set_current_user( $viewer );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );

		return array( (int) $response->get_status(), (array) $response->get_data() );
	}

	/**
	 * Whether the discussions route is registered at all in this environment.
	 *
	 * The bridge self-guards on Jetonomy being present. The suite aliases a stub
	 * for that class, but if the guard or the alias ever changes this test would
	 * quietly stop covering anything - so it says so instead.
	 *
	 * @return void
	 */
	private function require_route(): void {
		$routes = rest_get_server()->get_routes();

		$found = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( false !== strpos( (string) $route, 'members/(?P<id>' ) && false !== strpos( (string) $route, 'discussions' ) ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			$this->markTestSkipped( 'The Jetonomy discussions route is not registered in this environment, so there is nothing to gate.' );
		}
	}

	/**
	 * The two routes must give the same viewer the same answer about the same member.
	 *
	 * @return void
	 */
	public function test_discussions_agrees_with_the_profile_route_for_a_stranger(): void {
		$this->require_route();

		list( $profile_status ) = $this->get_as( '/buddynext/v1/users/' . $this->owner . '/profile', $this->stranger );
		list( $disc_status )    = $this->get_as( '/buddynext/v1/members/' . $this->owner . '/discussions', $this->stranger );

		$this->assertSame(
			$profile_status >= 400,
			$disc_status >= 400,
			sprintf(
				'The profile route answered %d and the discussions route answered %d for the same viewer and the same private member. One of these two is wrong, and it is not the one with the SECURITY comment.',
				$profile_status,
				$disc_status
			)
		);
	}

	/**
	 * And the same for a logged-out visitor, which is the worst case.
	 *
	 * @return void
	 */
	public function test_discussions_agrees_with_the_profile_route_for_a_guest(): void {
		$this->require_route();

		list( $profile_status ) = $this->get_as( '/buddynext/v1/users/' . $this->owner . '/profile', 0 );
		list( $disc_status )    = $this->get_as( '/buddynext/v1/members/' . $this->owner . '/discussions', 0 );

		$this->assertSame(
			$profile_status >= 400,
			$disc_status >= 400,
			sprintf( 'Guest: profile route %d, discussions route %d.', $profile_status, $disc_status )
		);
	}

	/**
	 * A PUBLIC member's discussions stay readable. Guards the guard.
	 *
	 * A fix that 404s everyone would pass both tests above and delete the feature.
	 *
	 * @return void
	 */
	public function test_a_public_members_discussions_are_still_readable(): void {
		$this->require_route();

		$public_member = self::factory()->user->create();
		buddynext_service( 'privacy' )->set_preference( $public_member, 'profile_visibility', 'public' );

		list( $status ) = $this->get_as( '/buddynext/v1/members/' . $public_member . '/discussions', $this->stranger );

		$this->assertLessThan( 400, $status, 'A public member’s discussions must remain readable.' );
	}
}
