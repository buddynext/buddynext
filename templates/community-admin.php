<?php
/**
 * Template: Community Admin Panel
 *
 * Site-wide admin overview for community managers. Shows platform stats,
 * recent signups, pending join requests, open reports, and the activity
 * log. Accessible to users with manage_options or buddynext_community_admin.
 *
 * No context vars required — all data is fetched here.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Permission gate ───────────────────────────────────────────────────────────

if ( ! current_user_can( 'manage_options' ) && ! buddynext_can( get_current_user_id(), 'buddynext-spaces/moderate' ) ) {
	wp_die( esc_html__( 'You do not have permission to access the Community Admin Panel.', 'buddynext' ) );
}

$current_user_id = get_current_user_id();
$bn_admin_user   = get_userdata( $current_user_id );

// ── Active section ────────────────────────────────────────────────────────────

// As the routed hub the active section is the /{slug}/{section}/ path segment,
// exposed as the bn_ca_section query var; the [buddynext_community_admin] shortcode
// embed (which never runs through the hub router) still uses the legacy ?bn_admin=
// param, so fall back to it. Default 'overview' renders at the bare /{slug}/.
$bn_ca_route_section = sanitize_key( (string) get_query_var( 'bn_ca_section', '' ) );
$admin_section       = '' !== $bn_ca_route_section
	? $bn_ca_route_section
	: ( isset( $_GET['bn_admin'] ) ? sanitize_key( wp_unslash( $_GET['bn_admin'] ) ) : 'overview' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
// The sidebar tabs are add_query_arg( 'bn_admin', ..., $admin_base ) links, so
// $admin_base MUST be the URL this panel is actually being viewed at. As the routed
// hub that is buddynext_community_admin_url(); but via the [buddynext_community_admin]
// shortcode the panel lives on whatever page the owner placed it on, so the caller
// passes that page's permalink. Hardcoding the hub slug sent every tab to
// /bn-community-admin/, which 404s when no page exists there.
$admin_base = ( isset( $admin_base ) && '' !== (string) $admin_base ) ? (string) $admin_base : buddynext_community_admin_url();

// ── Members section data (only when that section is active) ────────────────────
// Reuses Admin\Members::list_members() — already paginated (LIMIT/OFFSET), search
// and total-count aware — so the list is big-site safe. Roles come from the
// RoleService community-role layer (bn_community_role user meta).
$bn_ca_members  = null;
$bn_ca_m_page   = 1;
$bn_ca_m_search = '';
// Role changes are admin-only: a WordPress admin, or a member holding the
// community 'admin' role. Moderators can view the panel but not reassign roles.
// Granting the top Admin role stays site-administrator-only (mirrors the REST gate).
// Computed section-independently: the sidebar note (rendered on every section)
// needs the same admin-vs-moderator distinction as the Members role controls.
$bn_ca_roles           = buddynext_service( 'roles' );
$bn_ca_can_assign      = current_user_can( 'manage_options' )
	|| ( is_object( $bn_ca_roles ) && method_exists( $bn_ca_roles, 'is_admin' ) && $bn_ca_roles->is_admin( get_current_user_id() ) );
$bn_ca_can_grant_admin = current_user_can( 'manage_options' );
if ( 'members' === $admin_section ) {
	$bn_ca_m_page        = isset( $_GET['mpage'] ) ? max( 1, (int) $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$bn_ca_m_search      = isset( $_GET['ms'] ) ? sanitize_text_field( wp_unslash( $_GET['ms'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$bn_ca_admin_members = buddynext_service( 'admin_members' );
	$bn_ca_members       = ( is_object( $bn_ca_admin_members ) && method_exists( $bn_ca_admin_members, 'list_members' ) )
		? $bn_ca_admin_members->list_members(
			array(
				'page'     => $bn_ca_m_page,
				'per_page' => 20,
				'search'   => $bn_ca_m_search,
				'orderby'  => 'registered',
				'order'    => 'DESC',
			)
		)
		: array(
			'members' => array(),
			'total'   => 0,
			'pages'   => 1,
		);
}

// ── Spaces section data (only when that section is active) ────────────────────
// SpaceService::list_spaces_with_total() is LIMIT/OFFSET + COUNT-aware and cached,
// so the management list is big-site safe. is_admin => true surfaces secret and
// archived spaces to a site manager.
$bn_ca_spaces_list = null;
$bn_ca_sp_page     = 1;
$bn_ca_sp_total    = 0;
if ( 'spaces' === $admin_section ) {
	$bn_ca_sp_page = isset( $_GET['mpage'] ) ? max( 1, (int) $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$bn_ca_sp_svc  = buddynext_service( 'spaces' );
	if ( is_object( $bn_ca_sp_svc ) && method_exists( $bn_ca_sp_svc, 'list_spaces_with_total' ) ) {
		$bn_ca_sp_res      = $bn_ca_sp_svc->list_spaces_with_total(
			array(
				'page'     => $bn_ca_sp_page,
				'per_page' => 20,
				'is_admin' => true,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);
		$bn_ca_spaces_list = (array) ( $bn_ca_sp_res['items'] ?? array() );
		$bn_ca_sp_total    = (int) ( $bn_ca_sp_res['total'] ?? 0 );
	} else {
		$bn_ca_spaces_list = array();
	}
}

// ── Invites section data (only when that section is active) ───────────────────
// InviteService::get_invites()/count_invites() are LIMIT/OFFSET + COUNT-aware.
// The panel lists pending invites read-only; bulk sending stays in wp-admin.
$bn_ca_invites   = null;
$bn_ca_inv_page  = 1;
$bn_ca_inv_total = 0;
if ( 'invites' === $admin_section ) {
	$bn_ca_inv_page = isset( $_GET['mpage'] ) ? max( 1, (int) $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$bn_ca_inv_svc  = buddynext_service( 'invite' );
	if ( is_object( $bn_ca_inv_svc ) && method_exists( $bn_ca_inv_svc, 'get_invites' ) ) {
		$bn_ca_invites   = (array) $bn_ca_inv_svc->get_invites( 'pending', $bn_ca_inv_page, 20 );
		$bn_ca_inv_total = (int) $bn_ca_inv_svc->count_invites( 'pending' );
	} else {
		$bn_ca_invites = array();
	}
}

// ── Platform stats (consolidated via AdminHub::overview_stats) ─────────────────

$bn_ca_stats         = \BuddyNext\Admin\AdminHub::overview_stats();
$total_members       = (int) $bn_ca_stats['members_total'];
$new_today           = (int) $bn_ca_stats['members_new_today'];
$active_spaces       = (int) $bn_ca_stats['active_spaces'];
$open_reports        = (int) $bn_ca_stats['open_reports'];
$urgent_reports      = (int) $bn_ca_stats['urgent_reports'];
$posts_today         = (int) $bn_ca_stats['posts_today'];
$posts_pct           = (int) $bn_ca_stats['posts_pct'];
$total_pending_joins = (int) $bn_ca_stats['pending_joins'];

// ── Recent signups (WP-native, registration-ordered) ──────────────────────────

$recent_signups = get_users(
	array(
		'orderby' => 'registered',
		'order'   => 'DESC',
		'number'  => 10,
		'fields'  => array( 'ID', 'display_name', 'user_email', 'user_registered' ),
	)
);

// ── Pending space join requests (cross-space) ─────────────────────────────────

$bn_ca_spaces  = buddynext_service( 'spaces' );
$pending_joins = $bn_ca_spaces->get_pending_join_requests_all( 10 );

// ── Pending moderation appeals (suspension appeals awaiting review) ───────────

$bn_ca_mod             = buddynext_service( 'moderation' );
$pending_appeals       = $bn_ca_mod->get_pending_appeals( 25 );
$total_pending_appeals = $bn_ca_mod->count_pending_appeals();

// Batch-resolve appellant display names + the suspensions being contested so the
// review surface shows full context in two queries, never one query per row.
$bn_appeal_user_ids = array();
$bn_appeal_susp_ids = array();
foreach ( $pending_appeals as $bn_ca_ap ) {
	$bn_appeal_user_ids[] = (int) $bn_ca_ap['user_id'];
	$bn_appeal_susp_ids[] = (int) $bn_ca_ap['suspension_id'];
}
$bn_appeal_names = array();
if ( ! empty( $bn_appeal_user_ids ) ) {
	foreach ( get_users(
		array(
			'include' => array_values( array_unique( $bn_appeal_user_ids ) ),
			'fields'  => array( 'ID', 'display_name' ),
		)
	) as $bn_ca_u ) {
		$bn_appeal_names[ (int) $bn_ca_u->ID ] = (string) $bn_ca_u->display_name;
	}
}
$bn_appeal_susps = $bn_ca_mod->get_suspensions_by_ids( $bn_appeal_susp_ids );

// ── Open reports (cross-space, consolidated per content group) ─────────────────

$bn_ca_queue = $bn_ca_mod->get_queue(
	array(
		'per_page' => 10,
		'page'     => 1,
	)
);
$report_rows = $bn_ca_queue['items'];

// ── Recent activity log (site-wide) ──────────────────────────────────────────

$bn_ca_log     = buddynext_service( 'activity_log' );
$activity_rows = $bn_ca_log->recent( 20 );

// ── Helpers ───────────────────────────────────────────────────────────────────

if ( ! function_exists( 'bn_time_diff' ) ) {
	/**
	 * Human-readable time diff label (e.g. "3h ago").
	 *
	 * @param string $datetime MySQL datetime string.
	 * @return string Localized time diff.
	 */
	function bn_time_diff( string $datetime ): string {
		return sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours". */ __( '%s ago', 'buddynext' ), human_time_diff( strtotime( $datetime ), time() ) );
	}
}

