<?php
/**
 * Tests for PrivacyService.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\BlockService;
use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\SocialGraph\PrivacyService;

/**
 * @covers \BuddyNext\SocialGraph\PrivacyService
 */
class PrivacyServiceTest extends \WP_UnitTestCase {

	private PrivacyService    $service;
	private FollowService     $follows;
	private ConnectionService $connections;
	private BlockService      $blocks;
	private int $alice;
	private int $bob;
	private int $carol;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->follows     = new FollowService();
		$this->connections = new ConnectionService();
		$this->blocks      = new BlockService();
		$this->service     = new PrivacyService( $this->follows, $this->connections, $this->blocks );
		$this->alice       = self::factory()->user->create();
		$this->bob         = self::factory()->user->create();
		$this->carol       = self::factory()->user->create();
	}

	// ── Privacy preference storage ─────────────────────────────────────────

	public function test_default_follow_privacy_is_everyone(): void {
		$this->assertSame( 'everyone', $this->service->get_preference( $this->alice, 'who_can_follow' ) );
	}

	public function test_default_connect_privacy_is_everyone(): void {
		$this->assertSame( 'everyone', $this->service->get_preference( $this->alice, 'who_can_connect' ) );
	}

	public function test_default_profile_visibility_is_public(): void {
		$this->assertSame( 'public', $this->service->get_preference( $this->alice, 'profile_visibility' ) );
	}

	public function test_set_and_get_preference(): void {
		$this->service->set_preference( $this->alice, 'who_can_follow', 'nobody' );

		$this->assertSame( 'nobody', $this->service->get_preference( $this->alice, 'who_can_follow' ) );
	}

	// ── Follow permission checks ───────────────────────────────────────────

	public function test_can_follow_when_privacy_is_everyone(): void {
		$this->assertTrue( $this->service->can_follow( $this->bob, $this->alice ) );
	}

	public function test_cannot_follow_when_privacy_is_nobody(): void {
		$this->service->set_preference( $this->alice, 'who_can_follow', 'nobody' );

		$this->assertFalse( $this->service->can_follow( $this->bob, $this->alice ) );
	}

	public function test_blocked_user_cannot_follow(): void {
		$this->blocks->block( $this->alice, $this->bob );

		$this->assertFalse( $this->service->can_follow( $this->bob, $this->alice ) );
	}

	// ── Connection permission checks ───────────────────────────────────────

	public function test_can_connect_when_privacy_is_everyone(): void {
		$this->assertTrue( $this->service->can_connect( $this->bob, $this->alice ) );
	}

	public function test_cannot_connect_when_privacy_is_nobody(): void {
		$this->service->set_preference( $this->alice, 'who_can_connect', 'nobody' );

		$this->assertFalse( $this->service->can_connect( $this->bob, $this->alice ) );
	}

	public function test_can_connect_when_privacy_is_followers_and_actor_follows(): void {
		$this->service->set_preference( $this->alice, 'who_can_connect', 'followers' );
		$this->follows->follow( $this->alice, $this->bob );

		$this->assertTrue( $this->service->can_connect( $this->bob, $this->alice ) );
	}

	public function test_cannot_connect_when_privacy_is_followers_and_actor_does_not_follow(): void {
		$this->service->set_preference( $this->alice, 'who_can_connect', 'followers' );

		$this->assertFalse( $this->service->can_connect( $this->bob, $this->alice ) );
	}

	public function test_blocked_user_cannot_connect(): void {
		$this->blocks->block( $this->alice, $this->bob );

		$this->assertFalse( $this->service->can_connect( $this->bob, $this->alice ) );
	}

	// ── Profile visibility checks ──────────────────────────────────────────

	public function test_profile_visible_to_everyone_by_default(): void {
		$this->assertTrue( $this->service->can_view_profile( $this->bob, $this->alice ) );
	}

	public function test_profile_hidden_from_blocked_user(): void {
		$this->blocks->block( $this->alice, $this->bob );

		$this->assertFalse( $this->service->can_view_profile( $this->bob, $this->alice ) );
	}

	public function test_profile_visible_to_self_always(): void {
		$this->service->set_preference( $this->alice, 'profile_visibility', 'private' );

		$this->assertTrue( $this->service->can_view_profile( $this->alice, $this->alice ) );
	}

	public function test_private_profile_hidden_from_strangers(): void {
		$this->service->set_preference( $this->alice, 'profile_visibility', 'private' );

		$this->assertFalse( $this->service->can_view_profile( $this->bob, $this->alice ) );
	}

	public function test_followers_only_profile_visible_to_followers(): void {
		$this->service->set_preference( $this->alice, 'profile_visibility', 'followers' );
		$this->follows->follow( $this->bob, $this->alice );

		$this->assertTrue( $this->service->can_view_profile( $this->bob, $this->alice ) );
	}

	public function test_followers_only_profile_hidden_from_non_followers(): void {
		$this->service->set_preference( $this->alice, 'profile_visibility', 'followers' );

		$this->assertFalse( $this->service->can_view_profile( $this->bob, $this->alice ) );
	}

	public function test_connections_only_profile_visible_to_connections(): void {
		$this->service->set_preference( $this->alice, 'profile_visibility', 'connections' );
		$this->connections->send_request( $this->bob, $this->alice );
		$this->connections->accept_request( $this->alice, $this->bob );

		$this->assertTrue( $this->service->can_view_profile( $this->bob, $this->alice ) );
	}

	public function test_connections_only_profile_hidden_from_strangers(): void {
		$this->service->set_preference( $this->alice, 'profile_visibility', 'connections' );

		$this->assertFalse( $this->service->can_view_profile( $this->bob, $this->alice ) );
	}
}
