<?php
/**
 * BuddyNext template part: sidebar-explore-people.
 *
 * Self-chromed "People to discover" card for the Explore aside. Extracted
 * verbatim from the former `templates/feed/parts/explore-aside.php`
 * (people-to-discover block) so ExploreSidebarProvider can render it as a
 * `chrome => false` widget descriptor — markup, gating, and data source
 * unchanged, only the data now arrives via the provider's render closure
 * instead of the partial fetching it inline.
 *
 * @package BuddyNext
 * @since   1.6.0
 *
 * @var array<int,int> $bn_people Suggested member IDs from ExploreService::suggested_member_ids(). Empty renders nothing.
 * @var int             $bn_viewer Viewing user ID (0 for guests) — hides the follow button on self and for guests.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$bn_people = isset( $bn_people ) ? (array) $bn_people : array();
$bn_viewer = isset( $bn_viewer ) ? (int) $bn_viewer : 0;

if ( empty( $bn_people ) ) {
	return;
}

ob_start();
?>
<ul class="bn-member-row-list">
	<?php
	foreach ( $bn_people as $bn_uid ) :
		$bn_user = get_userdata( $bn_uid );
		if ( ! $bn_user ) {
			continue;
		}
		$bn_tone = array( 'violet', 'amber', 'emerald', 'rose', 'sky' )[ $bn_uid % 5 ];
		buddynext_get_template(
			'parts/sidebar-member-row.php',
			array(
				'row_user_id' => (int) $bn_uid,
				'row_name'    => (string) $bn_user->display_name,
				'row_handle'  => (string) $bn_user->user_nicename,
				'row_url'     => PageRouter::profile_url( $bn_uid ),
				'row_avatar'  => (string) get_avatar_url( $bn_uid, array( 'size' => 40 ) ),
				'row_tone'    => $bn_tone,
				// Guests / self get no Follow button (matches the prior gating).
				'row_follow'  => ( $bn_viewer > 0 && $bn_viewer !== (int) $bn_uid ),
			)
		);
	endforeach;
	?>
</ul>
<?php
buddynext_get_template(
	'parts/sidebar-card.php',
	array(
		'id'            => 'explore-people',
		'title'         => __( 'People to discover', 'buddynext' ),
		'title_icon'    => 'users',
		'body_html'     => (string) ob_get_clean(),
		'see_all_url'   => PageRouter::people_url(),
		'see_all_label' => __( 'See all members', 'buddynext' ),
	)
);
