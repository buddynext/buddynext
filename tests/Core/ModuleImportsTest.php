<?php
/**
 * Script modules import each other by registered id, never by relative path.
 *
 * A relative import ('../media/upload-core.js') resolves to an unversioned URL.
 * Hosts cache that URL for a year, so after a release that changes the file a
 * returning visitor keeps the old copy while the importing store is new, and
 * the whole module fails to load. A registered id goes through the import map
 * and carries a version.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * Module import tests.
 *
 * @coversNothing
 */
class ModuleImportsTest extends \WP_UnitTestCase {

	/**
	 * No shipped module imports another file by relative path.
	 *
	 * @return void
	 */
	public function test_no_module_imports_a_file_by_relative_path(): void {
		$root     = dirname( __DIR__, 2 ) . '/assets/js';
		$files    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$relative = array();

		foreach ( $files as $file ) {
			$path = (string) $file;
			if ( '.js' !== substr( $path, -3 ) || false !== strpos( $path, '/vendor/' ) ) {
				continue;
			}
			foreach ( file( $path ) as $number => $line ) {
				if ( preg_match( '#^\s*(import|export)\b[^\n]*[\'"]\.{1,2}/|\bimport\(\s*[\'"]\.{1,2}/#', $line ) ) {
					$relative[] = str_replace( $root . '/', '', $path ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $relative, 'Register the module in AssetService and import it by its @buddynext/ id.' );
	}
}
