<?php
/**
 * BuddyNext template part: profile people panel (followers / following / connections).
 *
 * The registry content seam for the three profile relationship tabs. One
 * parameterized panel — owner-only pending-request inbox (follow OR connection),
 * then the capped member grid, then the per-relation empty state. Rendered by
 * ProfileNav's followers/following/connections `render` callables, which
 * self-fetch the rows; this part only paints the bundle it is handed (no reveal
 * wrapper — the active panel is the only one rendered).
 *
 * @package BuddyNext
 *
 * @var string      $relation     Required. 'followers' | 'following' | 'connections'.
 * @var WP_User[]   $members      Capped member list for the grid.
 * @var WP_User[]   $pending      Owner-only pending requests (follow or connection).
 * @var int         $viewer_id    Current viewer user ID.
 * @var bool        $is_owner     Whether the viewer owns this profile.
 * @var string      $display_name Profile display name (empty states).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pp_relation = isset( $relation ) ? (string) $relation : 'followers';
$bn_pp_members  = isset( $members ) && is_array( $members ) ? $members : array();
$bn_pp_pending  = isset( $pending ) && is_array( $pending ) ? $pending : array();
$bn_pp_viewer   = isset( $viewer_id ) ? (int) $viewer_id : 0;
$bn_pp_is_owner = ! empty( $is_owner );
$bn_pp_name     = isset( $display_name ) ? (string) $display_name : '';

// Pending requests are connection-style only for the connections tab; followers
// use the follow-request store. Following has no inbox.
$bn_pp_is_conn = 'connections' === $bn_pp_relation;

if ( ! empty( $bn_pp_pending ) && 'following' !== $bn_pp_relation ) :
	$bn_pp_store = $bn_pp_is_conn ? 'buddynext/connection-requests' : 'buddynext/follow-requests';

	// Batch-prime mutual-connection counts + headline for the whole request list,
	// so a long pending queue (popular/private accounts) never fires a per-row
	// mutual query. Mutual counts reuse the directory's one-query self-join; the
	// headline reads from primed usermeta.
	$bn_pp_ids = array();
	foreach ( $bn_pp_pending as $bn_pp_u ) {
		if ( $bn_pp_u instanceof WP_User ) {
			$bn_pp_ids[] = (int) $bn_pp_u->ID;
		}
	}
	$bn_pp_ids    = array_values( array_unique( array_filter( $bn_pp_ids ) ) );
	$bn_pp_mutual = array();
	if ( $bn_pp_viewer > 0 && ! empty( $bn_pp_ids ) && function_exists( 'buddynext_service' ) ) {
		$bn_pp_mutual = buddynext_service( 'connections' )->mutual_ids_for( $bn_pp_viewer, $bn_pp_ids );
		update_meta_cache( 'user', $bn_pp_ids );
	}
	?>
	<section class="bn-follow-requests" aria-label="<?php echo $bn_pp_is_conn ? esc_attr__( 'Pending connection requests', 'buddynext' ) : esc_attr__( 'Pending follow requests', 'buddynext' ); ?>">
		<header class="bn-follow-requests__head">
			<h3 class="bn-follow-requests__title">
				<?php
				printf(
					'%s <span class="bn-follow-requests__count">%d</span>',
					$bn_pp_is_conn
						? esc_html( _n( 'Connection request', 'Connection requests', count( $bn_pp_pending ), 'buddynext' ) )
						: esc_html( _n( 'Pending request', 'Pending requests', count( $bn_pp_pending ), 'buddynext' ) ),
					(int) count( $bn_pp_pending )
				);
				?>
			</h3>
			<p class="bn-follow-requests__sub">
				<?php
				echo $bn_pp_is_conn
					? esc_html__( 'People who want to connect with you. Accept to connect.', 'buddynext' )
					: esc_html__( 'Your account is private. Approve who can follow you.', 'buddynext' );
				?>
			</p>
		</header>
		<ul class="bn-follow-requests__list" role="list">
			<?php
			foreach ( $bn_pp_pending as $bn_pp_req ) :
				if ( ! $bn_pp_req instanceof WP_User ) {
					continue;
				}
				$bn_pp_rid  = (int) $bn_pp_req->ID;
				$bn_pp_rurl = \BuddyNext\Core\PageRouter::profile_url( $bn_pp_rid );
				$bn_pp_ctx  = wp_json_encode(
					$bn_pp_is_conn
						? array(
							'requesterId' => $bn_pp_rid,
							'targetName'  => $bn_pp_req->user_nicename,
							'hidden'      => false,
							'busy'        => false,
							'restUrl'     => rest_url( 'buddynext/v1' ),
							'nonce'       => wp_create_nonce( 'wp_rest' ),
						)
						: array(
							'followerId' => $bn_pp_rid,
							'targetName' => $bn_pp_req->user_nicename,
							'hidden'     => false,
							'busy'       => false,
							'restUrl'    => rest_url( 'buddynext/v1' ),
							'nonce'      => wp_create_nonce( 'wp_rest' ),
						)
				);
				?>
				<li class="bn-follow-requests__row" data-wp-interactive="<?php echo esc_attr( $bn_pp_store ); ?>" data-wp-context='<?php echo esc_attr( (string) $bn_pp_ctx ); ?>' data-wp-bind--hidden="state.rowHidden">
					<a href="<?php echo esc_url( $bn_pp_rurl ); ?>" class="bn-follow-requests__avatar" aria-hidden="true" tabindex="-1">
						<img src="<?php echo esc_url( get_avatar_url( $bn_pp_rid, array( 'size' => 96 ) ) ); ?>" alt="" loading="lazy" />
					</a>
					<div class="bn-follow-requests__id">
						<a href="<?php echo esc_url( $bn_pp_rurl ); ?>" class="bn-follow-requests__name"><?php echo esc_html( $bn_pp_req->display_name ); ?></a>
						<span class="bn-follow-requests__handle">@<?php echo esc_html( $bn_pp_req->user_nicename ); ?></span>
						<?php
						$bn_pp_headline = (string) get_user_meta( $bn_pp_rid, 'bn_headline', true );
						if ( '' !== $bn_pp_headline ) :
							?>
							<span class="bn-follow-requests__headline"><?php echo esc_html( $bn_pp_headline ); ?></span>
						<?php endif; ?>
						<?php
						$bn_pp_mc = isset( $bn_pp_mutual[ $bn_pp_rid ] ) ? count( (array) $bn_pp_mutual[ $bn_pp_rid ] ) : 0;
						if ( $bn_pp_mc > 0 ) :
							?>
							<span class="bn-follow-requests__mutual">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of mutual connections */
										_n( '%d mutual connection', '%d mutual connections', $bn_pp_mc, 'buddynext' ),
										$bn_pp_mc
									)
								);
								?>
							</span>
						<?php endif; ?>
					</div>
					<div class="bn-follow-requests__actions">
						<?php if ( $bn_pp_is_conn ) : ?>
							<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.decline"><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
							<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.accept"><?php esc_html_e( 'Accept', 'buddynext' ); ?></button>
						<?php else : ?>
							<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.reject"><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
							<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.approve"><?php esc_html_e( 'Approve', 'buddynext' ); ?></button>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

