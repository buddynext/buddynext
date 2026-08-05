<?php
/**
 * Cross-plugin guard check.
 *
 * Every call into ANOTHER suite plugin's classes must be guarded, in the same
 * function, against the CLASS IT ACTUALLY CALLS. The failure this exists to
 * catch is a guard that names one class while the body calls a different one:
 * it reads as careful, passes phpcs and phpstan, and fatals only on a customer
 * site that has the partner plugin deactivated - the one configuration nobody
 * develops against.
 *
 * A near miss shipped once already: a guard checked AccessRule and the body
 * called Space::find(). A later audit found gates proving Enrollment and
 * Subscription while their callers used Course and MembershipPlan - safe only
 * because one autoloader happens to ship all four.
 *
 * Self-configuring: the plugin's own namespaces are read from its source, so
 * this file is identical in every repo and needs no per-plugin setup.
 *
 * Usage: php bin/check-cross-plugin-guards.php [--verbose]
 * Exit:  0 clean, 1 unguarded calls found, 2 could not run.
 *
 * @package Wbcom
 */

declare( strict_types = 1 );

/*
 * This is a standalone CLI reporter, not WordPress runtime code: it is invoked
 * as `php bin/check-cross-plugin-guards.php` with no WordPress bootstrap.
 *
 * Escaping output would corrupt a terminal report, and WP_Filesystem does not
 * exist in this process to read files through. Both rules are correct for the
 * plugin's own code and inapplicable here, so they are switched off for this
 * file with the reason rather than worked around.
 */
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI reporter; terminal output, not HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions -- No WordPress bootstrap; WP_Filesystem is unavailable.

/**
 * Top-level namespaces of the suite. A call into one of these that is NOT this
 * plugin's own is a cross-plugin call and needs a guard. Ordered longest-first
 * so Learnomy_Pro is matched before Learnomy.
 */
const SUITE_NAMESPACES = array(
	'Learnomy_Pro',
	'Learnomy',
	'Jetonomy_Pro',
	'Jetonomy',
	'BuddyNextPro',
	'BuddyNext',
	'WPMediaVerse',
);

/** Directories that never contain first-party source. */
const SKIP_DIRS = array( '.git', 'vendor', 'node_modules', 'dist', 'build', 'tests', 'libs' );

$verbose = in_array( '--verbose', $argv, true );
$root    = getcwd();

$scan_roots = array_values(
	array_filter(
		array( 'includes', 'templates', 'src' ),
		static function ( $dir ) use ( $root ) {
			return is_dir( $root . '/' . $dir );
		}
	)
);

if ( ! $scan_roots ) {
	fwrite( STDERR, 'cross-plugin guards: no includes/ templates/ or src/ under ' . $root . ' - run from the plugin root.' . PHP_EOL );
	exit( 2 );
}

/**
 * Every .php file under the scan roots.
 *
 * @param string   $root       Plugin root.
 * @param string[] $scan_roots Relative directories to walk.
 * @return string[] Absolute paths.
 */
function collect_files( string $root, array $scan_roots ): array {
	$files = array();

	foreach ( $scan_roots as $dir ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ),
				static function ( $current ) {
					return ! ( $current->isDir() && in_array( $current->getFilename(), SKIP_DIRS, true ) );
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}
	}

	sort( $files );

	return $files;
}

/**
 * Split a file into functions by brace balance.
 *
 * Deliberately not a real parser: it only needs to know which lines belong to
 * which function so a guard is credited to the body it protects.
 *
 * @param string[] $lines File lines.
 * @return array<int,array{name:string,start:int,end:int}>
 */
function split_functions( array $lines ): array {
	$out   = array();
	$count = count( $lines );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! preg_match( '/^\s*(?:(?:public|private|protected|static|final|abstract)\s+)*function\s+([A-Za-z_]\w*)/', $lines[ $i ], $m ) ) {
			continue;
		}

		$depth   = 0;
		$started = false;

		for ( $j = $i; $j < $count; $j++ ) {
			$depth += substr_count( $lines[ $j ], '{' ) - substr_count( $lines[ $j ], '}' );

			if ( false !== strpos( $lines[ $j ], '{' ) ) {
				$started = true;
			}

			if ( $started && $depth <= 0 ) {
				break;
			}
		}

		$end   = min( $j, $count - 1 );
		$out[] = array(
			'name'  => $m[1],
			'start' => $i,
			'end'   => $end,
		);
		$i     = $end;
	}

	return $out;
}

