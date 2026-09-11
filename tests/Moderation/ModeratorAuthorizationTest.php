<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A promoted community moderator must be able to moderate — at the SERVICE seam.
 *
 * Card 10264294189: the REST routes were opened to community moderators, but the
 * moderation service methods still hard-gated on manage_options, so a promoted
 * moderator passed the route and was refused one layer down (403). These lock in
 * the authorization at the seam: a site moderator can act, a plain member cannot,
 * and the heaviest sanction (an INDEFINITE suspension) stays admin-only.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Moderation\ModerationService;
use BuddyNext\Moderation\ModerationController;
use BuddyNext\Core\PermissionService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceMemberService;
use WP_REST_Server;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Moderation\ModerationService
 * @covers \BuddyNext\REST\BaseRestController
 */
class ModeratorAuthorizationTest extends WP_UnitTestCase {

	/** @var ModerationService */
	private ModerationService $mod;

	/** @var int A promoted site community moderator (no manage_options). */
	private int $moderator;

	/** @var int A plain member. */
	private int $member;

	/** @var int The user being actioned. */
	private int $victim;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->mod       = new ModerationService();
		$this->moderator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->member    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->victim    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		buddynext_service( 'roles' )->set_role( $this->moderator, 'moderator' );

		// A live REST server so the route-level tests dispatch through the real
		// permission_callback + handler + service stack — the layer the earlier
		// service-only guards never exercised, which is why this card round-tripped.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		( new ModerationController() )->register_routes();
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * A site moderator can issue a strike; a plain member cannot.
	 *
	 * @return void
	 */
	public function test_site_moderator_can_issue_a_strike_member_cannot(): void {
		$this->assertIsInt( $this->mod->issue_strike( $this->victim, $this->moderator, 'x' ), 'A site moderator may issue a strike.' );
		$this->assertWPError( $this->mod->issue_strike( $this->victim, $this->member, 'x' ), 'A plain member may not issue a strike.' );
	}

	/**
	 * A site moderator can issue a site-level warning; a plain member cannot. warn()
	 * was the one sanction mutator that still gated on space moderation alone, so a
	 * site moderator who moderates no particular space got a 403 (card 10264294189).
	 *
	 * @return void
	 */
	public function test_site_moderator_can_warn_member_cannot(): void {
		$this->assertFalse( is_wp_error( $this->mod->warn( $this->victim, $this->moderator, 'x' ) ), 'A site moderator may issue a site-level warning.' );
		$this->assertWPError( $this->mod->warn( $this->victim, $this->member, 'x' ), 'A plain member may not warn.' );
	}

	/**
	 * A site moderator can shadow-ban; a plain member cannot.
	 *
	 * @return void
	 */
	public function test_site_moderator_can_shadow_ban_member_cannot(): void {
		$this->assertTrue( $this->mod->shadow_ban( $this->victim, $this->moderator, 'x' ), 'A site moderator may shadow-ban.' );
		$this->assertWPError( $this->mod->shadow_ban( $this->victim, $this->member, 'x' ), 'A plain member may not shadow-ban.' );
	}

	/**
	 * A site moderator can suspend for a FIXED term, but only an admin may suspend
	 * INDEFINITELY.
	 *
	 * @return void
	 */
	public function test_fixed_suspension_is_moderator_indefinite_is_admin_only(): void {
		$this->assertIsInt(
			$this->mod->suspend_user( $this->victim, $this->moderator, 'x', array( 'duration_days' => 7 ) ),
			'A site moderator may suspend for a fixed duration.'
		);

		$this->mod->unsuspend_user( $this->victim, $this->moderator );

		$this->assertWPError(
			$this->mod->suspend_user( $this->victim, $this->moderator, 'x', array() ),
			'A site moderator may NOT suspend indefinitely.'
		);

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt(
			$this->mod->suspend_user( $this->victim, $admin, 'x', array() ),
			'An administrator may suspend indefinitely.'
		);
	}

	/**
	 * The core of the round-5 bounce: the sanction mutators authorised against
	 * RoleService::can_moderate_site() directly while the queue rendered its buttons
	 * from buddynext_can( '<ability>' ), so an owner who regraded an ability in Roles
	 * & Capabilities moved the BUTTON but not the ROUTE (card 10264294189). Now that
	 * every mutator routes through buddynext_can(), a regrade must move both together
	 * — and per action, so a different ability is untouched.
	 *
	 * The buddynext_user_can filter is the same seam the role map and per-user grants
	 * resolve through, so denying issue-strike here reproduces exactly what a regrade
	 * to "admins only" does at runtime, without warming the memoised role map.
	 *
	 * @return void
	 */
	public function test_regrading_an_ability_moves_button_and_route_together(): void {
		// Baseline: the moderator holds issue-strike (the ability the button reads).
		$this->assertTrue(
			buddynext_can( $this->moderator, 'buddynext-moderation/issue-strike' ),
			'A moderator holds issue-strike by default.'
		);

		$moderator = $this->moderator;
		$deny      = static function ( $result, $uid, $cap ) use ( $moderator ) {
			return ( 'buddynext-moderation/issue-strike' === $cap && (int) $uid === $moderator ) ? false : $result;
		};
		add_filter( 'buddynext_user_can', $deny, 10, 3 );

		// UI (the ability) AND the route (the service) now BOTH refuse — no divergence.
		$this->assertFalse(
			buddynext_can( $this->moderator, 'buddynext-moderation/issue-strike' ),
			'The button ability must reflect the regrade.'
		);
		$this->assertWPError(
			$this->mod->issue_strike( $this->victim, $this->moderator, 'x' ),
			'The strike route must refuse once the ability is regraded away — before this fix it returned a strike id.'
		);

		// Per-action granularity: suspend-user is a different ability and is untouched.
		$this->assertIsInt(
			$this->mod->suspend_user( $this->victim, $this->moderator, 'x', array( 'duration_days' => 7 ) ),
			'A different ability stays granted after regrading issue-strike.'
		);

		remove_filter( 'buddynext_user_can', $deny, 10 );
	}