if ( ! function_exists( 'bn_activity_icon' ) ) {
	/**
	 * Return an activity log SVG icon based on action type.
	 *
	 * @param string $action Activity action slug.
	 * @return string SVG icon markup via buddynext_get_icon().
	 */
	function bn_activity_icon( string $action ): string {
		$map = array(
			'new_member'      => buddynext_get_icon( 'user' ),
			'space_created'   => buddynext_get_icon( 'home' ),
			'report_resolved' => buddynext_get_icon( 'shield' ),
			'post_flagged'    => buddynext_get_icon( 'flag' ),
			'member_approved' => buddynext_get_icon( 'check-circle' ),
			'member_warned'   => buddynext_get_icon( 'ban' ),
			'space_requested' => buddynext_get_icon( 'home' ),
			'invite_sent'     => buddynext_get_icon( 'mail' ),
			'space_approved'  => buddynext_get_icon( 'check-circle' ),
			'profile_flagged' => buddynext_get_icon( 'user' ),
		);
		return $map[ $action ] ?? buddynext_get_icon( 'copy' );
	}
}

if ( ! function_exists( 'bn_report_severity' ) ) {
	/**
	 * Return a report severity label from reporter count.
	 *
	 * @param int $count Number of reporters.
	 * @return string Severity label: 'high', 'medium', or 'low'.
	 */
	function bn_report_severity( int $count ): string {
		if ( $count >= 3 ) {
			return 'high';
		} elseif ( $count >= 2 ) {
			return 'medium';
		}
		return 'low';
	}
}

/**
 * Fires before the community-admin inner content.
 */
do_action( 'buddynext_community_admin_before' );
?>

<?php
// Active section tab. Default 'reports' as the most action-oriented stream.
$allowed_sections = array( 'reports', 'mod_appeals', 'appeals', 'strikes', 'actions' );
$active_section   = isset( $_GET['bn_section'] ) ? sanitize_key( wp_unslash( $_GET['bn_section'] ) ) : 'reports'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $active_section, $allowed_sections, true ) ) {
	$active_section = 'reports';
}

$posts_pct_abs = abs( $posts_pct );
?>

