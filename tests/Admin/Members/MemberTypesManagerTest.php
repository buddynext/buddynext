<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The admin badge preview shows the text colour members see.
 *
 * @package BuddyNext\Tests\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Members;

use BuddyNext\Admin\Members\MemberTypesManager;
use BuddyNext\Core\Installer;

/**
 * The frontend corrects a badge's text colour to black or white when the saved
 * one is hard to read; the admin list printed the raw saved colour, so the two
 * disagreed (card 10498345359).
 *
 * @covers \BuddyNext\Admin\Members\MemberTypesManager::render_member_types_tab
 */
class MemberTypesManagerTest extends \WP_UnitTestCase {

	public function test_admin_badge_uses_the_readable_text_colour(): void {
		Installer::run();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// White on a mid blue is about 3.7:1, below the 4.5:1 a small label needs.
		buddynext_service( 'member_types' )->create(
			array(
				'name'       => 'Mid Blue Type',
				'slug'       => 'mid-blue-type',
				'color'      => '#3b82f6',
				'text_color' => '#ffffff',
			)
		);
		$expected = buddynext_readable_text_color( '#3b82f6', '#ffffff' );
		$this->assertSame( '#000000', $expected, 'fixture: members see black' );

		ob_start();
		( new MemberTypesManager() )->render_member_types_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'background:#3b82f6;color:#000000', $html );
		$this->assertStringNotContainsString( 'background:#3b82f6;color:#ffffff', $html );
	}
}
