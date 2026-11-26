<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The handle field's live preview folds accents the way the server does.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\AssetService;

/**
 * slug_fold_map() builds its letters without mbstring (Plugin Check flagged
 * mb_chr(), card 10501590945); the map must still match remove_accents().
 *
 * @covers \BuddyNext\Core\AssetService::slug_fold_map
 */
class AssetServiceSlugFoldTest extends \WP_UnitTestCase {

	public function test_fold_map_matches_remove_accents_without_mbstring(): void {
		$map = AssetService::slug_fold_map();

		$this->assertSame( 'O', $map['Ø'] ?? null, 'a letter Unicode does not decompose' );
		$this->assertSame( 'e', $map['é'] ?? null );
		$this->assertSame( 'A', $map['Ạ'] ?? null, 'Latin Extended Additional' );
		$this->assertArrayNotHasKey( 'a', $map, 'plain letters are not in the map' );
		foreach ( $map as $letter => $folded ) {
			$this->assertSame( remove_accents( $letter ), $folded );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
		$this->assertStringNotContainsString( 'mb_chr(', (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Core/AssetService.php' ) );
	}
}
