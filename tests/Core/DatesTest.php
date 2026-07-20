<?php
/**
 * Tests for the REST timestamp-normalization seam.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Dates;

/**
 * The one dispatch-time seam that adds ISO-8601 `<key>_gmt` siblings to
 * app-consumed buddynext/v1 responses.
 *
 * @covers \BuddyNext\Core\Dates
 */
class DatesTest extends \WP_UnitTestCase {

	/**
	 * Build a real request/response pair and run it through the seam.
	 *
	 * @param string $route Requested route.
	 * @param mixed  $data  Response data.
	 * @return mixed The (possibly transformed) response data.
	 */
	private function run_seam( string $route, $data ) {
		$request  = new \WP_REST_Request( 'GET', $route );
		$response = new \WP_REST_Response( $data );
		$result   = Dates::filter_rest_response( $response, null, $request );
		return $result->get_data();
	}

	/**
	 * Plugin boot must wire the seam onto the dispatch filter, or no real
	 * request is ever normalized.
	 *
	 * @return void
	 */
	public function test_seam_is_registered_on_dispatch_filter(): void {
		$this->assertNotFalse(
			has_filter( 'rest_request_after_callbacks', array( Dates::class, 'filter_rest_response' ) ),
			'Dates::register() must run at boot so buddynext/v1 responses get *_gmt siblings.'
		);
	}

	/**
	 * A stored UTC datetime becomes an ISO-8601 'Z' string; junk becomes ''.
	 *
	 * @return void
	 */
	public function test_iso8601_converts_utc_and_rejects_junk(): void {
		$this->assertSame( '2026-07-20T12:08:45Z', Dates::iso8601( '2026-07-20 12:08:45' ) );
		$this->assertSame( '', Dates::iso8601( '' ) );
		$this->assertSame( '', Dates::iso8601( '0000-00-00 00:00:00' ) );
		$this->assertSame( '', Dates::iso8601( 'not-a-date' ) );
	}

	/**
	 * An owned-namespace response gains a `<key>_gmt` sibling for each
	 * whitelisted timestamp, without disturbing the original field.
	 *
	 * @return void
	 */
	public function test_owned_namespace_gets_gmt_siblings(): void {
		$data = $this->run_seam(
			'/buddynext/v1/members/5/discussions',
			array(
				'created_at' => '2026-07-20 12:08:45',
				'title'      => 'A discussion',
			)
		);

		$this->assertSame( '2026-07-20 12:08:45', $data['created_at'], 'the original field is preserved' );
		$this->assertSame( '2026-07-20T12:08:45Z', $data['created_at_gmt'], 'the ISO-Z sibling is added' );
		$this->assertSame( 'A discussion', $data['title'] );
	}

	/**
	 * Nested rows (arrays and stdClass) are walked recursively.
	 *
	 * @return void
	 */
	public function test_nested_rows_are_normalized(): void {
		$row             = new \stdClass();
		$row->created_at = '2026-01-02 03:04:05';

		$data = $this->run_seam(
			'/buddynext/v1/feed',
			array(
				'items' => array(
					array( 'created_at' => '2026-07-20 12:08:45' ),
					$row,
				),
			)
		);

		$this->assertSame( '2026-07-20T12:08:45Z', $data['items'][0]['created_at_gmt'] );
		$this->assertSame( '2026-01-02T03:04:05Z', $data['items'][1]->created_at_gmt );
	}

	/**
	 * The seam is idempotent and ignores non-datetime values: a hand-written
	 * `_gmt` sibling is left alone, and ints / nulls / non-timestamp strings
	 * never sprout a sibling.
	 *
	 * @return void
	 */
	public function test_idempotent_and_skips_non_datetime_values(): void {
		$data = $this->run_seam(
			'/buddynext/v1/feed',
			array(
				'created_at'     => '2026-07-20 12:08:45',
				'created_at_gmt' => 'ALREADY-SET',   // Hand-emitted: must not be overwritten.
				'last_active'    => 1_700_000_000,   // Unix int: not a MySQL datetime.
				'edited_at'      => null,            // Never edited.
				'title'          => 'hello world',   // Not a timestamp key.
			)
		);

		$this->assertSame( 'ALREADY-SET', $data['created_at_gmt'], 'an existing sibling is not clobbered' );
		$this->assertArrayNotHasKey( 'last_active_gmt', $data, 'ints are skipped' );
		$this->assertArrayNotHasKey( 'edited_at_gmt', $data, 'nulls are skipped' );
		$this->assertArrayNotHasKey( 'title_gmt', $data, 'non-timestamp keys are ignored' );
	}

	/**
	 * A response outside the owned namespaces is left completely untouched.
	 *
	 * @return void
	 */
	public function test_foreign_namespace_is_untouched(): void {
		$data = $this->run_seam(
			'/wp/v2/posts',
			array( 'created_at' => '2026-07-20 12:08:45' )
		);

		$this->assertArrayNotHasKey( 'created_at_gmt', $data, 'only buddynext/v1 is normalized' );
	}

	/**
	 * A namespace added via the filter opts its endpoints in (the Pro hook).
	 *
	 * @return void
	 */
	public function test_namespaces_are_filterable_for_pro(): void {
		$cb = static function ( array $ns ): array {
			$ns[] = '/buddynext-pro/v1/';
			return $ns;
		};
		add_filter( 'buddynext_rest_timestamp_namespaces', $cb );

		$data = $this->run_seam(
			'/buddynext-pro/v1/events/42',
			array( 'created_at' => '2026-07-20 12:08:45' )
		);

		remove_filter( 'buddynext_rest_timestamp_namespaces', $cb );

		$this->assertSame( '2026-07-20T12:08:45Z', $data['created_at_gmt'], 'Pro extends coverage with one filter' );
	}

	/**
	 * A failed request (WP_Error) passes straight through the seam.
	 *
	 * @return void
	 */
	public function test_wp_error_passes_through(): void {
		$error   = new \WP_Error( 'boom', 'nope' );
		$request = new \WP_REST_Request( 'GET', '/buddynext/v1/feed' );

		$this->assertSame( $error, Dates::filter_rest_response( $error, null, $request ) );
	}
}
