<?php
/**
 * Fail when code is not translation-READY: any language must be addable by anyone.
 *
 * Tokenizer-based checks over includes/, templates/, blocks/ and the main plugin file:
 *   domain        every translation call passes the literal plugin text domain (no variable, constant, other plugin).
 *   dynamic       the msgid is one literal: no variable, interpolation or concatenation inside __( ... ).
 *   fragments     no sentence glued from translated fragments: __( 'a' ) . $x . __( 'b' ).
 *   ordered       more than one placeholder in a msgid must be ordered (%1$s %2$s).
 *   translators   a msgid with a placeholder has a translators: comment before the call.
 *   plural        no "(s)" in a msgid and no `1 === $n ? __() : __()` ternary: use _n().
 *   js-register   a script that uses wp.i18n is registered with wp_set_script_translations() and the right domain.
 *   number        raw number_format() in markup: use number_format_i18n().
 *   loading       Text Domain + Domain Path headers, and load_plugin_textdomain() or auto-load via the headers.
 * Informational (never fails): near-duplicate msgids in the .pot, and physical left/right CSS counts (RTL).
 * Dates are gated by the time standard (raw date()/wp_date()), not repeated here. A complete .pot is
 * gated by bin/check-pot-fresh.php.
 *
 * Ratchet: bin/i18n-readiness.baseline holds today's findings ("file<TAB>check<TAB>hash"); new ones fail,
 * stale ones fail so the list only shrinks.
 *
 * Usage: php bin/check-i18n-readiness.php [plugin-dir] [text-domain] [--all] [--write-baseline]
 *
 * @package BuddyNext
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput, WordPress.WP.I18n, WordPress.WP.GlobalVariablesOverride -- CLI gate: no WordPress bootstrap, terminal output.

$flags  = array_filter( array_slice( $argv, 1 ), static fn( $a ) => 0 === strpos( $a, '--' ) );
$args   = array_values( array_diff( array_slice( $argv, 1 ), $flags ) );
$root   = rtrim( $args[0] ?? dirname( __DIR__ ), '/' );
$slug   = basename( $root );
$domain = $args[1] ?? ( is_file( "$root/languages/buddynext.pot" ) ? 'buddynext' : $slug );
$base   = $root . '/bin/i18n-readiness.baseline';

// Translation functions and the argument index of the text domain.
$fns = array(
	'__'         => 1,
	'_e'         => 1,
	'esc_html__' => 1,
	'esc_html_e' => 1,
	'esc_attr__' => 1,
	'esc_attr_e' => 1,
	'_x'         => 2,
	'_ex'        => 2,
	'esc_html_x' => 2,
	'esc_attr_x' => 2,
	'_n'         => 3,
	'_nx'        => 4,
	'_n_noop'    => 2,
	'_nx_noop'   => 3,
);

/**
 * Files with an extension under the given dirs, minus tests, dev, libs, vendor and minified.
 *
 * @param string   $root Plugin root.
 * @param string[] $dirs Relative dirs.
 * @param string   $ext  Extension.
 * @return string[]
 */
function bn_list( string $root, array $dirs, string $ext ): array {
	$out = array();
	foreach ( $dirs as $d ) {
		if ( ! is_dir( "$root/$d" ) ) {
			continue;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$d", FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			$p = $f->getPathname();
			if ( substr( $p, -strlen( $ext ) - 1 ) === ".$ext" && ! preg_match( '#/(tests|dev|libs|vendor|node_modules)/|\.min\.#', substr( $p, strlen( $root ) ) ) ) {
				$out[] = $p;
			}
		}
	}
	sort( $out );
	return $out;
}

$found = array(); // [rel, line, check, text].
$rel   = static fn( string $f ): string => substr( $f, strlen( $root ) + 1 );

