<?php
/**
 * The suspended banner: one notice for a suspended member on a community page, nothing for anyone else.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * templates/parts/suspended-banner.php.
 */
class SuspendedBannerTest extends WP_UnitTestCase {

	/**
	 * Create the tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Render the part for the current user.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		buddynext_get_template( 'parts/suspended-banner.php' );
		return (string) ob_get_clean();
	}

	/**
	 * A suspended member sees the banner with the appeal link.
	 *
	 * @return void
	 */
	public function test_a_suspended_member_sees_the_banner_and_the_appeal_link(): void {
		$user = self::factory()->user->create();
		$this->assertTrue( true === buddynext_service( 'moderation' )->suspend( $user, 'test', 7 ) );
		wp_set_current_user( $user );

		$html = $this->render();
		$this->assertStringContainsString( 'bn-suspended-banner', $html );
		$this->assertStringContainsString( 'me/account-status', $html );
	}

	/**
	 * A member in good standing and a guest see nothing.
	 *
	 * @return void
	 */
	public function test_everyone_else_sees_nothing(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( '', trim( $this->render() ) );

		wp_set_current_user( 0 );
		$this->assertSame( '', trim( $this->render() ) );
	}
}
