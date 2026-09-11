<?php
/**
 * Space members body — the reusable roster region (gate + filter + grid + pagination).
 *
 * Header-less on purpose: this is JUST the members body, wrapped in its own
 * buddynext/space-members Interactivity region, so any surface that already renders
 * its own space header can drop in the exact same roster UX. spaces/members.php is
 * the canonical consumer; Wellbee Circles reuses it behind its own header (card
 * 10280272637). The part OWNS the roster gate and the member query, so the gate can
 * never drift between consumers — it runs BEFORE any row is fetched, so a gated
 * 100k-member roster is never loaded only to be refused.
 *
 * Context variables:
 *   $space_id  (int)  — required, the space's primary key.
 *   $viewer_id (int)  — optional, defaults to the current user.
 *
 * Overridable: copy to {theme}/buddynext/parts/space-members-body.php
 *
 * REST endpoint: GET buddynext/v1/spaces/{id}/members
 *
 * @package BuddyNext
 * @since   1.2.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\AvatarService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceVisibility;

// ── Context ─────────────────────────────────────────────────────────────────────
$space_id = isset( $space_id ) ? absint( $space_id ) : 0;
if ( $space_id <= 0 ) {
	return;
}

$bn_member_svc = new SpaceMemberService();
$space         = ( new SpaceService() )->get( $space_id );
if ( null === $space ) {
	return;
}

// Self-enqueue the buddynext/space-members store so the roster's interactive
// islands (the manage kebab, prev/next) hydrate wherever this part is rendered —
// not only on the space route. The module is registered globally on
// wp_enqueue_scripts, so this is a route-agnostic enqueue; WordPress dedupes it
// with the route-level enqueue on the space page. Without this a reuse behind
// another header (e.g. Wellbee Circles) would render the kebab and leave it dead
// (card 10280272637 RFT round 4). Enqueuing during the_content prints the module
// in the footer, which is in time.
$bn_assets = function_exists( 'buddynext_service' ) ? buddynext_service( 'assets' ) : null;
if ( $bn_assets instanceof \BuddyNext\Core\AssetService ) {
	$bn_assets->enqueue( 'space-members' );
}

// ── Current viewer ──────────────────────────────────────────────────────────────
// The roster gate AND the management affordances (remove / change-role) are all
// driven by this one resolved viewer — $viewer_id when a consumer passes it, else
// the current user. Capability and logged-in checks below therefore key on
// $current_user_id via user_can(), NOT current_user_can()/is_user_logged_in(),
// so a consumer that renders another member's roster gets that member's
// affordances rather than the actual request user's (card 10280272637 RFT round 4).
$current_user_id = isset( $viewer_id ) ? absint( $viewer_id ) : get_current_user_id();

// ── Roster gate (the ONE resolver, shared with GET /spaces/{id}/members) ─────────
// A private or secret space's roster belongs to the people in the room: its
// members, moderators, owner, and site admins. A non-member — logged out OR
// logged in — sees the space (name, description, member count: it is listed, not
// hidden) but not WHO is in it. A site owner re-opens private rosters
// Facebook-style with one add_filter( 'buddynext_space_can_view_roster', … ),
// which moves this page and the REST route together.
//
// The gate runs BEFORE any member row is fetched: on a 100k-member space we
// never load the roster only to refuse it.
$bn_can_view_roster = SpaceVisibility::can_view_roster( $space, $current_user_id );

// ── Filters ─────────────────────────────────────────────────────────────────────
$bn_sm_search = isset( $_GET['bn_sm_q'] ) ? sanitize_text_field( wp_unslash( $_GET['bn_sm_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_sm_role   = isset( $_GET['bn_sm_role'] ) ? sanitize_key( wp_unslash( $_GET['bn_sm_role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $bn_sm_role, array( 'owner', 'moderator', 'member' ), true ) ) {
	$bn_sm_role = '';
}

// ── Cursor pagination (keyset) ────────────────────────────────────────────────
// Deep OFFSET is replaced by a keyset cursor carried in the URL (?bn_after=), so
// page N of a 50k roster costs the same as page 1. Each page is a FULL server
// render: the card islands (the manage kebab) hydrate normally. A client-side
// "load more" that injected fetched cards would leave those islands inert — the
// WP Interactivity constraint documented in assets/js/feed/shared.js — so we
// paginate by navigation, not by DOM append. "Next" is a plain link carrying the
// next cursor + breadcrumb trail; "Previous" walks that trail back one page (see
// the pagination block below), so each page is a real, shareable URL.
$bn_per_page = 24;
$bn_after    = isset( $_GET['bn_after'] ) ? sanitize_text_field( wp_unslash( $_GET['bn_after'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Backward-nav breadcrumb. Keyset pagination has no cheap "page N-1" cursor, so
// each page carries the trail of prior-page cursors (?bn_prev=, comma-separated,
// page 1 omitted since it has no cursor). "Previous" pops the last entry to land
// on the EXACT prior page even with JS off — the earlier href fell back to the
// FIRST page, silently dropping the reader to page 1 (card 10280272637). Cursors
// are URL-safe base64 (CursorCodec: A-Za-z0-9-_ only, no comma), so comma is a
// safe delimiter.
$bn_prev_raw   = isset( $_GET['bn_prev'] ) ? sanitize_text_field( wp_unslash( $_GET['bn_prev'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_prev_trail = \BuddyNext\Core\CursorCodec::parse_trail( $bn_prev_raw );

// exclude_suspended folds in ModerationService::moderation_exclude_sql() via the
// service. The query is skipped entirely when the roster is gated. The header
// (space-header.php) shows the space's denormalised member COUNT, which is right
// for the whole roster but drifts once a filter/search narrows the grid — so the
// body runs a matching COUNT(*) ONLY when filtering (see $bn_result_count below),
// never on the default unfiltered view.
$bn_member_args = array(
	'search'            => $bn_sm_search,
	'role'              => $bn_sm_role,
	'exclude_suspended' => true,
);

$bn_page = $bn_can_view_roster
	? $bn_member_svc->get_members_keyset( $space_id, $current_user_id, ( '' !== $bn_after ? $bn_after : null ), $bn_per_page, $bn_member_args )
	: array(
		'items'       => array(),
		'next_cursor' => null,
	);

$bn_member_rows = $bn_page['items'];
$bn_next_cursor = $bn_page['next_cursor'];

// Filtered result total. When a search/role filter is active the header's
// denormalised member_count no longer matches the roster (it counts the whole
// space, not the block/suspension/search-filtered subset), so a search that
// returns 2 cards still showed "31 Members" (card 10280272637). count_members()
// runs the SAME WHERE as the grid (member_block_where + moderation_exclude_sql +
// role + search), so this total can never drift from the cards. Computed only
// while filtering; the unfiltered roster keeps trusting the header count.
$bn_is_filtered  = ( '' !== $bn_sm_search || '' !== $bn_sm_role );
$bn_result_count = ( $bn_can_view_roster && $bn_is_filtered )
	? $bn_member_svc->count_members( $space_id, $current_user_id, $bn_member_args )
	: null;

// Re-group owner → moderator → member WITHIN the page for display. The keyset
// order and next_cursor come from the service's joined_at ordering and are
// untouched by this presentational re-sort.
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


// ── Viewer management capability (mirrors SpaceController permissions) ────────────
// Remove member: owner/moderator or site admin. Change role: owner or site admin only.
$bn_viewer_role   = $current_user_id > 0
	? $bn_member_svc->get_role( $space_id, $current_user_id )
	: '';
$bn_is_site_admin = $current_user_id > 0 && user_can( $current_user_id, 'manage_options' );
$bn_can_remove    = $current_user_id > 0 && ( in_array( $bn_viewer_role, array( 'owner', 'moderator' ), true ) || $bn_is_site_admin );
$bn_can_set_role  = $current_user_id > 0 && ( 'owner' === $bn_viewer_role || $bn_is_site_admin );

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
$bn_filter_base = remove_query_arg( array( 'bn_sm_role', 'bn_sm_q', 'paged', 'bn_after' ) );
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

	<?php if ( ! $bn_can_view_roster ) : ?>
		<!--
		Roster gated: the space itself stays public (header above shows its name,
		description, avatar, cover, privacy badge and member COUNT) — only WHO is
		in the room is withheld. The rail still shows the owner + moderators, so a
		visitor can see who runs the space before requesting to join.
		-->
		<div class="bn-card bn-space-members__empty">
			<span class="bn-space-members__empty-icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></span>
			<div class="bn-space-members__empty-title"><?php esc_html_e( 'Members are private', 'buddynext' ); ?></div>
			<p class="bn-space-members__empty-desc">
				<?php esc_html_e( 'Only members of this space can see who else is in it. Join the space to see the full member list.', 'buddynext' ); ?>
			</p>
			<div class="bn-space-members__empty-actions">
				<a
					href="<?php echo esc_url( buddynext_space_url( (string) ( $space['slug'] ?? '' ) ) ); ?>"
					class="bn-btn"
					data-variant="primary"
					data-size="md"
				><?php esc_html_e( 'Back to space', 'buddynext' ); ?></a>
			</div>
		</div>
	<?php else : ?>

	<!-- Filter bar -->
	<div class="bn-card bn-space-members__filter">
		<form method="get" action="" class="bn-space-members__filter-form" role="search">
			<?php
			// Preserve any path-routing query vars other than the filters we own.
			foreach ( $_GET as $bn_q_key => $bn_q_val ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( in_array( $bn_q_key, array( 'bn_sm_q', 'bn_sm_role', 'paged', 'bn_after' ), true ) ) {
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

		<?php if ( null !== $bn_result_count ) : ?>
		<p class="bn-space-members__result-count" role="status" aria-live="polite">
			<?php
			printf(
				/* translators: %s: number of members matching the active filter/search. */
				esc_html( _n( '%s result', '%s results', $bn_result_count, 'buddynext' ) ),
				esc_html( number_format_i18n( $bn_result_count ) )
			);
			?>
		</p>
	<?php endif; ?>

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
							/* translators: %s: formatted join date. */
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
									/* translators: %s: name of the item the actions apply to. */
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

					<?php
					// Resolve the member's uploaded cover (per-user → site default → none),
					// the same seam the /members/ directory card uses, so a member's cover
					// shows here too instead of only the tone gradient (card 10290498358).
					$bn_sm_cover = function_exists( 'buddynext_user_cover_url' ) ? buddynext_user_cover_url( (int) $member_id ) : '';
					?>
					<div class="bn-md-card__cover" data-tone="<?php echo esc_attr( $role_meta['tone'] ); ?>"<?php echo '' !== $bn_sm_cover ? ' style="background-image:url(\'' . esc_url( $bn_sm_cover ) . '\')"' : ''; ?> aria-hidden="true"></div>

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
							<?php
							// Member labels (Pro) via the shared member-card meta seam, so
							// they show in the space roster like the directory + search.
							// Same CONSISTENT context-array shape as the member-directory card,
							// so a listener on this filter sees one contract on both surfaces
							// (was passing the member row here, $args there — card 10264294920).
							$bn_sm_labels = (string) apply_filters( 'buddynext_member_card_meta_html', '', $member_id, array( 'context' => 'space_roster' ) );
							if ( '' !== $bn_sm_labels ) {
								// Chip markup is wp_kses'd inside IconService::render_emoji().
								echo '<div class="bn-md-card__labels">' . $bn_sm_labels . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</div>

						<span class="bn-badge bn-md-card__type" data-tone="<?php echo esc_attr( $role_meta['tone'] ); ?>"><?php echo esc_html( $role_meta['label'] ); ?></span>

						<?php if ( '' !== $joined_formatted ) : ?>
							<p class="bn-md-card__meta"><?php echo esc_html( $joined_formatted ); ?></p>
						<?php endif; ?>

						<div class="bn-md-card__actions">
							<a href="<?php echo esc_url( $member_url ); ?>" class="bn-btn" data-variant="primary" data-size="sm"><?php esc_html_e( 'View', 'buddynext' ); ?></a>
							<?php if ( $current_user_id > 0 && $current_user_id !== $member_id ) : ?>
								<a
									href="<?php echo esc_url( PageRouter::messages_url() ); ?>"
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
									aria-label="
									<?php
										/* translators: %s: member display name. */
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

		<?php
		// Keyset prev/next. "Next" carries the opaque cursor of the last row on this
		// page (?bn_after=) plus the breadcrumb trail (?bn_prev=), preserving the
		// active filters and dropping any stale paged cursor. "Previous" walks the
		// trail back to the exact prior page (real page N-1 href); with JS,
		// actions.goBack still prefers history.back() when we arrived same-origin.
		// The nav shows only when a move is possible.
		$bn_page_base = remove_query_arg( array( 'paged', 'bn_after', 'bn_prev' ) );
		$bn_has_prev  = ( '' !== $bn_after );
		$bn_has_next  = ( null !== $bn_next_cursor );

		// "Previous" href: pop the last cursor off the trail. The popped cursor is
		// the prior page's bn_after (absent => page 1); the remainder stays the
		// trail. This is the real page N-1 URL, so JS-off navigation is correct;
		// with JS, actions.goBack still prefers history.back() when we arrived from
		// a same-origin page.
		$bn_prev_href = $bn_page_base;
		if ( $bn_has_prev ) {
			$bn_prev_step = \BuddyNext\Core\CursorCodec::pop_trail( $bn_prev_trail );
			if ( '' !== $bn_prev_step['after'] ) {
				$bn_prev_href = add_query_arg( 'bn_after', $bn_prev_step['after'], $bn_page_base );
			}
			if ( '' !== $bn_prev_step['trail'] ) {
				$bn_prev_href = add_query_arg( 'bn_prev', $bn_prev_step['trail'], $bn_prev_href );
			}
		}

		// "Next" href: push the current page's cursor onto the trail (page 1's
		// empty cursor is not stored), so the next page can walk back to this one.
		$bn_next_href = $bn_page_base;
		if ( $bn_has_next ) {
			$bn_next_trail = \BuddyNext\Core\CursorCodec::push_trail( $bn_prev_trail, $bn_after );
			$bn_next_href  = add_query_arg( 'bn_after', $bn_next_cursor, $bn_page_base );
			if ( '' !== $bn_next_trail ) {
				$bn_next_href = add_query_arg( 'bn_prev', $bn_next_trail, $bn_next_href );
			}
		}
		?>
		<?php if ( $bn_has_prev || $bn_has_next ) : ?>
		<nav class="bn-space-members__pagination" aria-label="<?php esc_attr_e( 'Members page navigation', 'buddynext' ); ?>">
			<?php if ( $bn_has_prev ) : ?>
				<a
					href="<?php echo esc_url( $bn_prev_href ); ?>"
					class="bn-btn"
					data-variant="ghost"
					data-size="sm"
					data-wp-on--click="actions.goBack"
				><?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Previous', 'buddynext' ); ?></a>
			<?php endif; ?>

			<?php if ( $bn_has_next ) : ?>
				<a
					href="<?php echo esc_url( $bn_next_href ); ?>"
					class="bn-btn"
					data-variant="ghost"
					data-size="sm"
				><?php esc_html_e( 'Next', 'buddynext' ); ?> <?php buddynext_icon( 'chevron-right' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

	<?php endif; /* $bn_can_view_roster */ ?>

</div><!-- .bn-space-members -->
