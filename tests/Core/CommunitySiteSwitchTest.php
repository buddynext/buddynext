<?php
/**
 * On a network subsite BuddyNext defines nothing but the community-site switch.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * Loads the real main file in a bare PHP process that reports a subsite, so the
 * result is not polluted by this suite having already booted BuddyNext.
 */
class CommunitySiteSwitchTest extends \WP_UnitTestCase {

	/**
	 * Include buddynext.php in a fresh process.
	 *
	 * @param bool $multisite Whether the process reports a network.
	 * @param bool $main_site Whether the process reports the main site.
	 * @return array<string,mixed> Decoded report.
	 */
	private function load_main_file( bool $multisite, bool $main_site ): array {
		$main = dirname( __DIR__, 2 ) . '/buddynext.php';
		$code = '
			define( "ABSPATH", "/" );
			function is_multisite() { return ' . ( $multisite ? 'true' : 'false' ) . '; }
			function is_main_site() { return ' . ( $main_site ? 'true' : 'false' ) . '; }
			function plugin_dir_path() { return "/"; }
			function plugin_dir_url() { return "/"; }
			function plugin_basename() { return "buddynext/buddynext.php"; }
			function __() { return ""; }
			function add_action() {}
			function add_filter() {}
			function register_activation_hook() {}
			function register_deactivation_hook() {}
			function is_admin() { return false; }
			try { require ' . var_export( $main, true ) . '; } catch ( \Throwable $e ) { echo json_encode( array( "error" => $e->getMessage() ) ); exit; }
			$user = get_defined_functions()["user"];
			$mine = array_values( array_filter( $user, static fn( $f ) => str_starts_with( $f, "buddynext" ) || str_starts_with( $f, "bn_" ) ) );
			echo json_encode( array(
				"constants" => array_values( array_filter( array_keys( get_defined_constants( true )["user"] ?? array() ), static fn( $c ) => str_starts_with( $c, "BUDDYNEXT" ) ) ),
				"functions" => $mine,
				"switch"    => function_exists( "buddynext_is_community_site" ) ? buddynext_is_community_site() : null,
			) );
		';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		$proc = proc_open( array( PHP_BINARY, '-n', '-r', $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$out  = (string) stream_get_contents( $pipes[1] );
		proc_close( $proc );
		$data = json_decode( $out, true );
		$this->assertIsArray( $data, 'Process output: ' . $out );
		$this->assertArrayNotHasKey( 'error', $data, (string) ( $data['error'] ?? '' ) );
		return $data;
	}

	public function test_a_subsite_defines_only_the_switch(): void {
		$r = $this->load_main_file( true, false );

		$this->assertSame( array(), $r['constants'], 'No BUDDYNEXT_* constant on a subsite.' );
		$this->assertSame( array( 'buddynext_is_community_site' ), $r['functions'] );
		$this->assertFalse( $r['switch'] );
	}

	public function test_the_main_file_holds_only_the_header_the_switch_and_the_bootstrap_require(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/buddynext.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		preg_match_all( '/^function\s+(\w+)/m', $src, $fns );
		$this->assertSame( array( 'buddynext_is_community_site' ), $fns[1], 'Any other top-level function would exist on a subsite.' );
		$this->assertStringNotContainsString( 'define(', $src );
		$this->assertMatchesRegularExpression( '/if \( buddynext_is_community_site\(\) \) \{\s*require __DIR__ \. \'\/bootstrap\.php\';\s*\}/', $src );
		$this->assertStringContainsString( 'Network: true', $src );
	}

	public function test_the_header_version_matches_the_constant_in_the_bootstrap(): void {
		$root   = dirname( __DIR__, 2 );
		$header = get_file_data( $root . '/buddynext.php', array( 'Version' => 'Version' ) )['Version'];
		$boot   = (string) file_get_contents( $root . '/bootstrap.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( "define( 'BUDDYNEXT_VERSION', '{$header}' );", $boot, 'Bump both when releasing: the header and the constant.' );
	}
}
