<?php
function bn_fixture_bad() {
	echo 'Your account is ready to use';
	$a = array( 'label' => 'Connected people', 'title' => 'Open the inbox' );
	$e = new WP_Error( 'bn_x', 'You cannot do that here.' );
	$body = array(
		'subject' => 'Someone followed you on {{site_name}}',
		'body'    => "Hi {{user_name}},\n\n{{actor_name}} has asked to follow you on <strong>{{site_name}}</strong>.",
	);
	?>
	<p>Welcome to the community</p>
	<input placeholder="Search for people" data-label-on="Connected">
	<?php
}
