<?php
/**
 * A section the community owner has limited (Settings > Navigation > Visibility)
 * opened by someone it is not open to.
 *
 * Served with a 403 status by PageRouter::dispatch_hub_template(), inside the
 * community layout, so a bookmark or shared link explains itself.
 *
 * Overridable: copy to {theme}/buddynext/parts/not-available.php.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="bn-not-available">
	<?php
	buddynext_get_template(
		'parts/empty-state.php',
		array(
			'icon'      => 'lock',
			'title'     => __( "This section isn't available to you", 'buddynext' ),
			'body'      => __( 'The community owner has limited who can open it.', 'buddynext' ),
			'cta_url'   => home_url( '/' ),
			'cta_label' => __( 'Go to the home page', 'buddynext' ),
		)
	);
	?>
</div>
