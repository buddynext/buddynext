<?php
/**
 * Store-merge collision gate.
 *
 * `store('ns', {...})` MERGES: when two files register the same namespace, the
 * one that loads last wins on any key both define — silently. Safe today by
 * accident (the four current same-key overlaps are all editor-vs-frontend), but
 * the JS-organisation rule invites deliberate splits, which make collisions
 * likely by design. This fails the build when two FRONTEND-CO-LOADABLE files
 * define the same top-level state/actions/callbacks key in one namespace without
 * a declared module dependency ordering them.
 *
 * Why co-loadability matters: `assets/js/blocks.js` is the block EDITOR script
 * (registered as editorScript, handle buddynext-blocks-editor), never enqueued on
 * the front end. Its store() calls never run alongside the frontend modules, so a
 * collision there is not a runtime collision. A file counts as frontend only when
 * it is a registered feature module in AssetService (the modules PageRouter
 * enqueues). Editor-only files are reported for context but never fail the gate.
 *
 * Conservative: it flags a candidate; a human confirms whether the shared key is a
 * genuine clash or a deliberate override with a declared dependency.
 *
 *   php bin/check-store-collisions.php        # exit 1 on an undeclared collision
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput -- CLI gate: reads plugin source from local disk, prints a report.

$bn_dir = dirname( __DIR__ );

/**
 * Slice a balanced brace block starting at the first { at/after $from.
 *
 * @param string $s    Source.
 * @param int    $from Offset.
 * @return string Block including braces, or '' if unbalanced.
 */
$brace = static function ( string $s, int $from ): string {
	$i = strpos( $s, '{', $from );
	if ( false === $i ) {
		return '';
	}
	$d = 0;
	for ( $j = $i, $n = strlen( $s ); $j < $n; $j++ ) {
		if ( '{' === $s[ $j ] ) {
			++$d;
		} elseif ( '}' === $s[ $j ] ) {
			--$d;
			if ( 0 === $d ) {
				return substr( $s, $i, $j - $i + 1 );
			}
		}
	}
	return '';
};

/**
 * Top-level keys of a state/actions/callbacks section inside a store body.
 *
 * @param string   $body  The store's { … } body.
 * @param string   $sec   'state' | 'actions' | 'callbacks'.
 * @param callable $brace Brace slicer.
 * @return string[]
 */
$section_keys = static function ( string $body, string $sec, callable $brace ): array {
	if ( ! preg_match( '/\b' . $sec . '\s*:\s*\{/', $body, $m, PREG_OFFSET_CAPTURE ) ) {
		return array();
	}
	$block = $brace( $body, $m[0][1] );
	$keys  = array();
	$depth = 0;
	foreach ( explode( "\n", $block ) as $line ) {
		$t = trim( $line );
		if ( 1 === $depth && preg_match( '/^(?:async\s+|\*\s*|get\s+|set\s+)?([A-Za-z_$][\w$]*)\s*[:(]/', $t, $k ) ) {
			$kw = $k[1];
			if ( ! in_array( $kw, array( 'if', 'for', 'return', 'const', 'let', 'var', 'try', 'catch', 'yield', 'function', 'while', 'switch', 'await', 'do', 'else' ), true ) ) {
				$keys[] = $kw;
			}
		}
		$depth += substr_count( $line, '{' ) - substr_count( $line, '}' );
	}
	return array_values( array_unique( $keys ) );
};

// ── frontend-loadable module files (from AssetService feature_modules) ───────
$assets   = (string) file_get_contents( $bn_dir . '/includes/Core/AssetService.php' );
$frontend = array();
if ( preg_match_all( "/'@buddynext\/[a-z-]+'\s*=>\s*'([a-z0-9\/-]+)'/", $assets, $fm, PREG_SET_ORDER ) ) {
	foreach ( $fm as $r ) {
		$frontend[ 'assets/js/' . $r[1] . '.js' ] = true;
	}
}

// ── declared feature-to-feature deps so an ordered pair is exempt ────────────
//
// The baseline deps every module gets (interactivity, rest-client, nav-init) are
// not feature-to-feature ordering and are ignored. A real ordering is declared as
// a conditional add: `if ( '@buddynext/x' === $id ) { $deps[] = array( 'id' =>
// '@buddynext/y' ) }`. Collect those pairs (symmetric — either order defines the
// merge sequence between the two).
$declared = array();
if ( preg_match_all( "/'(@buddynext\/[a-z-]+)'\s*===\s*\\\$id.*?\\\$deps\[\]\s*=\s*array\(\s*'id'\s*=>\s*'(@buddynext\/[a-z-]+)'/s", $assets, $cd, PREG_SET_ORDER ) ) {
	foreach ( $cd as $r ) {
		$declared[ $r[1] ][] = $r[2];
		$declared[ $r[2] ][] = $r[1];
	}
}

