<?php
/**
 * BuddyNext space members template.
 *
 * Displays the active members of a single space, ordered by role
 * (owner first, then moderators, then members) and join date ascending.
 * Composes from v2 primitives (.bn-card, .bn-avatar, .bn-badge, .bn-btn).
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
		"SELECT id, name, avatar_url, member_count, type FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$space_id
	)
);

if ( ! $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Filters ─────────────────────────────────────────────────────────────────────
$bn_sm_search = isset( $_GET['bn_sm_q'] ) ? sanitize_text_field( wp_unslash( $_GET['bn_sm_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_sm_role   = isset( $_GET['bn_sm_role'] ) ? sanitize_key( wp_unslash( $_GET['bn_sm_role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $bn_sm_role, array( 'owner', 'moderator', 'member' ), true ) ) {
	$bn_sm_role = '';
}

// ── Pagination ──────────────────────────────────────────────────────────────────
$bn_per_page = 24;
$bn_paged    = max( 1, absint( get_query_var( 'paged', 1 ) ) );
$bn_offset   = ( $bn_paged - 1 ) * $bn_per_page;

// ── Build WHERE clauses ─────────────────────────────────────────────────────────
$space_members_table = $wpdb->prefix . 'bn_space_members';

$bn_where_clauses = array( 'sm.space_id = %d', "sm.status = 'active'" );
$bn_where_args    = array( $space_id );

if ( '' !== $bn_sm_role ) {
	$bn_where_clauses[] = 'sm.role = %s';
	$bn_where_args[]    = $bn_sm_role;
}

if ( '' !== $bn_sm_search ) {
	$bn_where_clauses[] = '(u.display_name LIKE %s OR u.user_nicename LIKE %s)';
	$bn_like            = '%' . $wpdb->esc_like( $bn_sm_search ) . '%';
	$bn_where_args[]    = $bn_like;
	$bn_where_args[]    = $bn_like;
}

$bn_where_sql = implode( ' AND ', $bn_where_clauses );

// ── Fetch members ───────────────────────────────────────────────────────────────
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$members = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT u.ID, u.display_name, u.user_nicename, sm.role, sm.joined_at
		 FROM {$space_members_table} sm
		 JOIN {$wpdb->users} u ON u.ID = sm.user_id
		 WHERE {$bn_where_sql}
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
		array_merge( $bn_where_args, array( $bn_per_page, $bn_offset ) )
	)
);
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

$total_members = absint( $space->member_count );
$total_pages   = (int) ceil( $total_members / $bn_per_page );

// ── Current viewer ──────────────────────────────────────────────────────────────
$current_user_id = get_current_user_id();

// ── Viewer management capability (mirrors SpaceController permissions) ────────────
// Remove member: owner/moderator or site admin. Change role: owner or site admin only.
$bn_viewer_role   = $current_user_id > 0
	? ( new \BuddyNext\Spaces\SpaceMemberService() )->get_role( $space_id, $current_user_id )
	: '';
$bn_is_site_admin = current_user_can( 'manage_options' );
$bn_can_remove    = $current_user_id > 0 && ( in_array( $bn_viewer_role, array( 'owner', 'moderator' ), true ) || $bn_is_site_admin );
$bn_can_set_role  = $current_user_id > 0 && ( 'owner' === $bn_viewer_role || $bn_is_site_admin );

/**
 * Return initials from a display name.
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
 * Return tone + label for a space member role.
 */
if ( ! function_exists( 'bn_space_role_meta' ) ) {
	/**
	 * Return tone + label for a space member role.
	 *
	 * @param string $role Role slug: 'owner', 'moderator', 'member', or 'banned'.
	 * @return array{tone:string,label:string}
	 */
	function bn_space_role_meta( string $role ): array {
		$map = array(
			'owner'     => array(
				'tone'  => 'accent',
				'label' => __( 'Owner', 'buddynext' ),
			),
			'moderator' => array(
				'tone'  => 'info',
				'label' => __( 'Moderator', 'buddynext' ),
			),
			'member'    => array(
				'tone'  => 'default',
				'label' => __( 'Member', 'buddynext' ),
			),
			'banned'    => array(
				'tone'  => 'danger',
				'label' => __( 'Banned', 'buddynext' ),
			),
		);
		return $map[ $role ] ?? array(
			'tone'  => 'default',
			'label' => ucfirst( $role ),
		);
	}
}

// Build filter base URL — preserves query args other than role/q/paged.
$bn_filter_base = remove_query_arg( array( 'bn_sm_role', 'bn_sm_q', 'paged' ) );

// Privacy badge tone + label — single source via the space-type registry.
$mem_type    = (string) ( $space->type ?? 'open' );
$mem_privacy = array(
	'tone'  => \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( $mem_type ),
	'label' => \BuddyNext\Spaces\SpaceTypeRegistry::instance()->label( $mem_type ),
);
?>
<div
	class="bn-space-members"
	data-wp-interactive="buddynext/space-members"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'spaceId'   => absint( $space_id ),
				'restUrl'   => rest_url( 'buddynext/v1' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			)
		)
	);
	?>
	'
