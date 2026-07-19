<?php
/**
 * BuddyNext template part: sidebar-people-to-follow.
 *
 * Self-chromed "People to Follow" sidebar card. Extracted verbatim from the
 * former `templates/partials/sidebar.php` so FeedSidebarProvider can render
 * it as a `chrome => false` widget descriptor — markup and gating are
 * unchanged, only the data now arrives via the provider's render closure
 * instead of the partial preparing it inline.
 *
 * @package BuddyNext
 *
 * @var array<int,object> $sbar_suggested   Rows from WidgetService::suggested_follows(). Empty renders the empty state.
 * @var string            $sbar_members_url PageRouter::people_url() — "See all members" link target.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$sbar_suggested   = isset( $sbar_suggested ) ? (array) $sbar_suggested : array();
$sbar_members_url = isset( $sbar_members_url ) ? (string) $sbar_members_url : '';
?>
<div class="bn-sidebar-card">
	<div class="bn-sidebar-card__header">
		<?php esc_html_e( 'People to Follow', 'buddynext' ); ?>
	</div>
	<div class="bn-sidebar-card__body">
		<?php if ( ! empty( $sbar_suggested ) ) : ?>
			<ul class="bn-member-row-list">
				<?php foreach ( $sbar_suggested as $sbar_sug ) : ?>
					<?php
					$sbar_sug_id     = (int) ( $sbar_sug->ID ?? 0 );
					$sbar_sug_status = (string) ( $sbar_sug->follow_status ?? 'unfollowed' );
					$sbar_sug_handle = (string) ( $sbar_sug->user_nicename ?? ( $sbar_sug->user_login ?? '' ) );
					// A pending connection request overrides the @handle line.
					$sbar_sug_meta = 'requested' === $sbar_sug_status
						? __( 'Request sent', 'buddynext' )
						: ( '' !== $sbar_sug_handle ? '@' . $sbar_sug_handle : '' );
					buddynext_get_template(
						'parts/sidebar-member-row.php',
						array(
							'row_user_id' => $sbar_sug_id,
							'row_name'    => (string) ( $sbar_sug->display_name ?? '' ),
							'row_handle'  => $sbar_sug_handle,
							'row_url'     => \BuddyNext\Core\PageRouter::profile_url( $sbar_sug_id ),
							'row_avatar'  => (string) get_avatar_url( $sbar_sug_id, array( 'size' => 40 ) ),
							'row_meta'    => $sbar_sug_meta,
							'row_follow'  => true,
						)
					);
					?>
				<?php endforeach; ?>
			</ul>
			<a href="<?php echo esc_url( $sbar_members_url ); ?>" class="bn-sidebar-see-all">
				<?php esc_html_e( 'See all members', 'buddynext' ); ?>
			</a>
		<?php else : ?>
			<p class="bn-sidebar-card__empty">
				<?php esc_html_e( "We'll suggest people once you've completed onboarding.", 'buddynext' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
