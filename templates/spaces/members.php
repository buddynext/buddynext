<?php
/**
 * BuddyNext space members template.
 *
 * Displays the active members of a single space, ordered by role
 * (owner first, then moderators, then members) and join date ascending.
 * Shows member cards with avatar, name, handle, role badge. Paginated 24 per page.
 *
 * Context variable:
 *   $space_id (int) — the space's primary key.
 *
 * Overridable: copy to {theme}/buddynext/spaces/members.php
 *
 * REST endpoint: GET buddynext/v1/spaces/{id}/members
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

global $wpdb;

// ── Context ─────────────────────────────────────────────────────────────────────
$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( $space_id <= 0 ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// Fetch space details for the header.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$space = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT id, name, avatar_url, member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$space_id
	)
);

if ( ! $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Pagination ──────────────────────────────────────────────────────────────────
$bn_per_page = 24;
$bn_paged    = max( 1, absint( get_query_var( 'paged', 1 ) ) );
$bn_offset   = ( $bn_paged - 1 ) * $bn_per_page;

// ── Fetch members ───────────────────────────────────────────────────────────────
$space_members_table = $wpdb->prefix . 'bn_space_members';

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$members = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT u.ID, u.display_name, u.user_nicename, sm.role, sm.joined_at
		 FROM {$space_members_table} sm
		 JOIN {$wpdb->users} u ON u.ID = sm.user_id
		 WHERE sm.space_id = %d
		   AND sm.status = 'active'
		   AND NOT EXISTS (
		       SELECT 1 FROM {$wpdb->prefix}bn_user_suspensions sus
		       WHERE sus.user_id = sm.user_id
		         AND sus.lifted_at IS NULL
		         AND (sus.expires_at IS NULL OR sus.expires_at > NOW())
		   )
		   AND NOT EXISTS (
		       SELECT 1 FROM {$wpdb->usermeta} um
		       WHERE um.user_id = sm.user_id
		         AND um.meta_key = 'bn_shadow_banned'
		         AND um.meta_value = '1'
		   )
		 ORDER BY FIELD(sm.role, 'owner', 'moderator', 'member'), sm.joined_at ASC
		 LIMIT %d OFFSET %d",
		$space_id,
		$bn_per_page,
		$bn_offset
	)
);
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

$total_members = absint( $space->member_count );
$total_pages   = (int) ceil( $total_members / $bn_per_page );

// ── Current viewer ──────────────────────────────────────────────────────────────
$current_user_id = get_current_user_id();

// ── Avatar colours ──────────────────────────────────────────────────────────────
$bn_sm_colours = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#dc2626', '#d97706' );

/**
 * Return initials from a display name.
 *
 * @param string $name Display name.
 * @return string Up to two uppercase characters.
 */
if ( ! function_exists( 'bn_space_members_initials' ) ) {
	/**
	 * Return initials from a display name.
	 *
	 * @param string $name Display name.
	 * @return string Up to two uppercase characters.
	 */
	function bn_space_members_initials( string $name ): string {
		$parts = array_filter( explode( ' ', trim( $name ) ) );
		if ( count( $parts ) >= 2 ) {
			return strtoupper( substr( (string) reset( $parts ), 0, 1 ) . substr( (string) end( $parts ), 0, 1 ) );
		}
		return strtoupper( substr( $name, 0, 2 ) );
	}
}

/**
 * Return the display label for a space member role.
 *
 * @param string $role Role slug: 'owner', 'moderator', or 'member'.
 * @return string Translated label.
 */
if ( ! function_exists( 'bn_space_role_label' ) ) {
	/**
	 * Return the display label for a space member role.
	 *
	 * @param string $role Role slug: 'owner', 'moderator', or 'member'.
	 * @return string Translated label.
	 */
	function bn_space_role_label( string $role ): string {
		$labels = array(
			'owner'     => __( 'Owner', 'buddynext' ),
			'moderator' => __( 'Moderator', 'buddynext' ),
			'member'    => __( 'Member', 'buddynext' ),
		);
		return $labels[ $role ] ?? ucfirst( $role );
	}
}

$bn_nav_active = 'spaces';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div
	class="bn-space-members"
	data-wp-interactive="buddynext/space-members"
	data-wp-context='{"spaceId":<?php echo absint( $space_id ); ?>,"restUrl":"<?php echo esc_js( rest_url( 'buddynext/v1' ) ); ?>","nonce":"<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>"}'