	/**
	 * The mirror direction: granting a moderation ability to a plain member (as the
	 * per-user grant path does) must reach the SERVICE, not just the button. Before
	 * the fix the grant showed the button while the mutator 403'd.
	 *
	 * @return void
	 */
	public function test_granting_an_ability_reaches_the_service(): void {
		$member = $this->member;
		$grant  = static function ( $result, $uid, $cap ) use ( $member ) {
			return ( 'buddynext-moderation/issue-strike' === $cap && (int) $uid === $member ) ? true : $result;
		};
		add_filter( 'buddynext_user_can', $grant, 10, 3 );

		$this->assertTrue(
			buddynext_can( $this->member, 'buddynext-moderation/issue-strike' ),
			'The granted member holds the ability.'
		);
		$this->assertIsInt(
			$this->mod->issue_strike( $this->victim, $this->member, 'x' ),
			'A member granted issue-strike must be able to strike — the grant must reach the service.'
		);

		remove_filter( 'buddynext_user_can', $grant, 10 );
	}

	/**
	 * Report actions (Dismiss / Remove content) now authorise against a dedicated
	 * buddynext-moderation/dismiss ability — the one the card noted was missing from
	 * ROLE_MAP — distinct from review-queue, and the queue's Dismiss/Remove buttons
	 * read the same ability. A moderator holds it; a plain member does not.
	 *
	 * @return void
	 */
	public function test_dismiss_ability_is_moderator_gated(): void {
		$this->assertTrue(
			buddynext_can( $this->moderator, 'buddynext-moderation/dismiss' ),
			'A moderator holds the dismiss ability.'
		);
		$this->assertFalse(
			buddynext_can( $this->member, 'buddynext-moderation/dismiss' ),
			'A plain member does not hold the dismiss ability.'
		);
	}

	/**
	 * ROUTE-LEVEL guard — the layer the six earlier bounces never tested.
	 *
	 * The card's exact acceptance: grant a PLAIN member the issue-strike ability
	 * (the real per-user grant path — a bn_ability_* user_meta entry) and POST to
	 * the strike route. The route's permission_callback (require_moderator) used to
	 * read RoleService::can_moderate_site() directly, which no grant could reach, so
	 * the queue rendered the Strike button and the click 403'd. It must now return
	 * 201, proving the grant clears the route guard, not merely the service.
	 *
	 * @return void
	 */
	public function test_route_grant_issue_strike_ability_returns_201_for_member(): void {
		update_user_meta( $this->member, PermissionService::ability_meta_key( 'buddynext-moderation/issue-strike' ), '0' );

		wp_set_current_user( $this->member );
		$request = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->victim}/strikes" );
		$request->set_param( 'reason', 'spam' );
		$response = rest_do_request( $request );

		$this->assertSame(
			201,
			$response->get_status(),
			'A member granted the issue-strike ability must be able to POST a strike at the ROUTE, not just the service.'
		);
	}

	/**
	 * The other direction of the same guard: a member holding NO moderation ability
	 * is refused at the route with 403 — NOT the 500 the handler produced when it
	 * returned the service's status-less forbidden WP_Error raw (card 10264294189).
	 *
	 * @return void
	 */
	public function test_route_member_without_ability_gets_403_not_500(): void {
		wp_set_current_user( $this->member );
		$request = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->victim}/strikes" );
		$request->set_param( 'reason', 'spam' );
		$response = rest_do_request( $request );

		$this->assertSame(
			403,
			$response->get_status(),
			'A member with no moderation ability must get 403 from the strike route, never 500.'
		);
	}

	/**
	 * A space-only moderator (no site authority) may view the queue, and a plain
	 * member may not — on the SHARED predicate that the route and the template
	 * both consult. The bug was a 200 from GET /reports/queue while the template
	 * refused to draw the page as "Access Restricted" (card 10264294189); pinning
	 * can_view_queue() keeps the two surfaces in agreement.
	 *
	 * @return void
	 */
	public function test_space_only_moderator_may_view_queue_member_may_not(): void {
		$spaces  = new SpaceService();
		$members = new SpaceMemberService();

		$owner    = (int) self::factory()->user->create();
		$space_id = (int) $spaces->create(
			$owner,
			array(
				'name' => 'Queue Access Space',
				'slug' => 'queue-access-space',
				'type' => 'open',
			)
		);
		$members->join( $space_id, $this->member );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'role' => 'moderator' ),
			array(
				'space_id' => $space_id,
				'user_id'  => $this->member,
			)
		);
		wp_cache_flush();

		// Shared predicate: space moderator yes, site community moderator yes,
		// a plain member (the victim, subscriber, no space role) no.
		$this->assertTrue( $this->mod->can_view_queue( $this->member ), 'A space moderator must be able to view the queue.' );
		$this->assertTrue( $this->mod->can_view_queue( $this->moderator ), 'A site community moderator must be able to view the queue.' );
		$this->assertFalse( $this->mod->can_view_queue( $this->victim ), 'A plain member must not be able to view the queue.' );

		// Route agrees: space moderator gets 200, plain member gets 403.
		wp_set_current_user( $this->member );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/reports/queue' ) )->get_status(), 'The space moderator must get 200 from the queue route.' );

		wp_set_current_user( $this->victim );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/reports/queue' ) )->get_status(), 'A plain member must get 403 from the queue route.' );
	}
}
