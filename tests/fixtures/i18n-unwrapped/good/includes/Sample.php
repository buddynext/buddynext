<?php
function bn_fixture_good() {
	echo esc_html__( 'Your account is ready to use', 'buddynext' );
	$a = array( 'label' => __( 'Connected people', 'buddynext' ), 'slug' => 'connected-people', 'icon' => 'users' );
	$e = new WP_Error( 'bn_x', __( 'You cannot do that here.', 'buddynext' ) );
	$body = array( 'body' => __( 'Hi {{user_name}}, welcome.', 'buddynext' ) );
	?>
	<p><?php esc_html_e( 'Welcome to the community', 'buddynext' ); ?></p>
	<input placeholder="<?php echo esc_attr__( 'Search for people', 'buddynext' ); ?>" class="bn-input bn-input--wide">
	<?php
}
