<?php
/**
 * Notice for a space page the viewer may not use (moderation, settings, admin).
 *
 * Worded for who is asking, never a bare wp_die() (card 10343760197):
 * - a community moderator reviews reports in Community Admin (moderation page);
 * - a member of the space most likely lost the role, so "no longer";
 * - anyone else never had it.
 *
 * @package BuddyNext
 *
 * @var int    $space_id Space ID.
 * @var string $surface  'moderate' or 'manage'.
 */

defined( 'ABSPATH' ) || exit;

$bn_na_space    = isset( $space_id ) ? (int) $space_id : 0;
$bn_na_moderate = isset( $surface ) && 'moderate' === $surface;
$bn_na_uid      = get_current_user_id();
$bn_na_back     = \BuddyNext\Core\PageRouter::space_url( $bn_na_space );

if ( $bn_na_moderate && ( current_user_can( 'manage_options' ) || buddynext_can( $bn_na_uid, 'buddynext-spaces/moderate' ) ) ) {
	$bn_na = array(
		__( 'Reports from this space are in Community Admin', 'buddynext' ),
		__( 'You review reports for the whole community there, including this space.', 'buddynext' ),
		trailingslashit( buddynext_community_admin_url() ) . 'moderation/',
		__( 'Open Community Admin', 'buddynext' ),
	);
} elseif ( ( new \BuddyNext\Spaces\SpaceMemberService() )->get_role( $bn_na_space, $bn_na_uid ) ) {
	$bn_na   = $bn_na_moderate
		? array(
			__( 'You no longer moderate this space', 'buddynext' ),
			__( 'Your moderator access to this space has changed. You can still view and take part in it.', 'buddynext' ),
		)
		: array(
			__( 'You no longer manage this space', 'buddynext' ),
			__( 'Your access to manage this space has changed. You can still view and take part in it.', 'buddynext' ),
		);
	$bn_na[] = $bn_na_back;
	$bn_na[] = __( 'Back to space', 'buddynext' );
} else {
	$bn_na   = $bn_na_moderate
		? array(
			__( 'You don’t moderate this space', 'buddynext' ),
			__( 'Only the space owner and its moderators can review its reports.', 'buddynext' ),
		)
		: array(
			__( 'You don’t manage this space', 'buddynext' ),
			__( 'Only the space owner and its moderators can change its settings.', 'buddynext' ),
		);
	$bn_na[] = $bn_na_back;
	$bn_na[] = __( 'Back to space', 'buddynext' );
}

buddynext_get_template(
	'parts/empty-state.php',
	array(
		'icon'      => 'shield',
		'title'     => $bn_na[0],
		'body'      => $bn_na[1],
		'cta_url'   => $bn_na[2],
		'cta_label' => $bn_na[3],
	)
);
