<?php
/**
 * BuddyNext profile connections template.
 *
 * Displays the accepted connections of a given user. Shows member cards
 * with avatar, name, handle, and a "Connected" badge. Paginated 12 per page.
 *
 * Context variable:
 *   $user_id (int) — whose connections to show.
 *
 * Overridable: copy to {theme}/buddynext/profile/connections.php
 *
 * REST endpoint: GET buddynext/v1/users/{id}/connections
 *
 * @package BuddyNext
 * @since   1.0.0
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

// ── Pagination ──────────────────────────────────────────────────────────────────
$bn_per_page = 12;
$bn_paged    = max( 1, absint( get_query_var( 'paged', 1 ) ) );
$bn_offset   = ( $bn_paged - 1 ) * $bn_per_page;

// ── Fetch accepted connections (ConnectionService owns the SQL) ──────────────
// connections() returns an array of the other party's user IDs (accepted only),
// cached; connection_count() is the matching cached total.
$connections       = buddynext_service( 'connections' )->connections( $user_id, $bn_per_page, $bn_offset );
$total_connections = buddynext_service( 'connections' )->connection_count( $user_id );
$total_pages       = (int) ceil( $total_connections / $bn_per_page );

// ── Current viewer context ──────────────────────────────────────────────────────
$current_user_id = get_current_user_id();
$is_own_profile  = ( $current_user_id === $user_id );

/**
 * Fires before the profile connections inner content.
 *
 * @param int $user_id Profile owner.
 */
do_action( 'buddynext_profile_connections_before', isset( $user_id ) ? (int) $user_id : 0 );
?>
<?php
// The member cards below are rendered by parts/member-grid.php, which is its
// own `buddynext/members` Interactivity island (Follow / Connect / Message /
// kebab). This page therefore needs no interactive wrapper of its own.
?>
<div class="bn-connections">

	<!-- Header -->
	<div class="bn-connections-header">
		<h1 class="bn-connections-title">
			<?php
			if ( $is_own_profile ) {
				esc_html_e( 'Your Connections', 'buddynext' );
			} else {
				printf(
					/* translators: %s: member display name */
					esc_html__( "%s's Connections", 'buddynext' ),
					esc_html( $profile_user->display_name )
				);
			}
			?>
		</h1>
		<?php if ( $total_connections > 0 ) : ?>
			<span class="bn-connections-count">
				<?php
				/* translators: %s: formatted connection count */
				printf( esc_html__( '%s Connections', 'buddynext' ), esc_html( number_format_i18n( $total_connections ) ) );
				?>
			</span>
		<?php endif; ?>
		<a href="<?php echo esc_url( PageRouter::profile_url( $user_id ) ); ?>" class="bn-connections-back">
			<?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Back to profile', 'buddynext' ); ?>
		</a>
	</div>

	<!-- Connections grid — unified member cards (cover + actions). -->
	<?php if ( ! empty( $connections ) ) : ?>
		<?php
		buddynext_get_template(
			'parts/member-grid.php',
			array(
				'members'   => array_values(
					array_filter(
						array_map( static fn( $id ) => get_userdata( (int) $id ), $connections )
					)
				),
				'viewer_id' => $current_user_id,
			)
		);
		?>
	<?php else : ?>
		<div class="bn-empty-state">
			<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></div>
			<div class="bn-empty-title">
				<?php
				if ( $is_own_profile ) {
					esc_html_e( 'No connections yet', 'buddynext' );
				} else {
					esc_html_e( 'No connections to show', 'buddynext' );
				}
				?>
			</div>
			<p class="bn-empty-text">
				<?php
				if ( $is_own_profile ) {
					esc_html_e( 'Connect with other members to see them here.', 'buddynext' );
				} else {
					printf(
						/* translators: %s: member display name */
						esc_html__( "%s hasn't made any connections public yet.", 'buddynext' ),
						esc_html( $profile_user->display_name )
					);
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	buddynext_get_template(
		'parts/pagination.php',
		array(
			'current'    => (int) $bn_paged,
			'total'      => (int) $total_pages,
			'aria_label' => __( 'Connections page navigation', 'buddynext' ),
			'mid_size'   => 2,
		)
	);
	?>

</div><!-- .bn-connections -->
