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
	--green:       #34d399;
	--green-bg:    #0d2420;
}

/* ── Wrapper ── */
.bn-connections {
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
.bn-connections-header {
	display: flex;
	align-items: center;
	gap: var(--s3);
	margin-bottom: var(--s5);
	flex-wrap: wrap;
}
.bn-connections-title {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	color: var(--text-1);
	letter-spacing: -0.5px;
}
.bn-connections-count {
	background: var(--brand-light);
	color: var(--brand);
	font-size: var(--text-sm);
	font-weight: 700;
	padding: 3px 10px;
	border-radius: 20px;
}
.bn-connections-back {
	font-size: var(--text-sm);
	color: var(--text-2);
	text-decoration: none;
	display: flex;
	align-items: center;
	gap: var(--s1);
	margin-left: auto;
}
.bn-connections-back:hover { color: var(--brand); }

/* ── Member grid ── */
.bn-connections-grid {
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

/* ── Connected badge ── */
.bn-connected-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: var(--green-bg);
	color: var(--green);
	font-size: var(--text-xs);
	font-weight: 700;
	padding: 3px var(--s2);
	border-radius: 20px;
	margin-bottom: var(--s3);
}
[data-theme="dark"] .bn-connected-badge { background: var(--green-bg); color: var(--green); }

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
.bn-empty-text  { font-size: var(--text-sm); line-height: 1.6; color: var(--text-2); }

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
[data-theme="dark"] .bn-btn-view    { background: var(--brand); }

/* ── Mobile ── */
@media (max-width: 1024px) {
	.bn-connections-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 640px) {
	.bn-connections { padding: var(--s3); }
	.bn-connections-grid { grid-template-columns: repeat(2, 1fr); gap: var(--s2); }
	.bn-member-card { padding: var(--s4) var(--s3); }
	.bn-connections-back { margin-left: 0; width: 100%; }
}
</style>

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
			&#8592; <?php esc_html_e( 'Back to profile', 'buddynext' ); ?>
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
				$msg_url     = PageRouter::messages_url();
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
						&#10003; <?php esc_html_e( 'Connected', 'buddynext' ); ?>
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
							>&#128172;</a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		<?php else : ?>
			<div class="bn-empty-state">
				<div class="bn-empty-icon" aria-hidden="true">&#128101;</div>
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

	<?php if ( $total_pages > 1 ) : ?>
		<nav class="bn-pagination" aria-label="<?php esc_attr_e( 'Connections page navigation', 'buddynext' ); ?>">
			<?php if ( $bn_paged > 1 ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'paged', $bn_paged - 1 ) ); ?>"
					class="bn-page-btn"
					aria-label="<?php esc_attr_e( 'Previous page', 'buddynext' ); ?>"
				>&#8592;</a>
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
				>&#8594;</a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

</div><!-- .bn-connections -->
