<?php
/**
 * Every --bn-* custom property a stylesheet reads must be defined somewhere.
 *
 * An undefined token fails silently: with no fallback the browser drops the
 * whole declaration (the Files folder chip lost its tint, sixteen text colours
 * fell back to inherited), and with a raw fallback it bypasses dark mode and
 * re-theming. Card 10343755164 fixed 38 such names; this keeps the set closed.
 *
 * "Defined" means declared in a stylesheet, emitted by PHP (TokenService, an
 * inline style attribute) or set from JS (style.setProperty).
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

use WP_UnitTestCase;

/**
 * @coversNothing Static contract over assets/css, includes, templates and assets/js.
 */
class DesignTokenReferenceTest extends WP_UnitTestCase {

	/**
	 * Files under a plugin directory with one of the given extensions.
	 *
	 * @param string   $dir  Directory relative to the plugin root.
	 * @param string[] $exts Extensions without the dot.
	 * @return string[]
	 */
	private function files( string $dir, array $exts ): array {
		$root = dirname( __DIR__, 2 ) . '/' . $dir;
		if ( ! is_dir( $root ) ) {
			return array();
		}
		$out = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( in_array( $file->getExtension(), $exts, true ) ) {
				$out[] = $file->getPathname();
			}
		}
		return $out;
	}

	public function test_every_referenced_bn_token_is_defined(): void {
		$defined = array();
		$sources = array_merge(
			$this->files( 'assets/css', array( 'css' ) ),
			$this->files( 'includes', array( 'php' ) ),
			$this->files( 'templates', array( 'php' ) ),
			$this->files( 'blocks', array( 'php', 'js' ) ),
			$this->files( 'assets/js', array( 'js' ) ),
			array( dirname( __DIR__, 2 ) . '/buddynext.php' )
		);
		foreach ( $sources as $file ) {
			$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			// Declarations (`--bn-x:` in CSS or an inline style) and quoted names
			// (TokenService keys, setProperty( '--bn-x', ... )).
			preg_match_all( '/(--bn-[a-z0-9-]+)\s*:|[\'"](--bn-[a-z0-9-]+)[\'"]/', $text, $m );
			foreach ( array_merge( $m[1], $m[2] ) as $name ) {
				if ( '' !== $name ) {
					$defined[ $name ] = true;
				}
			}
		}

		$undefined = array();
		foreach ( $this->files( 'assets/css', array( 'css' ) ) as $file ) {
			preg_match_all( '/var\(\s*(--bn-[a-z0-9-]+)/', (string) file_get_contents( $file ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( $m[1] as $name ) {
				if ( ! isset( $defined[ $name ] ) ) {
					$undefined[ $name ][ basename( $file ) ] = true;
				}
			}
		}

		$report = array();
		foreach ( $undefined as $name => $where ) {
			$report[] = $name . ' (' . implode( ', ', array_keys( $where ) ) . ')';
		}
		$this->assertSame( array(), $report, "Undefined --bn-* tokens. Point them at a canonical token in bn-base.css / bn-admin.css:\n" . implode( "\n", $report ) );
	}
}