<?php if ( ! empty( $bn_pp_members ) ) : ?>
	<?php
	buddynext_get_template(
		'parts/member-grid.php',
		array(
			'members'   => $bn_pp_members,
			'viewer_id' => $bn_pp_viewer,
		)
	);
	?>
<?php else : ?>
	<div class="bn-empty-state">
		<div class="bn-empty-icon" aria-hidden="true">
			<?php buddynext_icon( 'following' === $bn_pp_relation ? 'user-plus' : 'users' ); ?>
		</div>
		<div class="bn-empty-title">
			<?php
			if ( 'followers' === $bn_pp_relation ) {
				echo esc_html(
					$bn_pp_is_owner
						? __( 'No followers yet', 'buddynext' )
						: sprintf( /* translators: %s: member name */ __( '%s has no followers yet.', 'buddynext' ), $bn_pp_name )
				);
			} elseif ( 'following' === $bn_pp_relation ) {
				echo esc_html(
					$bn_pp_is_owner
						? __( 'You are not following anyone yet', 'buddynext' )
						: sprintf( /* translators: %s: member name */ __( '%s is not following anyone yet.', 'buddynext' ), $bn_pp_name )
				);
			} else {
				echo esc_html(
					$bn_pp_is_owner
						? __( 'No connections yet', 'buddynext' )
						: sprintf( /* translators: %s: member name */ __( '%s has no connections yet.', 'buddynext' ), $bn_pp_name )
				);
			}
			?>
		</div>
	</div>
<?php endif; ?>
