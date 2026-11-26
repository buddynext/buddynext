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
 * @var bool   $online_only   Optional. Online only is on: the line reads "N members online". Default false.
 * @var bool   $filtered      Optional. Any directory filter is on: the line reads "N members found". Default false.
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
	'online_only'   => ! empty( $online_only ),
	'filtered'      => ! empty( $filtered ),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_member_directory_hero_args', $args );

$bn_classes = array_filter( (array) $args['classes'], 'is_string' );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_member_directory_hero_classes', $bn_classes, $args );

$bn_total = (int) $args['total_members'];

// Worded for what the list shows: the community, the members online, or the
// members a filter found. One builder, shared with the store's live update.
$bn_subtitle = \BuddyNext\Profile\MemberDirectoryService::directory_subtitle( $bn_total, (bool) $args['online_only'], (bool) $args['filtered'] );

// No header CTA: this is a directory of ALL members, so an "Edit profile" action
// (which targets the VIEWER's own profile) reads as misplaced here. Editing your
// own profile already lives on your own member card, the left nav, and the header
// avatar menu — so the directory hero stays a clean title + count.
do_action( 'buddynext_part_member_directory_hero_before', $args );

buddynext_get_template(
	'parts/section-head.php',
	array(
		'title'         => __( 'Members', 'buddynext' ),
		'subtitle'      => $bn_subtitle,
		'title_icon'    => 'users',
		'heading_level' => 'h1',
		'classes'       => $bn_classes,
		// The members store rewords the line when a filter changes without a reload.
		'subtitle_bind' => 'state.directorySubtitle',
	)
);

do_action( 'buddynext_part_member_directory_hero_after', $args );
