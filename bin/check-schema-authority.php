<?php
/**
 * Schema-authority gate.
 *
 * THE RULE, which is WordPress's own: a table's CREATE TABLE statement is the
 * single source of truth for its columns, and dbDelta() converges every install
 * onto it. Hand-written ALTER TABLE is reserved for the things dbDelta genuinely
 * cannot express — dropping a column or index, renaming, widening an ENUM,
 * replacing a unique key — each guarded so it is idempotent.
 *
 * WHAT WENT WRONG WITHOUT THIS GATE. bn_membership_tiers ended up with ten
 * columns that existed ONLY as additive ALTERs while the CREATE still described
 * the original seven. MembershipTierService named all of them, so the two were
 * bridged by a single version-gated, error-suppressed migration: any install
 * whose marker was current while the columns were absent got a fatal
 * "Unknown column 'price' in 'field list'" on every tier read. Nothing in the
 * build could see the divergence, because nothing was looking.
 *
 * WHAT THIS CHECKS
 *
 *   A. Every column added by an ALTER ... ADD COLUMN is also DECLARED in that
 *      table's CREATE TABLE. An ALTER may exist to converge installs quickly
 *      between releases — that is legitimate — but it must never be the only
 *      place a column is described.
 *
 *   B. Every key is NAMED. An unnamed `KEY (col)` makes dbDelta re-issue the
 *      same "Added index" on every single run, forever, silently.
 *
 * ONLY VERIFIED RULES LIVE HERE. The widely-repeated dbDelta folklore — that
 * PRIMARY KEY needs two spaces before its bracket, and that INDEX must be
 * written as KEY — was measured on this WordPress version (7.1) and is FALSE:
 * both forms converge in one pass and report nothing on the second. An earlier
 * draft of this gate enforced them and flagged 27 tables that had nothing wrong
 * with them. A guard that fires on a non-problem teaches people to ignore
 * guards, so if you are tempted to add a rule here, measure it first:
 *
 *   dbDelta( $sql ); $second = dbDelta( $sql );   // $second must be empty
 *
 *   php bin/check-schema-authority.php     # exit 1 on divergence
 *
 * A deliberate exception carries a `bn-schema-authority-ok:` marker comment on
 * the ADD COLUMN line, with the reason.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput -- CLI gate: reads plugin source from local disk, prints a report.

$bn_root  = dirname( __DIR__ );
$bn_files = array_values(
	array_filter(
		glob( $bn_root . '/includes/Core/Installer.php' ) ?: array()
	)
);

if ( empty( $bn_files ) ) {
	fwrite( STDERR, "check-schema-authority: no Installer.php found\n" );
	exit( 1 );
}

$bn_failures = array();
$bn_tables   = 0;
$bn_columns  = 0;

foreach ( $bn_files as $bn_file ) {
	$bn_raw = (string) file_get_contents( $bn_file );
	$bn_rel = ltrim( str_replace( $bn_root, '', $bn_file ), '/' );

	// Comments are PROSE, not schema. An early draft flagged a phantom column
	// named `clause` because a comment reads "column name => ADD COLUMN clause".
	// Blanked rather than removed, so every byte offset below still lines up.
	$bn_src = (string) preg_replace_callback(
		'!/\*.*?\*/|//[^\n]*!s',
		static fn( array $m ): string => str_repeat( ' ', strlen( $m[0] ) ),
		$bn_raw
	);

	// ── Declared columns, per table ──────────────────────────────────────────
	// CREATE TABLE {$p}bn_foo ( ... ) — the authority.
	$bn_declared = array();
	if ( preg_match_all( '/CREATE TABLE \{\$\w+\}([a-z0-9_]+) \((.*?)\n\s*\) /s', $bn_src, $bn_creates, PREG_SET_ORDER ) ) {
		foreach ( $bn_creates as $bn_create ) {
			$bn_table = $bn_create[1];
			$bn_body  = $bn_create[2];
			preg_match_all( '/^\s*([a-z0-9_]+)\s+[A-Za-z]/m', $bn_body, $bn_cols );
			$bn_declared[ $bn_table ] = array_values(
				array_diff(
					array_map( 'strtolower', $bn_cols[1] ),
					array( 'primary', 'unique', 'key', 'index', 'fulltext' )
				)
			);
			++$bn_tables;
			$bn_columns += count( $bn_declared[ $bn_table ] );

			// ── B. Unnamed keys — measured, and genuinely broken ─────────────
			// `KEY (col)` with no name: dbDelta cannot match it against the live
			// index, so it adds it again on every run. Verified: pass 2 and pass 3
			// both reported "Added index ... KEY `` (`name`)".
			if ( preg_match( '/^\s*(UNIQUE |FULLTEXT )?KEY\s+\(/mi', $bn_body ) ) {
				$bn_failures[] = "{$bn_rel}: {$bn_table} — every KEY must be NAMED, or dbDelta re-adds it "
					. 'on every run, forever, without reporting a problem.';
			}
		}
	}

	// ── A. Columns that exist only as an ALTER ───────────────────────────────
	//
	// Attribution has to be exact, or the gate reports the wrong table and stops
	// being believed. Two shapes appear in these installers:
	//
	// 1. ALTER TABLE `{$subs_table}` ADD COLUMN foo ...   — the variable is
	// right there in the statement. Read it.
	// 2. $x_columns = array( 'foo' => 'ADD COLUMN foo ...' ) applied by a loop
	// further down. No table in the string, so fall back to the nearest
	// preceding `$x_table = $prefix . 'bn_foo'` assignment.
	//
	// Shape 1 must be checked FIRST. Using the positional fallback for it
	// attributed bn_subscriptions columns to bn_invoices, because that table's
	// variable happened to be assigned earlier in the file.
	$bn_var_table = array();
	if ( preg_match_all( "/\\\$(\w+)\s*=\s*\\\$\w+(?:->prefix)?\s*\.\s*'([a-z0-9_]+)'/", $bn_src, $bn_vars, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		foreach ( $bn_vars as $bn_v ) {
			$bn_var_table[ $bn_v[1][0] ] = $bn_v[2][0];
		}
	}

	preg_match_all( "/\\\$\w+\s*=\s*\\\$\w+(?:->prefix)?\s*\.\s*'([a-z0-9_]+)'/", $bn_src, $bn_assign, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );

	preg_match_all( '/ADD COLUMN\s+`?([a-z0-9_]+)`?/i', $bn_src, $bn_adds, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );

	foreach ( $bn_adds as $bn_add ) {
		$bn_col    = strtolower( $bn_add[1][0] );
		$bn_offset = (int) $bn_add[0][1];

		// Deliberate exception marker on the same line.
		$bn_line_start = (int) strrpos( substr( $bn_src, 0, $bn_offset ), "\n" );
		$bn_line_end   = strpos( $bn_src, "\n", $bn_offset );
		$bn_line       = substr( $bn_src, $bn_line_start, ( false === $bn_line_end ? strlen( $bn_src ) : $bn_line_end ) - $bn_line_start );
		if ( str_contains( $bn_line, 'bn-schema-authority-ok:' ) ) {
			continue;
		}

		$bn_table = '';

		// Shape 1: the ALTER names its table variable in the same statement.
		$bn_look_back = substr( $bn_src, max( 0, $bn_offset - 300 ), min( 300, $bn_offset ) );
		if ( preg_match_all( '/ALTER TABLE\s+`?\{\$(\w+)\}`?/', $bn_look_back, $bn_alter_vars ) ) {
			$bn_var = (string) end( $bn_alter_vars[1] );
			if ( isset( $bn_var_table[ $bn_var ] ) ) {
				$bn_table = $bn_var_table[ $bn_var ];
			}
		}

		// Shape 2: nearest preceding table assignment.
		if ( '' === $bn_table ) {
			foreach ( $bn_assign as $bn_a ) {
				if ( (int) $bn_a[1][1] < $bn_offset ) {
					$bn_table = $bn_a[1][0];
				}
			}
		}

		if ( '' === $bn_table || ! isset( $bn_declared[ $bn_table ] ) ) {
			// Cannot attribute it — fall back to "declared anywhere", so an
			// unattributable ALTER never fails the build on a parsing limitation.
			$bn_all = array_merge( ...array_values( $bn_declared ?: array( array() ) ) );
			if ( ! in_array( $bn_col, $bn_all, true ) ) {
				$bn_failures[] = "{$bn_rel}: column `{$bn_col}` is added by ALTER but declared in NO CREATE TABLE.";
			}
			continue;
		}

		if ( ! in_array( $bn_col, $bn_declared[ $bn_table ], true ) ) {
			$bn_failures[] = "{$bn_rel}: {$bn_table}.{$bn_col} exists ONLY as an ALTER — declare it in the "
				. 'CREATE TABLE so dbDelta converges every install, whatever the migration marker says.';
		}
	}
}

if ( ! empty( $bn_failures ) ) {
	fwrite( STDERR, "\nSchema authority violations:\n\n" );
	foreach ( array_unique( $bn_failures ) as $bn_failure ) {
		fwrite( STDERR, "  - {$bn_failure}\n" );
	}
	fwrite( STDERR, "\nThe CREATE TABLE is the source of truth; ALTER is only for what dbDelta cannot express\n" );
	fwrite( STDERR, "(dropping, renaming, widening an ENUM, replacing a key).\n\n" );
	exit( 1 );
}

echo "schema authority clean — {$bn_tables} tables, {$bn_columns} columns, every ALTERed column declared\n";
exit( 0 );
