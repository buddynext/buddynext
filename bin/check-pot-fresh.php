<?php
/**
 * Fail when languages/<slug>.pot misses a msgid the code has, or keeps one it no longer has.
 *
 * A stale template is how new strings reach no locale: 1.3.0 wrapped every email
 * default in __() but the .pot predated it, so no .po could translate them and
 * every recipient still read English.
 *
 * Usage: php bin/check-pot-fresh.php [plugin-dir]   (default: this plugin). Needs WP-CLI.
 * Fix:   bin/i18n.sh (or bin/i18n.sh pot), then commit languages/.
 *
 * @package BuddyNext
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput -- CLI gate: no WordPress bootstrap, terminal output.

$dir  = rtrim( $argv[1] ?? dirname( __DIR__ ), '/' );
$slug = basename( $dir );
$pot  = "{$dir}/languages/{$slug}.pot";
if ( ! is_file( $pot ) ) {
	fwrite( STDERR, "FAIL: {$pot} is missing\n" );
	exit( 1 );
}
$fresh = tempnam( sys_get_temp_dir(), 'pot' );
// Same extraction as bin/i18n.sh, so the two cannot disagree about what a msgid is.
$cmd = sprintf(
	'%s i18n make-pot %s %s --slug=%s --domain=%s --skip-audit 2>&1',
	escapeshellcmd( getenv( 'WP_CLI' ) ? getenv( 'WP_CLI' ) : 'wp' ),
	escapeshellarg( $dir ),
	escapeshellarg( $fresh ),
	escapeshellarg( $slug ),
	escapeshellarg( $slug )
);
exec( $cmd, $out, $code );
if ( 0 !== $code ) {
	fwrite( STDERR, "FAIL: wp i18n make-pot failed:\n" . implode( "\n", $out ) . "\n" );
	exit( 1 );
}

/**
 * Msgid keys (context + msgid) of a .pot file.
 *
 * @param string $file Path.
 * @return array<string, true>
 */
function bn_pot_ids( string $file ): array {
	preg_match_all( '/^(?:msgctxt ("(?:[^"\\\\]|\\\\.)*")\n)?msgid ((?:"(?:[^"\\\\]|\\\\.)*"\n?)+)/m', (string) file_get_contents( $file ), $m, PREG_SET_ORDER );
	$ids = array();
	foreach ( $m as $row ) {
		$key = preg_replace( '/"\s*\n\s*"/', '', trim( $row[2] ) );
		if ( '""' !== $key ) {
			$ids[ ( $row[1] ?? '' ) . '|' . $key ] = true;
		}
	}
	return $ids;
}

$shipped  = bn_pot_ids( $pot );
$code_ids = bn_pot_ids( $fresh );
unlink( $fresh );
$missing = array_diff_key( $code_ids, $shipped );
$stale   = array_diff_key( $shipped, $code_ids );
if ( $missing || $stale ) {
	fwrite( STDERR, sprintf( "FAIL: %s is stale: %d msgids missing, %d no longer in the code (run bin/i18n.sh)\n", basename( $pot ), count( $missing ), count( $stale ) ) );
	foreach ( array_slice( array_keys( $missing ), 0, 10 ) as $msgid ) {
		fwrite( STDERR, "  missing {$msgid}\n" );
	}
	foreach ( array_slice( array_keys( $stale ), 0, 10 ) as $msgid ) {
		fwrite( STDERR, "  stale   {$msgid}\n" );
	}
	exit( 1 );
}
printf( "OK %s: %d msgids match the code\n", basename( $pot ), count( $shipped ) );