>

	<!-- Header -->
	<div class="bn-sm-header">
		<div class="bn-sm-space-icon" aria-hidden="true">
			<?php if ( ! empty( $space->avatar_url ) ) : ?>
				<img
					src="<?php echo esc_url( $space->avatar_url ); ?>"
					alt=""
					width="44"
					height="44"
					loading="lazy"
				>
			<?php else : ?>
				<?php buddynext_icon( 'home' ); ?>
			<?php endif; ?>
		</div>
		<h1 class="bn-sm-title">
			<?php
			printf(
				/* translators: %s: space name */
				esc_html__( '%s Members', 'buddynext' ),
				esc_html( $space->name )
			);
			?>
		</h1>
		<span class="bn-sm-count">
			<?php
			/* translators: %s: formatted member count */
			printf( esc_html__( '%s Members', 'buddynext' ), esc_html( number_format_i18n( $total_members ) ) );
			?>
		</span>
		<a href="<?php echo esc_url( PageRouter::space_url( $space_id ) ); ?>" class="bn-sm-back">
			<?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Back to space', 'buddynext' ); ?>
		</a>
	</div>

	<!-- Members grid -->
	<div
		class="bn-sm-grid"
		role="list"
		aria-label="<?php esc_attr_e( 'Space members', 'buddynext' ); ?>"
	>
		<?php if ( ! empty( $members ) ) : ?>
			<?php foreach ( $members as $member ) : ?>
				<?php
				$member_id     = (int) $member->ID;
				$member_name   = $member->display_name;
				$member_handle = '@' . $member->user_nicename;
				$member_role   = $member->role;
				$member_avatar = get_avatar_url( $member_id, array( 'size' => 128 ) );
				$member_colour = $bn_sm_colours[ $member_id % count( $bn_sm_colours ) ];
				$member_inits  = bn_space_members_initials( $member_name );
				$member_url    = PageRouter::profile_url( $member_id );
				$role_label    = bn_space_role_label( $member_role );

				// Determine badge CSS class.
				if ( 'owner' === $member_role ) {
					$role_class = 'bn-role-owner';
				} elseif ( 'moderator' === $member_role ) {
					$role_class = 'bn-role-mod';
				} else {
					$role_class = 'bn-role-member';
				}

				// Format joined date.
				$joined_formatted = '';
				if ( ! empty( $member->joined_at ) ) {
					$joined_ts        = strtotime( $member->joined_at );
					$joined_formatted = $joined_ts
						? sprintf(
							/* translators: %s: human-readable date */
							__( 'Joined %s', 'buddynext' ),
							date_i18n( get_option( 'date_format' ), $joined_ts )
						)
						: '';
				}
				?>
				<article class="bn-member-card" role="listitem">
					<a href="<?php echo esc_url( $member_url ); ?>" aria-label="<?php echo esc_attr( $member_name ); ?>">
						<?php if ( $member_avatar ) : ?>
							<img
								src="<?php echo esc_url( $member_avatar ); ?>"
								alt="<?php echo esc_attr( $member_name ); ?>"
								class="bn-avatar"
								width="64"
								height="64"
								loading="lazy"
							>
						<?php else : ?>
							<div
								class="bn-avatar"
								style="background:<?php echo esc_attr( $member_colour ); ?>;"
								aria-hidden="true"
							><?php echo esc_html( $member_inits ); ?></div>
						<?php endif; ?>
					</a>

					<div class="bn-member-name">
						<a href="<?php echo esc_url( $member_url ); ?>"><?php echo esc_html( $member_name ); ?></a>
					</div>
					<div class="bn-member-handle"><?php echo esc_html( $member_handle ); ?></div>

					<span class="bn-role-badge <?php echo esc_attr( $role_class ); ?>">
						<?php echo esc_html( $role_label ); ?>
					</span>

					<?php if ( '' !== $joined_formatted ) : ?>
						<div class="bn-joined-at"><?php echo esc_html( $joined_formatted ); ?></div>
					<?php endif; ?>

					<div class="bn-card-actions">
						<a href="<?php echo esc_url( $member_url ); ?>" class="bn-btn-view">
							<?php esc_html_e( 'View', 'buddynext' ); ?>
						</a>
						<?php if ( is_user_logged_in() && $current_user_id !== $member_id ) : ?>
							<a
								href="<?php echo esc_url( PageRouter::messages_url() ); ?>"
								class="bn-btn-message"
								aria-label="
								<?php
									/* translators: %s: member name */
									printf( esc_attr__( 'Message %s', 'buddynext' ), esc_attr( $member_name ) );
								?>
								"
							><?php buddynext_icon( 'message-circle' ); ?></a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		<?php else : ?>
			<div class="bn-empty-state">
				<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'home' ); ?></div>
				<div class="bn-empty-title"><?php esc_html_e( 'No members found', 'buddynext' ); ?></div>
				<p class="bn-empty-text"><?php esc_html_e( 'This space has no active members yet.', 'buddynext' ); ?></p>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
		<nav class="bn-pagination" aria-label="<?php esc_attr_e( 'Members page navigation', 'buddynext' ); ?>">
			<?php if ( $bn_paged > 1 ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'paged', $bn_paged - 1 ) ); ?>"
					class="bn-page-btn"
					aria-label="<?php esc_attr_e( 'Previous page', 'buddynext' ); ?>"
				><?php buddynext_icon( 'chevron-left' ); ?></a>
			<?php endif; ?>

			<?php
			$bn_page_start = max( 1, $bn_paged - 2 );
			$bn_page_end   = min( $total_pages, $bn_paged + 2 );
			for ( $page_num = $bn_page_start; $page_num <= $bn_page_end; $page_num++ ) :
				?>
				<?php if ( $page_num === $bn_paged ) : ?>
					<span class="bn-page-btn current" aria-current="page"><?php echo esc_html( (string) $page_num ); ?></span>
				<?php else : ?>
					<a
						href="<?php echo esc_url( add_query_arg( 'paged', $page_num ) ); ?>"
						class="bn-page-btn"
					><?php echo esc_html( (string) $page_num ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>

			<?php if ( $bn_paged < $total_pages ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'paged', $bn_paged + 1 ) ); ?>"
					class="bn-page-btn"
					aria-label="<?php esc_attr_e( 'Next page', 'buddynext' ); ?>"
				><?php buddynext_icon( 'chevron-right' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

</div><!-- .bn-space-members -->
