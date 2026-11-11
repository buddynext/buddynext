<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Inviting by the @handle a member shows (card 10435625744). A dotted email
 * gives a login like "markus.kaufmann" and a handle like "@markus-kaufmann";
 * the owner types the handle they see and must reach the right member.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Spaces\SpaceController::invite_member
 * @covers \BuddyNext\Profile\Handle::user_for_identifier
 */
class SpaceInviteByHandleTest extends \WP_Test_REST_TestCase {

	private int $owner;
	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner    = self::factory()->user->create();
		$this->space_id = (int) ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Handle room',
				'slug' => 'handle-room',
				'type' => 'open',
			)
		);
		wp_set_current_user( $this->owner );
	}

	private function invite( string $identifier ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/buddynext/v1/spaces/{$this->space_id}/invite" );
		$request->set_body_params( array( 'identifier' => $identifier ) );
		return rest_do_request( $request );
	}

	public function test_invite_by_handle_that_differs_from_login(): void {
		$invitee = self::factory()->user->create(
			array(
				'user_login'    => 'markus.kaufmann',
				'user_nicename' => 'markus-kaufmann',
			)
		);

		$response = $this->invite( '@markus-kaufmann' );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['invited'] ?? false );
		$this->assertSame( 'invited', ( new \BuddyNext\Spaces\SpaceMemberService() )->get_status( $this->space_id, $invitee ) );
	}

	// The same neutral refusal as any unsent invite, so it never reveals whether a handle exists.
	public function test_unknown_handle_is_refused_neutrally(): void {
		$res = $this->invite( '@nobody-by-that-name' );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'invite_not_sent', $res->get_data()['code'] );
	}

	public function test_duplicate_email_invite_is_not_reported_as_sent(): void {
		global $wpdb;
		$email = 'qafix-dup@example.com';

		$first = $this->invite( $email );
		$this->assertSame( 200, $first->get_status() );
		$this->assertTrue( $first->get_data()['email_invited'] );

		$second = $this->invite( $email );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'invite_not_sent', $second->get_data()['code'] );

		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_invites WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 1, $rows );
	}

	public function test_inviter_below_site_level_cannot_tell_why_nothing_was_sent(): void {
		global $wpdb;
		$services = new \BuddyNext\Onboarding\InviteService();

		// A pending invite to this space, one with no space, and one to a space the inviter cannot see.
		$services->create( 'qafix-here@example.com', '', 7, $this->space_id );
		$site = $services->create( 'qafix-site@example.com' );
		$services->create( 'qafix-other@example.com', '', 7, $this->space_id + 1000 );
		// A member of this space, and a member of nothing.
		$member = self::factory()->user->create( array( 'user_email' => 'qafix-member@example.com' ) );
		( new \BuddyNext\Spaces\SpaceMemberService() )->join( $this->space_id, $member );
		self::factory()->user->create( array( 'user_email' => 'qafix-stranger@example.com' ) );

		// Every other standing an account can hold in this space.
		global $wpdb;
		foreach ( array( 'pending', 'banned' ) as $standing ) {
			$held = self::factory()->user->create( array( 'user_email' => "qafix-{$standing}@example.com" ) );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'bn_space_members',
				array(
					'space_id' => $this->space_id,
					'user_id'  => $held,
					'role'     => 'member',
					'status'   => $standing,
				)
			);
		}

		$answers = array();
		foreach ( array( 'here', 'site', 'other', 'member', 'pending', 'banned' ) as $who ) {
			$response = $this->invite( "qafix-{$who}@example.com" );
			$this->assertSame( 409, $response->get_status(), $who );
			$answers[] = $response->get_data();
		}
		$this->assertCount( 1, array_unique( $answers, SORT_REGULAR ), 'every reason reads the same' );
		$this->assertStringNotContainsString( 'space', $answers[0]['message'] );
		$this->assertStringNotContainsString( 'account', $answers[0]['message'] );

		// Nothing an owner does here moves someone else's site invite into this space.
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}bn_invites WHERE id = %d", $site['invite_id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// An account that is not in the space reads exactly as a new address does.
		$new      = $this->invite( 'qafix-new@example.com' );
		$stranger = $this->invite( 'qafix-stranger@example.com' );
		$this->assertSame( $new->get_status(), $stranger->get_status() );
		$this->assertSame( $new->get_data(), $stranger->get_data() );
	}

	public function test_inviting_an_active_member_never_says_sent(): void {
		$member = self::factory()->user->create(
			array(
				'user_email' => 'qafix-active@example.com',
				'user_login' => 'qafixactive',
			)
		);
		( new \BuddyNext\Spaces\SpaceMemberService() )->join( $this->space_id, $member );

		// Below site level: the one neutral answer, by email and by @handle.
		$by_email  = $this->invite( 'qafix-active@example.com' );
		$by_handle = $this->invite( '@qafixactive' );
		$this->assertSame( 409, $by_email->get_status() );
		$this->assertSame( 'invite_not_sent', $by_email->get_data()['code'] );
		$this->assertSame( $by_email->get_data(), $by_handle->get_data() );

		// Site level: the real reason, by email, @handle and user id.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', "/buddynext/v1/spaces/{$this->space_id}/invite" );
		$request->set_body_params( array( 'user_id' => $member ) );
		foreach ( array( $this->invite( 'qafix-active@example.com' ), $this->invite( '@qafixactive' ), rest_do_request( $request ) ) as $response ) {
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'already_member', $response->get_data()['code'] );
		}

		// A second invite to someone already invited sends nothing and says so.
		$invited = self::factory()->user->create( array( 'user_login' => 'qafixinvited' ) );
		$this->assertSame( 200, $this->invite( '@qafixinvited' )->get_status() );
		$this->assertSame( 'already_invited', $this->invite( '@qafixinvited' )->get_data()['code'] );
		wp_set_current_user( $this->owner );
		$this->assertSame( 'invite_not_sent', $this->invite( '@qafixinvited' )->get_data()['code'] );

		// The membership itself is untouched.
		$this->assertSame( 'active', ( new \BuddyNext\Spaces\SpaceMemberService() )->get_status( $this->space_id, $member ) );
	}

	public function test_site_admin_sees_the_reason_and_may_attach(): void {
		global $wpdb;
		$services = new \BuddyNext\Onboarding\InviteService();
		$site     = $services->create( 'qafix-site@example.com' );
		$services->create( 'qafix-other@example.com', '', 7, $this->space_id + 1000 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( $this->invite( 'qafix-site@example.com' )->get_data()['attached'] );
		$this->assertSame( (string) $this->space_id, $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}bn_invites WHERE id = %d", $site['invite_id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'already_invited', $this->invite( 'qafix-site@example.com' )->get_data()['code'] );
		$this->assertSame( 'invited_elsewhere', $this->invite( 'qafix-other@example.com' )->get_data()['code'] );
	}

	public function test_non_inviter_gets_one_refusal_whatever_the_address(): void {
		self::factory()->user->create( array( 'user_email' => 'qafix-known@example.com' ) );
		wp_set_current_user( self::factory()->user->create() );

		$known   = $this->invite( 'qafix-known@example.com' );
		$unknown = $this->invite( 'qafix-unknown@example.com' );
		$this->assertSame( 403, $known->get_status() );
		$this->assertSame( $known->get_data(), $unknown->get_data() );
	}

	public function test_email_invites_are_throttled_per_inviter(): void {
		\BuddyNext\Core\RateLimiter::set( 'bn_space_email_invite_' . $this->owner, 30, HOUR_IN_SECONDS );
		$response = $this->invite( 'qafix-throttled@example.com' );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'invite_rate_limited', $response->get_data()['code'] );

		$handle_user = self::factory()->user->create( array( 'user_login' => 'qafix_byhandle' ) );
		$this->assertSame( 200, $this->invite( 'qafix_byhandle' )->get_status(), 'a @handle invite reveals no address, so it is not counted' );
		$this->assertGreaterThan( 0, $handle_user );
	}

	public function test_archived_space_refuses_any_address_alike(): void {
		global $wpdb;
		self::factory()->user->create( array( 'user_email' => 'qafix-known@example.com' ) );
		$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'is_archived' => 1 ), array( 'id' => $this->space_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();

		$known   = $this->invite( 'qafix-known@example.com' );
		$unknown = $this->invite( 'qafix-unknown@example.com' );
		$this->assertSame( 403, $known->get_status() );
		$this->assertSame( $known->get_data(), $unknown->get_data() );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_invites WHERE email = %s", 'qafix-unknown@example.com' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
