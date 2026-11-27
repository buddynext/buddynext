<?php
/**
 * Fail on user-facing English that is not wrapped in a translation function.
 *
 * PHP (templates/, includes/, blocks/) is read with token_get_all(): echo/print literals, inline HTML
 * text nodes and text attributes, label/title/description/message/text style array values, the message of
 * new WP_Error() and add_settings_error(). JS (assets/js) is read with a small string tokenizer: literals
 * assigned to textContent/innerHTML/placeholder/title, passed to setAttribute(aria-label...) or bnToast(),
 * and t( key, fallback ) calls whose key PHP never injects (a fallback is only fine when PHP supplies the key).
 *
 * Ratchet: bin/unwrapped-strings.baseline lists today's findings as "file<TAB>hash". Anything not in it
 * fails; an entry that no longer matches also fails, so the list only shrinks.
 *
 * Usage: php bin/check-unwrapped-strings.php [plugin-dir] [text-domain] [--all] [--write-baseline]
 *   --all             list every finding, ignore the baseline.
 *   --write-baseline  rewrite the baseline from the current findings (triage them first).
 *
 * @package BuddyNext
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput, WordPress.WP.GlobalVariablesOverride -- CLI gate: no WordPress bootstrap, terminal output.

$args  = array_values( array_filter( array_slice( $argv, 1 ), static fn( $a ) => 0 !== strpos( $a, '--' ) ) );
$flags = array_filter( array_slice( $argv, 1 ), static fn( $a ) => 0 === strpos( $a, '--' ) );
$root  = rtrim( $args[0] ?? dirname( __DIR__ ), '/' );
$base  = $root . '/bin/unwrapped-strings.baseline';

/**
 * Every .php / .js file under the shipped roots, minus tests, dev, libs, vendor, minified.
 *
 * @param string $root Plugin root.
 * @param string $ext  php or js.
 * @return string[]
 */