<div class="bn-ca" data-wp-interactive="buddynext/spaces">

	<!-- Admin subheader -->
	<div class="bn-ca-subheader" role="banner">
		<span class="bn-ca-subheader__title">
			<?php buddynext_icon( 'shield' ); ?>
			<?php esc_html_e( 'Community Admin Panel', 'buddynext' ); ?>
		</span>
		<span class="bn-ca-subheader__role"><?php esc_html_e( 'Community Manager', 'buddynext' ); ?></span>
		<span class="bn-ca-subheader__site">
			<?php
			printf(
				/* translators: %s: site name. */
				esc_html__( 'Managing: %s community', 'buddynext' ),
				esc_html( buddynext_site_name() )
			);
			?>
		</span>
		<div class="bn-ca-subheader__actions">
			<?php // Community Admin is an all-front-end surface — no link back to wp-admin. ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bn-ca-subheader__link" data-emphasis="primary">
				<?php buddynext_icon( 'chevron-left' ); ?>
				<?php esc_html_e( 'Back to community', 'buddynext' ); ?>
			</a>
		</div>
	</div>

	<div class="bn-ca-wrap">

		<!-- Sidebar -->
		<aside class="bn-ca-sidebar" aria-label="<?php esc_attr_e( 'Admin navigation', 'buddynext' ); ?>">
			<div class="bn-ca-sidebar__card">
				<div class="bn-ca-sidebar__heading"><?php esc_html_e( 'Admin', 'buddynext' ); ?></div>

				<?php
				$nav_items = array(
					'overview'   => array(
						'icon'  => 'bar-chart',
						'label' => __( 'Overview', 'buddynext' ),
					),
					'members'    => array(
						'icon'  => 'users',
						'label' => __( 'Members', 'buddynext' ),
					),
					'spaces'     => array(
						'icon'  => 'home',
						'label' => __( 'Spaces', 'buddynext' ),
					),
					'moderation' => array(
						'icon'  => 'shield',
						'label' => __( 'Moderation', 'buddynext' ),
						'badge' => $open_reports,
					),
					'reports'    => array(
						'icon'  => 'copy',
						'label' => __( 'Reports', 'buddynext' ),
					),
					'invites'    => array(
						'icon'  => 'mail',
						'label' => __( 'Email invites', 'buddynext' ),
					),
					'settings'   => array(
						'icon'  => 'settings',
						'label' => __( 'Settings', 'buddynext' ),
					),
				);
				// On the routed hub the tabs are clean /{slug}/{section}/ paths; via
				// the [buddynext_community_admin] shortcode embed (an arbitrary host
				// page) they stay ?bn_admin= links, since sub-paths of an arbitrary
				// page would not resolve to a route.
				$bn_ca_routed = ( 'community_admin' === (string) get_query_var( 'bn_hub' ) );
				foreach ( $nav_items as $key => $item ) :
					$is_active = ( $admin_section === $key );
					$tab_href  = $bn_ca_routed
						? ( 'overview' === $key ? $admin_base : trailingslashit( $admin_base . $key ) )
						: add_query_arg( 'bn_admin', $key, $admin_base );
					?>
					<a
						href="<?php echo esc_url( $tab_href ); ?>"
						class="bn-ca-nav-item"
						<?php echo $is_active ? 'aria-current="page"' : ''; ?>
					>
						<span class="bn-ca-nav-item__icon" aria-hidden="true"><?php buddynext_icon( $item['icon'] ); ?></span>
						<?php echo esc_html( $item['label'] ); ?>
						<?php if ( ! empty( $item['badge'] ) && (int) $item['badge'] > 0 ) : ?>
							<span class="bn-ca-nav-item__badge"><?php echo esc_html( number_format_i18n( (int) $item['badge'] ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>

				<?php // All-front-end surface — no "WordPress admin" link out to the backend. ?>

				<p class="bn-ca-sidebar__note">
					<?php
					// The panel is a community-WIDE manager view: it is reachable only by
					// site owners (manage_options) and community-role moderators/admins,
					// who moderate the whole community — not per-space. So the note states
					// what the viewer can actually do here, which is what a moderator most
					// needs to know: role + community-policy controls are owner-only.
					echo esc_html(
						$bn_ca_can_assign
							? __( 'You manage the whole community, including member roles and settings.', 'buddynext' )
							: __( 'You can moderate the whole community. Member roles and community settings are owner-only.', 'buddynext' )
					);
					?>
				</p>
			</div>
		</aside>

		<!-- Main content -->
		<main class="bn-ca-main">

		<?php
		// Section body router. The Members section renders its own list; every
		// other section still shows the Overview dashboard (their dedicated bodies
		// are separate follow-up work). Kept as one if/else so the large Overview
		// markup below is untouched.
		if ( 'members' === $admin_section && is_array( $bn_ca_members ) ) :
			$bn_ca_sec_url = $bn_ca_routed ? trailingslashit( $admin_base . 'members' ) : $admin_base;
			?>
			<section class="bn-ca-card" aria-labelledby="bn-ca-members-title" data-wp-interactive="buddynext/community-admin">
				<header class="bn-ca-card__head">
					<span id="bn-ca-members-title" class="bn-ca-card__title">
						<?php buddynext_icon( 'users' ); ?>
						<?php esc_html_e( 'Members', 'buddynext' ); ?>
						<span class="bn-ca-card__count"><?php echo esc_html( number_format_i18n( (int) $bn_ca_members['total'] ) ); ?></span>
					</span>
				</header>

				<form class="bn-ca-members-search" method="get" action="<?php echo esc_url( $bn_ca_sec_url ); ?>" role="search">
					<?php if ( ! $bn_ca_routed ) : ?>
						<input type="hidden" name="bn_admin" value="members" />
					<?php endif; ?>
					<input
						type="search"
						name="ms"
						class="bn-input"
						value="<?php echo esc_attr( $bn_ca_m_search ); ?>"
						placeholder="<?php esc_attr_e( 'Search members by name, username or email…', 'buddynext' ); ?>"
						aria-label="<?php esc_attr_e( 'Search members', 'buddynext' ); ?>"
					/>
					<button type="submit" class="bn-btn" data-variant="secondary" data-size="sm"><?php esc_html_e( 'Search', 'buddynext' ); ?></button>
				</form>

				<div class="bn-ca-card__body">
					<?php if ( empty( $bn_ca_members['members'] ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No members match your search.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php
						foreach ( $bn_ca_members['members'] as $bn_ca_m ) :
							// list_members() returns associative arrays, not WP_User objects.
							$bn_ca_m_name = '' !== (string) ( $bn_ca_m['display'] ?? '' ) ? (string) $bn_ca_m['display'] : (string) ( $bn_ca_m['login'] ?? '' );
							$bn_ca_m_id   = (int) ( $bn_ca_m['id'] ?? 0 );
							$bn_ca_m_role = ( is_object( $bn_ca_roles ) && method_exists( $bn_ca_roles, 'get_role' ) ) ? $bn_ca_roles->get_role( $bn_ca_m_id ) : 'member';
							$bn_ca_m_init = strtoupper( mb_substr( $bn_ca_m_name, 0, 1 ) );
							$bn_ca_m_reg  = mysql2date( (string) get_option( 'date_format' ), (string) ( $bn_ca_m['registered'] ?? '' ) );
							?>
							<div class="bn-ca-row">
								<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( $bn_ca_m_init ); ?></span>
								<div class="bn-ca-row__body">
									<span class="bn-ca-row__title">
										<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $bn_ca_m_id ) ); ?>"><?php echo esc_html( $bn_ca_m_name ); ?></a>
									</span>
									<span class="bn-ca-row__sub">
										<?php
										printf(
											/* translators: 1: username, 2: registration date. */
											esc_html__( '@%1$s · joined %2$s', 'buddynext' ),
											esc_html( (string) ( $bn_ca_m['handle'] ?? ( $bn_ca_m['login'] ?? '' ) ) ),
											esc_html( $bn_ca_m_reg )
										);
										?>
									</span>
								</div>
								<div class="bn-ca-row__actions">
									<?php
									// Role managers get a live <select> (POSTs to the role endpoint via the
									// buddynext/community-admin store); everyone else sees a read-only badge.
									// The viewer's own row is never editable — no self-demotion lockout.
									$bn_ca_editable = $bn_ca_can_assign && get_current_user_id() !== $bn_ca_m_id;
									if ( $bn_ca_editable ) :
										$bn_ca_ctx_json = (string) wp_json_encode(
											array(
												'userId' => $bn_ca_m_id,
												'role'   => $bn_ca_m_role,
											)
										);
										?>
										<select
											class="bn-input bn-ca-role-select"
											aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member name. */ __( 'Community role for %s', 'buddynext' ), $bn_ca_m_name ) ); ?>"
											data-wp-context='<?php echo esc_attr( $bn_ca_ctx_json ); ?>'
											data-wp-on--change="actions.setRole"
										>
											<option value="member" <?php selected( $bn_ca_m_role, 'member' ); ?>><?php esc_html_e( 'Member', 'buddynext' ); ?></option>
											<option value="moderator" <?php selected( $bn_ca_m_role, 'moderator' ); ?>><?php esc_html_e( 'Moderator', 'buddynext' ); ?></option>
											<?php if ( $bn_ca_can_grant_admin || 'admin' === $bn_ca_m_role ) : ?>
												<option value="admin" <?php selected( $bn_ca_m_role, 'admin' ); ?>><?php esc_html_e( 'Admin', 'buddynext' ); ?></option>
											<?php endif; ?>
										</select>
									<?php elseif ( 'admin' === $bn_ca_m_role ) : ?>
										<span class="bn-badge" data-tone="accent"><?php esc_html_e( 'Admin', 'buddynext' ); ?></span>
									<?php elseif ( 'moderator' === $bn_ca_m_role ) : ?>
										<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Moderator', 'buddynext' ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<?php if ( (int) $bn_ca_members['pages'] > 1 ) : ?>
					<nav class="bn-ca-pagination" aria-label="<?php esc_attr_e( 'Members pages', 'buddynext' ); ?>">
						<?php
						$bn_ca_pg_args = array();
						if ( '' !== $bn_ca_m_search ) {
							$bn_ca_pg_args['ms'] = $bn_ca_m_search;
						}
						if ( ! $bn_ca_routed ) {
							$bn_ca_pg_args['bn_admin'] = 'members';
						}
						if ( $bn_ca_m_page > 1 ) :
							$bn_ca_prev = add_query_arg( array_merge( $bn_ca_pg_args, array( 'mpage' => $bn_ca_m_page - 1 ) ), $bn_ca_sec_url );
							?>
							<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( $bn_ca_prev ); ?>"><?php esc_html_e( 'Previous', 'buddynext' ); ?></a>
						<?php endif; ?>
						<span class="bn-ca-pagination__status">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages. */
								esc_html__( 'Page %1$s of %2$s', 'buddynext' ),
								esc_html( number_format_i18n( $bn_ca_m_page ) ),
								esc_html( number_format_i18n( (int) $bn_ca_members['pages'] ) )
							);
							?>
						</span>
						<?php if ( $bn_ca_m_page < (int) $bn_ca_members['pages'] ) : ?>
							<?php $bn_ca_next = add_query_arg( array_merge( $bn_ca_pg_args, array( 'mpage' => $bn_ca_m_page + 1 ) ), $bn_ca_sec_url ); ?>
							<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( $bn_ca_next ); ?>"><?php esc_html_e( 'Next', 'buddynext' ); ?></a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			</section>

		<?php elseif ( 'spaces' === $admin_section ) : ?>

			<section class="bn-ca-card" aria-labelledby="bn-ca-spaces-title">
				<header class="bn-ca-card__head">
					<span id="bn-ca-spaces-title" class="bn-ca-card__title">
						<?php buddynext_icon( 'home' ); ?>
						<?php esc_html_e( 'Spaces', 'buddynext' ); ?>
						<span class="bn-ca-card__count"><?php echo esc_html( number_format_i18n( (int) $bn_ca_sp_total ) ); ?></span>
					</span>
				</header>
				<div class="bn-ca-card__body">
					<?php if ( empty( $bn_ca_spaces_list ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No spaces yet.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php
						foreach ( $bn_ca_spaces_list as $bn_ca_sp ) :
							$bn_sp_id    = (int) ( $bn_ca_sp['id'] ?? 0 );
							$bn_sp_name  = (string) ( $bn_ca_sp['name'] ?? '' );
							$bn_sp_type  = (string) ( $bn_ca_sp['type'] ?? 'open' );
							$bn_sp_count = (int) ( $bn_ca_sp['member_count'] ?? 0 );
							$bn_sp_init  = \BuddyNext\Profile\AvatarService::initials_for( $bn_sp_name );
							$bn_sp_admin = trailingslashit( \BuddyNext\Core\PageRouter::space_url( $bn_sp_id ) ) . 'admin/';
							$bn_sp_tone  = 'private' === $bn_sp_type ? 'info' : ( 'secret' === $bn_sp_type ? 'amber' : '' );
							?>
							<div class="bn-ca-row">
								<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( $bn_sp_init ); ?></span>
								<div class="bn-ca-row__body">
									<span class="bn-ca-row__title"><a href="<?php echo esc_url( $bn_sp_admin ); ?>"><?php echo esc_html( $bn_sp_name ); ?></a></span>
									<span class="bn-ca-row__sub">
										<?php
										printf(
											/* translators: %s: member count (already i18n-formatted). */
											esc_html( _n( '%s member', '%s members', $bn_sp_count, 'buddynext' ) ),
											esc_html( number_format_i18n( $bn_sp_count ) )
										);
										?>
									</span>
								</div>
								<div class="bn-ca-row__actions">
									<?php if ( '' !== $bn_sp_tone ) : ?>
										<span class="bn-badge" data-tone="<?php echo esc_attr( $bn_sp_tone ); ?>"><?php echo esc_html( ucfirst( $bn_sp_type ) ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<?php
				$bn_ca_sp_pages = (int) ceil( (int) $bn_ca_sp_total / 20 );
				if ( $bn_ca_sp_pages > 1 ) :
					$bn_ca_sp_base = $bn_ca_routed ? trailingslashit( $admin_base . 'spaces' ) : add_query_arg( 'bn_admin', 'spaces', $admin_base );
					?>
					<nav class="bn-ca-pagination" aria-label="<?php esc_attr_e( 'Spaces pages', 'buddynext' ); ?>">
						<?php if ( $bn_ca_sp_page > 1 ) : ?>
							<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( add_query_arg( 'mpage', $bn_ca_sp_page - 1, $bn_ca_sp_base ) ); ?>"><?php esc_html_e( 'Previous', 'buddynext' ); ?></a>
						<?php endif; ?>
						<span class="bn-ca-pagination__status">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages. */
								esc_html__( 'Page %1$s of %2$s', 'buddynext' ),
								esc_html( number_format_i18n( $bn_ca_sp_page ) ),
								esc_html( number_format_i18n( $bn_ca_sp_pages ) )
							);
							?>
						</span>
						<?php if ( $bn_ca_sp_page < $bn_ca_sp_pages ) : ?>
							<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( add_query_arg( 'mpage', $bn_ca_sp_page + 1, $bn_ca_sp_base ) ); ?>"><?php esc_html_e( 'Next', 'buddynext' ); ?></a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			</section>

		<?php elseif ( 'settings' === $admin_section ) : ?>

			<section class="bn-ca-card" aria-labelledby="bn-ca-settings-title">
				<header class="bn-ca-card__head">
					<span id="bn-ca-settings-title" class="bn-ca-card__title">
						<?php buddynext_icon( 'settings' ); ?>
						<?php esc_html_e( 'Community settings', 'buddynext' ); ?>
					</span>
				</header>
				<div class="bn-ca-card__body">
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<?php
						// Community-wide policy is a site-owner boundary (manage_options),
						// so this section links out to the wp-admin tabs that own it rather
						// than duplicating a write path on the panel. tab_url() resolves the
						// canonical tab placement (never hardcode admin.php?page=… strings).
						$bn_ca_settings_links = array(
							array(
								'icon'  => 'shield',
								'label' => __( 'Moderation & policy', 'buddynext' ),
								'sub'   => __( 'Auto-hide thresholds, strikes, banned words and rate limits.', 'buddynext' ),
								'url'   => \BuddyNext\Admin\AdminHub::tab_url( 'settings', 'moderation' ),
							),
							array(
								'icon'  => 'users',
								'label' => __( 'Roles & capabilities', 'buddynext' ),
								'sub'   => __( 'Set the minimum role required for each community action.', 'buddynext' ),
								'url'   => \BuddyNext\Admin\AdminHub::tab_url( 'settings', 'roles' ),
							),
							array(
								'icon'  => 'mail',
								'label' => __( 'Bulk email invites (CSV)', 'buddynext' ),
								'sub'   => __( 'Import a CSV of email addresses to invite in bulk.', 'buddynext' ),
								'url'   => \BuddyNext\Admin\AdminHub::tab_url( 'members', 'invites' ),
							),
						);
						foreach ( $bn_ca_settings_links as $bn_ca_sl ) :
							?>
							<a class="bn-ca-row" href="<?php echo esc_url( $bn_ca_sl['url'] ); ?>">
								<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php buddynext_icon( $bn_ca_sl['icon'] ); ?></span>
								<div class="bn-ca-row__body">
									<span class="bn-ca-row__title"><?php echo esc_html( $bn_ca_sl['label'] ); ?></span>
									<span class="bn-ca-row__sub"><?php echo esc_html( $bn_ca_sl['sub'] ); ?></span>
								</div>
								<div class="bn-ca-row__actions"><?php buddynext_icon( 'chevron-right' ); ?></div>
							</a>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'Community settings are managed by the site administrator.', 'buddynext' ); ?></p>
					<?php endif; ?>
				</div>
			</section>

		<?php elseif ( 'invites' === $admin_section ) : ?>

			<section class="bn-ca-card" aria-labelledby="bn-ca-invites-title">
				<header class="bn-ca-card__head">
					<span id="bn-ca-invites-title" class="bn-ca-card__title">
						<?php buddynext_icon( 'mail' ); ?>
						<?php esc_html_e( 'Pending email invites', 'buddynext' ); ?>
						<span class="bn-ca-card__count"><?php echo esc_html( number_format_i18n( (int) $bn_ca_inv_total ) ); ?></span>
					</span>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a class="bn-ca-card__link" href="<?php echo esc_url( \BuddyNext\Admin\AdminHub::tab_url( 'members', 'invites' ) ); ?>"><?php esc_html_e( 'Import CSV', 'buddynext' ); ?></a>
					<?php endif; ?>
				</header>

				<?php
				$bn_ca_inv_status = isset( $_GET['bn_invite'] ) ? sanitize_key( wp_unslash( $_GET['bn_invite'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( '' !== $bn_ca_inv_status ) :
					$bn_ca_inv_msgs = array(
						'sent'    => __( 'Invite sent.', 'buddynext' ),
						'skipped' => __( 'That email already has an account or a pending invite.', 'buddynext' ),
						'error'   => __( 'Enter a valid email address.', 'buddynext' ),
					);
					$bn_ca_inv_tone = 'sent' === $bn_ca_inv_status ? 'success' : ( 'skipped' === $bn_ca_inv_status ? 'info' : 'danger' );
					?>
					<div class="bn-ca-notice" data-tone="<?php echo esc_attr( $bn_ca_inv_tone ); ?>" role="status">
						<?php echo esc_html( $bn_ca_inv_msgs[ $bn_ca_inv_status ] ?? '' ); ?>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-ca-invite-form">
					<input type="hidden" name="action" value="bn_ca_send_invite">
					<?php wp_nonce_field( 'bn_ca_send_invite' ); ?>
					<input type="email" name="invite_email" class="bn-input" required placeholder="<?php esc_attr_e( 'name@example.com', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'Email address to invite', 'buddynext' ); ?>" />
					<input type="text" name="invite_first_name" class="bn-input" placeholder="<?php esc_attr_e( 'First name (optional)', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'First name', 'buddynext' ); ?>" />
					<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Send invite', 'buddynext' ); ?></button>
				</form>

				<div class="bn-ca-card__body">
					<?php if ( empty( $bn_ca_invites ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No pending invites.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php
						foreach ( $bn_ca_invites as $bn_ca_inv ) :
							$bn_inv_email = (string) ( $bn_ca_inv['email'] ?? '' );
							$bn_inv_exp   = (string) ( $bn_ca_inv['expires_at'] ?? '' );
							$bn_inv_init  = '' !== $bn_inv_email ? strtoupper( mb_substr( $bn_inv_email, 0, 1 ) ) : '?';
							?>
							<div class="bn-ca-row">
								<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( $bn_inv_init ); ?></span>
								<div class="bn-ca-row__body">
									<span class="bn-ca-row__title"><?php echo esc_html( $bn_inv_email ); ?></span>
									<span class="bn-ca-row__sub">
										<?php
										if ( '' !== $bn_inv_exp ) {
											printf(
												/* translators: %s: expiry date. */
												esc_html__( 'Expires %s', 'buddynext' ),
												esc_html( mysql2date( (string) get_option( 'date_format' ), $bn_inv_exp ) )
											);
										}
										?>
									</span>
								</div>
								<div class="bn-ca-row__actions">
									<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Pending', 'buddynext' ); ?></span>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<?php
				$bn_ca_inv_pages = (int) ceil( (int) $bn_ca_inv_total / 20 );
				if ( $bn_ca_inv_pages > 1 ) :
					$bn_ca_inv_base = $bn_ca_routed ? trailingslashit( $admin_base . 'invites' ) : add_query_arg( 'bn_admin', 'invites', $admin_base );
					?>
					<nav class="bn-ca-pagination" aria-label="<?php esc_attr_e( 'Invite pages', 'buddynext' ); ?>">
						<?php if ( $bn_ca_inv_page > 1 ) : ?>
							<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( add_query_arg( 'mpage', $bn_ca_inv_page - 1, $bn_ca_inv_base ) ); ?>"><?php esc_html_e( 'Previous', 'buddynext' ); ?></a>
						<?php endif; ?>
						<span class="bn-ca-pagination__status">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages. */
								esc_html__( 'Page %1$s of %2$s', 'buddynext' ),
								esc_html( number_format_i18n( $bn_ca_inv_page ) ),
								esc_html( number_format_i18n( $bn_ca_inv_pages ) )
							);
							?>
						</span>
						<?php if ( $bn_ca_inv_page < $bn_ca_inv_pages ) : ?>
							<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( add_query_arg( 'mpage', $bn_ca_inv_page + 1, $bn_ca_inv_base ) ); ?>"><?php esc_html_e( 'Next', 'buddynext' ); ?></a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			</section>

		<?php else : ?>

			<?php // The Moderation tab is queue-focused; the stats dashboard belongs to Overview. Both share the reports/appeals queue markup below. ?>
			<?php if ( 'moderation' !== $admin_section ) : ?>
			<!-- Stats grid — .bn-stat-grid primitive -->
			<div class="bn-stat-grid" role="list" aria-label="<?php esc_attr_e( 'Community metrics', 'buddynext' ); ?>">

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Members', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $total_members ) ); ?></div>
					<div class="bn-stat__delta" data-trend="up">
						<?php buddynext_icon( 'arrow-up' ); ?>
						<span>
							<?php
							printf(
								/* translators: %s: number of new members today (already i18n-formatted). */
								esc_html__( '%s new today', 'buddynext' ),
								esc_html( number_format_i18n( $new_today ) )
							);
							?>
						</span>
					</div>
				</div>

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Active spaces', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $active_spaces ) ); ?></div>
					<div class="bn-stat__delta" data-trend="flat"><?php esc_html_e( 'across the community', 'buddynext' ); ?></div>
				</div>

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Open reports', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $open_reports ) ); ?></div>
					<div class="bn-stat__delta" data-trend="<?php echo $urgent_reports > 0 ? 'down' : 'flat'; ?>">
						<span class="bn-ca-status-dot" data-severity="<?php echo $urgent_reports > 0 ? 'high' : 'low'; ?>" aria-hidden="true"></span>
						<span>
							<?php
							printf(
								/* translators: %s: number of urgent reports. */
								esc_html__( '%s urgent', 'buddynext' ),
								esc_html( number_format_i18n( $urgent_reports ) )
							);
							?>
						</span>
					</div>
				</div>

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Posts today', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $posts_today ) ); ?></div>
					<div class="bn-stat__delta" data-trend="<?php echo $posts_pct >= 0 ? 'up' : 'down'; ?>">
						<?php
						if ( $posts_pct >= 0 ) {
							buddynext_icon( 'arrow-up' );
						} else {
							buddynext_icon( 'arrow-down' );
						}
						?>
						<span>
							<?php
							printf(
								/* translators: %d: percentage change vs yesterday. */
								esc_html__( '%d%% vs yesterday', 'buddynext' ),
								absint( $posts_pct_abs )
							);
							?>
						</span>
					</div>
				</div>

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Pending joins', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $total_pending_joins ) ); ?></div>
					<div class="bn-stat__delta" data-trend="flat">
						<?php esc_html_e( 'invite-only spaces', 'buddynext' ); ?>
					</div>
				</div>

			</div>
			<?php endif; // stats grid hidden on the Moderation tab. ?>

			<!-- Section tabs — Reports / Pending joins / Recent activity -->
			<nav class="bn-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Admin sections', 'buddynext' ); ?>">
				<?php
				$sections = array(
					'reports'     => array(
						'label' => __( 'Reports', 'buddynext' ),
						'count' => (int) $open_reports,
					),
					'mod_appeals' => array(
						'label' => __( 'Appeals', 'buddynext' ),
						'count' => (int) $total_pending_appeals,
					),
					'appeals'     => array(
						'label' => __( 'Pending joins', 'buddynext' ),
						'count' => (int) $total_pending_joins,
					),
					'strikes'     => array(
						'label' => __( 'Recent signups', 'buddynext' ),
						'count' => is_countable( $recent_signups ) ? count( $recent_signups ) : 0,
					),
					'actions'     => array(
						'label' => __( 'Recent actions', 'buddynext' ),
						'count' => is_countable( $activity_rows ) ? count( $activity_rows ) : 0,
					),
				);
				foreach ( $sections as $skey => $sdata ) :
					$is_active = ( $active_section === $skey );
					$shref     = add_query_arg( 'bn_section', $skey );
					?>
					<a
						href="<?php echo esc_url( $shref ); ?>"
						class="bn-tab"
						role="tab"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
					>
						<?php echo esc_html( $sdata['label'] ); ?>
						<span class="bn-tab__count"><?php echo esc_html( number_format_i18n( (int) $sdata['count'] ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'reports' === $active_section ) : ?>

				<!-- Open reports -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-reports-title">
					<header class="bn-ca-card__head">
						<span id="bn-ca-reports-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'shield' ); ?>
							<?php esc_html_e( 'Open reports', 'buddynext' ); ?>
							<span class="bn-ca-card__count"><?php echo esc_html( number_format_i18n( (int) $open_reports ) ); ?></span>
						</span>
						<a href="<?php echo esc_url( add_query_arg( 'bn_admin', 'reports', $admin_base ) ); ?>" class="bn-ca-card__link">
							<?php esc_html_e( 'View all', 'buddynext' ); ?>
						</a>
					</header>

					<?php if ( empty( $report_rows ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No open reports.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php
						$displayed   = 0;
						$show_limit  = 5;
						$extra_count = max( 0, count( $report_rows ) - $show_limit );
						?>
						<?php foreach ( $report_rows as $rpt ) : ?>
							<?php
							if ( $displayed >= $show_limit ) {
								break;
							}
							$rpt_count    = (int) ( $rpt['reporter_count'] ?? 1 );
							$rpt_severity = bn_report_severity( $rpt_count );
							$rpt_reason   = ucfirst( (string) ( $rpt['reason'] ?? __( 'Report', 'buddynext' ) ) );
							$rpt_ts       = ! empty( $rpt['created_at'] ) ? (int) strtotime( (string) $rpt['created_at'] . ' UTC' ) : 0;
							$rpt_iso      = $rpt_ts ? gmdate( DATE_ATOM, $rpt_ts ) : '';
							$rpt_time     = $rpt_ts ? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours". */ __( '%s ago', 'buddynext' ), human_time_diff( $rpt_ts, time() ) ) : '';
							++$displayed;
							?>
							<div class="bn-ca-report-row" data-severity="<?php echo esc_attr( $rpt_severity ); ?>">
								<div class="bn-ca-report-row__body">
									<div class="bn-ca-report-row__type">
										<span class="bn-ca-status-dot" data-severity="<?php echo esc_attr( $rpt_severity ); ?>" aria-hidden="true"></span>
										<?php echo esc_html( $rpt_reason ); ?>
									</div>
									<div class="bn-ca-report-row__meta">
										<?php
										printf(
											/* translators: 1: number of reporters, 2: time-ago string. */
											esc_html( _n( '%1$s reporter - %2$s', '%1$s reporters - %2$s', (int) $rpt_count, 'buddynext' ) ),
											esc_html( number_format_i18n( $rpt_count ) ),
											esc_html( $rpt_time )
										);
										?>
									</div>
								</div>
								<?php if ( $rpt_iso ) : ?>
									<time class="bn-ca-row__time" datetime="<?php echo esc_attr( $rpt_iso ); ?>"><?php echo esc_html( $rpt_time ); ?></time>
								<?php endif; ?>
								<a
									href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'bn_admin' => 'reports',
												'bn_report_id' => (int) $rpt['id'],
											),
											$admin_base
										)
									);
									?>
									"
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
								><?php esc_html_e( 'Review', 'buddynext' ); ?></a>
							</div>
						<?php endforeach; ?>

						<?php if ( $extra_count > 0 ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'bn_admin', 'reports', $admin_base ) ); ?>" class="bn-ca-card__more">
								<?php
								printf(
									/* translators: %s: number of additional reports. */
									esc_html__( '+ %s more', 'buddynext' ),
									esc_html( number_format_i18n( $extra_count ) )
								);
								?>
							</a>
						<?php endif; ?>
					<?php endif; ?>
				</section>

			<?php elseif ( 'mod_appeals' === $active_section ) : ?>

				<!-- Suspension appeals awaiting review -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-appeals-title" data-wp-interactive="buddynext/moderation">
					<header class="bn-ca-card__head">
						<span id="bn-ca-appeals-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'message-circle' ); ?>
							<?php esc_html_e( 'Suspension appeals', 'buddynext' ); ?>
							<span class="bn-ca-card__count"><?php echo esc_html( number_format_i18n( (int) $total_pending_appeals ) ); ?></span>
						</span>
					</header>

					<?php if ( empty( $pending_appeals ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No appeals are waiting for review.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php foreach ( $pending_appeals as $bn_ca_ap ) : ?>
							<?php
							$ap_id     = (int) $bn_ca_ap['id'];
							$ap_uid    = (int) $bn_ca_ap['user_id'];
							$ap_name   = $bn_appeal_names[ $ap_uid ] ?? __( 'Member', 'buddynext' );
							$ap_msg    = trim( (string) $bn_ca_ap['message'] );
							$ap_when   = bn_time_diff( (string) $bn_ca_ap['created_at'] );
							$ap_susp   = $bn_appeal_susps[ (int) $bn_ca_ap['suspension_id'] ] ?? null;
							$ap_reason = $ap_susp ? trim( (string) $ap_susp['reason'] ) : '';
							$ap_ctx    = wp_json_encode(
								array(
									'appealId'  => $ap_id,
									'restUrl'   => esc_url_raw( rest_url( 'buddynext/v1' ) ),
									'restNonce' => wp_create_nonce( 'wp_rest' ),
								)
							);
							?>
							<div class="bn-ca-appeal" data-appeal-id="<?php echo esc_attr( (string) $ap_id ); ?>" data-wp-context="<?php echo esc_attr( (string) $ap_ctx ); ?>">
								<div class="bn-ca-appeal__head">
									<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( \BuddyNext\Profile\AvatarService::initials_for( $ap_name ) ); ?></span>
									<div class="bn-ca-appeal__who">
										<a class="bn-ca-appeal__name" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $ap_uid ) ); ?>"><?php echo esc_html( $ap_name ); ?></a>
										<span class="bn-ca-appeal__meta"><?php echo esc_html( $ap_when ); ?></span>
									</div>
								</div>
								<?php if ( '' !== $ap_reason ) : ?>
									<p class="bn-ca-appeal__contesting">
										<span class="bn-ca-appeal__label"><?php esc_html_e( 'Suspended for', 'buddynext' ); ?></span>
										<?php echo esc_html( $ap_reason ); ?>
									</p>
								<?php endif; ?>
								<?php if ( '' !== $ap_msg ) : ?>
									<blockquote class="bn-ca-appeal__msg"><?php echo esc_html( $ap_msg ); ?></blockquote>
								<?php endif; ?>
								<div class="bn-ca-appeal__actions">
									<button
										type="button"
										class="bn-btn"
										data-variant="primary"
										data-size="sm"
										data-wp-on--click="actions.approveAppeal"
									><?php esc_html_e( 'Approve & lift suspension', 'buddynext' ); ?></button>
									<button
										type="button"
										class="bn-btn"
										data-variant="ghost"
										data-size="sm"
										data-wp-on--click="actions.denyAppeal"
									><?php esc_html_e( 'Deny', 'buddynext' ); ?></button>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</section>

			<?php elseif ( 'appeals' === $active_section ) : ?>

				<!-- Pending join requests -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-joins-title">
					<header class="bn-ca-card__head">
						<span id="bn-ca-joins-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'mail' ); ?>
							<?php esc_html_e( 'Space join requests', 'buddynext' ); ?>
							<span class="bn-ca-card__count"><?php echo esc_html( number_format_i18n( (int) $total_pending_joins ) ); ?></span>
						</span>
					</header>

					<?php if ( empty( $pending_joins ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No pending join requests.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php foreach ( $pending_joins as $join ) : ?>
							<?php
							$j_uid    = (int) $join['user_id'];
							$j_sid    = (int) $join['space_id'];
							$j_member = '' !== (string) ( $join['member_name'] ?? '' ) ? (string) $join['member_name'] : __( 'Member', 'buddynext' );
							$j_space  = '' !== (string) ( $join['space_name'] ?? '' ) ? (string) $join['space_name'] : __( 'Space', 'buddynext' );
							$j_inits  = \BuddyNext\Profile\AvatarService::initials_for( $j_member );
							?>
							<div class="bn-ca-row">
								<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( $j_inits ); ?></span>
								<div class="bn-ca-row__body">
									<span class="bn-ca-row__title"><?php echo esc_html( $j_space ); ?></span>
									<span class="bn-ca-row__sub">
										<?php
										printf(
											/* translators: %s: member display name. */
											esc_html__( '%s wants to join', 'buddynext' ),
											esc_html( $j_member )
										);
										?>
									</span>
								</div>
								<div class="bn-ca-row__actions">
									<button
										type="button"
										class="bn-btn"
										data-variant="primary"
										data-size="sm"
										data-wp-on--click="actions.approveJoinRequest"
										data-user-id="<?php echo esc_attr( (string) $j_uid ); ?>"
										data-space-id="<?php echo esc_attr( (string) $j_sid ); ?>"
									><?php esc_html_e( 'Approve', 'buddynext' ); ?></button>
									<button
										type="button"
										class="bn-btn"
										data-variant="ghost"
										data-size="sm"
										data-wp-on--click="actions.declineJoinRequest"
										data-user-id="<?php echo esc_attr( (string) $j_uid ); ?>"
										data-space-id="<?php echo esc_attr( (string) $j_sid ); ?>"
									><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</section>

			<?php elseif ( 'strikes' === $active_section ) : ?>

				<!-- Recent signups -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-signups-title">
					<header class="bn-ca-card__head">
						<span id="bn-ca-signups-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'users' ); ?>
							<?php esc_html_e( 'Recent signups', 'buddynext' ); ?>
						</span>
						<a href="<?php echo esc_url( add_query_arg( 'bn_admin', 'members', $admin_base ) ); ?>" class="bn-ca-card__link">
							<?php esc_html_e( 'View all members', 'buddynext' ); ?>
						</a>
					</header>

					<?php if ( empty( $recent_signups ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No signups yet.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php foreach ( $recent_signups as $signup ) : ?>
							<?php
							$su_uid   = (int) $signup->ID;
							$su_name  = '' !== (string) ( $signup->display_name ?? '' ) ? (string) $signup->display_name : __( 'Member', 'buddynext' );
							$su_init  = \BuddyNext\Profile\AvatarService::initials_for( $su_name );
							$su_email = (string) ( $signup->user_email ?? '' );
							$su_ts    = isset( $signup->user_registered ) ? (int) strtotime( (string) $signup->user_registered ) : 0;
							$su_iso   = $su_ts ? gmdate( DATE_ATOM, $su_ts ) : '';
							$su_time  = $su_ts ? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours". */ __( '%s ago', 'buddynext' ), human_time_diff( $su_ts, time() ) ) : '';
							?>
							<div class="bn-ca-row">
								<span class="bn-avatar" data-size="sm" aria-label="<?php echo esc_attr( $su_name ); ?>"><?php echo esc_html( $su_init ); ?></span>
								<div class="bn-ca-row__body">
									<span class="bn-ca-row__title"><?php echo esc_html( $su_name ); ?></span>
									<span class="bn-ca-row__sub"><?php echo esc_html( $su_email ); ?></span>
								</div>
								<?php if ( $su_iso ) : ?>
									<time class="bn-ca-row__time" datetime="<?php echo esc_attr( $su_iso ); ?>"><?php echo esc_html( $su_time ); ?></time>
								<?php endif; ?>
								<div class="bn-ca-row__actions">
									<a
										href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $su_uid ) ); ?>"
										class="bn-btn"
										data-variant="ghost"
										data-size="sm"
									><?php esc_html_e( 'View profile', 'buddynext' ); ?></a>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</section>

			<?php else : // 'actions' section. ?>

				<!-- Recent activity log -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-actions-title">
					<header class="bn-ca-card__head">
						<span id="bn-ca-actions-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'copy' ); ?>
							<?php esc_html_e( 'Recent actions', 'buddynext' ); ?>
						</span>
						<a href="<?php echo esc_url( add_query_arg( 'bn_admin', 'log', $admin_base ) ); ?>" class="bn-ca-card__link">
							<?php esc_html_e( 'View full log', 'buddynext' ); ?>
						</a>
					</header>

					<div class="bn-ca-activity-scroll" role="log" aria-label="<?php esc_attr_e( 'Recent site activity', 'buddynext' ); ?>">
						<?php if ( empty( $activity_rows ) ) : ?>
							<p class="bn-ca-card__empty"><?php esc_html_e( 'No recent activity.', 'buddynext' ); ?></p>
						<?php else : ?>
							<?php foreach ( $activity_rows as $act ) : ?>
								<?php
								$act_action = '' !== (string) ( $act['action'] ?? '' ) ? (string) $act['action'] : 'note';
								$act_icon   = bn_activity_icon( $act_action );
								$act_type   = (string) ( $act['object_type'] ?? '' );
								$act_desc   = '' !== (string) ( $act['action'] ?? '' )
									? ucfirst( str_replace( '_', ' ', (string) $act['action'] ) ) . ( '' !== $act_type ? ' (' . $act_type . ')' : '' )
									: '';
								$act_ts     = ! empty( $act['created_at'] ) ? (int) strtotime( (string) $act['created_at'] ) : 0;
								$act_iso    = $act_ts ? gmdate( DATE_ATOM, $act_ts ) : '';
								$act_meta   = $act_ts ? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours". */ __( '%s ago', 'buddynext' ), human_time_diff( $act_ts, time() ) ) : '';
								$act_report = ( 'post_flagged' === $act_action );
								?>
								<div class="bn-ca-activity-row">
									<span class="bn-ca-activity-row__icon" aria-hidden="true"><?php echo $act_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG via buddynext_get_icon(), already wp_kses() sanitized. ?></span>
									<div class="bn-ca-activity-row__body">
										<div class="bn-ca-activity-row__desc"><?php echo esc_html( $act_desc ); ?></div>
										<?php if ( $act_iso ) : ?>
											<time class="bn-ca-activity-row__meta" datetime="<?php echo esc_attr( $act_iso ); ?>"><?php echo esc_html( $act_meta ); ?></time>
										<?php else : ?>
											<div class="bn-ca-activity-row__meta"><?php echo esc_html( $act_meta ); ?></div>
										<?php endif; ?>
									</div>
									<?php if ( $act_report ) : ?>
										<div class="bn-ca-activity-row__action">
											<a
												href="<?php echo esc_url( add_query_arg( 'bn_admin', 'reports', $admin_base ) ); ?>"
												class="bn-btn"
												data-variant="secondary"
												data-size="sm"
											><?php esc_html_e( 'Review', 'buddynext' ); ?></a>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</section>

			<?php endif; ?>

		<?php endif; // 'members' section body vs the Overview dashboard. ?>

		</main>

	</div><!-- /.bn-ca-wrap -->

</div><!-- /.bn-ca -->
<?php
/**
 * Fires after the community-admin inner content.
 */
do_action( 'buddynext_community_admin_after' );
?>
