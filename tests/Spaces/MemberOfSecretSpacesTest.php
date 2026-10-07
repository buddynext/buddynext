<?php
/**
 * A profile's "Member of" list never reveals a secret space to outsiders.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Logged-out visitors saw every secret space a member belonged to on their
 * profile. membership_rows() now takes the viewer; GET /users/{id}/spaces
 * reads the same rows.
 *
 * @covers \BuddyNext\Spaces\SpaceMemberService::membership_rows
 * @covers \BuddyNext\Spaces\SpaceController::get_member_spaces
 */
class MemberOfSecretSpacesTest extends WP_UnitTestCase {

	/**
	 * Fresh schema and caches.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
	}

	/**
	 * Names the given viewer sees in the member's list.
	 *
	 * @param int $member Profile owner.
	 * @param int $viewer Who is looking.
	 * @return string[]
	 */
	private function seen_by( int $member, int $viewer ): array {
		$names = wp_list_pluck( buddynext_service( 'space_members' )->membership_rows( $member, 50, $viewer ), 'name' );
		sort( $names );
		return $names;
	}

	/**
	 * Secret spaces show only to the member, fellow members and admins.
	 *
	 * @return void
	 */
	public function test_secret_spaces_only_show_to_insiders(): void {
		$member = self::factory()->user->create();
		$fellow = self::factory()->user->create();
		$spaces = new SpaceService();
		$secret = $spaces->create( $member, array( 'name' => 'Hidden Circle', 'slug' => 'hidden-' . wp_rand( 1000, 9999 ), 'type' => 'secret' ) );
		$spaces->create( $member, array( 'name' => 'Open Hall', 'slug' => 'open-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );

		$both = array( 'Hidden Circle', 'Open Hall' );
		$this->assertSame( array( 'Open Hall' ), $this->seen_by( $member, 0 ), 'Logged-out visitor.' );
		$this->assertSame( array( 'Open Hall' ), $this->seen_by( $member, self::factory()->user->create() ), 'Another member outside the space.' );
		$this->assertSame( $both, $this->seen_by( $member, $member ), 'The member themselves.' );
		$this->assertSame( $both, $this->seen_by( $member, self::factory()->user->create( array( 'role' => 'administrator' ) ) ), 'Site admin.' );

		$members = buddynext_service( 'space_members' );
		$members->invite( (int) $secret, $member, $fellow );
		$members->join( (int) $secret, $fellow );
		$this->assertSame( $both, $this->seen_by( $member, $fellow ), 'A fellow member of the secret space.' );

		// REST reads the same rows for the requesting viewer.
		wp_set_current_user( 0 );
		$names = wp_list_pluck( rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$member}/spaces" ) )->get_data(), 'name' );
		$this->assertSame( array( 'Open Hall' ), $names );
	}

	/**
	 * An archived space leaves other people's view of the member's list, even
	 * when the list was cached before the archive; the member and admins still see it.
	 *
	 * @return void
	 */
	public function test_archived_spaces_hide_from_other_viewers(): void {
		$member = self::factory()->user->create();
		$spaces = new SpaceService();
		$old    = $spaces->create( $member, array( 'name' => 'Old Club', 'slug' => 'old-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );
		$spaces->create( $member, array( 'name' => 'Open Hall', 'slug' => 'open-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );

		$both = array( 'Old Club', 'Open Hall' );
		$this->assertSame( $both, $this->seen_by( $member, 0 ), 'Primes the cached rows before the archive.' );

		$this->assertTrue( $spaces->archive( (int) $old, $member ) );

		$this->assertSame( array( 'Open Hall' ), $this->seen_by( $member, 0 ), 'Logged-out visitor.' );
		$this->assertSame( array( 'Open Hall' ), $this->seen_by( $member, self::factory()->user->create() ), 'Another member.' );
		$this->assertSame( $both, $this->seen_by( $member, $member ), 'The member themselves.' );
		$this->assertSame( $both, $this->seen_by( $member, self::factory()->user->create( array( 'role' => 'administrator' ) ) ), 'Site admin.' );

		wp_set_current_user( 0 );
		$names = wp_list_pluck( rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$member}/spaces" ) )->get_data(), 'name' );
		$this->assertSame( array( 'Open Hall' ), $names, 'REST reads the same rows.' );
	}

	/**
	 * A profile the viewer may not see hides its spaces too.
	 *
	 * @return void
	 */
	public function test_hidden_profile_hides_its_spaces(): void {
		$member = self::factory()->user->create();
		buddynext_service( 'privacy' )->set_preference( $member, 'profile_visibility', 'private' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$member}/spaces" ) )->get_status() );
	}
}