>

	<!-- Header (space-home hero shape) -->
	<div class="bn-sh-header">
		<div class="bn-sh-cover"></div>
		<div class="bn-sh-inner">
			<div class="bn-sh-avatar" aria-hidden="true">
				<?php if ( ! empty( $space->avatar_url ) ) : ?>
					<img
						src="<?php echo esc_url( $space->avatar_url ); ?>"
						alt=""
						loading="lazy"
					>
				<?php else : ?>
					<?php buddynext_icon( 'home' ); ?>
				<?php endif; ?>
			</div>

			<div class="bn-sh-info">
				<h1 class="bn-sh-name">
					<?php echo esc_html( $space->name ); ?>
					<span class="bn-badge" data-tone="<?php echo esc_attr( $mem_privacy['tone'] ); ?>"><?php echo esc_html( $mem_privacy['label'] ); ?></span>
				</h1>
				<div class="bn-sh-meta">
					<span><?php buddynext_icon( 'users' ); ?>
						<?php
						/* translators: %s: formatted member count */
						printf( esc_html__( '%s members', 'buddynext' ), esc_html( number_format_i18n( $total_members ) ) );
						?>
					</span>
				</div>
			</div>

			<div class="bn-sh-actions">
				<a
					href="<?php echo esc_url( PageRouter::space_url( $space_id ) ); ?>"
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
				><?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Back to space', 'buddynext' ); ?></a>
			</div>
		</div>
	</div>

	<!-- Filter bar -->
	<div class="bn-card bn-space-members__filter">
		<form method="get" action="" class="bn-space-members__filter-form" role="search">
			<?php
			// Preserve any path-routing query vars other than the filters we own.
			foreach ( $_GET as $bn_q_key => $bn_q_val ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( in_array( $bn_q_key, array( 'bn_sm_q', 'bn_sm_role', 'paged' ), true ) ) {
					continue;
				}
				printf(
					'<input type="hidden" name="%s" value="%s">',
					esc_attr( sanitize_key( $bn_q_key ) ),
					esc_attr( sanitize_text_field( wp_unslash( $bn_q_val ) ) )
				);
			}
			?>
			<label class="bn-sr-only" for="bn_sm_q"><?php esc_html_e( 'Search members', 'buddynext' ); ?></label>
			<input
				type="search"
				id="bn_sm_q"
				name="bn_sm_q"
				class="bn-input bn-space-members__filter-search"
				placeholder="<?php esc_attr_e( 'Search members…', 'buddynext' ); ?>"
				value="<?php echo esc_attr( $bn_sm_search ); ?>"
			>

			<label class="bn-sr-only" for="bn_sm_role"><?php esc_html_e( 'Filter by role', 'buddynext' ); ?></label>
			<select id="bn_sm_role" name="bn_sm_role" class="bn-select bn-space-members__filter-role">
				<option value="" <?php selected( $bn_sm_role, '' ); ?>><?php esc_html_e( 'All roles', 'buddynext' ); ?></option>
				<option value="owner" <?php selected( $bn_sm_role, 'owner' ); ?>><?php esc_html_e( 'Owner', 'buddynext' ); ?></option>
				<option value="moderator" <?php selected( $bn_sm_role, 'moderator' ); ?>><?php esc_html_e( 'Moderator', 'buddynext' ); ?></option>
				<option value="member" <?php selected( $bn_sm_role, 'member' ); ?>><?php esc_html_e( 'Member', 'buddynext' ); ?></option>
			</select>

			<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
				<?php esc_html_e( 'Filter', 'buddynext' ); ?>
			</button>

			<?php if ( '' !== $bn_sm_search || '' !== $bn_sm_role ) : ?>
				<a href="<?php echo esc_url( $bn_filter_base ); ?>" class="bn-btn" data-variant="ghost" data-size="md">
					<?php esc_html_e( 'Reset', 'buddynext' ); ?>
				</a>
			<?php endif; ?>
		</form>
	</div>

	<!-- Members grid -->
	<div
		class="bn-space-members__grid"
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
				$member_inits  = bn_space_members_initials( $member_name );
				$member_url    = PageRouter::profile_url( $member_id );
				$role_meta     = bn_space_role_meta( $member_role );

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
				<article class="bn-card bn-space-members__card" data-interactive role="listitem">
					<a
						href="<?php echo esc_url( $member_url ); ?>"
						class="bn-space-members__card-avatar-link"
						aria-label="<?php echo esc_attr( $member_name ); ?>"
					>
						<span class="bn-avatar" data-size="xl" aria-hidden="true">
							<?php if ( $member_avatar ) : ?>
								<img
									src="<?php echo esc_url( $member_avatar ); ?>"
									alt=""
									loading="lazy"
								>
							<?php else : ?>
								<?php echo esc_html( $member_inits ); ?>
							<?php endif; ?>
						</span>
					</a>

					<div class="bn-space-members__card-name">
						<a href="<?php echo esc_url( $member_url ); ?>"><?php echo esc_html( $member_name ); ?></a>
					</div>
					<div class="bn-space-members__card-handle"><?php echo esc_html( $member_handle ); ?></div>

					<span class="bn-badge" data-tone="<?php echo esc_attr( $role_meta['tone'] ); ?>">
						<?php echo esc_html( $role_meta['label'] ); ?>
					</span>

					<?php if ( '' !== $joined_formatted ) : ?>
						<div class="bn-space-members__card-joined"><?php echo esc_html( $joined_formatted ); ?></div>
					<?php endif; ?>

					<div class="bn-space-members__card-actions">
						<a
							href="<?php echo esc_url( $member_url ); ?>"
							class="bn-btn"
							data-variant="ghost"
							data-size="sm"
						><?php esc_html_e( 'View', 'buddynext' ); ?></a>
						<?php if ( is_user_logged_in() && $current_user_id !== $member_id ) : ?>
							<a
								href="<?php echo esc_url( PageRouter::messages_url() ); ?>"
								class="bn-btn"
								data-variant="ghost"
								data-size="sm"
								aria-label="
								<?php
									/* translators: %s: member name */
									printf( esc_attr__( 'Message %s', 'buddynext' ), esc_attr( $member_name ) );
								?>
								"
							><?php buddynext_icon( 'message-circle' ); ?> <?php esc_html_e( 'Message', 'buddynext' ); ?></a>
						<?php endif; ?>
						<?php if ( $current_user_id !== $member_id && 'owner' !== $member_role && ( $bn_can_remove || $bn_can_set_role ) ) : ?>
							<?php if ( $bn_can_set_role && 'moderator' === $member_role ) : ?>
								<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-user-id="<?php echo esc_attr( (string) $member_id ); ?>" data-role="member" data-wp-on--click="actions.changeRole"><?php esc_html_e( 'Make member', 'buddynext' ); ?></button>
							<?php elseif ( $bn_can_set_role ) : ?>
								<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-user-id="<?php echo esc_attr( (string) $member_id ); ?>" data-role="moderator" data-wp-on--click="actions.changeRole"><?php esc_html_e( 'Make moderator', 'buddynext' ); ?></button>
							<?php endif; ?>
							<?php if ( $bn_can_remove ) : ?>
								<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-user-id="<?php echo esc_attr( (string) $member_id ); ?>" data-wp-on--click="actions.removeMember" aria-label="<?php /* translators: %s: member name */ printf( esc_attr__( 'Remove %s', 'buddynext' ), esc_attr( $member_name ) ); ?>"><?php buddynext_icon( 'user-minus' ); ?> <?php esc_html_e( 'Remove', 'buddynext' ); ?></button>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		<?php else : ?>
			<div class="bn-card bn-space-members__empty">
				<span class="bn-space-members__empty-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></span>
				<div class="bn-space-members__empty-title"><?php esc_html_e( 'No members found', 'buddynext' ); ?></div>
				<p class="bn-space-members__empty-desc">
					<?php if ( '' !== $bn_sm_search || '' !== $bn_sm_role ) : ?>
						<?php esc_html_e( 'Try clearing the filter or search.', 'buddynext' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'This space has no active members yet.', 'buddynext' ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
		<nav class="bn-space-members__pagination" aria-label="<?php esc_attr_e( 'Members page navigation', 'buddynext' ); ?>">
			<?php if ( $bn_paged > 1 ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'paged', $bn_paged - 1 ) ); ?>"
					class="bn-btn"
					data-variant="ghost"
					data-size="sm"
					aria-label="<?php esc_attr_e( 'Previous page', 'buddynext' ); ?>"
				><?php buddynext_icon( 'chevron-left' ); ?></a>
			<?php endif; ?>

			<?php
			$bn_page_start = max( 1, $bn_paged - 2 );
			$bn_page_end   = min( $total_pages, $bn_paged + 2 );
			for ( $page_num = $bn_page_start; $page_num <= $bn_page_end; $page_num++ ) :
				?>
				<?php if ( $page_num === $bn_paged ) : ?>
					<span class="bn-btn" data-variant="primary" data-size="sm" aria-current="page"><?php echo esc_html( (string) $page_num ); ?></span>
				<?php else : ?>
					<a
						href="<?php echo esc_url( add_query_arg( 'paged', $page_num ) ); ?>"
						class="bn-btn"
						data-variant="ghost"
						data-size="sm"
					><?php echo esc_html( (string) $page_num ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>

			<?php if ( $bn_paged < $total_pages ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'paged', $bn_paged + 1 ) ); ?>"
					class="bn-btn"
					data-variant="ghost"
					data-size="sm"
					aria-label="<?php esc_attr_e( 'Next page', 'buddynext' ); ?>"
				><?php buddynext_icon( 'chevron-right' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

</div><!-- .bn-space-members -->
