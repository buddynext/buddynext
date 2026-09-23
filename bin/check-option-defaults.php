<?php
/**
 * Every owner setting has ONE declared default, or is marked never-reset.
 *
 * WHY THIS EXISTS (card 9995933507). Owner settings drifted: the same option was
 * read with a different fallback in different files (buddynext_brand_color had
 * six), so "the default" depended on which code path you hit. And a settings tab
 * cannot offer a safe "Restore defaults" when half its fields declare no default
 * at all. This gate makes the registry the single source of a setting's default,
 * and fails the build when that source is missing.
 *
 * WHAT IT CHECKS. It statically parses every registry page's `new Field( array(
 * ... ) )` descriptors (Free + Pro) and, for each field the SettingsDriver
 * actually registers (type is not `readonly`/`custom`), requires ONE of:
 *   - a declared `'default'` (the Settings-API default every read then inherits), OR
 *   - `'resettable' => false` — the field is OWNER DATA (site name, banned words,
 *     sender identity, secrets, page mappings, brand images); it is never reset and
 *     needs no configuration default.
 * A field that is neither is drift waiting to happen: it fails here by name.
 *
 * It also prints the inventory (option, tier, has_default, resettable) the card
 * asks to be attached — pass --inventory to print it and exit 0.
 *
 * Static by design (no WordPress boot), like the other bin/check-*.php gates.
 *
 * Usage: php bin/check-option-defaults.php               (exit 1 on a gap)
 *        php bin/check-option-defaults.php --inventory    (print inventory, exit 0)
 *
 * @package BuddyNext
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.GlobalVariablesOverride -- CLI gate: reads plugin source from local disk and prints a terminal report; not WordPress runtime.

$here = __DIR__;
$free = dirname( $here );
$pro  = getenv( 'BUDDYNEXT_PRO_PATH' );
if ( false === $pro || '' === $pro ) {
	$pro = dirname( $free ) . '/buddynext-pro';
}

// The registry pages: files whose settings_fields() declares Field descriptors.
$page_files = array(
	$free . '/includes/Admin/Settings.php',
	$pro . '/includes/Admin/AIAdmin.php',
	$pro . '/includes/Admin/AIModAdmin.php',
	$pro . '/includes/Admin/PaymentsAdmin.php',
	$pro . '/includes/Admin/RealtimeAdmin.php',
);

/**
 * Yield every .php file under a root, skipping vendor/node_modules/tests.
 *
 * @param string $root Directory.
 * @return \Generator<string>
 */
function walk_php( string $root ): \Generator {
	$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$path = $file->getPathname();
		if ( substr( $path, -4 ) !== '.php' ) {
			continue;
		}
		if ( preg_match( '#/(vendor|node_modules|tests)/#', $path ) ) {
			continue;
		}
		yield $path;
	}
}

/**
 * Extract every `new Field( array( ... ) )` descriptor body from PHP source, using
 * brace matching so a nested array in a descriptor does not end it early.
 *
 * @param string $src PHP source.
 * @return array<int, array{tier:string, body:string, line:int}> Descriptor bodies.
 */
function bn_extract_field_descriptors( string $src ): array {
	$out = array();
	$len = strlen( $src );
	$off = 0;
	while ( false !== ( $pos = strpos( $src, 'new Field(', $off ) ) ) {
		$i     = $pos + strlen( 'new Field(' );
		$depth = 1;
		while ( $i < $len && $depth > 0 ) {
			$ch = $src[ $i ];
			if ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch ) {
				--$depth;
			}
			++$i;
		}
		$body = substr( $src, $pos, $i - $pos );
		$out[] = array(
			'body' => $body,
			'line' => substr_count( $src, "\n", 0, $pos ) + 1,
		);
		$off = $i;
	}
	return $out;
}

/**
 * Pull one single-quoted scalar for a descriptor key, e.g. key or type.
 *
 * @param string $body Descriptor body.
 * @param string $name Array key.
 * @return string|null
 */
function bn_descriptor_string( string $body, string $name ): ?string {
	if ( preg_match( "/'" . preg_quote( $name, '/' ) . "'\\s*=>\\s*'([^']*)'/", $body, $m ) ) {
		return $m[1];
	}
	return null;
}