foreach ( array_merge( bn_list( $root, array( 'includes', 'templates', 'blocks' ), 'php' ), glob( "$root/*.php" ) ) as $file ) {
	$t    = array();
	$tr   = array(); // End lines of translators: comments.
	$line = 1;
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $x ) {
		if ( is_array( $x ) ) {
			$line = $x[2];
			if ( in_array( $x[0], array( T_COMMENT, T_DOC_COMMENT ), true ) && false !== stripos( $x[1], 'translators:' ) ) {
				$tr[] = $line + substr_count( $x[1], "\n" );
			}
			if ( T_WHITESPACE === $x[0] ) {
				continue;
			}
			$t[] = array( $x[0], $x[1], $x[2] );
		} else {
			$t[] = array( $x, $x, $line );
		}
	}
	$n = count( $t );
	for ( $i = 0; $i < $n; $i++ ) {
		$k = $t[ $i ];
		if ( T_STRING !== $k[0] || ! isset( $fns[ $k[1] ] ) || ! isset( $t[ $i + 1 ] ) || '(' !== $t[ $i + 1 ][0] ) {
			continue;
		}
		if ( $i > 0 && in_array( $t[ $i - 1 ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
			continue; // A method or declaration, not the WordPress function.
		}
		// Split arguments at depth 0.
		$args_t = array();
		$cur    = array();
		$d      = 0;
		for ( $j = $i + 2; $j < $n; $j++ ) {
			$c = $t[ $j ];
			if ( in_array( $c[0], array( '(', '[', T_ARRAY ), true ) && T_ARRAY !== $c[0] ) {
				++$d;
			} elseif ( in_array( $c[0], array( ')', ']' ), true ) ) {
				if ( 0 === $d ) {
					break;
				}
				--$d;
			} elseif ( ',' === $c[0] && 0 === $d ) {
				$args_t[] = $cur;
				$cur      = array();
				continue;
			}
			if ( T_COMMENT !== $c[0] && T_DOC_COMMENT !== $c[0] ) {
				$cur[] = $c;
			}
		}
		if ( $cur ) {
			$args_t[] = $cur;
		}
		$end  = $j;
		$name = $k[1];
		$at   = $rel( $file );
		$ln   = $k[2];
		$text = '';
		// Domain.
		$di = $fns[ $name ];
		$da = $args_t[ $di ] ?? null;
		if ( null === $da || 1 !== count( $da ) || T_CONSTANT_ENCAPSED_STRING !== $da[0][0] || substr( $da[0][1], 1, -1 ) !== $domain ) {
			$found[] = array( $at, $ln, 'domain', "$name(): " . ( null === $da ? 'no text domain' : implode( '', array_column( $da, 1 ) ) ) );
		}
		// The msgid(s): argument 0 (and 1 for _n / _n_noop).
		$ids = in_array( $name, array( '_n', '_nx', '_n_noop', '_nx_noop' ), true ) ? array( 0, 1 ) : array( 0 );
		foreach ( $ids as $ai ) {
			$a = $args_t[ $ai ] ?? array();
			if ( 1 !== count( $a ) || T_CONSTANT_ENCAPSED_STRING !== $a[0][0] ) {
				$found[] = array( $at, $ln, 'dynamic', "$name(): " . substr( implode( ' ', array_column( $a, 1 ) ), 0, 80 ) );
				continue;
			}
			$id = substr( $a[0][1], 1, -1 );
			if ( preg_match( '/\(s\)|\(es\)/', $id ) ) {
				$found[] = array( $at, $ln, 'plural', $id );
			}
			preg_match_all( '/%(?:(\d+)\$)?[-+0#]*\d*(?:\.\d+)?[sdfu]/', str_replace( '%%', '', $id ), $pm );
			if ( count( $pm[0] ) > 1 && in_array( '', $pm[1], true ) ) {
				$found[] = array( $at, $ln, 'ordered', $id );
			}
			if ( $pm[0] && ! array_filter( $tr, static fn( $e ) => $e <= $ln && $e >= $ln - 4 ) ) {
				$found[] = array( $at, $ln, 'translators', $id );
			}
		}
		// Fragments: __( a ) . glue . __( b ) at the same depth. Glue that is only HTML string literals is layout, not a sentence.
		if ( isset( $t[ $end + 1 ] ) && '.' === $t[ $end + 1 ][0] ) {
			$sentence_glue = false;
			for ( $j = $end + 2, $d = 0; $j < $n; $j++ ) {
				$c = $t[ $j ];
				if ( in_array( $c[0], array( '(', '[' ), true ) ) {
					++$d;
				} elseif ( in_array( $c[0], array( ')', ']' ), true ) ) {
					if ( --$d < 0 ) {
						break;
					}
				} elseif ( 0 === $d && in_array( $c[0], array( ';', ',', '?', ':', T_DOUBLE_ARROW ), true ) ) {
					break;
				} elseif ( 0 === $d && T_STRING === $c[0] && isset( $fns[ $c[1] ] ) && '(' === ( $t[ $j + 1 ][0] ?? '' ) ) {
					if ( $sentence_glue ) {
						$found[] = array( $at, $ln, 'fragments', "$name() glued to another translated fragment" );
					}
					break;
				} elseif ( 0 === $d && '.' !== $c[0] && ! ( T_CONSTANT_ENCAPSED_STRING === $c[0] && preg_match( '/[<>]/', $c[1] ) ) ) {
					$sentence_glue = true; // A variable, call or plain-text literal between two translations.
				}
			}
		}
		// Ternary plural: `1 === $n ? __( ... ) : __( ... )` or `$n === 1 ? ...` right before this call.
		if ( $i >= 2 && '?' === $t[ $i - 1 ][0] ) {
			$low = max( 0, $i - 8 );
			for ( $j = $i - 2; $j >= $low; $j-- ) {
				if ( T_LNUMBER === $t[ $j ][0] && '1' === $t[ $j ][1] && preg_match( '/%\d*\$?[ds]|^.\d+ /', (string) ( $t[ $i + 2 ][1] ?? '' ) ) ) {
					$found[] = array( $at, $ln, 'plural', 'ternary on 1 picks between two translations: use _n()' );
					break;
				}
			}
		}
		$i = $end;
	}
	// Raw number_format() (not _i18n) in templates and views.
	foreach ( $t as $idx => $c ) {
		if ( T_STRING === $c[0] && 'number_format' === $c[1] && '(' === ( $t[ $idx + 1 ][0] ?? '' ) ) {
			$found[] = array( $rel( $file ), $c[2], 'number', 'number_format(): use number_format_i18n()' );
		}
	}
}


// Loading: headers and the textdomain being loaded.
$main = null;
foreach ( glob( "$root/*.php" ) as $f ) {
	if ( preg_match( '/^\s*\*?\s*Plugin Name:/m', substr( (string) file_get_contents( $f ), 0, 4000 ) ) ) {
		$main = $f;
		break;
	}
}
if ( $main ) {
	$head = substr( (string) file_get_contents( $main ), 0, 4000 );
	if ( ! preg_match( '/Text Domain:\s*' . preg_quote( $domain, '/' ) . '\s*$/m', $head ) ) {
		$found[] = array( basename( $main ), 1, 'loading', "header 'Text Domain: $domain' missing or different" );
	}
	if ( ! preg_match( '/Domain Path:\s*\/languages\s*$/m', $head ) ) {
		$found[] = array( basename( $main ), 1, 'loading', "header 'Domain Path: /languages' missing" );
	}
}
$src = '';
foreach ( array_merge( bn_list( $root, array( 'includes' ), 'php' ), glob( "$root/*.php" ) ) as $f ) {
	$src .= (string) file_get_contents( $f );
}
if ( ! preg_match( "/load_plugin_textdomain\(\s*['\"]" . preg_quote( $domain, '/' ) . "['\"]/", $src ) ) {
	$found[] = array( 'includes', 1, 'loading', "load_plugin_textdomain( '$domain', ... ) is never called" );
}

// JS: a file using wp.i18n must belong to a script registered with wp_set_script_translations( handle, domain ).
preg_match_all( "/wp_(?:register|enqueue)_script\(\s*['\"]([^'\"]+)['\"]\s*,[^;]*?['\"]([^'\"]*\.js)['\"]/s", $src . implode( '', array_map( 'file_get_contents', bn_list( $root, array( 'templates', 'blocks' ), 'php' ) ) ), $reg, PREG_SET_ORDER );
preg_match_all( "/wp_set_script_translations\(\s*['\"]([^'\"]+)['\"]\s*,\s*([^,]+),/", $src, $set, PREG_SET_ORDER );
$handles = array();
foreach ( $set as $s ) {
	$handles[ $s[1] ] = trim( $s[2], " '\"" );
}
$byfile = array();
foreach ( $reg as $r ) {
	$byfile[ basename( $r[2] ) ][] = $r[1];
}
foreach ( bn_list( $root, array( 'assets/js', 'blocks' ), 'js' ) as $f ) {
	if ( ! preg_match( '/@wordpress\/i18n|\bwp\.i18n\b/', (string) file_get_contents( $f ) ) || false !== strpos( $f, '/vendor/' ) ) {
		continue;
	}
	$ok = false;
	foreach ( $byfile[ basename( $f ) ] ?? array() as $h ) {
		$ok = $ok || ( isset( $handles[ $h ] ) && $domain === $handles[ $h ] );
	}
	// Blocks are registered from block.json: their editor script handle is set in BlockRegistrar.
	// A handle registered through a variable path ($url . $rel): accept a PHP file that names the script and sets translations for the domain.
	foreach ( bn_list( $root, array( 'includes' ), 'php' ) as $pf ) {
		$ps = (string) file_get_contents( $pf );
		$ok = $ok || ( false !== strpos( $ps, basename( $f ) ) && preg_match( "/wp_set_script_translations\([^;]*['\"]" . preg_quote( $domain, '/' ) . "['\"]/", $ps ) );
	}
	$ok = $ok || ( false !== strpos( $f, '/blocks' ) && isset( $handles['buddynext-blocks-editor'] ) );
	if ( ! $ok ) {
		$found[] = array( $rel( $f ), 1, 'js-register', "uses wp.i18n but no wp_set_script_translations( handle, '$domain' ) for a handle that loads it" );
	}
}

// Informational: near-duplicate msgids in the .pot (differ only by case or punctuation, same context).
$pot = "$root/languages/$domain.pot";
if ( is_file( $pot ) ) {
	preg_match_all( '/^(?:msgctxt ("(?:[^"\\\\]|\\\\.)*")\n)?msgid ((?:"(?:[^"\\\\]|\\\\.)*"\n?)+)/m', (string) file_get_contents( $pot ), $pm, PREG_SET_ORDER );
	$norm = array();
	foreach ( $pm as $m ) {
		$id             = preg_replace( '/"\s*\n\s*"/', '', trim( $m[2] ) );
		$key            = $m[1] . '|' . preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $id ) );
		$norm[ $key ][] = $id;
	}
	$dups = array_filter( $norm, static fn( $v ) => count( $v ) > 1 );
	printf( "info: %d near-duplicate msgid groups in %s (differ only by case or punctuation; examples: %s)\n", count( $dups ), basename( $pot ), implode( ' | ', array_map( static fn( $v ) => implode( ' ~ ', array_slice( $v, 0, 2 ) ), array_slice( $dups, 0, 3 ) ) ) );
}
// Informational: physical CSS properties (RTL).
$phys = 0;
$by   = array();
foreach ( bn_list( $root, array( 'assets/css' ), 'css' ) as $f ) {
	$c     = preg_match_all( '/(?<![-\w])(?:margin|padding|border)-(?:left|right)\b|(?<![-\w])(?:left|right)\s*:|text-align\s*:\s*(?:left|right)|float\s*:\s*(?:left|right)/', (string) file_get_contents( $f ) );
	$phys += $c;
	if ( $c ) {
		$by[ $rel( $f ) ] = $c;
	}
}
arsort( $by );
printf( "info: %d physical left/right declarations in assets/css (RTL; top: %s)\n", $phys, implode( ', ', array_map( static fn( $k, $v ) => "$k=$v", array_slice( array_keys( $by ), 0, 3 ), array_slice( $by, 0, 3 ) ) ) );

