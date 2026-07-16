<?php
/**
 * Account holds must apply over REST, not only on web.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\RestHoldGate;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\REST\Router;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Account holds must apply over REST, not only on web.
 *
 * Both holds — "full" email verification and forced 2FA enrolment — hung off
 * template_redirect, which does not fire on a REST request. So over REST neither
 * hold was weaker; it was absent. Probed before this gate existed, with enforcement
 * set to "full", an unverified member could `react` and `follow` while `create post`
 * and `create comment` came back GATED (email_unverified).
 *
 * Those two were caught only because PostService and CommentService each call
 * is_verified() themselves — that is the "restricted" tier, and it works over REST
 * precisely because it lives in a service rather than in a redirect. Everything
 * without such a call was open.
 *
 * @covers \BuddyNext\Auth\RestHoldGate
 */
class RestHoldGateTest extends \WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * An unverified member.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * A post to act on.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Boot REST with the gate wired, and a target post.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		( new Router() )->register();
		( new RestHoldGate() )->register();
		do_action( 'rest_api_init' );

		$author        = self::factory()->user->create();
		$this->member  = self::factory()->user->create();
		$this->post_id = (int) ( new PostService() )->create(
			$author,
			array(
				'content' => 'target',
				'type'    => 'text',
			)
		);

		update_option( 'buddynext_email_verify', true );
	}

	/**
	 * Clear the holds.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_email_verify' );
		delete_option( 'buddynext_verify_enforcement' );
		remove_all_filters( 'buddynext_gate_unverified' );
		remove_all_filters( 'buddynext_enforce_2fa_enrolment' );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * React over REST as the current user and return the status.
	 *
	 * Reactions are the probe because nothing in ReactionService checks
	 * verification — so the status here is the gate's answer and nobody else's.
	 *
	 * @return int
	 */
	private function react_status(): int {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/reactions/toggle' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $this->post_id );
		$request->set_param( 'emoji', 'like' );

		return $this->server->dispatch( $request )->get_status();
	}

	/**
	 * The hold this class was written for.
	 *
	 * @return void
	 */
	public function test_full_enforcement_holds_an_unverified_member_out_of_rest(): void {
		update_option( 'buddynext_verify_enforcement', 'full' );
		wp_set_current_user( $this->member );

		$this->assertSame(
			403,
			$this->react_status(),
			'"Full" enforcement promises the member cannot use the community until they verify. Before this gate they could react over REST.'
		);
	}

	/**
	 * "Restricted" is a different promise and must keep working as it did.
	 *
	 * It means "cannot post or comment", it is enforced inside PostService and
	 * CommentService, and it already behaved identically on web and REST. Gating it
	 * here would take away reactions a restricted member is entitled to.
	 *
	 * @return void
	 */
	public function test_restricted_enforcement_still_allows_reacting(): void {
		update_option( 'buddynext_verify_enforcement', 'restricted' );
		wp_set_current_user( $this->member );

		$this->assertNotSame(
			403,
			$this->react_status(),
			'Restricted means "no posting/commenting", not "no community". Do not over-gate it.'
		);
	}

	/**
	 * A verified member is untouched.
	 *
	 * @return void
	 */
	public function test_a_verified_member_passes(): void {
		update_option( 'buddynext_verify_enforcement', 'full' );
		// The meta is_verified() reads; there is no public setter, and verify()
		// wants a real token.
		update_user_meta( $this->member, 'buddynext_email_verified', 1 );
		wp_set_current_user( $this->member );

		$this->assertNotSame( 403, $this->react_status() );
	}

	/**
	 * An administrator is never trapped out of their own site.
	 *
	 * The same carve-out both web gates make.
	 *
	 * @return void
	 */
	public function test_an_administrator_is_never_held(): void {
		update_option( 'buddynext_verify_enforcement', 'full' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertNotSame( 403, $this->react_status() );
	}

	/**
	 * The auth surface stays reachable, or the hold is a locked room with the key
	 * inside.
	 *
	 * @return void
	 */
	public function test_the_auth_surface_stays_reachable_while_held(): void {
		update_option( 'buddynext_verify_enforcement', 'full' );
		wp_set_current_user( $this->member );

		$status = $this->server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/auth/verify/status' ) )->get_status();

		$this->assertNotSame(
			403,
			$status,
			'A held member must still be able to check status and resend — that is how they stop being held.'
		);
	}

	/**
	 * A site that opted out on web is not suddenly gated on REST.
	 *
	 * @return void
	 */
	public function test_the_existing_web_filter_is_honoured(): void {
		update_option( 'buddynext_verify_enforcement', 'full' );
		add_filter( 'buddynext_gate_unverified', '__return_false' );
		wp_set_current_user( $this->member );

		$this->assertNotSame(
			403,
			$this->react_status(),
			'buddynext_gate_unverified turns the web hold off; the REST mirror must respect the same switch.'
		);
	}

	/**
	 * Non-BuddyNext namespaces are never touched.
	 *
	 * @return void
	 */
	public function test_core_routes_are_not_gated(): void {
		update_option( 'buddynext_verify_enforcement', 'full' );
		wp_set_current_user( $this->member );

		$status = $this->server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) )->get_status();

		$this->assertNotSame( 403, $status, 'The gate must scope to buddynext(-pro)/v1 and leave wp/v2 alone.' );
	}
}
