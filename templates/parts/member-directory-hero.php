<?php
/**
 * BuddyNext template part: member-directory-hero.
 *
 * Renders the member directory hero header — title, "%s members in the
 * community" subtitle, and an Edit-profile CTA on the right when the
 * viewer is logged in. Delegates the visual markup to the canonical
 * `parts/section-head.php` so directory + future hubs share the same
 * primitive.
 *
 * Used by: templates/directory/members.php.
 *
 * @package BuddyNext
 *
 * @var int    $total_members Required. Total member count (used in the subtitle).
 * @var string $current_type  Optional. Currently-selected member-type slug ('' = all). Default ''.
 * @var string $view_mode     Optional. Reserved for future grid/list view modes. Default 'grid'.
 * @var int    $viewer_id     Optional. Currently-viewing user ID. Default 0.
 * @var array  $classes       Optional. Extra CSS classes appended to the underlying section-head.
 *
 * Fires:
 *   - do_action( 'buddynext_part_member_directory_hero_before', $args )
 *   - do_action( 'buddynext_part_member_directory_hero_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_member_directory_hero_args',    array $args )
 *   - apply_filters( 'buddynext_part_member_directory_hero_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'total_members' => isset( $total_members ) ? (int) $total_members : 0,
	'current_type'  => isset( $current_type ) ? (string) $current_type : '',
	'view_mode'     => isset( $view_mode ) ? (string) $view_mode : 'grid',
	'viewer_id'     => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_member_directory_hero_args', $args );

$bn_classes = array_filter( (array) $args['classes'], 'is_string' );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_member_directory_hero_classes', $bn_classes, $args );

$bn_total     = (int) $args['total_members'];
$bn_viewer_id = (int) $args['viewer_id'];

$bn_subtitle = sprintf(
	/* translators: %s: formatted member count */
	__( '%s members in the community', 'buddynext' ),
	number_format_i18n( $bn_total )
);

$bn_actions = '';
if ( $bn_viewer_id > 0 ) {
	$bn_actions = sprintf(
		'<a class="bn-btn" data-variant="secondary" data-size="md" href="%1$s"><span>%2$s</span></a>',
		esc_url( \BuddyNext\Core\PageRouter::edit_profile_url( $bn_viewer_id ) ),
		esc_html__( 'Edit profile', 'buddynext' )
	);
}

do_action( 'buddynext_part_member_directory_hero_before', $args );

buddynext_get_template(
	'parts/section-head.php',
	array(
		'title'         => __( 'Members', 'buddynext' ),
		'subtitle'      => $bn_subtitle,
		'heading_level' => 'h1',
		'actions_html'  => $bn_actions,
		'classes'       => $bn_classes,
	)
);

do_action( 'buddynext_part_member_directory_hero_after', $args );
