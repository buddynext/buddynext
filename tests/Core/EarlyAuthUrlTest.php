<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Another plugin may call wp_login_url() before WordPress builds its query
 * (card 10436110970). BuddyNext's login_url filter must answer, not fatal.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;

/**
 * @covers \BuddyNext\Core\PageRouter::login_url
 * @covers \BuddyNext\Core\PageRouter::signup_url
 */
class EarlyAuthUrlTest extends \WP_UnitTestCase {

	public function test_auth_urls_answer_before_the_query_exists(): void {
		$saved = $GLOBALS['wp_query'];
		unset( $GLOBALS['wp_query'] );

		try {
			$login  = PageRouter::login_url( null );
			$signup = PageRouter::signup_url( null );
			$core   = wp_login_url();
		} finally {
			$GLOBALS['wp_query'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the fixture.
		}

		$this->assertStringNotContainsString( 'redirect_to', $login );
		$this->assertStringNotContainsString( 'redirect_to', $signup );
		$this->assertSame( $login, $core );
	}
}
