<?php
/**
 * Stand-in for a MediaVerse Pro older than 2.7.0, whose DocumentLicense::can_write()
 * was a real licence check. Loaded only in a separate test process.
 *
 * @package BuddyNext\Tests\Nav
 */

namespace WPMediaVersePro\Documents;

/**
 * Counts calls so a test can prove the legacy fallback is reached only when needed.
 */
class DocumentLicense {

	/**
	 * Number of can_write() calls.
	 *
	 * @var int
	 */
	public static int $calls = 0;

	/**
	 * Licence answer.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function can_write( int $user_id = 0 ): bool {
		unset( $user_id );
		++self::$calls;
		return true;
	}
}