/**
 * Class names proven to exist by the guards in a block of code.
 *
 * @param string $text Source.
 * @return string[] Normalised class names (no leading backslash).
 */
function guards_in( string $text ): array {
	$found = array();

	// class_exists( '\Foo\Bar' ) and class_exists( '\\Foo\\Bar' ) both appear.
	preg_match_all(
		"/(?:class_exists|method_exists|interface_exists|function_exists)\(\s*'\\\\{0,2}([A-Za-z_][A-Za-z0-9_\\\\]*)'/",
		$text,
		$matches
	);

	// is_callable( array( '\Foo\Bar', 'method' ) ) proves the class as surely as
	// class_exists does, and is the idiomatic guard when the caller cares that a
	// SPECIFIC method is there - which is the stricter check, not a looser one.
	preg_match_all(
		"/is_callable\(\s*(?:array\(|\[)\s*'\\\\{0,2}([A-Za-z_][A-Za-z0-9_\\\\]*)'/",
		$text,
		$callables
	);

	foreach ( array_merge( $matches[1], $callables[1] ) as $class ) {
		$found[] = ltrim( str_replace( '\\\\', '\\', $class ), '\\' );
	}

	return array_unique( $found );
}

/**
 * Cross-plugin classes called in a block of code.
 *
 * The lookbehind is what keeps a product name from matching mid-path: BuddyNext
 * Pro's own \BuddyNextPro\Integrations\Learnomy\LearnomySocial is not a Learnomy
 * class, and reading it as one turns a plugin's own code into false findings.
 *
 * @param string $text Source.
 * @return array<int,array{class:string,offset:int}>
 */
function foreign_calls( string $text ): array {
	$alternation = implode( '|', SUITE_NAMESPACES );
	$pattern     = '/(?:new\s+)?(?<![A-Za-z0-9_\\\\])\\\\?((?:' . $alternation . ')(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)\s*(?:::|\()/';

	preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE );

	$out = array();

	foreach ( $matches[1] as $match ) {
		$out[] = array(
			'class'  => ltrim( $match[0], '\\' ),
			'offset' => $match[1],
		);
	}

	return $out;
}

$files        = collect_files( $root, $scan_roots );
$own_prefixes = array();

// This plugin's own namespaces, read from its source rather than configured.
foreach ( $files as $file_path ) {
	if ( preg_match_all( '/^namespace\s+([A-Za-z_][A-Za-z0-9_]*)/m', (string) file_get_contents( $file_path ), $m ) ) {
		foreach ( $m[1] as $ns ) {
			$own_prefixes[ $ns ] = true;
		}
	}
}

if ( ! $own_prefixes ) {
	fwrite( STDERR, "cross-plugin guards: no namespace declarations found; nothing to check.\n" );
	exit( 0 );
}

/*
 * A Pro plugin cannot run without its free base - the base is a hard
 * requirement checked once at boot, not an optional partner - so calls into it
 * are not cross-plugin calls and must not be reported. Derived by stripping the
 * Pro suffix rather than configured, so this stays the same file in every repo:
 * Jetonomy_Pro implies Jetonomy, BuddyNextPro implies BuddyNext.
 *
 * Without this, Jetonomy Pro alone reported 111 "unguarded" calls into the base
 * it is installed on top of, and a check that cries wolf gets switched off.
 */
foreach ( array_keys( $own_prefixes ) as $ns ) {
	$base = preg_replace( '/_?Pro$/', '', $ns );

	if ( $base && $base !== $ns ) {
		$own_prefixes[ $base ] = true;
	}
}

$findings = array();
$checked  = 0;

