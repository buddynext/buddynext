<?php
/**
 * Surface-map generator — the store-per-hub contract.
 *
 * Emits, from source (no runtime, no browser), the mapping that a refactor or a
 * new feature must not silently break:
 *
 *     hub (PageRouter case) -> module (@buddynext/*) -> file -> namespace -> actions
 *
 * There was no such index. The manifest records frontend_assets as a flat file
 * list, so "which store loads on which surface, and what does it wire" was
 * answered by hand — and got it wrong (people and post hubs missed; two enqueue
 * comments claimed CSS-only for a behavioural dependency). This makes the answer
 * regenerable and diffable instead.
 *
 * Three parse targets, all static:
 *   1. PageRouter::enqueue_hub_assets()  -> hub case -> enqueue('feature') calls
 *   2. AssetService feature_modules map  -> '@buddynext/slug' => 'dir/file'
 *   3. each assets/js store file         -> store('namespace', {...}) -> action keys
 *
 * Output: audit/surface-map.json (BN's audit/ is gitignored-local; the canonical
 * copy lives on the Pro shelf, same as the manifest). Run:
 *
 *     php bin/build-surface-map.php            # writes + prints summary
 *     php bin/build-surface-map.php --check    # exits 1 if the committed map is stale
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput -- CLI build tool: reads plugin source from local disk and writes a JSON artifact.

$bn_dir = dirname( __DIR__ );

/**
 * Slice a balanced { … } block starting at the first brace at/after $from.
 *
 * @param string $s    Source.
 * @param int    $from Offset to start searching for the opening brace.
 * @return string The block including its braces, or '' if unbalanced.
 */
$bn_brace_block = static function ( string $s, int $from ): string {
	$i = strpos( $s, '{', $from );
	if ( false === $i ) {
		return '';
	}
	$depth = 0;
	for ( $j = $i, $n = strlen( $s ); $j < $n; $j++ ) {
		if ( '{' === $s[ $j ] ) {
			++$depth;
		} elseif ( '}' === $s[ $j ] ) {
			--$depth;
			if ( 0 === $depth ) {
				return substr( $s, $i, $j - $i + 1 );
			}
		}
	}
	return '';
};

// ── 1. hub case -> enqueued feature slugs ────────────────────────────────────
$router = (string) file_get_contents( $bn_dir . '/includes/Core/PageRouter.php' );
if ( ! preg_match( '/function enqueue_hub_assets\b/', $router, $mm, PREG_OFFSET_CAPTURE ) ) {
	fwrite( STDERR, "surface-map: enqueue_hub_assets() not found\n" );
	exit( 1 );
}
$router_body  = $bn_brace_block( $router, $mm[0][1] );
$hub_enqueues = array();
$current_hub  = '(default)';
foreach ( explode( "\n", $router_body ) as $line ) {
	if ( preg_match( "/case '([a-z_-]+)':/", $line, $c ) ) {
		$current_hub = $c[1];
	}
	if ( preg_match( "/\\\$assets->enqueue\(\s*'([a-z-]+)'\s*\)/", $line, $e ) ) {
		$hub_enqueues[ $current_hub ][] = $e[1];
	}
}
foreach ( $hub_enqueues as $h => $list ) {
	$hub_enqueues[ $h ] = array_values( array_unique( $list ) );
}

// ── 2. module id -> store file ───────────────────────────────────────────────
$assets      = (string) file_get_contents( $bn_dir . '/includes/Core/AssetService.php' );
$module_file = array();
if ( preg_match( '/\$feature_modules\s*=\s*array\(/', $assets, $am, PREG_OFFSET_CAPTURE ) ) {
	$block = $bn_brace_block( '{' . substr( $assets, $am[0][1] + strlen( $am[0][0] ) ), 0 );
	if ( preg_match_all( "/'(@buddynext\/[a-z-]+)'\s*=>\s*'([a-z0-9\/-]+)'/", $block, $rows, PREG_SET_ORDER ) ) {
		foreach ( $rows as $r ) {
			$module_file[ $r[1] ] = 'assets/js/' . $r[2] . '.js';
		}
	}
}

