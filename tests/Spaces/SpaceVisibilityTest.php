<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests for the canonical space visibility resolver.
 *
 * Covers the ONE decision point every surface (server-rendered template AND
 * REST) must route through, so the page and the app can never disagree about
 * who may see a space, its roster, or its content:
 *
 *   - a private space's roster is members-only (logged out AND logged in)
 *   - a secret space's roster is members-only
 *   - member / moderator / owner / site-admin see the roster
 *   - `buddynext_space_can_view_roster` re-opens it on BOTH paths
 *   - the private space itself stays listed + its member COUNT public
 *   - archived spaces drop out of the directory (owner + admin still see them)
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceVisibility;
use WP_REST_Request;

/**
 * Space visibility resolver + directory archive scope.
 *
 * @covers \BuddyNext\Spaces\SpaceVisibility
 * @covers \BuddyNext\Spaces\SpaceService::list_query_scope
 */
class SpaceVisibilityTest extends \WP_Test_REST_TestCase {

	/**
	 * Space service under test.
	 *
	 * @var SpaceService
	 */
	private SpaceService $service;

	/**
	 * Membership service under test.
	 *
	 * @var SpaceMemberService
	 */
	private SpaceMemberService $members;

	/**
	 * Space owner user ID.
	 *
	 * @var int
	 */
	private int $owner_id;

	/**
	 * Plain active member of the private space.
	 *
	 * @var int
	 */
	private int $member_id;

	/**
	 * Moderator of the private space.
	 *
	 * @var int
	 */
	private int $moderator_id;

	/**
	 * A logged-in user with no relationship to the space.
	 *
	 * @var int
	 */
	private int $stranger_id;

	/**
	 * Site administrator.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Private space ID.
	 *
	 * @var int
	 */
	private int $private_id;

	/**
	 * Secret space ID.
	 *
	 * @var int
	 */
	private int $secret_id;

	/**
	 * Open space ID.
	 *
	 * @var int
	 */
	private int $open_id;

	/**
	 * Seed the schema, the users, and one space of each visibility.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service = new SpaceService();
		$this->members = new SpaceMemberService();

		$this->owner_id     = self::factory()->user->create( array( 'display_name' => 'Owner Oona' ) );
		$this->member_id    = self::factory()->user->create( array( 'display_name' => 'Roster Rita' ) );
		$this->moderator_id = self::factory()->user->create( array( 'display_name' => 'Mod Mika' ) );
		$this->stranger_id  = self::factory()->user->create( array( 'display_name' => 'Stranger Sven' ) );
		$this->admin_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->private_id = (int) $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Book Club',
				'slug' => 'vis-private',
				'type' => 'private',
			)
		);
		$this->secret_id  = (int) $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Founders Lounge',
				'slug' => 'vis-secret',
				'type' => 'secret',
			)
		);
		$this->open_id    = (int) $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Town Square',
				'slug' => 'vis-open',
				'type' => 'open',
			)
		);

		// Roster of the private space: owner + moderator + one plain member.
		$this->members->join( $this->private_id, $this->member_id );
		$this->members->join( $this->private_id, $this->moderator_id );
		$this->members->change_role( $this->private_id, $this->moderator_id, 'moderator', $this->owner_id );

		// The secret space has one member besides the owner.
		$this->members->join( $this->secret_id, $this->member_id );

		wp_set_current_user( 0 );
	}

	/**
	 * Render templates/spaces/members.php for a space and return its HTML.
	 *
	 * The server-rendered path — the surface that leaked the roster to logged-out
	 * visitors. Rendered exactly as PageRouter dispatches it.
	 *
	 * @param int $space_id Space to render.
	 * @return string Rendered HTML.
	 */
	private function render_members_template( int $space_id ): string {
		ob_start();
		buddynext_get_template( 'spaces/members.php', array( 'space_id' => $space_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Perform GET /buddynext/v1/spaces/{id}/members.
	 *
	 * @param int $space_id Space to fetch.
	 * @return \WP_REST_Response
	 */
	private function get_members_rest( int $space_id ): \WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $space_id . '/members' ) );
	}

	/* ── Private roster: refused to non-members ─────────────────────────────── */

	/**
	 * A logged-out visitor must not see a private space's roster (template path).
	 *
	 * @return void
	 */
	public function test_logged_out_cannot_see_private_roster_template(): void {
		$html = $this->render_members_template( $this->private_id );

		$this->assertStringNotContainsString( 'Roster Rita', $html );
	}