/**
 * The field's OPTION identifier: its `'key'` as a quoted string, or the raw
 * class-/self-constant expression when the key is a constant (e.g.
 * self::OPTION_WEBHOOK_SECRET). Resolving the constant's VALUE is unnecessary here
 * — the gate only needs to name the field and check its default/resettable — so a
 * constant-keyed field is still covered, never silently skipped.
 *
 * @param string $body Descriptor body.
 * @return string|null Option identifier, or null if there is no `'key' =>` at all.
 */
function bn_descriptor_key( string $body ): ?string {
	$literal = bn_descriptor_string( $body, 'key' );
	if ( null !== $literal ) {
		return $literal;
	}
	if ( preg_match( "/'key'\\s*=>\\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)+)/", $body, $m ) ) {
		return $m[1];
	}
	return null;
}

// ── Part 2: no re-authored fallback drift ──────────────────────────────────────
//
// Options that were consolidated onto ONE canonical default: every get_option() of
// them must reference that canonical (a const or helper) rather than re-type a
// literal, or the drift the card fixed silently returns. `marker` is a substring
// the canonical fallback contains; `baseline` lists file basenames where a read
// deliberately passes something else because it is an EXISTENCE check ("has the
// owner set this?"), not a value read.
$guarded = array(
	'buddynext_brand_color'   => array(
		'marker'   => 'DEFAULT_BRAND',
		'baseline' => array( 'SetupChecklist.php' ), // false is an existence check (is a brand set).
	),
	'buddynext_reg_mode'      => array(
		'marker'   => 'buddynext_default_reg_mode',
		'baseline' => array( 'Installer.php', 'SetupChecklist.php' ), // false is an existence check (configured yet).
	),
);

/**
 * Blank out comments/docblocks while preserving line numbers, so a get_option()
 * example inside a docblock is never mistaken for a real read.
 *
 * @param string $src PHP source.
 * @return string
 */
function bn_strip_comments( string $src ): string {
	$out = '';
	foreach ( token_get_all( $src ) as $token ) {
		if ( is_array( $token ) ) {
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}
			$out .= $token[1];
		} else {
			$out .= $token;
		}
	}
	return $out;
}

/**
 * Extract each get_option( '<option>', <fallback> ) read of one option, with its
 * fallback text (balanced to the call's close paren) and line.
 *
 * @param string $src    PHP source.
 * @param string $option Option name.
 * @return array<int, array{fallback:string, line:int}>
 */
function bn_get_option_reads( string $src, string $option ): array {
	$out    = array();
	$needle = 'get_option(';
	$len    = strlen( $src );
	$off    = 0;
	while ( false !== ( $pos = strpos( $src, $needle, $off ) ) ) {
		$i     = $pos + strlen( $needle );
		$depth = 1;
		while ( $i < $len && $depth > 0 ) {
			if ( '(' === $src[ $i ] ) {
				++$depth;
			} elseif ( ')' === $src[ $i ] ) {
				--$depth;
			}
			++$i;
		}
		$call = substr( $src, $pos, $i - $pos );
		$off  = $i;
		if ( preg_match( "/get_option\\(\\s*'" . preg_quote( $option, '/' ) . "'\\s*,(.*)\\)\\s*$/s", $call, $m ) ) {
			$out[] = array(
				'fallback' => trim( $m[1] ),
				'line'     => substr_count( $src, "\n", 0, $pos ) + 1,
			);
		}
	}
	return $out;
}

$violations = array();
$inventory  = array();

foreach ( $page_files as $file ) {
	if ( ! is_file( $file ) ) {
		continue; // Pro not checked out — its pages are all-defaulted anyway.
	}
	$tier = ( false !== strpos( $file, '/buddynext-pro/' ) ) ? 'pro' : 'free';
	$rel  = basename( dirname( dirname( $file ) ) ) . '/' . basename( $file );
	$src  = (string) file_get_contents( $file );

	foreach ( bn_extract_field_descriptors( $src ) as $field ) {
		$body = $field['body'];
		$key  = bn_descriptor_key( $body );
		$type = bn_descriptor_string( $body, 'type' );
		if ( null === $key ) {
			continue; // A Field built from variables — not statically resolvable here.
		}

		$driver_registered = ! in_array( $type, array( 'readonly', 'custom' ), true );
		$has_default       = (bool) preg_match( "/'default(?:_callback)?'\\s*=>/", $body );
		$non_resettable    = (bool) preg_match( "/'resettable'\\s*=>\\s*false/", $body );

		$inventory[] = array(
			'option'      => $key,
			'tier'        => $tier,
			'type'        => (string) $type,
			'registered'  => $driver_registered,
			'has_default' => $has_default,
			'resettable'  => ! $non_resettable,
		);

		if ( $driver_registered && ! $has_default && ! $non_resettable ) {
			$violations[] = array(
				'file' => $rel,
				'line' => $field['line'],
				'key'  => $key,
			);
		}
	}
}