function bn_files( string $root, string $ext ): array {
	$out = array();
	foreach ( 'php' === $ext ? array( 'templates', 'includes', 'blocks' ) : array( 'assets/js' ) as $d ) {
		if ( ! is_dir( "$root/$d" ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$d", FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$p = $f->getPathname();
			// CLI commands, demo seeders and the cert runner print to a terminal for developers, not to members.
			if ( substr( $p, -strlen( $ext ) - 1 ) === ".$ext" && ! preg_match( '#/(tests|dev|libs|vendor|node_modules|Demo|Cert)/|\.min\.js$|Command\.php$#', substr( $p, strlen( $root ) ) ) ) {
				$out[] = $p;
			}
		}
	}
	sort( $out );
	return $out;
}

/**
 * True when the text reads like English a person sees (not an identifier, class list, URL, path or format-only string).
 *
 * @param string $s Candidate text.
 * @return bool
 */
function bn_english( string $s ): bool {
	$s = trim( preg_replace( '/\{\{[^}]*\}\}|%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[sdfu]|&#?\w+;|\$\{[^}]*\}/', ' ', html_entity_decode( $s ) ) );
	$s = trim( preg_replace( '/\s+/', ' ', $s ) );
	if ( preg_match( '/\w+="[^"]*"|[a-z-]+:\s*[^;]+;|^(AND|ADD|SELECT|FROM|WHERE|UPDATE|ALTER) /', $s ) && ! preg_match( '/</', $s ) ) {
		return false; // Attribute fragments, CSS declarations and SQL are code.
	}
	if ( ! preg_match( '/[A-Za-z]{3,}/', $s ) || preg_match( '#^(https?:|/|\#|\.|[a-z0-9_.-]+\.(php|js|css|png|svg|json)$)#i', $s ) ) {
		return false;
	}
	if ( false === strpos( $s, ' ' ) ) {
		// One word: only a Capitalised plain word reads as a label (Save), not snake_case, kebab-case, camelCase or ALL_CAPS.
		return (bool) preg_match( '/^[A-Z][a-z]{2,}[.!?:]?$/', $s );
	}
	$words = explode( ' ', $s );
	$ident = 0;
	foreach ( $words as $w ) {
		if ( preg_match( '/^[a-z0-9_.:\/\[\]=-]+$/', $w ) && preg_match( '/[-_.:\/\[\]=\d]/', $w ) ) {
			++$ident;
		}
	}
	return $ident < count( $words ) && ! preg_match( '/^[a-z]+(?:[-_][a-z0-9]+)+(?: [a-z]+(?:[-_][a-z0-9]+)*)*$/', $s );
}

/**
 * English candidates in an HTML fragment: text nodes plus text-bearing attribute values. State carries across PHP islands.
 *
 * @param string $html Fragment.
 * @param array  $st   Lexer state, carried between fragments.
 * @return string[]
 */
function bn_html_candidates( string $html, array &$st ): array {
	$out = array();
	$n   = strlen( $html );
	$i   = 0;
	$buf = '';
	while ( $i < $n ) {
		$mode = $st['mode'];
		if ( 'text' === $mode ) {
			$lt   = strpos( $html, '<', $i );
			$buf .= substr( $html, $i, false === $lt ? $n - $i : $lt - $i );
			if ( false === $lt ) {
				break;
			}
			$i = $lt;
			if ( '' !== trim( $buf ) && bn_english( $buf ) ) {
				$out[] = trim( $buf );
			}
			$buf = '';
			if ( 0 === strpos( $html, '<!--', $i ) ) {
				$st['mode'] = 'comment';
			} elseif ( preg_match( '/\G<(script|style)\b/i', $html, $m, 0, $i ) ) {
				$st['mode'] = 'tag';
				$st['raw']  = strtolower( $m[1] );
			} else {
				$st['mode'] = 'tag';
				$st['raw']  = '';
			}
			$st['tag'] = '';
			++$i;
		} elseif ( 'comment' === $mode ) {
			$e = strpos( $html, '-->', $i );
			if ( false === $e ) {
				break;
			}
			$i          = $e + 3;
			$st['mode'] = 'text';
		} elseif ( 'tag' === $mode ) {
			$gt         = strpos( $html, '>', $i );
			$st['tag'] .= substr( $html, $i, false === $gt ? $n - $i : $gt - $i );
			if ( false === $gt ) {
				break;
			}
			$i = $gt + 1;
			if ( preg_match_all( '/\s((?:placeholder|title|alt|aria-label|aria-description|aria-placeholder|data-[a-z-]*(?:label|text|title|message|msg|placeholder|confirm|tooltip)[a-z-]*))\s*=\s*"([^"<]*)"/i', $st['tag'], $am, PREG_SET_ORDER ) ) {
				foreach ( $am as $a ) {
					if ( bn_english( $a[2] ) ) {
						$out[] = $a[1] . '="' . $a[2] . '"';
					}
				}
			}
			$st['mode'] = $st['raw'] && '/' !== substr( $st['tag'], -1 ) ? 'raw' : 'text';
		} else { // raw: inside a script or style element until its closing tag.
			if ( ! preg_match( '#</' . $st['raw'] . '\s*>#i', $html, $m, PREG_OFFSET_CAPTURE, $i ) ) {
				break;
			}
			$i          = $m[0][1] + strlen( $m[0][0] );
			$st['mode'] = 'text';
		}
	}
	if ( 'text' === $st['mode'] && '' !== trim( $buf ) && bn_english( $buf ) ) {
		$out[] = trim( $buf );
	}
	return $out;
}

/**
 * Scan one PHP file.
 *
 * @param string $file Path.
 * @return array<int, array{int,string}> [line, literal].
 */
function bn_scan_php( string $file ): array {
	$tok = token_get_all( (string) file_get_contents( $file ) );
	// Drop whitespace/comments, keep line numbers.
	$t    = array();
	$line = 1;
	foreach ( $tok as $x ) {
		if ( is_array( $x ) ) {
			$line = $x[2];
			if ( in_array( $x[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$t[] = array( $x[0], $x[1], $x[2] );
		} else {
			$t[] = array( $x, $x, $line );
		}
	}
	$str   = static function ( $tk ) {
		return T_CONSTANT_ENCAPSED_STRING === $tk[0] ? substr( $tk[1], 1, -1 ) : ( T_ENCAPSED_AND_WHITESPACE === $tk[0] ? $tk[1] : null );
	};
	$keys  = array( 'label', 'title', 'description', 'message', 'text', 'placeholder', 'hint', 'subject', 'heading', 'tooltip', 'help', 'preview', 'body', 'cta', 'empty_text', 'button_label' );
	$out   = array();
	$st    = array(
		'mode' => 'text',
		'tag'  => '',
		'raw'  => '',
	);
	$count = count( $t );
	for ( $i = 0; $i < $count; $i++ ) {
		$k = $t[ $i ];
		if ( T_INLINE_HTML === $k[0] ) {
			foreach ( bn_html_candidates( $k[1], $st ) as $c ) {
				$out[] = array( $k[2], $c );
			}
			continue;
		}
		// echo / print literals at paren depth 0.
		if ( in_array( $k[0], array( T_ECHO, T_PRINT ), true ) ) {
			$d   = 0;
			$st2 = array(
				'mode' => 'text',
				'tag'  => '',
				'raw'  => '',
			);
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$c = $t[ $j ];
				if ( '(' === $c[0] || '[' === $c[0] || T_ARRAY === $c[0] ) {
					++$d;
				} elseif ( ')' === $c[0] || ']' === $c[0] ) {
					--$d;
				} elseif ( ';' === $c[0] || T_CLOSE_TAG === $c[0] ) {
					break;
				}
				$s = $d <= 0 ? $str( $c ) : null;
				if ( null !== $s ) {
					foreach ( bn_html_candidates( $s, $st2 ) as $x ) {
						$out[] = array( $c[2], $x );
					}
				}
			}
			continue;
		}
		// Literal first argument of an escaper, or sprintf/printf/wp_die format: esc_html( 'Text' ).
		if ( T_STRING === $k[0] && isset( $t[ $i + 2 ] ) && '(' === $t[ $i + 1 ][0] && in_array( $k[1], array( 'esc_html', 'esc_attr', 'wp_die', 'printf' ), true ) ) {
			$s = $str( $t[ $i + 2 ] );
			if ( null !== $s && ! in_array( $k[1], array( 'esc_attr' ), true ) ) {
				$st3 = array(
					'mode' => 'text',
					'tag'  => '',
					'raw'  => '',
				);
				foreach ( bn_html_candidates( $s, $st3 ) as $x ) {
					$out[] = array( $t[ $i + 2 ][2], $x );
				}
			}
		}
		// The shape: label key, fat arrow, literal.
		if ( T_CONSTANT_ENCAPSED_STRING === $k[0] && isset( $t[ $i + 3 ] ) && ! ( "'description'" === $k[1] && preg_match( '#(Controller|ResponseSchema)\.php$#', $file ) ) && T_DOUBLE_ARROW === $t[ $i + 1 ][0] && in_array( substr( $k[1], 1, -1 ), $keys, true ) ) {
			$s = $str( $t[ $i + 2 ] );
			if ( null !== $s && in_array( $t[ $i + 3 ][0], array( ',', ')', ']' ), true ) && bn_english( strip_tags( $s ) ) ) {
				$out[] = array( $t[ $i + 2 ][2], $s );
			}
		}
		// WP_Error message (2nd arg) and add_settings_error message (3rd arg).
		$skip = null;
		if ( T_NEW === $k[0] && isset( $t[ $i + 2 ] ) && 'WP_Error' === $t[ $i + 1 ][1] && '(' === $t[ $i + 2 ][0] ) {
			$skip = 1;
			$p    = $i + 3;
		} elseif ( T_STRING === $k[0] && 'add_settings_error' === $k[1] && isset( $t[ $i + 1 ] ) && '(' === $t[ $i + 1 ][0] ) {
			$skip = 2;
			$p    = $i + 2;
		}
		if ( null !== $skip ) {
			$d = 0;
			$a = 0;
			for ( $j = $p; $j < $count; $j++ ) {
				$c = $t[ $j ];
				if ( '(' === $c[0] || '[' === $c[0] || T_ARRAY === $c[0] ) {
					++$d;
				} elseif ( ')' === $c[0] || ']' === $c[0] ) {
					if ( --$d < 0 ) {
						break;
					}
				} elseif ( ',' === $c[0] && 0 === $d ) {
					++$a;
					continue;
				}
				if ( 0 === $d && $a === $skip ) {
					$s = $str( $c );
					if ( null !== $s && bn_english( $s ) ) {
						$out[] = array( $c[2], $s );
					}
				}
			}
		}
	}
	return $out;
}

/**
 * Tokenise JS into [type, value, line] with type s (string), t (template), p (punct), w (word). Comments dropped.
 *
 * @param string $src JS source.
 * @return array<int, array{string,string,int}>
 */
function bn_js_tokens( string $src ): array {
	$out  = array();
	$n    = strlen( $src );
	$line = 1;
	$prev = '';
	for ( $i = 0; $i < $n; ) {
		$c = $src[ $i ];
		if ( "\n" === $c ) {
			++$line;
			++$i;
		} elseif ( ctype_space( $c ) ) {
			++$i;
		} elseif ( '/' === $c && '/' === ( $src[ $i + 1 ] ?? '' ) ) {
			$e = strpos( $src, "\n", $i );
			$i = false === $e ? $n : $e;
		} elseif ( '/' === $c && '*' === ( $src[ $i + 1 ] ?? '' ) ) {
			$e     = strpos( $src, '*/', $i + 2 );
			$e     = false === $e ? $n : $e + 2;
			$line += substr_count( $src, "\n", $i, $e - $i );
			$i     = $e;
		} elseif ( "'" === $c || '"' === $c || '`' === $c ) {
			$j = $i + 1;
			while ( $j < $n && $src[ $j ] !== $c ) {
				if ( '\\' === $src[ $j ] ) {
					++$j;
				}
				if ( '`' === $c && '$' === $src[ $j ] && '{' === ( $src[ $j + 1 ] ?? '' ) ) { // Skip ${ ... } (may contain quotes).
					$depth = 0;
					for ( ; $j < $n; $j++ ) {
						if ( '{' === $src[ $j ] ) {
							++$depth;
						} elseif ( '}' === $src[ $j ] && 0 === --$depth ) {
							break;
						}
					}
				}
				++$j;
			}
			$raw   = substr( $src, $i + 1, $j - $i - 1 );
			$out[] = array( '`' === $c ? 't' : 's', $raw, $line );
			$line += substr_count( $raw, "\n" );
			$i     = $j + 1;
		} elseif ( '/' === $c && ( '' === $prev || false !== strpos( '(,=:[!&|?{};', $prev ) || in_array( $prev, array( 'return', 'typeof' ), true ) ) ) { // Regex literal.
			$j  = $i + 1;
			$cl = false;
			while ( $j < $n && ( '/' !== $src[ $j ] || $cl ) && "\n" !== $src[ $j ] ) {
				if ( '\\' === $src[ $j ] ) {
					++$j;
				} elseif ( '[' === $src[ $j ] ) {
					$cl = true;
				} elseif ( ']' === $src[ $j ] ) {
					$cl = false;
				}
				++$j;
			}
			$i     = $j + 1;
			$prev  = 'x';
			$out[] = array( 'p', 'x', $line );
		} elseif ( preg_match( '/\G[A-Za-z_$][\w$]*/', $src, $m, 0, $i ) ) {
			$out[] = array( 'w', $m[0], $line );
			$i    += strlen( $m[0] );
		} else {
			$out[] = array( 'p', $c, $line );
			++$i;
		}
		$last = end( $out );
		$prev = $last ? ( 'w' === $last[0] || 'p' === $last[0] ? $last[1] : 'x' ) : '';
	}
	return $out;
}

/**
 * Scan one JS file.
 *
 * @param string $file Path.
 * @return array{0: array, 1: array} Findings, then [line, key, fallback] for each t() call.
 */
function bn_scan_js( string $file ): array {
	$t     = bn_js_tokens( (string) file_get_contents( $file ) );
	$sinks = array( 'textContent', 'innerHTML', 'innerText', 'placeholder', 'title', 'alt', 'ariaLabel' );
	$attrs = array( 'aria-label', 'title', 'placeholder', 'alt', 'aria-description' );
	$out   = array();
	$calls = array();
	$count = count( $t );
	$st    = array(
		'mode' => 'text',
		'tag'  => '',
		'raw'  => '',
	);
	for ( $i = 0; $i < $count; $i++ ) {
		$k = $t[ $i ];
		if ( 'w' === $k[0] && 't' === $k[1] && isset( $t[ $i + 4 ] ) && '(' === $t[ $i + 1 ][1] && 's' === $t[ $i + 2 ][0] && ',' === $t[ $i + 3 ][1] && 's' === $t[ $i + 4 ][0] && ( 0 === $i || '.' !== $t[ $i - 1 ][1] ) ) {
			$calls[] = array( $k[2], $t[ $i + 2 ][1], $t[ $i + 4 ][1] );
		}
		if ( 's' !== $k[0] && 't' !== $k[0] ) {
			continue;
		}
		// Walk back over `+` concatenation to the sink.
		$p = $i - 1;
		while ( $p >= 1 && '+' === $t[ $p ][1] && $p >= 2 ) {
			$p -= 2;
			if ( ')' === $t[ $p ][1] ) {
				for ( $dep = 0; $p >= 0; $p-- ) {
					$dep += ')' === $t[ $p ][1] ? 1 : ( '(' === $t[ $p ][1] ? -1 : 0 );
					if ( 0 === $dep ) {
						break;
					}
				}
				--$p;
			}
		}
		$hit = false;
		if ( $p >= 1 && '=' === $t[ $p ][1] && 'w' === $t[ $p - 1 ][0] && in_array( $t[ $p - 1 ][1], $sinks, true ) ) {
			$hit = true;
		} elseif ( $p >= 1 && '(' === $t[ $p ][1] && 'w' === $t[ $p - 1 ][0] && 'bnToast' === $t[ $p - 1 ][1] ) {
			$hit = true;
		} elseif ( $p >= 3 && ',' === $t[ $p ][1] && 's' === $t[ $p - 1 ][0] && in_array( $t[ $p - 1 ][1], $attrs, true ) && '(' === $t[ $p - 2 ][1] && 'setAttribute' === $t[ $p - 3 ][1] ) {
			$hit = true;
		} elseif ( $p >= 1 && ':' === $t[ $p ][1] && ( 'w' === $t[ $p - 1 ][0] || 's' === $t[ $p - 1 ][0] ) && in_array( $t[ $p - 1 ][1], array( 'label', 'title', 'message', 'text', 'placeholder', 'description' ), true ) && isset( $t[ $i + 1 ] ) && in_array( $t[ $i + 1 ][1], array( ',', '}' ), true ) ) {
			$hit = true;
		}
		if ( ! $hit ) {
			continue;
		}
		if ( 't' === $k[0] || preg_match( '/<[a-z]/i', $k[1] ) ) {
			foreach ( bn_html_candidates( $k[1], $st ) as $c ) {
				$out[] = array( $k[2], $c );
			}
			$st = array(
				'mode' => 'text',
				'tag'  => '',
				'raw'  => '',
			);
		} elseif ( bn_english( $k[1] ) ) {
			$out[] = array( $k[2], $k[1] );
		}
	}
	return array( $out, $calls );
}

// PHP array keys PHP injects into JS (the 'i18n' objects): any quoted key followed by =>.
$injected = array();
foreach ( array_merge( bn_files( $root, 'php' ), array_filter( array( "$root/" . basename( $root ) . '.php', "$root/buddynext.php" ), 'is_file' ) ) as $f ) {
	preg_match_all( "/['\"]([A-Za-z0-9_-]+)['\"]\s*=>/", (string) file_get_contents( $f ), $m );
	$injected += array_flip( $m[1] );
}

$found = array(); // [relpath, line, literal].
foreach ( bn_files( $root, 'php' ) as $f ) {
	foreach ( bn_scan_php( $f ) as $r ) {
		$found[] = array( substr( $f, strlen( $root ) + 1 ), $r[0], $r[1] );
	}
}
foreach ( bn_files( $root, 'js' ) as $f ) {
	list( $lits, $calls ) = bn_scan_js( $f );
	$rel                  = substr( $f, strlen( $root ) + 1 );
	foreach ( $lits as $r ) {
		$found[] = array( $rel, $r[0], $r[1] );
	}
	foreach ( $calls as $c ) {
		if ( ! isset( $injected[ $c[1] ] ) && bn_english( $c[2] ) ) {
			$found[] = array( $rel, $c[0], "t( {$c[1]} ) fallback never injected: {$c[2]}" );
		}
	}
}

$hash = static fn( string $lit ): string => substr( sha1( preg_replace( '/\s+/', ' ', trim( $lit ) ) ), 0, 10 );
$now  = array();
foreach ( $found as $r ) {
	$now[ $r[0] . "\t" . $hash( $r[2] ) ] = $r;
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
	fwrite( STDERR, sprintf( "%s:%d:%s\n", $r[0], $r[1], str_replace( "\n", '\n', substr( $r[2], 0, 100 ) ) ) );
}
foreach ( array_keys( $gone ) as $g ) {
	fwrite( STDERR, "baseline entry no longer found (delete it): $g\n" );
}
if ( $new || $gone ) {
	fwrite( STDERR, sprintf( "FAIL: %d unwrapped string(s) not in the baseline, %d stale baseline entries. Wrap each in __() with the text domain and add the msgid (bin/i18n.sh).\n", count( $new ), count( $gone ) ) );
	exit( 1 );
}
printf( "OK: no new unwrapped strings (%d baselined)\n", count( $known ) );