// ── 3. store file -> namespace -> actions (2-arg store() calls only) ─────────
// Keyed file => [ namespace => [action keys] ]. Walked recursively; PHP glob()
// does not support ** so a RecursiveIterator is used.
$store_namespaces = array();
$rii              = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $bn_dir . '/assets/js', FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $fileinfo ) {
	if ( 'js' !== strtolower( $fileinfo->getExtension() ) || false !== strpos( $fileinfo->getPathname(), '/vendor/' ) ) {
		continue;
	}
	$rel = 'assets/js/' . ltrim( str_replace( $bn_dir . '/assets/js', '', $fileinfo->getPathname() ), '/' );
	$src = (string) file_get_contents( $fileinfo->getPathname() );
	// 2-arg store('ns', { ... }) — a registration, not a read.
	if ( ! preg_match_all( "/store\(\s*'([^']+)'\s*,/", $src, $sm, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $sm[1] as $k => $nsmatch ) {
		$ns  = $nsmatch[0];
		$blk = $bn_brace_block( $src, $sm[0][ $k ][1] );
		if ( ! preg_match( '/\bactions\s*:\s*\{/', $blk, $abm, PREG_OFFSET_CAPTURE ) ) {
			$store_namespaces[ $rel ][ $ns ] = array();
			continue;
		}
		$actions_blk = $bn_brace_block( $blk, $abm[0][1] );
		$keys        = array();
		$depth       = 0;
		foreach ( explode( "\n", $actions_blk ) as $l ) {
			$trim = trim( $l );
			if ( 1 === $depth && preg_match( '/^(?:async\s+|\*\s*|get\s+)?([A-Za-z_$][\w$]*)\s*[:(]/', $trim, $km ) ) {
				$kw = $km[1];
				if ( ! in_array( $kw, array( 'if', 'for', 'return', 'const', 'let', 'var', 'try', 'catch', 'yield', 'function', 'while', 'switch', 'await' ), true ) ) {
					$keys[] = $kw;
				}
			}
			$depth += substr_count( $l, '{' ) - substr_count( $l, '}' );
		}
		$prev                            = $store_namespaces[ $rel ][ $ns ] ?? array();
		$store_namespaces[ $rel ][ $ns ] = array_values( array_unique( array_merge( $prev, $keys ) ) );
	}
}

// ── join: namespace -> files that register it ────────────────────────────────
$ns_files = array();
foreach ( $store_namespaces as $file => $nss ) {
	foreach ( $nss as $ns => $acts ) {
		$ns_files[ $ns ][] = $file;
	}
}

// ── assemble the contract ────────────────────────────────────────────────────
$slug_to_module = static function ( string $slug ): string {
	return '@buddynext/' . $slug;
};
$contract       = array(
	'generated_from'        => 'source (PageRouter::enqueue_hub_assets + AssetService feature_modules + store() calls)',
	'hubs'                  => array(),
	'namespaces'            => array(),
	'multi_file_namespaces' => array(),
);
// A module's entry file may split its store() registrations across relative-
// imported sub-files (import './share-modal.js') that WP serves but never
// registers. Follow that import graph so the hub view stays complete after a
// split — otherwise extracting a namespace into a sub-file makes it vanish from
// the map even though it still loads with the module.
$import_graph = static function ( ?string $entry ) use ( $bn_dir ): array {
	if ( null === $entry ) {
		return array();
	}
	$seen  = array();
	$stack = array( $entry );
	while ( $stack ) {
		$rel = array_pop( $stack );
		if ( isset( $seen[ $rel ] ) ) {
			continue;
		}
		$seen[ $rel ] = true;
		$abs          = $bn_dir . '/' . $rel;
		if ( ! is_readable( $abs ) ) {
			continue;
		}
		$src = (string) file_get_contents( $abs );
		if ( preg_match_all( "/from\s+'(\.[^']+\.js)'/", $src, $im ) ) {
			$base = dirname( $rel );
			foreach ( $im[1] as $spec ) {
				$resolved = $base . '/' . $spec;
				// Normalise ../ and ./ segments.
				$parts = array();
				foreach ( explode( '/', $resolved ) as $seg ) {
					if ( '..' === $seg ) {
						array_pop( $parts );
					} elseif ( '.' !== $seg && '' !== $seg ) {
						$parts[] = $seg;
					}
				}
				$stack[] = implode( '/', $parts );
			}
		}
	}
	return array_keys( $seen );
};

foreach ( $hub_enqueues as $hub => $features ) {
	$mods = array();
	foreach ( $features as $slug ) {
		$mid  = $slug_to_module( $slug );
		$file = $module_file[ $mid ] ?? null;
		// Namespaces from the entry file AND every relative-imported sub-file.
		$ns_in_graph = array();
		foreach ( $import_graph( $file ) as $graph_file ) {
			if ( isset( $store_namespaces[ $graph_file ] ) ) {
				$ns_in_graph = array_merge( $ns_in_graph, array_keys( $store_namespaces[ $graph_file ] ) );
			}
		}
		$mods[ $mid ] = array(
			'file'       => $file,
			'namespaces' => array_values( array_unique( $ns_in_graph ) ),
		);
	}
	$contract['hubs'][ $hub ] = $mods;
}
foreach ( $store_namespaces as $file => $nss ) {
	foreach ( $nss as $ns => $acts ) {
		$contract['namespaces'][ $ns ][ $file ] = $acts;
	}
}
foreach ( $ns_files as $ns => $files ) {
	$u = array_values( array_unique( $files ) );
	if ( count( $u ) > 1 ) {
		$contract['multi_file_namespaces'][ $ns ] = $u;
	}
}
ksort( $contract['hubs'] );
ksort( $contract['namespaces'] );
ksort( $contract['multi_file_namespaces'] );

$json = wp_json_encode_or_native( $contract );

$out_path = $bn_dir . '/audit/surface-map.json';
$check    = in_array( '--check', $argv, true );

if ( $check ) {
	if ( ! is_readable( $out_path ) ) {
		fwrite( STDERR, "surface-map: no committed map at $out_path — run without --check first\n" );
		exit( 1 );
	}
	$committed = (string) file_get_contents( $out_path );
	if ( trim( $committed ) !== trim( $json ) ) {
		fwrite( STDERR, "surface-map: STALE — enqueue map, a store namespace, or an action changed. Regenerate: php bin/build-surface-map.php\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "surface-map: up to date\n" );
	exit( 0 );
}

@mkdir( dirname( $out_path ), 0755, true );
file_put_contents( $out_path, $json . "\n" );

// Summary printed after a write.
$feed_hubs = array();
foreach ( $contract['hubs'] as $hub => $mods ) {
	if ( isset( $mods['@buddynext/feed'] ) ) {
		$feed_hubs[] = $hub;
	}
}
printf( "surface-map: wrote %s\n", str_replace( $bn_dir . '/', '', $out_path ) );
printf( "  hubs: %d, namespaces: %d, multi-file namespaces: %d\n", count( $contract['hubs'] ), count( $contract['namespaces'] ), count( $contract['multi_file_namespaces'] ) );
printf( "  @buddynext/feed loads on hubs: %s\n", implode( ', ', $feed_hubs ) );

/**
 * JSON encode with WP's helper when available, else native.
 *
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode_or_native( $data ): string {
	if ( function_exists( 'wp_json_encode' ) ) {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
	return (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}
