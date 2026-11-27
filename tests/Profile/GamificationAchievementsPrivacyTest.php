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

	/**
	 * An unpublished badge carries no share link for another member; its owner keeps it.
	 *
	 * @return void
	 */
	public function test_unshared_badge_has_no_share_url_for_others(): void {
		$GLOBALS['wb_gam_test']['badges'][ $this->owner_id ] = array(
			array(
				'id'        => 'welcome',
				'name'      => 'Welcome',
				'earned_at' => '2026-01-01 00:00:00',
			),
		);

		$others = $this->read_as( self::factory()->user->create() );
		$this->assertSame( '', $others['badges'][0]['share_url'] ?? null, 'no link to a badge the member has not shared' );

		$own = $this->read_as( $this->owner_id );
		$this->assertNotSame( '', $own['badges'][0]['share_url'] ?? '', 'the owner still gets the link' );
		unset( $GLOBALS['wb_gam_test']['badges'][ $this->owner_id ] );
	}

	/**
	 * "Show my level and points on my profile" switched off hides points, rank and level from everyone else, and
	 * only from them: the member still sees their own standing.
	 *
	 * @return void
	 */
	public function test_show_points_off_hides_the_standing_from_others(): void {
		if ( ! class_exists( '\\WBGam\\Engine\\MemberPrefs' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in for the partner class, which is not loaded in these tests.
			eval( 'namespace WBGam\\Engine; class MemberPrefs { public static function get( $id ) { return array( "show_rank" => $GLOBALS["wb_gam_test"]["show_rank"][ $id ] ?? true ); } }' );
		}
		$GLOBALS['wb_gam_test']['points'][ $this->owner_id ] = 120;
		$viewer = self::factory()->user->create();

		$this->assertNotEmpty( $this->read_as( $viewer )['standing'], 'Fixture is wrong: the standing should be visible by default.' );

		$GLOBALS['wb_gam_test']['show_rank'][ $this->owner_id ] = false;
		$this->assertSame( array(), $this->read_as( $viewer )['standing'], 'Points, rank and level stayed visible after the member switched them off.' );
		$this->assertNotEmpty( $this->read_as( $this->owner_id )['standing'], 'The member lost sight of their own standing.' );

		unset( $GLOBALS['wb_gam_test']['show_rank'], $GLOBALS['wb_gam_test']['points'][ $this->owner_id ] );
	}

	/**
	 * The shared badge card carries the member's level, so the same choice hides it there (the feed, Explore and
	 * the REST feed item all resolve the card through one filter), while an administrator keeps seeing it.
	 *
	 * @return void
	 */
	public function test_show_points_off_hides_the_level_on_the_badge_card_but_not_from_an_admin(): void {
		if ( ! class_exists( '\\WBGam\\Engine\\MemberPrefs' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in for the partner class, which is not loaded in these tests.
			eval( 'namespace WBGam\\Engine; class MemberPrefs { public static function get( $id ) { return array( "show_rank" => $GLOBALS["wb_gam_test"]["show_rank"][ $id ] ?? true ); } }' );
		}
		$bridge = new \BuddyNext\Bridges\GamificationBridge();
		$facts  = array( array( 'icon' => 'award', 'text' => 'Member' ), array( 'icon' => 'tag', 'text' => 'Helper' ) );
		$meta   = array( 'user_id' => $this->owner_id );
		$viewer = self::factory()->user->create();
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $viewer );
		$this->assertCount( 2, $bridge->drop_points_fact( $facts, 'badge', $meta ), 'Fixture is wrong: the level shows by default.' );

		$GLOBALS['wb_gam_test']['show_rank'][ $this->owner_id ] = false;
		$this->assertCount( 1, $bridge->drop_points_fact( $facts, 'badge', $meta ), 'The badge card still named the level.' );
		wp_set_current_user( 0 );
		$this->assertCount( 1, $bridge->drop_points_fact( $facts, 'badge', $meta ), 'A guest still saw the level.' );
		wp_set_current_user( $admin );
		$this->assertCount( 2, $bridge->drop_points_fact( $facts, 'badge', $meta ), 'An administrator lost the level moderation needs.' );
		wp_set_current_user( $this->owner_id );
		$this->assertCount( 2, $bridge->drop_points_fact( $facts, 'badge', $meta ), 'The member lost their own level.' );

		unset( $GLOBALS['wb_gam_test']['show_rank'] );
	}

	/**
	 * The REST feed item carries the card's stored facts, so the level is dropped there too for a viewer who may
	 * not see it (the app reads this JSON, not the rendered card).
	 *
	 * @return void
	 */
	public function test_show_points_off_drops_the_level_from_the_rest_card_facts(): void {
		if ( ! class_exists( '\\WBGam\\Engine\\MemberPrefs' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in for the partner class, which is not loaded in these tests.
			eval( 'namespace WBGam\\Engine; class MemberPrefs { public static function get( $id ) { return array( "show_rank" => $GLOBALS["wb_gam_test"]["show_rank"][ $id ] ?? true ); } }' );
		}
		$bridge = new \BuddyNext\Bridges\GamificationBridge();
		add_filter( 'buddynext_integration_card_types', static fn( $t ) => $t + array( 'badge' => array( 'label' => 'Badge', 'icon' => 'award' ) ) );
		add_filter( 'buddynext_card_facts', array( $bridge, 'drop_points_fact' ), 10, 3 );

		$item = array(
			'id'        => 987654,
			'type'      => 'badge',
			'user_id'   => $this->owner_id,
			'link_meta' => array( 'facts' => array( array( 'icon' => 'award', 'text' => 'Member' ), array( 'icon' => 'tag', 'text' => 'Helper' ) ) ),
		);
		$GLOBALS['wb_gam_test']['show_rank'][ $this->owner_id ] = false;

		wp_set_current_user( self::factory()->user->create() );
		$out = \BuddyNext\Feed\IntegrationActivity::enrich_item( $item, get_current_user_id() );
		$this->assertCount( 1, $out['link_meta']['facts'], 'The level stayed in the REST link_meta facts.' );

		wp_set_current_user( $this->owner_id );
		$out = \BuddyNext\Feed\IntegrationActivity::enrich_item( $item, $this->owner_id );
		$this->assertCount( 2, $out['link_meta']['facts'], 'The member lost their own level.' );

		remove_filter( 'buddynext_card_facts', array( $bridge, 'drop_points_fact' ), 10 );
		unset( $GLOBALS['wb_gam_test']['show_rank'] );
	}
}
