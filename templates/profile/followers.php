<?php
/**
 * BuddyNext profile followers template.
 *
 * Displays the list of users that follow the profile owner. 24 cards per page,
 * driven by FollowService::get_followers() with paginated args.
 *
 * Context variable:
 *   $user_id (int) — whose followers list to show.
 *
 * Overridable: copy to {theme}/buddynext/profile/followers.php
 *
 * REST endpoint: GET buddynext/v1/users/{id}/followers
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

$all_ids = $follow_svc->followers( $user_id );
$total   = count( $all_ids );

// Pending follow requests — only the owner sees them.
$pending_ids = $is_own_profile ? $follow_svc->pending_followers( $user_id ) : array();

// Filter out blocked relationships for the viewer (defensive — same rule as REST).
if ( $current_user_id > 0 ) {
	$all_ids = array_values(
		array_filter(
			$all_ids,
			static function ( int $other_id ) use ( $blocks_svc, $current_user_id ): bool {
				return $other_id === $current_user_id || ! $blocks_svc->is_blocking_either( $current_user_id, $other_id );
			}
		)
	);
	$total   = count( $all_ids );
}

$total_pages = $total > 0 ? (int) ceil( $total / $bn_per_page ) : 0;
$offset      = ( $bn_paged - 1 ) * $bn_per_page;
$page_ids    = array_slice( $all_ids, $offset, $bn_per_page );

// ── Page title (rendered as <h1> inside the surface) ────────────────────────────
$page_title = $is_own_profile
	? __( 'Your followers', 'buddynext' )
	: sprintf(
		/* translators: %s: member display name */
		__( 'Followers · %s', 'buddynext' ),
		$profile_user->display_name
	);

/**
 * Fires before the profile followers inner content.
 *
 * @param int $user_id Profile owner.
 */
do_action( 'buddynext_profile_followers_before', (int) $user_id );
?>
<?php
// Member cards are rendered by parts/member-grid.php (its own
// `buddynext/members` island), so this page needs no interactive wrapper.
?>
<div class="bn-connections bn-followers">

	<!-- Header -->
	<div class="bn-connections-header">
		<h1 class="bn-connections-title"><?php echo esc_html( $page_title ); ?></h1>
		<?php if ( $total > 0 ) : ?>
			<span class="bn-connections-count">
				<?php
				/* translators: %s: formatted follower count */
				printf( esc_html( _n( '%s follower', '%s followers', $total, 'buddynext' ) ), esc_html( number_format_i18n( $total ) ) );
				?>
			</span>
		<?php endif; ?>
		<a href="<?php echo esc_url( PageRouter::profile_url( $user_id ) ); ?>" class="bn-connections-back">
			<?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Back to profile', 'buddynext' ); ?>
		</a>
	</div>

	<?php if ( ! empty( $pending_ids ) ) : ?>
		<section class="bn-follow-requests" aria-labelledby="bn-follow-requests-title">
			<header class="bn-follow-requests__head">
				<h2 id="bn-follow-requests-title" class="bn-follow-requests__title">
					<?php
					printf(
						/* translators: %d: number of pending follow requests */
						esc_html( _n( 'Pending request', 'Pending requests', count( $pending_ids ), 'buddynext' ) ) . ' <span class="bn-follow-requests__count">%d</span>',
						(int) count( $pending_ids )
					);
					?>
				</h2>
				<p class="bn-follow-requests__sub">
					<?php esc_html_e( 'Your account is private. Approve who can see your posts.', 'buddynext' ); ?>
				</p>
			</header>

			<ul class="bn-follow-requests__list" role="list">
				<?php
				foreach ( $pending_ids as $req_id ) :
					$req_id   = (int) $req_id;
					$req_user = get_userdata( $req_id );
					if ( ! $req_user ) {
						continue; }
					$req_name   = $req_user->display_name;
					$req_handle = '@' . $req_user->user_nicename;
					$req_avatar = get_avatar_url( $req_id, array( 'size' => 96 ) );
					$req_url    = PageRouter::profile_url( $req_id );
					$req_ctx    = wp_json_encode(
						array(
							'followerId' => $req_id,
							'targetName' => $req_user->user_nicename,
							'hidden'     => false,
							'busy'       => false,
							'restUrl'    => rest_url( 'buddynext/v1' ),
							'nonce'      => wp_create_nonce( 'wp_rest' ),
						)
					);
					?>
					<li class="bn-follow-requests__row"
						data-wp-interactive="buddynext/follow-requests"
						data-wp-context='<?php echo esc_attr( (string) $req_ctx ); ?>'
						data-wp-bind--hidden="state.rowHidden"
					>
						<a href="<?php echo esc_url( $req_url ); ?>" class="bn-follow-requests__avatar" aria-hidden="true" tabindex="-1">
							<img src="<?php echo esc_attr( $req_avatar ); ?>" alt="" loading="lazy" />
						</a>
						<div class="bn-follow-requests__id">
							<a href="<?php echo esc_url( $req_url ); ?>" class="bn-follow-requests__name"><?php echo esc_html( $req_name ); ?></a>
							<span class="bn-follow-requests__handle"><?php echo esc_html( $req_handle ); ?></span>
						</div>
						<div class="bn-follow-requests__actions">
							<button type="button"
								class="bn-btn bn-btn--sm"
								data-variant="ghost"
								data-wp-on--click="actions.reject"
							><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
							<button type="button"
								class="bn-btn bn-btn--sm bn-btn--primary"
								data-variant="primary"
								data-wp-on--click="actions.approve"
							><?php esc_html_e( 'Approve', 'buddynext' ); ?></button>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<!-- Grid -->
	<?php if ( ! empty( $page_ids ) ) : ?>
		<?php
		buddynext_get_template(
			'parts/member-grid.php',
			array(
				'members'   => array_values(
					array_filter(
						array_map( static fn( $fid ) => get_userdata( (int) $fid ), $page_ids )
					)
				),
				'viewer_id' => $current_user_id,
			)
		);
		?>
	<?php else : ?>
		<div class="bn-connections-grid bn-followers-grid" role="list">
			<div class="bn-empty-state">
				<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></div>
				<div class="bn-empty-title">
					<?php
					if ( $is_own_profile ) {
						esc_html_e( 'No followers yet', 'buddynext' );
					} else {
						printf(
							/* translators: %s: member display name */
							esc_html__( '%s has no followers yet.', 'buddynext' ),
							esc_html( $profile_user->display_name )
						);
					}
					?>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<?php
	if ( $total_pages > 1 ) {
		buddynext_get_template(
			'parts/pagination.php',
			array(
				'current'    => (int) $bn_paged,
				'total'      => (int) $total_pages,
				'aria_label' => __( 'Followers page navigation', 'buddynext' ),
				'mid_size'   => 2,
			)
		);
	}
	?>
</div>
