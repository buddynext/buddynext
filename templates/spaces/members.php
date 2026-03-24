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
 * @param string $role Role slug: 'owner', 'mod', or 'member'.
 * @return string Translated label.
 */
if ( ! function_exists( 'bn_space_role_label' ) ) {
	/**
	 * Return the display label for a space member role.
	 *
	 * @param string $role Role slug: 'owner', 'mod', or 'member'.
	 * @return string Translated label.
	 */
	function bn_space_role_label( string $role ): string {
		$labels = array(
			'owner'  => __( 'Owner', 'buddynext' ),
			'mod'    => __( 'Moderator', 'buddynext' ),
			'member' => __( 'Member', 'buddynext' ),
		);
		return $labels[ $role ] ?? ucfirst( $role );
	}
}
?>
<style>
/* ── BuddyNext design tokens ── */
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs:  11px;  --text-sm: 13px;  --text-base: 15px;
	--text-lg:  17px;  --text-xl: 20px;  --text-2xl: 24px;
	--leading-body: 1.7;
	--bg:          #ffffff;
	--bg-subtle:   #f8f8f7;
	--bg-hover:    #f1f1f0;
	--surface:     #ffffff;
	--border:      #e8e8e5;
	--border-soft: #f1f1ee;
	--text-1:      #37352f;
	--text-2:      #787774;
	--text-3:      #aeaca8;
	--brand:       #0073aa;
	--brand-light: #e8f4fb;
	--brand-hover: #005f8e;
	--green:       #059669;
	--green-bg:    #ecfdf5;
	--red:         #dc2626;
	--red-bg:      #fef2f2;
	--jetonomy:    #5b21b6;
	--jetonomy-bg: #f5f3ff;
	--s1: 4px;  --s2: 8px;   --s3: 12px;  --s4: 16px;
	--s5: 20px; --s6: 24px;  --s8: 32px;
	--radius-sm: 6px;  --radius: 10px;  --radius-lg: 14px;
}
[data-theme="dark"] {
	--bg:          #191919;
	--bg-subtle:   #202020;
	--bg-hover:    #2a2a2a;
	--surface:     #252525;
	--border:      #333330;
	--border-soft: #2c2c2a;
	--text-1:      #e8e8e6;
	--text-2:      #9b9b97;
	--text-3:      #6b6b67;
	--brand:       #4dabdb;
	--brand-light: #1a2e3a;
	--brand-hover: #5fbfe8;
	--jetonomy:    #a78bfa;
	--jetonomy-bg: #1e1830;
	--green:       #34d399;
	--green-bg:    #0d2420;
}

/* ── Wrapper ── */
.bn-space-members {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	line-height: var(--leading-body);
	background: var(--bg-subtle);
	min-height: 60vh;
	padding: var(--s6) var(--s5);
	-webkit-font-smoothing: antialiased;
}

/* ── Header ── */
.bn-sm-header {
	display: flex;
	align-items: center;
	gap: var(--s3);
	margin-bottom: var(--s5);
	flex-wrap: wrap;
}
.bn-sm-space-icon {
	width: 44px;
	height: 44px;
	border-radius: var(--radius);
	background: var(--brand-light);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 22px;
	flex-shrink: 0;
	overflow: hidden;
}
.bn-sm-space-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: var(--radius); }
.bn-sm-title {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	color: var(--text-1);
	letter-spacing: -0.5px;
}
.bn-sm-count {
	background: var(--brand-light);
	color: var(--brand);
	font-size: var(--text-sm);
	font-weight: 700;
	padding: 3px 10px;
	border-radius: 20px;
}
.bn-sm-back {
	font-size: var(--text-sm);
	color: var(--text-2);
	text-decoration: none;
	display: flex;
	align-items: center;
	gap: var(--s1);
	margin-left: auto;
}
.bn-sm-back:hover { color: var(--brand); }

/* ── Grid ── */
.bn-sm-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: var(--s3);
}

/* ── Member card ── */
.bn-member-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s5) var(--s4);
	text-align: center;
	position: relative;
	transition: border-color 0.15s, box-shadow 0.15s;
}
.bn-member-card:hover {
	border-color: var(--brand);
	box-shadow: 0 2px 12px rgba(0, 115, 170, 0.1);
}

