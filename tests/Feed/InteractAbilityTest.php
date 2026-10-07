<?php
/**
 * The "React, share, bookmark and vote" ability is enforced on the server.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PermissionService;
use WP_REST_Request;

/**
 * buddynext-feed/interact hid the controls on the post card and nothing else:
 * with it raised to Moderator a member's reaction, bookmark and share were still
 * accepted by the API, so the app and any client ignored the owner's setting.
 *
 * @covers \BuddyNext\REST\BaseRestController::require_interact
 * @covers \BuddyNext\REST\BaseRestController::engagement_target_error
 */
class InteractAbilityTest extends \WP_UnitTestCase {

	private int $member;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wpdb;
		$this->member = self::factory()->user->create();
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => self::factory()->user->create(),
				'type'       => 'text',
				'content'    => 'A public post',
				'status'     => 'published',
				'privacy'    => 'public',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$this->post_id = (int) $wpdb->insert_id;
		wp_set_current_user( $this->member );
	}

	public function tear_down(): void {
		remove_all_filters( 'buddynext_role_map' );
		$this->forget_role_map();
		parent::tear_down();
	}

	/**
	 * Drop the per-request role map memo, as a new request would.
	 *
	 * @return void
	 */
	private function forget_role_map(): void {
		$memo = new \ReflectionProperty( PermissionService::class, 'role_map_cache' );
		$memo->setValue( null, null );
	}

	/**
	 * Status of each engagement write for the current member.
	 *
	 * @param int $post_id Target post.
	 * @return array<string,int>
	 */
	private function statuses( int $post_id ): array {
		$react = new WP_REST_Request( 'POST', '/buddynext/v1/reactions/toggle' );
		$react->set_param( 'object_type', 'post' );
		$react->set_param( 'object_id', $post_id );
		$react->set_param( 'emoji', 'like' );

		return array(
			'react'    => rest_do_request( $react )->get_status(),
			'bookmark' => rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/posts/' . $post_id . '/bookmark' ) )->get_status(),
			'share'    => rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/posts/' . $post_id . '/share' ) )->get_status(),
			'vote'     => rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/posts/' . $post_id . '/vote' ) )->get_status(),
		);
	}

	public function test_a_member_engages_by_default(): void {
		$got = $this->statuses( $this->post_id );
		$this->assertSame( 200, $got['react'] );
		$this->assertSame( 200, $got['bookmark'] );
		$this->assertContains( $got['share'], array( 200, 201 ) );
		$this->assertNotSame( 403, $got['vote'], 'Refused for not being a poll, never for the role.' );
	}

	public function test_raising_the_ability_refuses_every_engagement_write(): void {
		add_filter(
			'buddynext_role_map',
			static function ( array $map ): array {
				$map['buddynext-feed/interact'] = 'moderator';
				return $map;
			}
		);
		$this->forget_role_map();

		foreach ( $this->statuses( $this->post_id ) as $surface => $code ) {
			$this->assertSame( 403, $code, $surface );
		}
	}

	public function test_post_zero_is_not_a_post(): void {
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/posts/0/bookmark' ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/posts/0/share' ) )->get_status() );
	}
}
