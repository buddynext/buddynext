<?php
/**
 * A member can read their own account standing.
 *
 * Members could list their submitted appeals but not the suspension an appeal is
 * ABOUT — that route is admin-only — so an Account Standing screen could show a
 * history of appeals while being unable to offer "Appeal this suspension":
 * submit_appeal() needs a suspension id the member had no way to obtain.
 *
 * Two things are load-bearing in the payload and are asserted here rather than
 * left to review:
 *
 *  - The acting moderator is NOT named. Who acted on a report is sensitive for
 *    the same reason the report queue is admin-only.
 *  - Shadow-ban state is NOT reported. A shadow ban only functions while the
 *    member does not know about it.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use WP_Error;
use WP_REST_Request;

/**
 * The member-scoped standing endpoint.
 *
 * @covers \BuddyNext\Moderation\ModerationService::get_standing
 * @covers \BuddyNext\Moderation\ModerationService::submit_appeal
 */
class MemberStandingTest extends \WP_UnitTestCase {

	/**
	 * Moderation service under test.
	 *
	 * @var ModerationService
	 */
	private $moderation;

	/**
	 * The moderator taking action.
	 *
	 * @var int
	 */
	private $admin = 0;

	/**
	 * The member reading their own standing.
	 *
	 * @var int
	 */
	private $member = 0;

	/**
	 * Boot the schema, the users and the REST server.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->moderation = new ModerationService();
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member     = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Read /me/standing as the member.
	 *
	 * @return array{status:int,data:array<string,mixed>}
	 */
	private function standing(): array {
		wp_set_current_user( $this->member );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/standing' ) );

		return array(
			'status' => $response->get_status(),
			'data'   => (array) $response->get_data(),
		);
	}

	/**
	 * A member in good standing gets an explicit, empty answer — not a 404 the
	 * client has to interpret.
	 *
	 * @return void
	 */
	public function test_a_clean_member_reads_an_empty_standing(): void {
		$result = $this->standing();

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 0, $result['data']['strikes'] );
		$this->assertSame( array(), $result['data']['history'] );
		$this->assertNull( $result['data']['suspension'] );
	}

	/**
	 * The reported gap: the suspension must be visible, and must carry the id
	 * that POST /me/appeals requires.
	 *
	 * @return void
	 */
	public function test_an_active_suspension_is_visible_with_its_id(): void {
		$suspension_id = $this->moderation->suspend_user(
			$this->member,
			$this->admin,
			'Repeated spam',
			array( 'duration_days' => 7 )
		);
		$this->assertIsInt( $suspension_id );

		$data = $this->standing()['data'];

		$this->assertNotNull( $data['suspension'] );
		$this->assertSame( $suspension_id, $data['suspension']['id'], 'Without the id an appeal cannot be submitted.' );
		$this->assertSame( 'Repeated spam', $data['suspension']['reason'] );
		$this->assertNotNull( $data['suspension']['expires_at'] );
		$this->assertTrue( $data['suspension']['can_appeal'] );
		$this->assertFalse( $data['suspension']['appeal_pending'] );
	}

	/**
	 * Strikes are counted and listed for the member's own record.
	 *
	 * @return void
	 */
	public function test_strikes_are_counted_and_listed(): void {
		$this->moderation->issue_strike( $this->member, $this->admin, 'First warning' );
		$this->moderation->issue_strike( $this->member, $this->admin, 'Second warning' );

		$data = $this->standing()['data'];

		$this->assertSame( 2, $data['strikes'] );
		$this->assertCount( 2, $data['history'] );
		$this->assertSame( 'Second warning', $data['history'][0]['reason'], 'Newest first.' );
	}

	/**
	 * The payload must not name the moderator, and must not mention shadow-ban
	 * state. This is the assertion that stops either being added later for
	 * convenience.
	 *
	 * @return void
	 */
	public function test_the_payload_leaks_neither_the_moderator_nor_a_shadow_ban(): void {
		$this->moderation->issue_strike( $this->member, $this->admin, 'A strike' );
		$this->moderation->suspend_user( $this->member, $this->admin, 'A suspension', array( 'duration_days' => 3 ) );
		$this->moderation->shadow_ban( $this->member, $this->admin, 'Quietly muted' );

		$json = (string) wp_json_encode( $this->standing()['data'] );

		$this->assertStringNotContainsString( 'issued_by', $json );
		$this->assertStringNotContainsString( 'suspended_by', $json );
		$this->assertStringNotContainsString( 'shadow', $json, 'A shadow ban only works while the member does not know.' );

		// Assert on KEYS rather than on the id's digits — a moderator id like "8"
		// occurs inside timestamps, so a substring check would fail on noise and
		// pass on nothing useful.
		$data = $this->standing()['data'];
		$this->assertSame( array( 'strikes', 'history', 'suspension' ), array_keys( $data ) );
		foreach ( $data['history'] as $row ) {
			$this->assertSame( array( 'id', 'reason', 'created_at', 'created_at_gmt' ), array_keys( $row ) );
		}
	}

	/**
	 * A member cannot appeal the same suspension twice — the guard the new
	 * "Appeal this suspension" button makes reachable with a double tap.
	 *
	 * @return void
	 */
	public function test_the_same_suspension_cannot_be_appealed_twice(): void {
		$suspension_id = $this->moderation->suspend_user(
			$this->member,
			$this->admin,
			'Spam',
			array( 'duration_days' => 7 )
		);

		$first = $this->moderation->submit_appeal( $this->member, (int) $suspension_id, 'Please reconsider.' );
		$this->assertIsInt( $first );

		$second = $this->moderation->submit_appeal( $this->member, (int) $suspension_id, 'Double tap.' );
		$this->assertInstanceOf( WP_Error::class, $second, 'A duplicate appeal was filed.' );
		$this->assertSame( 'appeal_already_pending', $second->get_error_code() );

		$data = $this->standing()['data'];
		$this->assertTrue( $data['suspension']['appeal_pending'] );
		$this->assertFalse( $data['suspension']['can_appeal'] );
	}

	/**
	 * The route is own-record-only: no id parameter exists, and a logged-out
	 * caller gets nothing.
	 *
	 * @return void
	 */
	public function test_standing_requires_auth(): void {
		wp_set_current_user( 0 );

		$this->assertSame(
			401,
			rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/standing' ) )->get_status()
		);
	}
}