// ── parse every store file: file => ns => { keys } ───────────────────────────
$per_file = array();
$rii      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $bn_dir . '/assets/js', FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $fi ) {
	if ( 'js' !== strtolower( $fi->getExtension() ) || false !== strpos( $fi->getPathname(), '/vendor/' ) ) {
		continue;
	}
	$rel = 'assets/js/' . ltrim( str_replace( $bn_dir . '/assets/js', '', $fi->getPathname() ), '/' );
	$src = (string) file_get_contents( $fi->getPathname() );
	if ( ! preg_match_all( "/store\(\s*'([^']+)'\s*,/", $src, $sm, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $sm[1] as $k => $nsm ) {
		$ns                      = $nsm[0];
		$body                    = $brace( $src, $sm[0][ $k ][1] );
		$keys                    = array_merge(
			$section_keys( $body, 'state', $brace ),
			$section_keys( $body, 'actions', $brace ),
			$section_keys( $body, 'callbacks', $brace )
		);
		$per_file[ $rel ][ $ns ] = array_values( array_unique( array_merge( $per_file[ $rel ][ $ns ] ?? array(), $keys ) ) );
	}
}

// ── namespace => files that register it ──────────────────────────────────────
$ns_files = array();
foreach ( $per_file as $file => $nss ) {
	foreach ( $nss as $ns => $keys ) {
		$ns_files[ $ns ][ $file ] = $keys;
	}
}

// module id for a file, to check declared deps.
$file_module = array();
if ( preg_match_all( "/'(@buddynext\/[a-z-]+)'\s*=>\s*'([a-z0-9\/-]+)'/", $assets, $mm2, PREG_SET_ORDER ) ) {
	foreach ( $mm2 as $r ) {
		$file_module[ 'assets/js/' . $r[2] . '.js' ] = $r[1];
	}
}

$fail    = array();
$context = array();
foreach ( $ns_files as $ns => $files ) {
	if ( count( $files ) < 2 ) {
		continue;
	}
	$reg_files = array_keys( $files );
	$count     = count( $reg_files );
	for ( $x = 0; $x < $count; $x++ ) {
		for ( $y = $x + 1; $y < $count; $y++ ) {
			$fa     = $reg_files[ $x ];
			$fb     = $reg_files[ $y ];
			$shared = array_values( array_intersect( $files[ $fa ], $files[ $fb ] ) );
			if ( ! $shared ) {
				continue;
			}
			if ( ! ( isset( $frontend[ $fa ] ) && isset( $frontend[ $fb ] ) ) ) {
				$context[] = sprintf( '  %s : %s vs %s share [%s] - but one is editor-only (never co-loads), not a runtime collision', $ns, $fa, $fb, implode( ', ', $shared ) );
				continue;
			}
			$ma           = $file_module[ $fa ] ?? '';
			$mb           = $file_module[ $fb ] ?? '';
			$dep_declared = ( isset( $declared[ $ma ] ) && in_array( $mb, $declared[ $ma ], true ) )
				|| ( isset( $declared[ $mb ] ) && in_array( $ma, $declared[ $mb ], true ) );
			if ( $dep_declared ) {
				$context[] = sprintf( '  %s : %s vs %s share [%s] - but a module dependency is declared, so order is defined', $ns, $fa, $fb, implode( ', ', $shared ) );
				continue;
			}
			$fail[] = sprintf( "  %s\n     %s\n     %s\n     shared keys: %s\n     -> declare a module dependency between %s and %s, or rename one side.", $ns, $fa, $fb, implode( ', ', $shared ), $ma ? $ma : $fa, $mb ? $mb : $fb );
		}
	}
}

if ( $context ) {
	fwrite( STDOUT, "store-collisions: safe overlaps (recorded, not failures):\n" . implode( "\n", $context ) . "\n\n" );
}
if ( $fail ) {
	fwrite( STDERR, "store-collisions: UNDECLARED same-key collision on a co-loading namespace:\n" . implode( "\n", $fail ) . "\n" );
	exit( 1 );
}
fwrite( STDOUT, "store-collisions: clean — no undeclared same-key collision between co-loading modules\n" );
exit( 0 );
