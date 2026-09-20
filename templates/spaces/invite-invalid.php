<?php
/**
 * BuddyNext template: invalid / expired space invite link.
 *
 * Rendered (at HTTP 200, never a 404) when a visitor opens a `?invite=` link
 * for a space they still cannot see — i.e. the link is invalid, expired, has
 * been reset, or has hit its use limit. It reveals NONE of the space's content
 * or name, so a secret space is not disclosed; it only tells the visitor the
 * link is spent and points them back to the public directory.
 *
 * Theme-overridable at {child-theme}/buddynext/spaces/invite-invalid.php.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

buddynext_get_template(
	'parts/empty-state.php',
	array(
		'icon'      => 'link',
		'title'     => __( 'This invite link is no longer valid', 'buddynext' ),
		'body'      => __( 'Ask the space for a new one.', 'buddynext' ),
		'cta_url'   => \BuddyNext\Core\PageRouter::spaces_url(),
		'cta_label' => __( 'Browse spaces', 'buddynext' ),
		'cta_icon'  => 'compass',
		'classes'   => array( 'bn-invite-invalid' ),
	)
);
