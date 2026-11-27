<?php
function bn_good( $n, $name ) {
	$a = __( 'Save', 'buddynext' );
	/* translators: 1: sender, 2: recipient. */
	$f = sprintf( __( '%1$s sent %2$s a message', 'buddynext' ), $a, $name );
	/* translators: %d: number of comments. */
	$i = sprintf( _n( '%d comment', '%d comments', $n, 'buddynext' ), $n );
	$h = '<p>' . esc_html__( 'First paragraph.', 'buddynext' ) . '</p><p>' . esc_html__( 'Second paragraph.', 'buddynext' ) . '</p>';
	$j = number_format_i18n( $n );
}
