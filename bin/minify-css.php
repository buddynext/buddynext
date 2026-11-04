<?php
/**
 * Minify stylesheets in place for the release package.
 *
 * Strips comments and collapses whitespace outside strings, and drops the space
 * around { } ; , and > . It never touches the space before or after a colon:
 * `.a :hover` and `.a:hover` select different elements. The repo keeps the
 * readable source; bin/build-release.sh runs this on the staged copy only.
 *
 * Usage: php bin/minify-css.php <file.css> [more.css ...]
 *        php bin/minify-css.php --self-test
 *
 * @package BuddyNext
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput -- CLI build tool: rewrites a staged stylesheet on local disk.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * Minify one stylesheet's text.
 *
 * @param string $css Source.
 * @return string
 */
function bn_minify_css( string $css ): string {
	$out   = '';
	$len   = strlen( $css );
	$quote = '';
	$space = false;
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $css[ $i ];
		if ( '' !== $quote ) {
			$out .= $c;
			if ( '\\' === $c && $i + 1 < $len ) {
				$out .= $css[ ++$i ];
			} elseif ( $c === $quote ) {
				$quote = '';
			}
			continue;
		}
		if ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
			$end = strpos( $css, '*/', $i + 2 );
			$i   = false === $end ? $len : $end + 1;
			continue;
		}
		if ( ctype_space( $c ) ) {
			$space = true;
			continue;
		}
		if ( $space ) {
			$prev = substr( $out, -1 );
			if ( '' !== $out && false === strpos( '{};,>', $prev ) && false === strpos( '{};,>', $c ) ) {
				$out .= ' ';
			}
			$space = false;
		}
		if ( '"' === $c || "'" === $c ) {
			$quote = $c;
		}
		$out .= $c;
	}
	return str_replace( ';}', '}', $out );
}

$args = array_slice( $argv, 1 );

if ( array( '--self-test' ) === $args ) {
	$cases = array(
		".a :hover { color: red; }\n"                  => '.a :hover{color: red}',
		'/* note */ .b > .c , .d { margin: 0 auto ; }' => '.b>.c,.d{margin: 0 auto}',
		'.e::after { content: "/* keep  this */"; }'   => '.e::after{content: "/* keep  this */"}',
		'.f { width: calc( 100% - 2px ); }'            => '.f{width: calc( 100% - 2px )}',
		"@media (max-width: 480px) {\n .g { x: 1 }\n}" => '@media (max-width: 480px){.g{x: 1}}',
	);
	$fail  = 0;
	foreach ( $cases as $in => $want ) {
		$got = bn_minify_css( $in );
		if ( $got !== $want ) {
			fwrite( STDERR, "FAIL: {$in}\n  want {$want}\n  got  {$got}\n" );
			$fail = 1;
		}
	}
	echo $fail ? '' : "minify-css self-test passed\n";
	exit( $fail );
}

foreach ( $args as $file ) {
	$css = file_get_contents( $file );
	if ( false === $css ) {
		fwrite( STDERR, "minify-css: cannot read {$file}\n" );
		exit( 1 );
	}
	file_put_contents( $file, bn_minify_css( $css ) );
}
