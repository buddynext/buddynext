<?php
/**
 * A list page past the last one (/members/page/50/, /members/x/articles/page/9/).
 *
 * Served with a 404 status by PageRouter::dispatch_hub_template(), inside the
 * community layout, so a person who followed an old link (the list got shorter
 * since) has a way back to the start of it.
 *
 * Overridable: copy to {theme}/buddynext/parts/past-last-page.php.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="bn-past-last-page">
	<?php
	buddynext_get_template(
		'parts/empty-state.php',
		array(
			'icon'      => 'search',
			'title'     => __( "This page doesn't exist any more", 'buddynext' ),
			'body'      => __( 'The list is shorter than it was when this link was made.', 'buddynext' ),
			'cta_url'   => \BuddyNext\Core\PageRouter::first_page(),
			'cta_label' => __( 'Go to the first page', 'buddynext' ),
		)
	);
	?>
</div>
