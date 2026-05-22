<?php
/**
 * BuddyNext profile following template.
 *
 * Displays the list of users that the profile owner follows. 24 cards per page,
 * driven by FollowService::get_following() with paginated args.
 *
 * Context variable:
 *   $user_id (int) — whose following list to show.
 *
 * Overridable: copy to {theme}/buddynext/profile/following.php
 *
 * REST endpoint: GET buddynext/v1/users/{id}/following
 *
 * @package BuddyNext
 * @since   1.1.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

// ── Context ─────────────────────────────────────────────────────────────────────
$user_id = isset( $user_id ) ? absint( $user_id ) : 0;
if ( $user_id <= 0 ) {
	wp_die( esc_html__( 'Member not found.', 'buddynext' ) );
}

$profile_user = get_userdata( $user_id );
if ( ! $profile_user ) {
	wp_die( esc_html__( 'Member not found.', 'buddynext' ) );
}

$current_user_id = get_current_user_id();
$is_own_profile  = ( $current_user_id === $user_id );

// ── Pagination ──────────────────────────────────────────────────────────────────
$bn_per_page = 24;
$bn_paged    = max( 1, absint( get_query_var( 'paged', 1 ) ) );

$follow_svc = buddynext_service( 'follows' );
$blocks_svc = buddynext_service( 'blocks' );

$all_ids = $follow_svc->following( $user_id );

if ( $current_user_id > 0 ) {
	$all_ids = array_values(
		array_filter(
			$all_ids,
			static function ( int $other_id ) use ( $blocks_svc, $current_user_id ): bool {
				return $other_id === $current_user_id || ! $blocks_svc->is_blocking_either( $current_user_id, $other_id );
			}
		)
	);
}

$total       = count( $all_ids );
$total_pages = $total > 0 ? (int) ceil( $total / $bn_per_page ) : 0;
$offset      = ( $bn_paged - 1 ) * $bn_per_page;
$page_ids    = array_slice( $all_ids, $offset, $bn_per_page );

$page_title = $is_own_profile
	? __( 'You are following', 'buddynext' )
	: sprintf(
		/* translators: %s: member display name */
		__( 'Following · %s', 'buddynext' ),
		$profile_user->display_name
	);

/**
 * Fires before the profile following inner content.
 *
 * @param int $user_id Profile owner.
 */
do_action( 'buddynext_profile_following_before', (int) $user_id );
?>
<div class="bn-connections bn-following"
	data-wp-interactive="buddynext/connections"
	data-wp-context='{"userId":<?php echo absint( $user_id ); ?>,"restUrl":"<?php echo esc_js( rest_url( 'buddynext/v1' ) ); ?>","nonce":"<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>"}'>

	<div class="bn-connections-header">
		<h1 class="bn-connections-title"><?php echo esc_html( $page_title ); ?></h1>
		<?php if ( $total > 0 ) : ?>
			<span class="bn-connections-count">
				<?php
				/* translators: %s: formatted following count */
				printf( esc_html__( 'Following %s', 'buddynext' ), esc_html( number_format_i18n( $total ) ) );
				?>
			</span>
		<?php endif; ?>
		<a href="<?php echo esc_url( PageRouter::profile_url( $user_id ) ); ?>" class="bn-connections-back">
			<?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Back to profile', 'buddynext' ); ?>
		</a>
	</div>

	<div class="bn-connections-grid bn-following-grid" role="list" aria-label="<?php esc_attr_e( 'Following', 'buddynext' ); ?>">
		<?php if ( ! empty( $page_ids ) ) : ?>
			<?php
			foreach ( $page_ids as $fid ) :
				$fid    = (int) $fid;
				$f_user = get_userdata( $fid );
				if ( ! $f_user ) {
					continue;
				}
				$f_name   = $f_user->display_name;
				$f_handle = '@' . $f_user->user_nicename;
				$f_avatar = get_avatar_url( $fid, array( 'size' => 128 ) );
				$f_url    = PageRouter::profile_url( $fid );
				$is_fb    = $current_user_id > 0 ? $follow_svc->is_following( $current_user_id, $fid ) : false;
				?>
				<article class="bn-member-card bn-following-card" role="listitem">
					<a href="<?php echo esc_url( $f_url ); ?>" aria-label="<?php echo esc_attr( $f_name ); ?>">
						<?php if ( $f_avatar ) : ?>
							<img src="<?php echo esc_url( $f_avatar ); ?>"
								alt="<?php echo esc_attr( $f_name ); ?>"
								class="bn-avatar"
								width="64"
								height="64"
								loading="lazy">
						<?php endif; ?>
					</a>

					<div class="bn-member-name">
						<a href="<?php echo esc_url( $f_url ); ?>"><?php echo esc_html( $f_name ); ?></a>
					</div>
					<div class="bn-member-handle"><?php echo esc_html( $f_handle ); ?></div>

					<?php if ( $current_user_id > 0 && $current_user_id !== $fid ) : ?>
						<div class="bn-card-actions">
							<a href="<?php echo esc_url( $f_url ); ?>" class="bn-btn-view">
								<?php esc_html_e( 'View', 'buddynext' ); ?>
							</a>
							<?php
							include __DIR__ . '/../partials/connection-button.php';
							?>
							<?php if ( $is_fb ) : ?>
							<span class="bn-badge" data-tone="accent">
								<?php esc_html_e( 'Following', 'buddynext' ); ?>
							</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</article>
				<?php
				$user_id = (int) $profile_user->ID;
			endforeach;
			?>
		<?php else : ?>
			<div class="bn-empty-state">
				<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></div>
				<div class="bn-empty-title">
					<?php
					if ( $is_own_profile ) {
						esc_html_e( 'You are not following anyone yet', 'buddynext' );
					} else {
						printf(
							/* translators: %s: member display name */
							esc_html__( '%s is not following anyone yet.', 'buddynext' ),
							esc_html( $profile_user->display_name )
						);
					}
					?>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<?php
	if ( $total_pages > 1 ) {
		buddynext_get_template(
			'parts/pagination.php',
			array(
				'current'    => (int) $bn_paged,
				'total'      => (int) $total_pages,
				'aria_label' => __( 'Following page navigation', 'buddynext' ),
				'mid_size'   => 2,
			)
		);
	}
	?>
</div>