	/**
	 * A logged-out visitor must not see a private space's roster (REST path).
	 *
	 * @return void
	 */
	public function test_logged_out_cannot_see_private_roster_rest(): void {
		$response = $this->get_members_rest( $this->private_id );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A logged-in NON-member is refused a private roster exactly like a guest.
	 *
	 * @return void
	 */
	public function test_logged_in_stranger_cannot_see_private_roster(): void {
		wp_set_current_user( $this->stranger_id );

		$this->assertFalse( SpaceVisibility::can_view_roster( $this->service->get( $this->private_id ), $this->stranger_id ) );
		$this->assertSame( 403, $this->get_members_rest( $this->private_id )->get_status() );
		$this->assertStringNotContainsString( 'Roster Rita', $this->render_members_template( $this->private_id ) );
	}

	/**
	 * A logged-out visitor must not see a secret space's roster (both paths).
	 *
	 * @return void
	 */
	public function test_logged_out_cannot_see_secret_roster(): void {
		$this->assertFalse( SpaceVisibility::can_view_roster( $this->service->get( $this->secret_id ), 0 ) );
		$this->assertFalse( SpaceVisibility::can_view_space( $this->service->get( $this->secret_id ), 0 ) );
		$this->assertSame( 403, $this->get_members_rest( $this->secret_id )->get_status() );
		$this->assertStringNotContainsString( 'Roster Rita', $this->render_members_template( $this->secret_id ) );
	}

	/* ── Private roster: allowed for the people in the room ─────────────────── */

	/**
	 * An ACTIVE MEMBER of a private space sees its roster on both paths.
	 *
	 * @return void
	 */
	public function test_active_member_can_see_private_roster(): void {
		wp_set_current_user( $this->member_id );

		$this->assertTrue( SpaceVisibility::can_view_roster( $this->service->get( $this->private_id ), $this->member_id ) );
		$this->assertSame( 200, $this->get_members_rest( $this->private_id )->get_status() );
		$this->assertStringContainsString( 'Roster Rita', $this->render_members_template( $this->private_id ) );
	}

	/**
	 * A moderator of a private space sees its roster.
	 *
	 * @return void
	 */
	public function test_moderator_can_see_private_roster(): void {
		wp_set_current_user( $this->moderator_id );

		$this->assertTrue( SpaceVisibility::can_view_roster( $this->service->get( $this->private_id ), $this->moderator_id ) );
		$this->assertSame( 200, $this->get_members_rest( $this->private_id )->get_status() );
	}

	/**
	 * The owner of a private space sees its roster.
	 *
	 * @return void
	 */
	public function test_owner_can_see_private_roster(): void {
		wp_set_current_user( $this->owner_id );

		$this->assertTrue( SpaceVisibility::can_view_roster( $this->service->get( $this->private_id ), $this->owner_id ) );
		$this->assertSame( 200, $this->get_members_rest( $this->private_id )->get_status() );
	}

	/**
	 * A site admin sees a private (and a secret) roster.
	 *
	 * @return void
	 */
	public function test_site_admin_can_see_private_and_secret_roster(): void {
		wp_set_current_user( $this->admin_id );

		$this->assertTrue( SpaceVisibility::can_view_roster( $this->service->get( $this->private_id ), $this->admin_id ) );
		$this->assertTrue( SpaceVisibility::can_view_roster( $this->service->get( $this->secret_id ), $this->admin_id ) );
		$this->assertSame( 200, $this->get_members_rest( $this->private_id )->get_status() );
		$this->assertSame( 200, $this->get_members_rest( $this->secret_id )->get_status() );
	}

	/**
	 * An open space's roster stays public — we did not over-gate.
	 *
	 * @return void
	 */
	public function test_open_space_roster_stays_public(): void {
		$this->assertTrue( SpaceVisibility::can_view_roster( $this->service->get( $this->open_id ), 0 ) );
		$this->assertSame( 200, $this->get_members_rest( $this->open_id )->get_status() );
	}

	/* ── The re-open filter (Facebook-style private rosters) ────────────────── */

	/**
	 * The re-open filter lifts the private roster on BOTH the template and the REST
	 * path — one add_filter(), both surfaces, because there is only one decision point.
	 *
	 * @return void
	 */
	public function test_filter_reopens_private_roster_on_both_paths(): void {
		add_filter( 'buddynext_space_can_view_roster', '__return_true' );

		$this->assertTrue( SpaceVisibility::can_view_roster( $this->service->get( $this->private_id ), 0 ) );
		$this->assertSame( 200, $this->get_members_rest( $this->private_id )->get_status() );
		$this->assertStringContainsString( 'Roster Rita', $this->render_members_template( $this->private_id ) );

		remove_filter( 'buddynext_space_can_view_roster', '__return_true' );
	}

	/**
	 * The filter receives the space id, viewer id, and type so a site owner can
	 * re-open selectively.
	 *
	 * @return void
	 */
	public function test_filter_receives_space_viewer_and_type(): void {
		$seen = array();

		$capture = static function ( $can_view, $space_id, $viewer_id, $type ) use ( &$seen ) {
			$seen = array( $space_id, $viewer_id, $type );

			return $can_view;
		};
		add_filter( 'buddynext_space_can_view_roster', $capture, 10, 4 );

		SpaceVisibility::can_view_roster( $this->service->get( $this->private_id ), $this->stranger_id );

		remove_filter( 'buddynext_space_can_view_roster', $capture, 10 );

		$this->assertSame( array( $this->private_id, $this->stranger_id, 'private' ), $seen );
	}

	/* ── What STAYS public about a private space ────────────────────────────── */

	/**
	 * A private space stays listed in the public directory, with its member COUNT,
	 * so a stranger can decide whether to request to join. Private means
	 * "listed but gated", not "hidden".
	 *
	 * @return void
	 */
	public function test_private_space_stays_listed_with_member_count(): void {
		$rows = $this->service->list_spaces( array( 'viewer' => 0 ) );
		$ids  = wp_list_pluck( $rows, 'id' );

		$this->assertContains( $this->private_id, array_map( 'intval', $ids ) );
		$this->assertNotContains( $this->secret_id, array_map( 'intval', $ids ) );

		$listed = null;
		foreach ( $rows as $row ) {
			if ( (int) $row['id'] === $this->private_id ) {
				$listed = $row;
			}
		}

		$this->assertNotNull( $listed );
		$this->assertSame( 3, (int) $listed['member_count'] );
	}

	/**
	 * A private space's name and description stay visible on the members page even
	 * when the roster itself is gated — the stranger must see what the space IS.
	 *
	 * @return void
	 */
	public function test_private_space_name_still_renders_when_roster_gated(): void {
		$html = $this->render_members_template( $this->private_id );

		$this->assertStringContainsString( 'Book Club', $html );
		$this->assertStringNotContainsString( 'Roster Rita', $html );
	}

	/* ── Archived spaces leave the directory ────────────────────────────────── */

	/**
	 * An archived space disappears from the public directory for a logged-out
	 * viewer — archive is the non-destructive way to retire a space, so it must
	 * actually retire it.
	 *
	 * @return void
	 */
	public function test_archived_space_hidden_from_logged_out_directory(): void {
		$this->service->archive( $this->open_id, $this->owner_id );

		$ids = array_map( 'intval', wp_list_pluck( $this->service->list_spaces( array( 'viewer' => 0 ) ), 'id' ) );

		$this->assertNotContains( $this->open_id, $ids );
	}

	/**
	 * The archived space's owner still sees it (they are the one who unarchives).
	 *
	 * @return void
	 */
	public function test_archived_space_still_visible_to_owner(): void {
		$this->service->archive( $this->open_id, $this->owner_id );

		$ids = array_map(
			'intval',
			wp_list_pluck( $this->service->list_spaces( array( 'viewer' => $this->owner_id ) ), 'id' )
		);

		$this->assertContains( $this->open_id, $ids );
	}

	/**
	 * A site admin still sees an archived space.
	 *
	 * @return void
	 */
	public function test_archived_space_still_visible_to_site_admin(): void {
		$this->service->archive( $this->open_id, $this->owner_id );

		$ids = array_map(
			'intval',
			wp_list_pluck(
				$this->service->list_spaces(
					array(
						'viewer'   => $this->admin_id,
						'is_admin' => true,
					)
				),
				'id'
			)
		);

		$this->assertContains( $this->open_id, $ids );
	}

	/**
	 * The archived space is gone from the totals too (list + count never drift).
	 *
	 * @return void
	 */
	public function test_archived_space_excluded_from_directory_total(): void {
		$before = $this->service->list_spaces_with_total( array( 'viewer' => 0 ) );
		$this->service->archive( $this->open_id, $this->owner_id );
		$after = $this->service->list_spaces_with_total( array( 'viewer' => 0 ) );

		$this->assertSame( (int) $before['total'] - 1, (int) $after['total'] );
		$this->assertCount( (int) $after['total'], $after['items'] );
	}

	/**
	 * Unarchiving puts the space back in the public directory.
	 *
	 * @return void
	 */
	public function test_unarchive_restores_space_to_directory(): void {
		$this->service->archive( $this->open_id, $this->owner_id );
		$this->service->unarchive( $this->open_id, $this->owner_id );

		$ids = array_map( 'intval', wp_list_pluck( $this->service->list_spaces( array( 'viewer' => 0 ) ), 'id' ) );

		$this->assertContains( $this->open_id, $ids );
	}

	/**
	 * An archived space is also gone from search for a logged-out viewer.
	 *
	 * @return void
	 */
	public function test_archived_space_hidden_from_search(): void {
		$this->service->archive( $this->open_id, $this->owner_id );

		$ids = array_map( 'intval', wp_list_pluck( $this->service->search( 'Town Square', array( 'viewer' => 0 ) ), 'id' ) );

		$this->assertNotContains( $this->open_id, $ids );
	}
}
