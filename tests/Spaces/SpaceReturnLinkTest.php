<?php
/**
 * The Space page's "go back" link: same site only, fixed wording.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Spaces\SpaceReturnLink;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Spaces\SpaceReturnLink
 */
class SpaceReturnLinkTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unset( $_GET['return_to'], $_GET['return_for'] );
		parent::tear_down();
	}

	public function test_a_page_on_this_site_is_kept(): void {
		$_GET['return_to'] = rawurlencode( home_url( '/events/book-night/' ) );
		$this->assertSame( home_url( '/events/book-night/' ), SpaceReturnLink::url() );
	}

	public function test_another_site_and_a_missing_value_give_no_link(): void {
		$_GET['return_to'] = rawurlencode( 'https://evil.example/phish' );
		$this->assertSame( '', SpaceReturnLink::url() );
		unset( $_GET['return_to'] );
		$this->assertSame( '', SpaceReturnLink::url() );
	}

	public function test_wording_comes_from_a_fixed_list(): void {
		$_GET['return_for'] = 'event';
		$this->assertSame( 'Back to the event', SpaceReturnLink::words()[1] );
		$_GET['return_for'] = '<b>Hacked</b>';
		$this->assertSame( 'Go back', SpaceReturnLink::words()[1] );
	}
}
