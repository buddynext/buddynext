<?php
/**
 * The Discussions empty state offers each viewer their real next step.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * Discussion CTA by viewer.
 */
class JetonomySpaceDiscussionCtaTest extends \WP_UnitTestCase {

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner = self::factory()->user->create();
	}

	private function space( string $type ): object {
		$id = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'CTA ' . $type,
				'slug' => 'cta-' . $type . '-' . wp_rand( 1000, 9999 ),
				'type' => $type,
			)
		);
		return ( new SpaceService() )->get_object( (int) $id );
	}

	private function state_for( object $space, int $viewer ): array {
		$can_post = \BuddyNext\Spaces\SpacePostGuard::can_post( (int) $space->id, $viewer );
		$method   = new \ReflectionMethod( JetonomyBridge::class, 'discussion_empty_state' );
		return $method->invoke( new JetonomyBridge(), $space, $viewer, $can_post, 'http://example.org/community/s/cta/' );
	}

	public function test_only_someone_who_may_post_gets_start_a_discussion(): void {
		$space   = $this->space( 'open' );
		$member  = self::factory()->user->create();
		$members = new SpaceMemberService();
		$members->join( (int) $space->id, $member );

		$this->assertStringEndsWith( '/new/', $this->state_for( $space, $member )['cta_url'] );

		update_space_meta( (int) $space->id, 'who_can_post', 'mods' );
		wp_cache_flush();
		$restricted = $this->state_for( $space, $member );
		$this->assertArrayNotHasKey( 'cta_url', $restricted );
		$this->assertArrayNotHasKey( 'cta_action', $restricted );
		$this->assertStringEndsWith( '/new/', $this->state_for( $space, $this->owner )['cta_url'] );
	}

	public function test_guest_gets_log_in_and_a_non_member_gets_the_join_path(): void {
		$open    = $this->space( 'open' );
		$private = $this->space( 'private' );
		$secret  = $this->space( 'secret' );
		$other   = self::factory()->user->create();

		$guest = $this->state_for( $open, 0 );
		$this->assertStringContainsString( 'login', $guest['cta_url'] );
		$this->assertStringNotContainsString( '/new/', $guest['cta_url'] );

		$this->assertSame( 'actions.joinSpace', $this->state_for( $open, $other )['cta_action'] );
		$this->assertSame( 'actions.requestJoin', $this->state_for( $private, $other )['cta_action'] );
		$this->assertArrayNotHasKey( 'cta_action', $this->state_for( $secret, $other ) );

		( new SpaceMemberService() )->request_join( (int) $private->id, $other );
		wp_cache_flush();
		$this->assertArrayNotHasKey( 'cta_action', $this->state_for( $private, $other ) );
	}
}
