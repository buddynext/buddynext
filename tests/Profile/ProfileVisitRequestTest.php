<?php
/**
 * Which requests count as a person opening a profile page.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\ProfileService;

/**
 * Profile visit request tests.
 *
 * @covers \BuddyNext\Profile\ProfileService::request_is_a_page_visit
 */
class ProfileVisitRequestTest extends \WP_UnitTestCase {

	/**
	 * Clear the request headers the check reads.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_SERVER['HTTP_SEC_FETCH_DEST'], $_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_PURPOSE'] );
		parent::tear_down();
	}

	/**
	 * A page load, an in-page navigation and an old browser are visits.
	 *
	 * @return void
	 */
	public function test_page_loads_count(): void {
		$this->assertTrue( ProfileService::request_is_a_page_visit(), 'No headers (old browser).' );

		$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
		$this->assertTrue( ProfileService::request_is_a_page_visit() );

		$_SERVER['HTTP_SEC_FETCH_DEST'] = 'empty';
		$this->assertTrue( ProfileService::request_is_a_page_visit(), 'fetch(), used by the in-page router.' );
	}

	/**
	 * An embedded sub-resource or a prefetch is not a visit.
	 *
	 * @return void
	 */
	public function test_sub_resources_and_prefetch_do_not_count(): void {
		foreach ( array( 'image', 'iframe', 'script', 'style', 'video' ) as $dest ) {
			$_SERVER['HTTP_SEC_FETCH_DEST'] = $dest;
			$this->assertFalse( ProfileService::request_is_a_page_visit(), $dest );
		}

		$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
		$_SERVER['HTTP_SEC_PURPOSE']    = 'prefetch';
		$this->assertFalse( ProfileService::request_is_a_page_visit(), 'Prefetch.' );
	}
}
