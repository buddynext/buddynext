<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Member lists show only the headline and bio the profile page would show.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Directory cards read the raw headline and bio, so a member who hid them (or their whole
 * profile) still had them published on the public GET /members route.
 *
 * @covers \BuddyNext\Profile\ProfileService::card_identity_for
 */
class CardIdentityVisibilityTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, clean cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
		wp_set_current_user( 0 );
	}

	/**
	 * A member with a headline and bio, each at the visibility asked for.
	 *
	 * @param string $headline_vis Headline visibility.
	 * @param string $bio_vis      Bio visibility.
	 * @param string $profile_vis  Whole-profile visibility.
	 * @return int
	 */
	private function member( string $headline_vis = 'public', string $bio_vis = 'public', string $profile_vis = 'public' ): int {
		$uid = self::factory()->user->create();
		$res = buddynext_service( 'profiles' )->save_profile(
			$uid,
			array(
				'headline'             => "HEADSECRET{$uid}",
				'headline__visibility' => $headline_vis,
				'bio'                  => "BIOSECRET{$uid}",
				'bio__visibility'      => $bio_vis,
			)
		);
		$this->assertNotWPError( $res );
		buddynext_service( 'privacy' )->set_preference( $uid, 'profile_visibility', $profile_vis );
		return $uid;
	}

	/**
	 * What a viewer reads for one member.
	 *
	 * @param int $viewer Viewer.
	 * @param int $member Member.
	 * @return array
	 */
	private function read( int $viewer, int $member ): array {
		return buddynext_service( 'profiles' )->card_identity_for( $viewer, array( $member ) )[ $member ];
	}

	/**
	 * Profile visibility: guests and strangers get nothing, the right relationship does.
	 *
	 * @return void
	 */
	public function test_profile_visibility_gates_both_fields(): void {
		$private     = $this->member( 'public', 'public', 'private' );
		$followers   = $this->member( 'public', 'public', 'followers' );
		$connections = $this->member( 'public', 'public', 'connections' );
		$stranger    = self::factory()->user->create();
		$follower    = self::factory()->user->create();
		$connection  = self::factory()->user->create();
		$empty       = array(
			'headline' => '',
			'bio'      => '',
		);

		buddynext_service( 'follows' )->follow( $follower, $followers );
		buddynext_service( 'connections' )->send_request( $connection, $connections );
		buddynext_service( 'connections' )->accept_request( $connections, $connection );

		foreach ( array( $private, $followers, $connections ) as $hidden ) {
			$this->assertSame( $empty, $this->read( 0, $hidden ) );
			$this->assertSame( $empty, $this->read( $stranger, $hidden ) );
		}

		$this->assertSame( "HEADSECRET{$followers}", $this->read( $follower, $followers )['headline'] );
		$this->assertSame( "BIOSECRET{$connections}", $this->read( $connection, $connections )['bio'] );
		$this->assertSame( $empty, $this->read( $follower, $private ) );
		$this->assertSame( "BIOSECRET{$private}", $this->read( $private, $private )['bio'], 'The owner always reads their own card.' );
	}

	/**
	 * Field visibility: each field on its own, on a profile open to everyone.
	 *
	 * @return void
	 */
	public function test_field_visibility_gates_each_field(): void {
		$member   = $this->member( 'private', 'members' );
		$stranger = self::factory()->user->create();

		$this->assertSame(
			array(
				'headline' => '',
				'bio'      => '',
			),
			$this->read( 0, $member )
		);
		$this->assertSame(
			array(
				'headline' => '',
				'bio'      => "BIOSECRET{$member}",
			),
			$this->read( $stranger, $member )
		);

		$public = $this->member();
		$this->assertSame(
			array(
				'headline' => "HEADSECRET{$public}",
				'bio'      => "BIOSECRET{$public}",
			),
			$this->read( 0, $public )
		);
	}

	/**
	 * Hiding the field hides the older usermeta copies too.
	 *
	 * @return void
	 */
	public function test_usermeta_copies_follow_the_field(): void {
		$member = $this->member( 'private', 'private' );
		update_user_meta( $member, 'bn_field_bio', 'MIRRORSECRET' );
		update_user_meta( $member, 'description', 'DESCSECRET' );

		$this->assertSame( '', $this->read( 0, $member )['bio'] );

		$legacy = self::factory()->user->create();
		update_user_meta( $legacy, 'bn_headline', 'OLDHEAD' );
		update_user_meta( $legacy, 'description', 'OLDBIO' );
		$this->assertSame(
			array(
				'headline' => 'OLDHEAD',
				'bio'      => 'OLDBIO',
			),
			$this->read( 0, $legacy )
		);
	}

	/**
	 * A site admin opens a closed profile, as on the profile page, but not an "Only me" field.
	 *
	 * @return void
	 */
	public function test_admin_reads_closed_profile_but_not_only_me_field(): void {
		$member = $this->member( 'public', 'private', 'private' );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertSame(
			array(
				'headline' => "HEADSECRET{$member}",
				'bio'      => '',
			),
			$this->read( $admin, $member )
		);
	}

	/**
	 * Blocking the viewer closes the card, whatever the visibility.
	 *
	 * @return void
	 */
	public function test_blocked_viewer_reads_nothing(): void {
		$member = $this->member();
		$viewer = self::factory()->user->create();
		buddynext_service( 'blocks' )->block( $member, $viewer );

		$this->assertSame( '', $this->read( $viewer, $member )['headline'] );
	}

	/**
	 * The public REST route, the SSR grid and search rows, all as a guest.
	 *
	 * @return void
	 */
	public function test_guest_surfaces_carry_no_hidden_text(): void {
		$hidden  = $this->member( 'public', 'public', 'private' );
		$only_me = $this->member( 'private', 'private' );
		$open    = $this->member();
		$needles = array( "HEADSECRET{$hidden}", "BIOSECRET{$hidden}", "HEADSECRET{$only_me}", "BIOSECRET{$only_me}" );

		rest_get_server();
		$rest = wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/members' ) )->get_data() );

		ob_start();
		buddynext_get_template(
			'parts/member-directory-grid.php',
			array(
				'members'   => array( get_userdata( $hidden ), get_userdata( $only_me ), get_userdata( $open ) ),
				'viewer_id' => 0,
			)
		);
		$ssr = (string) ob_get_clean();

		$search = new \ReflectionMethod( buddynext_service( 'search' ), 'enrich_members' );
		$rows   = wp_json_encode( $search->invoke( buddynext_service( 'search' ), array( $hidden, $only_me, $open ), 0 ) );

		foreach ( array(
			'REST'   => $rest,
			'SSR'    => $ssr,
			'search' => $rows,
		) as $surface => $body ) {
			foreach ( $needles as $needle ) {
				$this->assertStringNotContainsString( $needle, (string) $body, $surface );
			}
			$this->assertStringContainsString( "BIOSECRET{$open}", (string) $body, "{$surface} must still show an open bio" );
		}
		$this->assertStringContainsString( "HEADSECRET{$open}", $ssr );
		$this->assertStringContainsString( "HEADSECRET{$open}", (string) $rest );
	}

	/**
	 * GET /users/{id}/profile honours Bio visibility on an open profile.
	 *
	 * @return void
	 */
	public function test_single_profile_rest_bio_honours_field_visibility(): void {
		$member = $this->member( 'public', 'private' );

		rest_get_server();
		$data = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$member}/profile" ) )->get_data();

		$this->assertSame( "HEADSECRET{$member}", (string) ( $data['groups'][0]['fields'][0]['value'] ?? '' ), 'The route answered with the real profile.' );
		$this->assertStringNotContainsString( "BIOSECRET{$member}", (string) wp_json_encode( $data ) );
	}

	/**
	 * Search must not find a member by text the member hid.
	 *
	 * @return void
	 */
	public function test_search_index_holds_only_what_strangers_may_read(): void {
		global $wpdb;

		$tiers = $this->member( 'members', 'private' );
		$open  = $this->member();

		$profiles = buddynext_service( 'profiles' );
		$profiles->index_user( $tiers );
		$profiles->index_user( $open );

		$row = static fn( int $uid ) => $wpdb->get_row( $wpdb->prepare( "SELECT content, content_members FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'user' AND object_id = %d", $uid ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$indexed = $row( $tiers );
		$this->assertStringNotContainsString( 'SECRET', (string) $indexed['content'] );
		$this->assertStringContainsString( "HEADSECRET{$tiers}", (string) $indexed['content_members'] );
		$this->assertStringNotContainsString( "BIOSECRET{$tiers}", (string) $indexed['content_members'] );

		$this->assertStringContainsString( "BIOSECRET{$open}", (string) $row( $open )['content'] );

		buddynext_service( 'privacy' )->set_preference( $open, 'profile_visibility', 'private' );
		$this->assertStringNotContainsString( 'SECRET', (string) $row( $open )['content'], 'Closing the profile empties its searchable text.' );
	}

	/**
	 * The query cost of a page does not grow with its size.
	 *
	 * @return void
	 */
	public function test_query_count_does_not_grow_with_the_page(): void {
		global $wpdb;

		$viewer  = self::factory()->user->create();
		$members = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$members[] = $this->member( 'public', 'public', 0 === $i % 2 ? 'followers' : 'public' );
		}

		$cost = static function ( array $ids ) use ( $viewer, $wpdb ): int {
			wp_cache_flush();
			$before = $wpdb->num_queries;
			buddynext_service( 'profiles' )->card_identity_for( $viewer, $ids );
			return $wpdb->num_queries - $before;
		};

		$cost( $members ); // Warm the once-per-request ability lookup, so both runs below compare alike.
		$this->assertSame( $cost( array_slice( $members, 0, 2 ) ), $cost( $members ) );
	}
}
