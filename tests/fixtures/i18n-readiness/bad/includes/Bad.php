<?php
function bn_bad( $n, $label, $name ) {
	$a = __( 'Save', 'other-plugin' );
	$b = __( 'Save', $domain );
	$c = __( $label, 'buddynext' );
	$d = __( 'Hello ' . $name, 'buddynext' );
	$e = __( 'Welcome', 'buddynext' ) . ' ' . $name . __( 'to the site', 'buddynext' );
	$f = sprintf( __( '%s sent %s a message', 'buddynext' ), $a, $b );
	$g = sprintf( __( 'Deleted %d', 'buddynext' ), $n );
	$h = __( '%d item(s) found', 'buddynext' );
	$i = 1 === $n ? __( '%d comment', 'buddynext' ) : __( '%d comments', 'buddynext' );
	$j = number_format( $n );
}
