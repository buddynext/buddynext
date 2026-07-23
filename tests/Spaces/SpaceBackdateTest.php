<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Importer seam: SpaceService::create() honours a backdated created_at in $data.
 *
 * @package BuddyNext\Tests\Spaces
 * @since 1.1.0
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * Locks created_at pass-through on space creation.
 */
class SpaceBackdateTest extends WP_UnitTestCase {

	/**
	 * data['created_at'] in the past is stored; a future value is clamped.
	 *
	 * @return void
	 */
	public function test_create_honours_backdated_created_at(): void {
		global $wpdb;

		$owner   = self::factory()->user->create();
		$service = new SpaceService();

		$space_id = $service->create(
			$owner,
			array(
				'name'       => 'Imported Space',
				'slug'       => 'imported-space-backdate',
				'privacy'    => 'public',
				'created_at' => '2018-01-01 10:00:00',
			)
		);
		$this->assertIsInt( $space_id );
		$this->assertSame(
			'2018-01-01 10:00:00',
			$wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id ) )
		);

		$future_id = $service->create(
			$owner,
			array(
				'name'       => 'Future Space',
				'slug'       => 'future-space-backdate',
				'privacy'    => 'public',
				'created_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertIsInt( $future_id );
		$stored = strtotime( (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $future_id ) ) . ' UTC' );
		$this->assertLessThanOrEqual( time() + 2, $stored, 'future created_at must be clamped to now' );
	}
}
