<?php
/**
 * Functional tests for the shareable space invite-link feature.
 *
 * Covers the card's "Done when" acceptance list: create/reset, expiry, the
 * use-cap under concurrency, ban/paywall/site-invite gates that a link must NOT
 * bypass, the can_invite gate on both routes, secret-content non-exposure, and
 * the onboarding round-trip (join blocked pre-onboarding; completion returns to
 * the space for a valid pending invite, and to the normal destination for an
 * expired one).
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Auth\RegistrationPolicy;
use BuddyNext\Spaces\SpaceInviteLinkService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceVisibility;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Spaces\SpaceInviteLinkService
 * @covers \BuddyNext\Spaces\SpaceController::join_space
 * @covers \BuddyNext\Spaces\SpaceController::get_invite_link
 * @covers \BuddyNext\Spaces\SpaceController::save_invite_link
 * @covers \BuddyNext\Spaces\SpaceController::revoke_invite_link
 */
class SpaceInviteLinkFlowTest extends \WP_Test_REST_TestCase {

	private int $owner_id;
	private int $space_id;
	private SpaceService $spaces;
	private SpaceInviteLinkService $links;
	private SpaceMemberService $members;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->spaces  = new SpaceService();
		$this->links   = new SpaceInviteLinkService();
		$this->members = new SpaceMemberService();
		$this->owner_id = self::factory()->user->create();
		$this->space_id = (int) $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Invite Space',
				'slug' => 'invite-space',
				'type' => 'private',
			)
		);
	}

	private function make_link( string $expires = '7d', int $max = 0 ): array {
		return $this->links->create( $this->space_id, $this->owner_id, $expires, $max );
	}

	/** @covers Plan item: create and reset (old token rejected). */
	public function test_create_then_reset_rejects_old_token(): void {
		$first = $this->make_link();
		$this->assertTrue( $this->links->validate( $this->space_id, $first['token'] ) );

		$second = $this->make_link();
		$this->assertNotSame( $first['token'], $second['token'] );
		$this->assertWPError( $this->links->validate( $this->space_id, $first['token'] ) );
		$this->assertTrue( $this->links->validate( $this->space_id, $second['token'] ) );
	}

	/** Revoke turns the link off without issuing a new one (card 10331990056). */
	public function test_revoke_removes_link_and_rejects_old_token(): void {
		$link = $this->make_link();
		wp_set_current_user( $this->owner_id );

		$res = rest_do_request( new WP_REST_Request( 'DELETE', '/buddynext/v1/spaces/' . $this->space_id . '/invite-link' ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertNull( $res->get_data()['invite_link'] );
		$this->assertNull( $this->links->get( $this->space_id ) );
		$this->assertWPError( $this->links->validate( $this->space_id, $link['token'] ) );
	}

	/** @covers Plan item: expiry is enforced. */
	public function test_expiry_is_enforced(): void {
		$link = $this->make_link( '1d' );
		$this->assertTrue( $this->links->validate( $this->space_id, $link['token'] ) );

		// Force the stored record into the past.
		$record               = get_space_meta( $this->space_id, 'invite_link', true );
		$record['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		update_space_meta( $this->space_id, 'invite_link', $record );

		$this->assertWPError( $this->links->validate( $this->space_id, $link['token'] ) );
	}

	/**
	 * @covers Plan item: the max-uses cap holds under two concurrent joins.
	 *
	 * The atomic capped UPDATE is the concurrency gate — a single statement whose
	 * WHERE clause admits exactly one winner when only one slot remains. Two
	 * sequential consume() calls model the two racers: the second must be refused.
	 */
	public function test_max_uses_cap_holds(): void {
		$this->make_link( '7d', 1 );
		$this->assertTrue( $this->links->consume( $this->space_id ) );
		$this->assertWPError( $this->links->consume( $this->space_id ) );
	}

	/** @covers Plan item: a valid token joins a private space directly (no request). */
	public function test_valid_token_joins_directly(): void {
		$link   = $this->make_link();
		$joiner = self::factory()->user->create();
		$this->settle_onboarding( $joiner );
		wp_set_current_user( $joiner );

		$res = $this->join_with_token( $joiner, $link['token'] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( (bool) ( $res->get_data()['joined'] ?? false ) );
		$this->assertSame( 'active', $this->members->get_status( $this->space_id, $joiner ) );
		$this->assertSame( '1', (string) get_space_meta( $this->space_id, 'invite_link_uses', true ) );
		$this->assertNotEmpty( get_space_meta( $this->space_id, 'joined_via_link_' . $joiner, true ) );
	}

	/** @covers Plan item: a second joiner past the cap is refused. */
	public function test_second_joiner_past_cap_is_refused(): void {
		$link = $this->make_link( '7d', 1 );
		$a    = self::factory()->user->create();
		$b    = self::factory()->user->create();
		$this->settle_onboarding( $a );
		$this->settle_onboarding( $b );

		$this->assertSame( 200, $this->join_with_token( $a, $link['token'] )->get_status() );
		$res_b = $this->join_with_token( $b, $link['token'] );
		$this->assertSame( 403, $res_b->get_status() );
		$this->assertSame( 'invite_link_invalid', $res_b->get_data()['code'] ?? '' );
		$this->assertNull( $this->members->get_status( $this->space_id, $b ) );
	}

	/** @covers Plan item: a banned user is rejected even with a valid token. */
	public function test_banned_user_rejected(): void {
		$link   = $this->make_link();
		$banned = self::factory()->user->create();
		$this->members->join( $this->space_id, $banned );
		$this->members->ban( $this->space_id, $this->owner_id, $banned );

		$res = $this->join_with_token( $banned, $link['token'] );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'space_banned', $res->get_data()['code'] ?? '' );
	}

	/**
	 * @covers Plan item: an invite-only SITE still blocks signup.
	 *
	 * A space-invite token is not a site-registration invite: RegistrationPolicy
	 * (mode 'invite') must still refuse a signup carrying the space link's token.
	 */
	public function test_space_link_does_not_bypass_invite_only_site(): void {
		$link = $this->make_link();
		update_option( 'buddynext_reg_mode', 'invite' );

		$result = ( new RegistrationPolicy() )->check_access( 'nobody@example.test', $link['token'], 'signup' );

		$this->assertWPError( $result );
		$this->assertSame( 'bn_reg_invite', $result->get_error_code() );

		delete_option( 'buddynext_reg_mode' );
	}

	/** @covers Plan item: the paid-space filter still denies, and the use is refunded. */
	public function test_paid_space_filter_still_denies_and_refunds(): void {
		$link   = $this->make_link( '7d', 10 );
		$joiner = self::factory()->user->create();
		$this->settle_onboarding( $joiner );

		$deny = static fn() => false;
		add_filter( 'buddynext_can_join_space', $deny );
		$res = $this->join_with_token( $joiner, $link['token'] );
		remove_filter( 'buddynext_can_join_space', $deny );

		$this->assertGreaterThanOrEqual( 400, $res->get_status() );
		$this->assertNull( $this->members->get_status( $this->space_id, $joiner ) );
		// The reserved use was released, so the cap is not silently spent.
		$this->assertSame( '0', (string) get_space_meta( $this->space_id, 'invite_link_uses', true ) );
	}

	/** @covers Plan item: a user without can_invite gets 403 on both invite-link routes. */
	public function test_non_inviter_gets_403_on_both_routes(): void {
		$outsider = self::factory()->user->create();
		wp_set_current_user( $outsider );

		$get = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $this->space_id . '/invite-link' ) );
		$this->assertSame( 403, $get->get_status() );

		$post = new WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $this->space_id . '/invite-link' );
		$post->set_body_params( array( 'expires' => '7d', 'max_uses' => 0 ) );
		$this->assertSame( 403, rest_do_request( $post )->get_status() );

		$this->make_link();
		$delete = rest_do_request( new WP_REST_Request( 'DELETE', '/buddynext/v1/spaces/' . $this->space_id . '/invite-link' ) );
		$this->assertSame( 403, $delete->get_status() );
		$this->assertNotNull( $this->links->get( $this->space_id ), 'an outsider must not revoke the link' );
	}

	/** @covers Plan item: a secret space's content is not exposed on an invalid link. */
	public function test_secret_content_not_exposed_on_invalid_link(): void {
		$secret_id = (int) $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Secret',
				'slug' => 'secret-invite',
				'type' => 'secret',
			)
		);
		$this->make_link_for( $secret_id );
		$outsider = self::factory()->user->create();
		$space    = $this->spaces->get( $secret_id );

		// No valid prime happened, so the space stays hidden and its content gated.
		$this->assertFalse( SpaceVisibility::can_view_space( $space, $outsider ) );
		$this->assertFalse( SpaceVisibility::can_view_content( $space, $outsider ) );
		$this->assertWPError( $this->links->validate( $secret_id, 'bogusbogusbogusbogusbogusbogus00' ) );
	}

	/** @covers Plan item: REST join with a token before onboarding returns onboarding_incomplete. */
	public function test_join_before_onboarding_is_blocked(): void {
		$this->enable_onboarding_gate();
		$link   = $this->make_link();
		$joiner = self::factory()->user->create(); // Registered now → after the gate → onboarding required.

		$res = $this->join_with_token( $joiner, $link['token'] );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'onboarding_incomplete', $res->get_data()['code'] ?? '' );
		$this->assertNull( $this->members->get_status( $this->space_id, $joiner ) );

		$this->disable_onboarding_gate();
	}

	/** @covers Plan item: completing onboarding with a valid pending invite returns the space URL. */
	public function test_complete_onboarding_returns_space_for_valid_pending(): void {
		$this->enable_onboarding_gate();
		$link   = $this->make_link();
		$joiner = self::factory()->user->create();
		update_user_meta(
			$joiner,
			SpaceInviteLinkService::PENDING_KEY,
			array( array( 'space_id' => $this->space_id, 'token' => $link['token'], 'primed_at' => time() ) )
		);
		wp_set_current_user( $joiner );

		$data = rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/complete' ) )->get_data();

		$this->assertStringContainsString( 'invite-space', (string) ( $data['redirect_to'] ?? '' ) );
		$this->assertSame( 'active', $this->members->get_status( $this->space_id, $joiner ) );
		$this->assertSame( '', (string) get_user_meta( $joiner, SpaceInviteLinkService::PENDING_KEY, true ) );

		$this->disable_onboarding_gate();
	}

	/** @covers Plan item: completing onboarding with an EXPIRED pending invite returns the normal redirect. */
	public function test_complete_onboarding_ignores_expired_pending(): void {
		$this->enable_onboarding_gate();
		$link   = $this->make_link( '1d' );
		$joiner = self::factory()->user->create();

		// Expire the link, then leave it as the pending invite.
		$record               = get_space_meta( $this->space_id, 'invite_link', true );
		$record['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		update_space_meta( $this->space_id, 'invite_link', $record );
		update_user_meta(
			$joiner,
			SpaceInviteLinkService::PENDING_KEY,
			array( array( 'space_id' => $this->space_id, 'token' => $link['token'], 'primed_at' => time() ) )
		);
		wp_set_current_user( $joiner );

		$data = rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/complete' ) )->get_data();

		$this->assertStringNotContainsString( 'invite-space', (string) ( $data['redirect_to'] ?? '' ) );
		$this->assertNull( $this->members->get_status( $this->space_id, $joiner ) );
		$this->assertSame( '', (string) get_user_meta( $joiner, SpaceInviteLinkService::PENDING_KEY, true ) );

		$this->disable_onboarding_gate();
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	private function join_with_token( int $user_id, string $token ): \WP_REST_Response {
		wp_set_current_user( $user_id );
		$req = new WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $this->space_id . '/join' );
		$req->set_body_params( array( 'invite' => $token ) );
		return rest_do_request( $req );
	}

	private function make_link_for( int $space_id ): array {
		return $this->links->create( $space_id, $this->owner_id, '7d', 0 );
	}

	/**
	 * Mark a user's onboarding complete — the realistic precondition for anyone
	 * who has reached a Join action (onboarding is required for everyone, so an
	 * un-onboarded user is bounced to the wizard before they could join).
	 *
	 * @param int $user_id User to settle.
	 * @return void
	 */
	private function settle_onboarding( int $user_id ): void {
		update_user_meta( $user_id, 'bn_onboarding_complete', '1' );
	}

	private function enable_onboarding_gate(): void {
		update_option( 'buddynext_features', array( 'onboarding' => true ) );
		update_option( 'buddynext_onboarding_gate_since', 1 );
	}

	private function disable_onboarding_gate(): void {
		delete_option( 'buddynext_features' );
		delete_option( 'buddynext_onboarding_gate_since' );
	}

	/**
	 * A use is one distinct person: the same member reserving twice (a leave then
	 * rejoin) takes only one slot, so one holder cannot exhaust a capped link.
	 */
	public function test_reserve_slot_counts_distinct_people(): void {
		$link = $this->make_link( '7d', 10 );
		$a    = self::factory()->user->create();
		$b    = self::factory()->user->create();

		// A takes one use; a rejoin (reserve again after mark_slot) takes NO further
		// use — one person cannot burn the cap by leave+rejoin.
		$this->assertSame( 1, $this->links->reserve_slot( $this->space_id, $a, $link['token'] ) );
		$this->links->mark_slot( $this->space_id, $a );
		$this->assertSame( 0, $this->links->reserve_slot( $this->space_id, $a, $link['token'] ) );
		$this->assertSame( '1', (string) get_space_meta( $this->space_id, 'invite_link_uses', true ) );

		// A DIFFERENT person does take a use; their rejoin again does not.
		$this->assertSame( 1, $this->links->reserve_slot( $this->space_id, $b, $link['token'] ) );
		$this->links->mark_slot( $this->space_id, $b );
		$this->assertSame( 0, $this->links->reserve_slot( $this->space_id, $b, $link['token'] ) );
		$this->assertSame( '2', (string) get_space_meta( $this->space_id, 'invite_link_uses', true ) );
	}

	/**
	 * Resetting the link clears the per-person slot markers, so a member who used the
	 * old link counts fresh against the new one.
	 */
	public function test_reset_clears_slot_markers(): void {
		$first = $this->make_link( '7d', 1 );
		$a     = self::factory()->user->create();
		$this->assertSame( 1, $this->links->reserve_slot( $this->space_id, $a, $first['token'] ) );
		$this->links->mark_slot( $this->space_id, $a );

		$second = $this->make_link( '7d', 1 ); // reset
		$this->assertSame( '0', (string) get_space_meta( $this->space_id, 'invite_link_uses', true ) );
		$this->assertSame( 1, $this->links->reserve_slot( $this->space_id, $a, $second['token'] ) );
	}

	/**
	 * reserve_slot re-checks the token, so a link reset between validate() and the
	 * reservation refuses the stale token (the TOCTOU window).
	 */
	public function test_reserve_slot_rejects_a_token_reset_after_validate(): void {
		$first = $this->make_link( '7d', 0 );
		$a     = self::factory()->user->create();
		$this->assertTrue( $this->links->validate( $this->space_id, $first['token'] ) );

		$this->make_link( '7d', 0 ); // reset lands after validate()

		$this->assertWPError( $this->links->reserve_slot( $this->space_id, $a, $first['token'] ) );
	}
}
