<?php
/**
 * The server-rendered member grid hands its page to add-on primers.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Pro's member labels batch-load on buddynext_directory_members_primed. Only the
 * REST directory fired it, so the /members/ page read labels one query per card
 * on a cold cache.
 *
 * @coversNothing
 */
class DirectoryGridPrimeHookTest extends WP_UnitTestCase {

	public function test_the_grid_fires_the_directory_prime_hook_with_its_page(): void {
		Installer::install_schema();
		$members = array( self::factory()->user->create(), self::factory()->user->create() );
		$seen    = array();
		add_action(
			'buddynext_directory_members_primed',
			static function ( array $ids ) use ( &$seen ): void {
				$seen = $ids;
			}
		);

		ob_start();
		buddynext_get_template(
			'parts/member-directory-grid.php',
			array(
				'members'   => array_map( 'get_userdata', $members ),
				'viewer_id' => 0,
			)
		);
		ob_end_clean();

		sort( $seen );
		$this->assertSame( $members, $seen );
	}
}