/* ── Avatar ── */
.bn-avatar {
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto var(--s3);
	width: 64px;
	height: 64px;
	font-size: 22px;
	overflow: hidden;
	flex-shrink: 0;
}
.bn-avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }

/* ── Card content ── */
.bn-member-name {
	font-weight: 700;
	font-size: var(--text-sm);
	margin-bottom: var(--s1);
	color: var(--text-1);
}
.bn-member-name a { color: inherit; text-decoration: none; }
.bn-member-name a:hover { color: var(--brand); }
.bn-member-handle { font-size: var(--text-xs); color: var(--text-3); margin-bottom: var(--s2); }
.bn-joined-at { font-size: var(--text-xs); color: var(--text-3); margin-bottom: var(--s3); }

/* ── Role badge ── */
.bn-role-badge {
	display: inline-block;
	font-size: var(--text-xs);
	font-weight: 700;
	padding: 3px var(--s2);
	border-radius: 20px;
	margin-bottom: var(--s3);
}
.bn-role-owner  { background: #f5f3ff; color: #5b21b6; }
.bn-role-mod    { background: var(--brand-light); color: var(--brand); }
.bn-role-member { background: var(--bg-subtle); color: var(--text-2); border: 1px solid var(--border); }
[data-theme="dark"] .bn-role-owner  { background: var(--jetonomy-bg); color: var(--jetonomy); }
[data-theme="dark"] .bn-role-mod    { background: var(--brand-light); color: var(--brand); }
[data-theme="dark"] .bn-role-member { background: var(--bg-hover); color: var(--text-3); border-color: var(--border); }

/* ── Card actions ── */
.bn-card-actions {
	display: flex;
	gap: var(--s2);
	justify-content: center;
	flex-wrap: wrap;
}
.bn-btn-view {
	background: var(--brand);
	color: #fff;
	padding: 6px var(--s3);
	border-radius: 14px;
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	text-decoration: none;
	transition: background 0.15s;
}
.bn-btn-view:hover { background: var(--brand-hover); color: #fff; }
.bn-btn-message {
	background: var(--bg);
	color: var(--text-2);
	padding: 6px 10px;
	border-radius: 14px;
	font-size: var(--text-base);
	cursor: pointer;
	border: 1.5px solid var(--border);
	font-family: var(--font-body);
	line-height: 1;
	transition: border-color 0.15s;
	text-decoration: none;
}
.bn-btn-message:hover { border-color: var(--brand); }

/* ── Empty state ── */
.bn-empty-state {
	grid-column: 1 / -1;
	text-align: center;
	padding: var(--s8) var(--s5);
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	color: var(--text-3);
}
.bn-empty-icon  { font-size: 40px; margin-bottom: var(--s3); }
.bn-empty-title { font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; color: var(--text-2); margin-bottom: var(--s2); }
.bn-empty-text  { font-size: var(--text-sm); color: var(--text-2); }

/* ── Pagination ── */
.bn-pagination {
	display: flex;
	justify-content: center;
	gap: var(--s2);
	margin-top: var(--s5);
	flex-wrap: wrap;
}
.bn-page-btn {
	padding: var(--s2) var(--s3);
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	background: var(--surface);
	text-decoration: none;
	transition: border-color 0.12s, color 0.12s;
}
.bn-page-btn:hover { border-color: var(--brand); color: var(--brand); }
.bn-page-btn.current { background: var(--brand); border-color: var(--brand); color: #fff; font-weight: 700; }

/* ── Dark mode overrides ── */
[data-theme="dark"] .bn-member-card { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-btn-message { background: var(--surface); }

/* ── Mobile ── */
@media (max-width: 1024px) {
	.bn-sm-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 640px) {
	.bn-space-members { padding: var(--s3); }
	.bn-sm-grid { grid-template-columns: repeat(2, 1fr); gap: var(--s2); }
	.bn-member-card { padding: var(--s4) var(--s3); }
	.bn-sm-back { margin-left: 0; width: 100%; }
	.bn-sm-title { font-size: var(--text-xl); }
}
</style>

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
				} elseif ( 'mod' === $member_role ) {
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
