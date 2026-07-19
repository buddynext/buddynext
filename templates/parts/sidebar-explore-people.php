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
<ul class="bn-ex-people">
	<?php
	foreach ( $bn_people as $bn_uid ) :
		$bn_user = get_userdata( $bn_uid );
		if ( ! $bn_user ) {
			continue;
		}
		$bn_url  = PageRouter::profile_url( $bn_uid );
		$bn_av   = (string) get_avatar_url( $bn_uid, array( 'size' => 40 ) );
		$bn_tone = array( 'violet', 'amber', 'emerald', 'rose', 'sky' )[ $bn_uid % 5 ];
		?>
		<li class="bn-ex-person">
			<a class="bn-ex-person__id" href="<?php echo esc_url( $bn_url ); ?>">
				<span class="bn-avatar" data-size="md" data-tone="<?php echo esc_attr( $bn_tone ); ?>">
					<?php if ( '' !== $bn_av ) : ?>
						<img src="<?php echo esc_url( $bn_av ); ?>" alt="" width="36" height="36" loading="lazy" decoding="async">
					<?php else : ?>
						<?php echo esc_html( mb_strtoupper( mb_substr( (string) $bn_user->display_name, 0, 1 ) ) ); ?>
					<?php endif; ?>
				</span>
				<span class="bn-ex-person__text">
					<span class="bn-ex-person__name"><?php echo esc_html( $bn_user->display_name ); ?></span>
					<span class="bn-ex-person__meta">@<?php echo esc_html( $bn_user->user_nicename ); ?></span>
				</span>
			</a>
			<?php
			if ( $bn_viewer > 0 && $bn_viewer !== $bn_uid ) {
				$user_id = $bn_uid;
				buddynext_get_template( 'partials/follow-button.php', array( 'user_id' => $bn_uid ) );
			}
			?>
		</li>
	<?php endforeach; ?>
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
		'see_all_label' => __( 'All', 'buddynext' ),
	)
);
