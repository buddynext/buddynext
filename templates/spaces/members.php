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
use BuddyNext\Profile\AvatarService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

// ── Context ─────────────────────────────────────────────────────────────────────
$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( $space_id <= 0 ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// Space details for the header — canonical hydrate via SpaceService (no SQL here).
$bn_member_svc = new SpaceMemberService();
$space         = ( new SpaceService() )->get( $space_id );

if ( null === $space ) {
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

// ── Current viewer ──────────────────────────────────────────────────────────────
$current_user_id = get_current_user_id();

// ── Fetch members via the service (search / role / suspension via args) ──────────
// The list AND the total honour the SAME filters so pagination never drifts:
// get_members() + count_members() share the args array (exclude_suspended folds
// in ModerationService::moderation_exclude_sql() — no inline NOT EXISTS SQL here).
$bn_member_args = array(
	'search'            => $bn_sm_search,
	'role'              => $bn_sm_role,
	'exclude_suspended' => true,
);

$bn_member_rows = $bn_member_svc->get_members( $space_id, $current_user_id, $bn_per_page, $bn_offset, $bn_member_args );

// get_members() orders by join date; re-group owner → moderator → member in PHP to
// preserve the previous visual order without a FIELD() sort in the service.
$bn_role_rank = array(
	'owner'     => 0,
	'moderator' => 1,
	'member'    => 2,
);
usort(
	$bn_member_rows,
	static function ( array $a, array $b ) use ( $bn_role_rank ): int {
		$ra = $bn_role_rank[ $a['role'] ?? 'member' ] ?? 3;
		$rb = $bn_role_rank[ $b['role'] ?? 'member' ] ?? 3;
		if ( $ra !== $rb ) {
			return $ra <=> $rb;
		}
		return strcmp( (string) ( $a['joined_at'] ?? '' ), (string) ( $b['joined_at'] ?? '' ) );
	}
);

// Filtered total — matches the listed rows so the page count is correct.
$total_members = $bn_member_svc->count_members( $space_id, $current_user_id, $bn_member_args );
$total_pages   = (int) ceil( $total_members / $bn_per_page );

// ── Viewer management capability (mirrors SpaceController permissions) ────────────
// Remove member: owner/moderator or site admin. Change role: owner or site admin only.
$bn_viewer_role   = $current_user_id > 0
	? $bn_member_svc->get_role( $space_id, $current_user_id )
	: '';
$bn_is_site_admin = current_user_can( 'manage_options' );
$bn_can_remove    = $current_user_id > 0 && ( in_array( $bn_viewer_role, array( 'owner', 'moderator' ), true ) || $bn_is_site_admin );
$bn_can_set_role  = $current_user_id > 0 && ( 'owner' === $bn_viewer_role || $bn_is_site_admin );

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
$mem_type    = (string) ( $space['type'] ?? 'open' );
$mem_privacy = array(
	'tone'  => \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( $mem_type ),
	'label' => \BuddyNext\Spaces\SpaceTypeRegistry::instance()->label( $mem_type ),
);
?>
<div
	class="bn-sh-stack bn-space-members"
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

	<!-- Unified space header + nav bar (same as every other space tab). -->
	<?php
	buddynext_get_template(
		'parts/space-header.php',
		array(
			'space_id'   => $space_id,
			'active_tab' => 'members',
		)
	);
	?>

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
		<?php if ( ! empty( $bn_member_rows ) ) : ?>
			<?php foreach ( $bn_member_rows as $member ) : ?>
				<?php
				$member_id     = (int) ( $member['user_id'] ?? 0 );
				$member_name   = (string) ( $member['display_name'] ?? '' );
				$member_handle = '@' . (string) ( $member['user_nicename'] ?? '' );
				$member_role   = (string) ( $member['role'] ?? 'member' );
				$member_avatar = get_avatar_url( $member_id, array( 'size' => 128 ) );
				$member_inits  = AvatarService::initials_for( $member_name );
				$member_url    = PageRouter::profile_url( $member_id );
				$role_meta     = bn_space_role_meta( $member_role );

				// Format joined date. joined_at is stored in UTC; convert to the
				// site's configured timezone for display via get_date_from_gmt().
				$joined_formatted = '';
				if ( ! empty( $member['joined_at'] ) ) {
					$joined_local     = get_date_from_gmt( (string) $member['joined_at'], (string) get_option( 'date_format' ) );
					$joined_formatted = '' !== $joined_local
						? sprintf(
							/* translators: %s: human-readable date */
							__( 'Joined %s', 'buddynext' ),
							$joined_local
						)
						: '';
				}
				?>
				<?php
				// Can the viewer manage this member (set role / remove)? These
				// secondary actions live behind the overflow kebab pinned top-right
				// (the shared .bn-md-card menu), so the card body shows at most two
				// primary actions (View + Message).
				$bn_can_manage = ( $current_user_id !== $member_id && 'owner' !== $member_role && ( $bn_can_remove || $bn_can_set_role ) );
				?>
				<article class="bn-card bn-md-card" data-interactive role="listitem">
					<?php if ( $bn_can_manage ) : ?>
						<div
							class="bn-md-card__menu-wrap"
							data-wp-context='{"menuOpen":false}'
							data-wp-on-document--click="actions.closeMenuOnOutside"
						>
							<button
								type="button"
								class="bn-md-card__menu"
								aria-haspopup="true"
								aria-expanded="false"
								aria-label="
								<?php
									/* translators: %s: member name */
									printf( esc_attr__( 'More actions for %s', 'buddynext' ), esc_attr( $member_name ) );
								?>
								"
								data-wp-on--click="actions.toggleMenu"
								data-wp-bind--aria-expanded="state.menuExpanded"
							><?php buddynext_icon( 'more-horizontal' ); ?></button>
							<div
								class="bn-md-card__menu-pop"
								role="menu"
								data-wp-bind--hidden="!state.menuOpen"
								hidden
							>
								<?php if ( $bn_can_set_role && 'moderator' === $member_role ) : ?>
									<button type="button" class="bn-md-card__menu-item" role="menuitem" data-user-id="<?php echo esc_attr( (string) $member_id ); ?>" data-role="member" data-wp-on--click="actions.changeRole"><?php esc_html_e( 'Make member', 'buddynext' ); ?></button>
								<?php elseif ( $bn_can_set_role ) : ?>
									<button type="button" class="bn-md-card__menu-item" role="menuitem" data-user-id="<?php echo esc_attr( (string) $member_id ); ?>" data-role="moderator" data-wp-on--click="actions.changeRole"><?php esc_html_e( 'Make moderator', 'buddynext' ); ?></button>
								<?php endif; ?>
								<?php if ( $bn_can_remove ) : ?>
									<button type="button" class="bn-md-card__menu-item bn-md-card__menu-item--danger" role="menuitem" data-user-id="<?php echo esc_attr( (string) $member_id ); ?>" data-wp-on--click="actions.removeMember"><?php esc_html_e( 'Remove from space', 'buddynext' ); ?></button>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<div class="bn-md-card__cover" data-tone="<?php echo esc_attr( $role_meta['tone'] ); ?>" aria-hidden="true"></div>

					<a href="<?php echo esc_url( $member_url ); ?>" class="bn-md-card__avatar-link" tabindex="-1" aria-hidden="true">
						<span class="bn-avatar bn-md-card__avatar" data-size="xl">
							<?php if ( $member_avatar ) : ?>
								<img src="<?php echo esc_url( $member_avatar ); ?>" alt="" width="72" height="72" loading="lazy" decoding="async">
							<?php else : ?>
								<?php echo esc_html( $member_inits ); ?>
							<?php endif; ?>
						</span>
					</a>

					<div class="bn-md-card__body">
						<div class="bn-md-card__identity">
							<h3 class="bn-md-card__name"><a href="<?php echo esc_url( $member_url ); ?>"><?php echo esc_html( $member_name ); ?></a></h3>
							<p class="bn-md-card__handle"><?php echo esc_html( $member_handle ); ?></p>
						</div>

						<span class="bn-badge bn-md-card__type" data-tone="<?php echo esc_attr( $role_meta['tone'] ); ?>"><?php echo esc_html( $role_meta['label'] ); ?></span>

						<?php if ( '' !== $joined_formatted ) : ?>
							<p class="bn-md-card__meta"><?php echo esc_html( $joined_formatted ); ?></p>
						<?php endif; ?>

						<div class="bn-md-card__actions">
							<a href="<?php echo esc_url( $member_url ); ?>" class="bn-btn" data-variant="ghost" data-size="sm"><?php esc_html_e( 'View', 'buddynext' ); ?></a>
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
						</div>
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