// Part 2 scan: walk both repos and flag any re-authored fallback for a guarded option.
$drift = array();
foreach ( array( $free, $pro ) as $root ) {
	if ( ! is_dir( $root ) ) {
		continue;
	}
	$repo = basename( $root );
	foreach ( walk_php( $root ) as $file ) {
		if ( basename( $file ) === 'check-option-defaults.php' ) {
			continue; // Don't flag this gate's own $guarded literals.
		}
		$src = bn_strip_comments( (string) file_get_contents( $file ) );
		foreach ( $guarded as $option => $rule ) {
			if ( false === strpos( $src, $option ) ) {
				continue;
			}
			if ( in_array( basename( $file ), $rule['baseline'], true ) ) {
				continue; // Deliberate existence-check read.
			}
			foreach ( bn_get_option_reads( $src, $option ) as $read ) {
				if ( false === strpos( $read['fallback'], $rule['marker'] ) ) {
					$drift[] = array(
						'file'   => $repo . '/' . ltrim( str_replace( $root, '', $file ), '/' ),
						'line'   => $read['line'],
						'option' => $option,
						'marker' => $rule['marker'],
					);
				}
			}
		}
	}
}

if ( in_array( '--inventory', $argv, true ) ) {
	usort(
		$inventory,
		static function ( array $a, array $b ): int {
			return array( $a['tier'], $a['option'] ) <=> array( $b['tier'], $b['option'] );
		}
	);
	printf( "%-45s %-5s %-12s %-11s %s\n", 'OPTION', 'TIER', 'TYPE', 'HAS_DEFAULT', 'RESETTABLE' );
	foreach ( $inventory as $row ) {
		printf(
			"%-45s %-5s %-12s %-11s %s\n",
			$row['option'],
			$row['tier'],
			$row['type'],
			$row['registered'] ? ( $row['has_default'] ? 'yes' : 'NO' ) : 'n/a(custom)',
			$row['resettable'] ? 'yes' : 'no (owner data)'
		);
	}
	printf( "\n%d fields (%d driver-registered).\n", count( $inventory ), count( array_filter( $inventory, static fn( $r ) => $r['registered'] ) ) );
	exit( 0 );
}

$failed = false;

if ( ! empty( $violations ) ) {
	$failed = true;
	echo "Owner settings with no declared default and no 'resettable' => false reason:\n\n";
	foreach ( $violations as $v ) {
		echo "  {$v['file']}:{$v['line']}: '{$v['key']}' — add a 'default' => ... , or 'resettable' => false if it is owner data.\n";
	}
	echo "\nEvery owner setting must ship one declared default (so every get_option inherits it),\n";
	echo "unless it is owner data that must never be reset (site name, banned words, sender\n";
	echo "identity, secrets/keys, page mappings, brand images) — those get 'resettable' => false.\n\n";
}

if ( ! empty( $drift ) ) {
	$failed = true;
	echo "get_option() fallbacks that drift from the one canonical default:\n\n";
	foreach ( $drift as $d ) {
		echo "  {$d['file']}:{$d['line']}: '{$d['option']}' fallback must reference the canonical default ({$d['marker']}), not a re-typed literal.\n";
	}
	echo "\nUse the ONE declared default (the const or helper) as the fallback so a consolidated\n";
	echo "option cannot silently split again. A deliberate existence check belongs in the gate's\n";
	echo "baseline for that option.\n";
}

if ( $failed ) {
	exit( 1 );
}

$registered = count( array_filter( $inventory, static fn( $r ) => $r['registered'] ) );
echo "option-defaults gate: OK — every driver-registered owner setting declares a default or is marked never-reset ({$registered} settings); guarded fallbacks stay canonical.\n";
exit( 0 );
