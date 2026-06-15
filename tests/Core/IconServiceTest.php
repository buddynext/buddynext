<?php
/**
 * Tests for IconService::has() — the guard integrations use to avoid blank icons.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\IconService;

/**
 * @covers \BuddyNext\Core\IconService
 */
class IconServiceTest extends \WP_UnitTestCase {

	public function test_has_true_for_shipped_icon(): void {
		// Slugs the suite/integrations rely on — must exist on disk.
		foreach ( array( 'folder', 'briefcase', 'file-text', 'graduation-cap', 'book-open', 'store', 'award', 'bell', 'chevron-right' ) as $slug ) {
			$this->assertTrue( IconService::has( $slug ), "icon '{$slug}.svg' should exist" );
		}
	}

	public function test_has_false_for_missing_icon(): void {
		$this->assertFalse( IconService::has( 'definitely-not-an-icon-xyz' ) );
		$this->assertFalse( IconService::has( '' ) );
	}

	public function test_has_does_not_allow_path_traversal(): void {
		// sanitize_file_name() strips the traversal, so this resolves to a
		// non-existent slug rather than escaping the icons directory.
		$this->assertFalse( IconService::has( '../../wp-config' ) );
	}
}
