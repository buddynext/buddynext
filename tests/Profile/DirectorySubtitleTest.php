<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The member directory header states the count of what the grid is showing.
 *
 * With "Online only" on it read "1 member in the community" over a grid of
 * online members, and a member-type filter kept the whole-community total.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\MemberDirectoryService;
use WP_UnitTestCase;

/**
 * Directory subtitle wording follows the active filter.
 *
 * @covers \BuddyNext\Profile\MemberDirectoryService::directory_subtitle
 */
class DirectorySubtitleTest extends WP_UnitTestCase {

	/**
	 * Online only names the online count, filtered names what was found, and
	 * the unfiltered directory names the community.
	 *
	 * @return void
	 */
	public function test_subtitle_follows_the_filter(): void {
		$this->assertSame( '6 members online', MemberDirectoryService::directory_subtitle( 6, true, true ) );
		$this->assertSame( '1 member online', MemberDirectoryService::directory_subtitle( 1, true, true ) );
		$this->assertSame( '203 members found', MemberDirectoryService::directory_subtitle( 203, false, true ) );
		$this->assertSame( '1,224 members in the community', MemberDirectoryService::directory_subtitle( 1224, false, false ) );
	}

	/**
	 * The hero binds the subtitle to the store, so a client-side filter change
	 * rewrites it instead of leaving the server text behind.
	 *
	 * @return void
	 */
	public function test_hero_binds_the_subtitle_for_client_updates(): void {
		ob_start();
		buddynext_get_template(
			'parts/member-directory-hero.php',
			array(
				'total_members' => 6,
				'online_only'   => true,
				'filtered'      => true,
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-wp-text="state.directorySubtitle"', $html );
		$this->assertStringContainsString( '6 members online', $html );
	}
}
