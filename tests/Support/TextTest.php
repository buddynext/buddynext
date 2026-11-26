<?php
/**
 * Tests for the extension-free Text helpers: BuddyNext never fatals on a host
 * without mbstring, ctype, dom or fileinfo.
 *
 * @package BuddyNext\Tests\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Support;

use BuddyNext\Support\Text;

/**
 * @covers \BuddyNext\Support\Text
 */
class TextTest extends \WP_UnitTestCase {

	/**
	 * Extension functions WordPress does not polyfill (it covers mb_substr, mb_strlen and mb_str_split).
	 */
	private const UNPOLYFILLED = '/\b(mb_(?!substr\b|strlen\b|str_split\b)\w+|ctype_\w+|finfo_\w+)\s*\(/';

	/**
	 * With the extension functions really absent (disabled for a child PHP), the
	 * helpers fall back and file_mime() reports "cannot tell" instead of fataling.
	 */
	public function test_helpers_work_with_the_extension_functions_absent(): void {
		$disabled = 'finfo_open,finfo_file,mb_strtoupper,mb_strtolower,mb_stripos,mb_strrpos,mb_strimwidth,ctype_digit';
		$code     = sprintf(
			'require %s; $t = "BuddyNext\\\\Support\\\\Text"; echo json_encode( array( function_exists( "finfo_open" ), $t::file_mime( __FILE__ ), $t::upper( "abc" ), $t::is_digits( "123" ) ) );',
			var_export( dirname( __DIR__, 2 ) . '/includes/Support/Text.php', true )
		);
		$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' -n -d ' . escapeshellarg( 'disable_functions=' . $disabled ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1' );

		$this->assertSame( array( false, null, 'ABC', true ), json_decode( (string) $out, true ), (string) $out );
	}

	/**
	 * With fileinfo present the type comes from the file's own bytes.
	 */
	public function test_file_mime_reads_the_file_when_fileinfo_is_present(): void {
		if ( ! function_exists( 'finfo_open' ) ) {
			$this->markTestSkipped( 'fileinfo is not loaded here.' );
		}
		$file = wp_tempnam( 'bn-mime' );
		file_put_contents( $file, '{"options":{"a":1}}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertContains( Text::file_mime( $file ), array( 'application/json', 'text/plain' ) );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * The owner notice names every extension the helpers stand in for.
	 */
	public function test_missing_extensions_only_reports_the_guarded_extensions(): void {
		$this->assertSame( array(), array_diff( Text::missing_extensions(), array( 'mbstring', 'ctype', 'dom', 'fileinfo' ) ) );
	}

	/**
	 * No code outside Support\Text calls an unpolyfilled mbstring, ctype or fileinfo
	 * function unless the same line checks function_exists() first.
	 */
	public function test_no_unguarded_extension_call_outside_the_helper(): void {
		$root      = dirname( __DIR__, 2 );
		$offenders = array();
		foreach ( array( 'includes', 'templates', 'blocks' ) as $dir ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$path = (string) $file;
				if ( ! str_ends_with( $path, '.php' ) || str_ends_with( $path, 'Support/Text.php' ) ) {
					continue;
				}
				// Rebuild the source without comments so prose naming a function is not a call.
				$code = '';
				foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
						$code .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
						continue;
					}
					$code .= is_array( $token ) ? $token[1] : $token;
				}
				foreach ( explode( "\n", $code ) as $i => $line ) {
					if ( preg_match( self::UNPOLYFILLED, $line ) && ! str_contains( $line, 'function_exists(' ) ) {
						$offenders[] = substr( $path, strlen( $root ) + 1 ) . ':' . ( $i + 1 );
					}
				}
			}
		}

		$this->assertSame( array(), $offenders, 'Route these through BuddyNext\\Support\\Text.' );
	}
}
