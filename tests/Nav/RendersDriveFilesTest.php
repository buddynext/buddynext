<?php
/**
 * Folder permissions on the Files tab: MediaVerse's answers win, and the legacy
 * licence check (deprecated in MediaVerse 2.7.0) runs only for an older MediaVerse.
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Nav\Providers\RendersDriveFiles;
use WPMediaVersePro\Documents\DocumentLicense;

/**
 * Exposes the trait's folder rules.
 */
final class DriveFilesProbe {
	use RendersDriveFiles;

	/**
	 * Row answers.
	 *
	 * @param array $view       Drive view.
	 * @param bool  $can_write  Write access.
	 * @param bool  $owns_drive Owner flag.
	 * @return array
	 */
	public static function rows( array $view, bool $can_write, bool $owns_drive ): array {
		return self::drive_folder_permissions( $view, $can_write, $owns_drive );
	}
}

/**
 * @covers \BuddyNext\Nav\Providers\RendersDriveFiles
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RendersDriveFilesTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once __DIR__ . '/Fixtures/document-license-stub.php';
		DocumentLicense::$calls = 0;
	}

	public function test_mediaverse_answers_are_used_and_the_licence_is_never_asked(): void {
		$rows = DriveFilesProbe::rows( array( 'folders' => array( array( 'can_manage' => true ), array( 'can_manage' => false ) ) ), true, true );

		$this->assertSame( array( true, false ), array_column( $rows, 'can_manage' ) );
		$this->assertSame( 0, DocumentLicense::$calls );
	}

	public function test_an_older_mediaverse_without_answers_falls_back_to_the_licence(): void {
		$rows = DriveFilesProbe::rows( array( 'folders' => array( array( 'id' => 1 ) ) ), true, true );

		$this->assertSame( array( true ), array_column( $rows, 'can_manage' ) );
		$this->assertSame( 1, DocumentLicense::$calls );
	}

	public function test_no_write_access_manages_nothing(): void {
		$rows = DriveFilesProbe::rows( array( 'folders' => array( array( 'can_manage' => true ) ) ), false, true );

		$this->assertSame( array( false ), array_column( $rows, 'can_manage' ) );
	}
}
