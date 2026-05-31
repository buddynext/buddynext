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

global $wpdb;

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

// ── Fetch accepted connections ──────────────────────────────────────────────────
$connections_table = $wpdb->prefix . 'bn_connections';

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$connections = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT u.ID, u.display_name, u.user_nicename
		 FROM {$connections_table} c
		 JOIN {$wpdb->users} u ON (
		     (c.requester_id = %d AND u.ID = c.recipient_id)
		     OR (c.recipient_id = %d AND u.ID = c.requester_id)
		 )
		 WHERE c.status = 'accepted'
		 ORDER BY c.created_at DESC
		 LIMIT %d OFFSET %d",
		$user_id,
		$user_id,
		$bn_per_page,
		$bn_offset
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$total_connections = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*)
		 FROM {$connections_table} c
		 WHERE c.status = 'accepted'
		   AND (c.requester_id = %d OR c.recipient_id = %d)",
		$user_id,
		$user_id
	)
);
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$total_pages = (int) ceil( $total_connections / $bn_per_page );

// ── Current viewer context ──────────────────────────────────────────────────────
$current_user_id = get_current_user_id();
$is_own_profile  = ( $current_user_id === $user_id );

// ── Avatar colours ─────────────────────────────────────────────────────────────
$bn_conn_colours = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#dc2626', '#d97706' );

/**
 * Return initials from a display name.
 *
 * @param string $name Display name.
 * @return string Up to two uppercase characters.
 */
if ( ! function_exists( 'bn_connections_initials' ) ) {
	/**
	 * Return initials from a display name.
	 *
	 * @param string $name Display name.
	 * @return string Up to two uppercase characters.
	 */
	function bn_connections_initials( string $name ): string {
		$parts = array_filter( explode( ' ', trim( $name ) ) );
		if ( count( $parts ) >= 2 ) {
			return strtoupper( substr( (string) reset( $parts ), 0, 1 ) . substr( (string) end( $parts ), 0, 1 ) );
		}
		return strtoupper( substr( $name, 0, 2 ) );
	}
}

/**
 * Fires before the profile connections inner content.
 *
 * @param int $user_id Profile owner.
 */
do_action( 'buddynext_profile_connections_before', isset( $user_id ) ? (int) $user_id : 0 );
?>
<div
	class="bn-connections"
	data-wp-interactive="buddynext/connections"
	data-wp-context='{"userId":<?php echo absint( $user_id ); ?>,"restUrl":"<?php echo esc_js( rest_url( 'buddynext/v1' ) ); ?>","nonce":"<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>"}'
>

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

	<!-- Connections grid -->
	<div
		class="bn-connections-grid"
		role="list"
		aria-label="<?php esc_attr_e( 'Connections', 'buddynext' ); ?>"
	>
		<?php if ( ! empty( $connections ) ) : ?>
			<?php foreach ( $connections as $connection ) : ?>
				<?php
				$conn_id     = (int) $connection->ID;
				$conn_name   = $connection->display_name;
				$conn_handle = '@' . $connection->user_nicename;
				$conn_avatar = get_avatar_url( $conn_id, array( 'size' => 128 ) );
				$conn_colour = $bn_conn_colours[ $conn_id % count( $bn_conn_colours ) ];
				$conn_inits  = bn_connections_initials( $conn_name );
				$conn_url    = PageRouter::profile_url( $conn_id );
				$msg_url     = add_query_arg( array( 'recipient' => $conn_id ), PageRouter::messages_url() );
				?>
				<article class="bn-member-card" role="listitem">
					<a href="<?php echo esc_url( $conn_url ); ?>" aria-label="<?php echo esc_attr( $conn_name ); ?>">
						<?php if ( $conn_avatar ) : ?>
							<img
								src="<?php echo esc_url( $conn_avatar ); ?>"
								alt="<?php echo esc_attr( $conn_name ); ?>"
								class="bn-avatar"
								width="64"
								height="64"
								loading="lazy"
							>
						<?php else : ?>
							<div
								class="bn-avatar"
								style="background:<?php echo esc_attr( $conn_colour ); ?>;"
								aria-hidden="true"
							><?php echo esc_html( $conn_inits ); ?></div>
						<?php endif; ?>
					</a>

					<div class="bn-member-name">
						<a href="<?php echo esc_url( $conn_url ); ?>"><?php echo esc_html( $conn_name ); ?></a>
					</div>
					<div class="bn-member-handle"><?php echo esc_html( $conn_handle ); ?></div>

					<div class="bn-connected-badge" aria-label="<?php esc_attr_e( 'Connected', 'buddynext' ); ?>">
						<?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Connected', 'buddynext' ); ?>
					</div>

					<div class="bn-card-actions">
						<a href="<?php echo esc_url( $conn_url ); ?>" class="bn-btn-view">
							<?php esc_html_e( 'View', 'buddynext' ); ?>
						</a>
						<?php if ( is_user_logged_in() && $current_user_id !== $conn_id ) : ?>
							<a
								href="<?php echo esc_url( $msg_url ); ?>"
								class="bn-btn-message"
								aria-label="
								<?php
									/* translators: %s: member name */
									printf( esc_attr__( 'Message %s', 'buddynext' ), esc_attr( $conn_name ) );
								?>
								"
							><?php buddynext_icon( 'message-circle' ); ?></a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
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
	</div>

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
