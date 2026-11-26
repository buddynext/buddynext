<?php
/**
 * A held member is held off the Jetonomy forum, on the web and over REST.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use WP_Error;
use WP_REST_Request;

/**
 * Jetonomy has no hold of its own, so before 1.3.0 a member held by
 * "Paying members only" was refused buddynext/v1 but could still read every
 * discussion over jetonomy/v1 and on the forum pages. The bridge now applies
 * the same hold, with the same answer the community routes give.
 *
 * @covers \BuddyNext\Bridges\JetonomyBridge::hold_forum_rest
 * @covers \BuddyNext\Bridges\JetonomyBridge::hold_forum_pages
 * @covers \BuddyNext\Auth\RestHoldGate::partner_route_refusal
 */
class JetonomyMemberHoldTest extends \WP_UnitTestCase {

	/**
	 * Drop the hold and the query var.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_member_hold' );
		set_query_var( 'jetonomy_route', '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Hold every member.
	 *
	 * @param string[] $hubs Hubs left open.
	 * @return void
	 */
	private function hold_everyone( array $hubs = array( 'settings' ) ): void {
		add_filter(
			'buddynext_member_hold',
			static fn() => array(
				'code'    => 'test_hold',
				'message' => 'Held for the test.',
				'url'     => home_url( '/held/' ),
				'hubs'    => $hubs,
				'routes'  => array( '/buddynext/v1/me/account' ),
			)
		);
	}

	/**
	 * Run the REST gate for a route.
	 *
	 * @param string $route Route.
	 * @param mixed  $prior Prior pre-dispatch result.
	 * @return mixed
	 */
	private function dispatch_gate( string $route, $prior = null ) {
		return ( new JetonomyBridge() )->hold_forum_rest( $prior, null, new WP_REST_Request( 'GET', $route ) );
	}

	/**
	 * Run the web gate, returning the redirect target or '' when it let the request through.
	 *
	 * @param string $route Jetonomy route query var.
	 * @return string
	 */
	private function page_gate( string $route ): string {
		set_query_var( 'jetonomy_route', $route );
		$throw = static function ( $location ): void {
			throw new \RuntimeException( (string) $location );
		};
		add_filter( 'wp_redirect', $throw );
		try {
			( new JetonomyBridge() )->hold_forum_pages();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}
		return '';
	}

	/**
	 * A held member gets the hold's 403 on both Jetonomy namespaces, whatever the case.
	 */
	public function test_held_member_is_refused_jetonomy_rest(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->hold_everyone();

		foreach ( array( '/jetonomy/v1/spaces', '/JETONOMY/v1/posts/5', '/jetonomy-pro/v1/attachments' ) as $route ) {
			$result = $this->dispatch_gate( $route );
			$this->assertInstanceOf( WP_Error::class, $result, $route );
			$this->assertSame( 'test_hold', $result->get_error_code(), $route );
			$this->assertSame( 403, $result->get_error_data()['status'], $route );
			$this->assertSame( home_url( '/held/' ), $result->get_error_data()['hold_url'], $route );
		}
	}

	/**
	 * Members without a hold, administrators and other namespaces are untouched.
	 */
	public function test_gate_only_removes_access_from_held_members_on_jetonomy(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertNull( $this->dispatch_gate( '/jetonomy/v1/spaces' ), 'no hold, no refusal' );

		$this->hold_everyone();
		$this->assertNull( $this->dispatch_gate( '/wp/v2/posts' ), 'core REST is not ours to gate' );
		$this->assertNull( $this->dispatch_gate( '/jetonomyx/v1/spaces' ), 'a look-alike namespace is not Jetonomy' );
		$prior = new WP_Error( 'earlier', 'Earlier answer.' );
		$this->assertSame( $prior, $this->dispatch_gate( '/jetonomy/v1/spaces', $prior ), 'an earlier short-circuit wins' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertNull( $this->dispatch_gate( '/jetonomy/v1/spaces' ), 'administrators are never held' );

		wp_set_current_user( 0 );
		$this->assertNull( $this->dispatch_gate( '/jetonomy/v1/spaces' ), 'visitors are Jetonomy\'s own call' );
	}

	/**
	 * Forum pages send a held member to the hold's page; feeds, sitemaps and an open discussions hub do not.
	 */
	public function test_held_member_is_sent_from_forum_pages(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->page_gate( 'space' ), 'no hold, no redirect' );

		$this->hold_everyone();
		$this->assertSame( home_url( '/held/' ), $this->page_gate( 'space' ) );
		$this->assertSame( home_url( '/held/' ), $this->page_gate( 'post' ) );
		$this->assertSame( '', $this->page_gate( 'feed' ) );
		$this->assertSame( '', $this->page_gate( 'sitemap' ) );
		$this->assertSame( '', $this->page_gate( '' ), 'not a forum page' );

		remove_all_filters( 'buddynext_member_hold' );
		$this->hold_everyone( array( 'settings', 'discussions' ) );
		$this->assertSame( '', $this->page_gate( 'space' ), 'a hold that keeps discussions open leaves the forum open' );
	}
}
