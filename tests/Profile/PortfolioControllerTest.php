<?php
/**
 * Tests for the Portfolio REST endpoint (the app's view of a member's panels).
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

/**
 * @covers \BuddyNext\Profile\PortfolioController
 */
class PortfolioControllerTest extends \WP_UnitTestCase {

	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		// Fire rest_api_init on a fresh server so routes register the WP-correct
		// way (the Pro plugin registers PortfolioController on this hook).
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->member_id = self::factory()->user->create();

		add_filter(
			'buddynext_member_suite_panels',
			static function ( array $panels, int $member_id ): array {
				$panels[] = array(
					'key'       => 'cb_jobs',
					'label'     => 'Jobs',
					'icon'      => 'briefcase',
					'cta'       => array( 'label' => 'View all', 'url' => 'https://x/company/' ),
					'owner_cta' => array( 'label' => 'Manage', 'url' => 'https://x/dashboard/' ),
					'items'     => array( array( 'title' => 'Senior PHP Dev', 'url' => 'https://x/j1', 'meta' => 'Acme' ) ),
				);
				return $panels;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function get_portfolio( int $member_id ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', "/buddynext/v1/members/{$member_id}/portfolio" );
		return rest_get_server()->dispatch( $request );
	}

	public function test_returns_panels_as_json(): void {
		$res  = $this->get_portfolio( $this->member_id );
		$data = $res->get_data();

		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $data['panels'] );
		$this->assertSame( 'cb_jobs', $data['panels'][0]['key'] );
		$this->assertSame( 'Senior PHP Dev', $data['panels'][0]['items'][0]['title'] );
		$this->assertSame( 'https://x/company/', $data['panels'][0]['cta']['url'] );
	}

	public function test_owner_cta_hidden_from_other_viewers(): void {
		// A different logged-in user must not receive the manage link.
		wp_set_current_user( self::factory()->user->create() );
		$data = $this->get_portfolio( $this->member_id )->get_data();
		$this->assertSame( array(), $data['panels'][0]['owner_cta'] );
		$this->assertFalse( $data['is_owner'] );

		// The owner gets it.
		wp_set_current_user( $this->member_id );
		$data = $this->get_portfolio( $this->member_id )->get_data();
		$this->assertSame( 'https://x/dashboard/', $data['panels'][0]['owner_cta']['url'] );
		$this->assertTrue( $data['is_owner'] );
	}

	public function test_unknown_member_returns_empty(): void {
		$data = $this->get_portfolio( 99999999 )->get_data();
		$this->assertSame( array(), $data['panels'] );
	}

	public function test_a_missing_member_answers_in_the_same_shape_as_a_hidden_one(): void {
		wp_set_current_user( self::factory()->user->create() );

		$missing = rest_do_request( new \WP_REST_Request( 'GET', '/buddynext/v1/members/99999999/portfolio' ) )->get_data();
		$this->assertSame( array( 'member_id', 'is_owner', 'panels' ), array_keys( $missing ) );
		$this->assertFalse( $missing['is_owner'] );
		$this->assertSame( array(), $missing['panels'] );
	}
}
