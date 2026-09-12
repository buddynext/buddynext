<?php
/**
 * Regression: the DM recipient picker (GET /members?messageable=1) must page OVER
 * the send-permission predicate, not under it.
 *
 * mvs_can_send_message is a PHP predicate, so it can only run after the query.
 * An earlier fix filtered a single already-paginated page, which let a page come
 * back empty while messageable matches sat on later pages — and the composer
 * fetches one page and does not follow the cursor, so the member saw "No people
 * found" with many messageable members (card 10297758738, bounced twice). The
 * endpoint now keeps pulling source pages until it has a full page of messageable
 * candidates, and reports has_more instead of an unfiltered total.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Profile\MemberDirectoryController::list_members
 */
class MessageablePickerTest extends \WP_Test_REST_TestCase {

	private int $viewer;
	private int $can_msg;   // messageable, sorts SECOND (older)
	private int $cannot_msg; // DMs off, sorts FIRST (newer)

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->viewer = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Both share a distinctive search token in their display name. The
		// messageable one is registered OLDER so the default 'newest' sort puts the
		// DMs-off member on page 1 and the messageable member on a later page — the
		// exact ordering that produced the empty picker.
		$this->can_msg = self::factory()->user->create(
			array(
				'role'            => 'subscriber',
				'display_name'    => 'Zqpicker Able',
				'user_registered' => '2020-01-01 00:00:00',
			)
		);
		$this->cannot_msg = self::factory()->user->create(
			array(
				'role'            => 'subscriber',
				'display_name'    => 'Zqpicker Baker',
				'user_registered' => '2024-01-01 00:00:00',
			)
		);

		// Make exactly the "Baker" member un-messageable, whoever asks.
		add_filter(
			'mvs_can_send_message',
			function ( $can, $sender, $recipient ) {
				return (int) $recipient === $this->cannot_msg ? false : $can;
			},
			10,
			3
		);
	}

	private function query( bool $messageable ): array {
		wp_set_current_user( $this->viewer );
		$req = new WP_REST_Request( 'GET', '/buddynext/v1/members' );
		$req->set_param( 'search', 'Zqpicker' );
		$req->set_param( 'per_page', 1 );
		if ( $messageable ) {
			$req->set_param( 'messageable', 1 );
		}
		return (array) rest_do_request( $req )->get_data();
	}

	private function ids( array $data ): array {
		return array_map( static fn( $i ) => (int) $i['user_id'], (array) ( $data['items'] ?? array() ) );
	}

	/**
	 * Without the filter, per_page=1 returns the DMs-off member first — this is the
	 * ordering that made the naive post-page filter return an empty result.
	 */
	public function test_unfiltered_page_one_is_the_dms_off_member(): void {
		$ids = $this->ids( $this->query( false ) );
		$this->assertSame( array( $this->cannot_msg ), $ids, 'DMs-off member should sort onto page 1.' );
	}

	/**
	 * messageable=1 at per_page=1 must OVER-FETCH past the DMs-off page-1 member and
	 * still return the messageable member — never an empty page.
	 */
	public function test_messageable_over_fetches_past_dms_off_member(): void {
		$data = $this->query( true );
		$ids  = $this->ids( $data );

		$this->assertNotEmpty( $ids, 'Picker must not be empty when a messageable member exists below the DMs-off page.' );
		$this->assertSame( array( $this->can_msg ), $ids, 'Only the messageable member is offered.' );
		$this->assertNotContains( $this->cannot_msg, $ids, 'The DMs-off member is never offered.' );
	}

	/**
	 * In messageable mode the response reports has_more, not an unfiltered total
	 * that contradicts the list it describes.
	 */
	public function test_messageable_reports_has_more_not_total(): void {
		$data = $this->query( true );
		$this->assertArrayHasKey( 'has_more', $data );
		$this->assertArrayNotHasKey( 'total', $data );
	}
}