foreach ( $files as $file_path ) {
	$body = (string) file_get_contents( $file_path );

	// Cheap reject: no suite namespace mentioned at all.
	$mentions = false;
	foreach ( SUITE_NAMESPACES as $ns ) {
		if ( false !== strpos( $body, $ns . '\\' ) ) {
			$mentions = true;
			break;
		}
	}

	if ( ! $mentions ) {
		continue;
	}

	++$checked;
	$lines     = explode( "\n", $body );
	$functions = split_functions( $lines );

	/*
	 * Guard helpers: real code gates on `$this->ready()` rather than repeating
	 * the class name, so a helper whose body is class_exists checks counts as
	 * proving those classes. Without this the check reports every correctly
	 * gated call in the plugin and gets switched off.
	 */
	$helpers   = array();
	$delegates = array();

	foreach ( $functions as $fn ) {
		$chunk = implode( "\n", array_slice( $lines, $fn['start'], $fn['end'] - $fn['start'] + 1 ) );

		if ( false === strpos( $chunk, 'return' ) ) {
			continue;
		}

		$proves = guards_in( $chunk );

		if ( ! $proves ) {
			continue;
		}

		$helpers[ $fn['name'] ] = $proves;

		preg_match_all( '/\$this->([A-Za-z_]\w*)\(\)/', $chunk, $calls );
		$delegates[ $fn['name'] ] = array_unique( $calls[1] );
	}

	// cohorts_active() returns is_active() && class_exists(Cohort), so it proves
	// everything is_active() proves. Settle the chain.
	for ( $pass = 0; $pass < 3; $pass++ ) {
		foreach ( $helpers as $name => $proves ) {
			foreach ( $delegates[ $name ] ?? array() as $other ) {
				if ( isset( $helpers[ $other ] ) ) {
					$helpers[ $name ] = array_unique( array_merge( $helpers[ $name ], $helpers[ $other ] ) );
				}
			}
		}
	}

	foreach ( $functions as $fn ) {
		$chunk   = implode( "\n", array_slice( $lines, $fn['start'], $fn['end'] - $fn['start'] + 1 ) );
		$guarded = guards_in( $chunk );

		foreach ( $helpers as $name => $proves ) {
			if ( false !== strpos( $chunk, '$this->' . $name . '()' ) || false !== strpos( $chunk, 'self::' . $name . '()' ) ) {
				$guarded = array_merge( $guarded, $proves );
			}
		}

		$guarded = array_unique( $guarded );

		foreach ( foreign_calls( $chunk ) as $call ) {
			$class = $call['class'];
			$top   = strstr( $class, '\\', true );

			if ( isset( $own_prefixes[ $top ] ) ) {
				continue;
			}

			$covered = false;

			foreach ( $guarded as $guard ) {
				if ( $guard === $class || 0 === strpos( $class, $guard . '\\' ) ) {
					$covered = true;
					break;
				}
			}

			if ( $covered ) {
				continue;
			}

			$line = $fn['start'] + substr_count( substr( $chunk, 0, $call['offset'] ), "\n" ) + 1;

			$findings[] = array(
				'file'    => ltrim( str_replace( $root, '', $file_path ), '/' ),
				'line'    => $line,
				'fn'      => $fn['name'],
				'class'   => $class,
				'guarded' => $guarded,
			);
		}
	}
}

if ( ! $findings ) {
	echo 'cross-plugin guards: OK (' . $checked . " files with suite references, 0 unguarded calls)\n";
	exit( 0 );
}

echo 'cross-plugin guards: ' . count( $findings ) . ' UNGUARDED cross-plugin call(s)' . PHP_EOL . PHP_EOL;

foreach ( $findings as $f ) {
	echo $f['file'] . ':' . $f['line'] . '  ' . $f['fn'] . "()\n";
	echo '    calls  : ' . $f['class'] . "\n";
	echo '    guards : ' . ( $f['guarded'] ? implode( ', ', $f['guarded'] ) : 'NONE' ) . "\n";

	if ( $verbose ) {
		echo '    fix    : guard this function against ' . $f['class'] . ' (or a helper that proves it)' . PHP_EOL;
	}

	echo "\n";
}

echo "Each call above runs on a site where the other plugin is deactivated.\n";
echo "Guard the exact class the body calls - not a sibling that happens to ship beside it.\n";

exit( 1 );