$hash = static fn( string $x ): string => substr( sha1( preg_replace( '/\s+/', ' ', trim( $x ) ) ), 0, 10 );
$now  = array();
foreach ( $found as $r ) {
	$now[ $r[0] . "\t" . $r[2] . "\t" . $hash( $r[3] ) ] = $r;
}
if ( in_array( '--write-baseline', $flags, true ) ) {
	$keys = array_keys( $now );
	sort( $keys );
	file_put_contents( $base, implode( "\n", $keys ) . "\n" );
	printf( "wrote %d entries to %s\n", count( $keys ), $base );
	exit( 0 );
}
$known = is_file( $base ) && ! in_array( '--all', $flags, true ) ? array_flip( array_filter( explode( "\n", (string) file_get_contents( $base ) ) ) ) : array();
$new   = array_diff_key( $now, $known );
$gone  = array_diff_key( $known, $now );
foreach ( $new as $r ) {
	fwrite( STDERR, sprintf( "%s:%d:%s: %s\n", $r[0], $r[1], $r[2], substr( str_replace( "\n", '\n', $r[3] ), 0, 100 ) ) );
}
foreach ( array_keys( $gone ) as $g ) {
	fwrite( STDERR, "baseline entry no longer found (delete it): $g\n" );
}
if ( $new || $gone ) {
	fwrite( STDERR, sprintf( "FAIL: %d new translation-readiness finding(s), %d stale baseline entries. Fix the code (see TRANSLATION-READINESS.md).\n", count( $new ), count( $gone ) ) );
	exit( 1 );
}
printf( "OK: no new readiness findings (%d baselined)\n", count( $known ) );
