<?php
/**
 * A link preview must not be redirected onto an internal host.
 *
 * The SSRF guard ran on the FIRST url only, and `wp_remote_get()` then followed up
 * to five redirects by itself. So a page on a perfectly ordinary host could answer
 * `302 Location: http://169.254.169.254/latest/meta-data/` and the server would
 * follow it — reachable by any logged-in member through the link-preview route.
 *
 * ## What the card got wrong, and why the fix is still worth having
 *
 * Card 10227862917 says redirect hops are not re-checked. On this stack they are:
 * `WP_Http::handle_redirects()` runs `wp_http_validate_url()` on every `Location`,
 * and on WP 7.1 that rejects loopback, RFC1918, 0.0.0.0/8, 169.254/16, CGNAT,
 * TEST-NETs, multicast and reserved space — measured side by side against our own
 * guard, identical verdicts on all six cases including IPv6. The card's proof of
 * concept (302 to 127.0.0.1) will therefore not reproduce.
 *
 * It is still worth closing. We support WP 6.9+, core's list has grown over
 * releases, and that branch is IPv4-only — so relying on which core happens to be
 * underneath is the actual defect, not the specific range.
 *
 * ## Why not simply `'redirection' => 0`
 *
 * Because it would break previews for every URL that redirects, which is most of
 * them: http->https, trailing-slash canonicalisation, link shorteners, campaign
 * redirects. The one-line fix would have traded a theoretical hole for a visible
 * regression. Hops are followed, one validated at a time.
 *
 * ## How this is driven
 *
 * `pre_http_request` answers each fetch with a canned response, so nothing leaves
 * the machine and no redirecting server is needed. The assertion is that the
 * internal host is never REQUESTED — checking the returned metadata would only
 * tell us what came back, not where we went.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * Redirect handling in the link-preview fetch.
 *
 * @covers \BuddyNext\Feed\PostService::fetch_following_validated_redirects
 */
class LinkPreviewRedirectSsrfTest extends WP_UnitTestCase {

	/**
	 * Every URL the HTTP layer was asked for, in order.
	 *
	 * @var string[]
	 */
	private array $requested = array();

	/**
	 * Map of URL => canned response.
	 *
	 * @var array<string, array<string,mixed>>
	 */
	private array $responses = array();

	/**
	 * Intercept all outbound HTTP.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->requested = array();
		$this->responses = array();

		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Remove the interceptor.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );

		parent::tear_down();
	}

	/**
	 * Answer an outbound request from the canned map, recording the URL.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request args.
	 * @param string $url     Requested URL.
	 * @return array<string,mixed>
	 */
	public function answer( $preempt, $args, $url ) {
		unset( $preempt, $args );

		$this->requested[] = (string) $url;

		if ( isset( $this->responses[ $url ] ) ) {
			return $this->responses[ $url ];
		}

		return array(
			'headers'  => array(),
			'body'     => '<html><head><title>Ordinary page</title></head><body></body></html>',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Queue a redirect from one URL to another.
	 *
	 * @param string $from Source URL.
	 * @param string $to   Location header value.
	 * @return void
	 */
	private function redirect( string $from, string $to ): void {
		$this->responses[ $from ] = array(
			'headers'  => array( 'location' => $to ),
			'body'     => '',
			'response' => array( 'code' => 302, 'message' => 'Found' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Run the link-preview fetch for a URL.
	 *
	 * @param string $url Starting URL.
	 * @return mixed
	 */
	private function preview( string $url ) {
		$method = new \ReflectionMethod( PostService::class, 'fetch_following_validated_redirects' );
		$method->setAccessible( true );

		return $method->invoke( null, $url );
	}

	/**
	 * The cloud-metadata case: a public host redirects to the metadata endpoint.
	 *
	 * @return void
	 */
	public function test_a_redirect_to_cloud_metadata_is_never_followed(): void {
		$this->redirect( 'https://example.com/', 'http://169.254.169.254/latest/meta-data/' );

		$result = $this->preview( 'https://example.com/' );

		$this->assertNotContains(
			'http://169.254.169.254/latest/meta-data/',
			$this->requested,
			'The server followed a redirect onto the cloud metadata endpoint.'
		);
		$this->assertTrue( is_wp_error( $result ), 'A refused hop must report an error rather than a silent empty preview.' );
	}

	/**
	 * Loopback, private ranges and IPv6 are all refused as hops.
	 *
	 * @return void
	 */
	public function test_no_internal_host_is_followed(): void {
		foreach ( array( 'http://127.0.0.1/', 'http://10.0.0.5/', 'http://192.168.1.1/', 'http://[::1]/' ) as $internal ) {
			$this->requested = array();
			$this->redirect( 'https://example.com/', $internal );

			$this->preview( 'https://example.com/' );

			$this->assertNotContains( $internal, $this->requested, 'Followed a redirect to ' . $internal );
		}
	}

	/**
	 * A RELATIVE Location is resolved before it is judged.
	 *
	 * A hop of `/latest/meta-data/` is harmless; the point is that resolution
	 * happens against the current URL rather than being skipped.
	 *
	 * @return void
	 */
	public function test_a_relative_redirect_is_resolved_and_followed(): void {
		$this->redirect( 'https://example.com/a', '/b' );

		$this->preview( 'https://example.com/a' );

		$this->assertContains( 'https://example.com/b', $this->requested, 'A relative Location was not resolved against the current URL.' );
	}

	/**
	 * An ordinary redirect chain still works. Guards the guard.
	 *
	 * Refusing every redirect would pass every test above and break previews for
	 * most real links — http->https and trailing-slash alone cover a huge share.
	 *
	 * @return void
	 */
	public function test_an_ordinary_redirect_chain_is_still_followed(): void {
		$this->redirect( 'http://example.com/page', 'https://example.com/page' );
		$this->redirect( 'https://example.com/page', 'https://example.com/page/' );

		$result = $this->preview( 'http://example.com/page' );

		$this->assertContains( 'https://example.com/page/', $this->requested, 'A perfectly ordinary redirect chain was not followed.' );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 200, (int) wp_remote_retrieve_response_code( $result ) );
	}

	/**
	 * A redirect loop terminates instead of spinning.
	 *
	 * @return void
	 */
	public function test_a_redirect_loop_gives_up(): void {
		$this->redirect( 'https://example.com/a', 'https://example.com/b' );
		$this->redirect( 'https://example.com/b', 'https://example.com/a' );

		$result = $this->preview( 'https://example.com/a' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'too_many_redirects', $result->get_error_code() );
		$this->assertLessThanOrEqual( 7, count( $this->requested ), 'The loop was not bounded.' );
	}
}
