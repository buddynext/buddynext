<?php
/**
 * Single-space right-sidebar provider (Free core).
 *
 * Registers the five titled sidebar cards — about, sub-spaces, owner/
 * moderators, members preview, top contributors — that formerly lived
 * inline in `templates/parts/space-sidebar.php` as a single
 * `buddynext_right_sidebar` callback registered by every space sub-page
 * (home, members, moderation). That partial resolved the space, its
 * moderators, a capped member preview and the top contributors from
 * `$space_id`/`$viewer_id`/`$active_tab` passed by each includer; those three
 * now travel via `Surface::set( 'space', [...] )` instead, and this provider
 * reads them back through `Surface::context()`.
 *
 * Distinct from FeedSidebarProvider and ExploreSidebarProvider: these
 * descriptors use the registry's DEFAULT chrome (no `chrome => false`), so
 * each `render` closure echoes ONLY the inner card body and SidebarRegistry
 * wraps it in `parts/sidebar-card.php` using the descriptor's `title`/`icon`
 * — same pattern as MembersSidebarProvider and SpacesDirectorySidebarProvider.
 *
 * Only the WIDGETS vary per tab: the members-preview card is skipped on the
 * Members tab, where the full roster is already the page body (no
 * duplication).
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Core\PageRouter;
use BuddyNext\Messages\MessagesData;
use BuddyNext\Profile\AvatarService;
use BuddyNext\Sidebar\Surface;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceVisibility;

/**
 * Single-space sidebar widget descriptors.
 */
class SpaceSidebarProvider {

	/**
	 * Surface this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'space' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * Appends the five single-space descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( 'space' !== $surface ) {
			return $descriptors;
		}

		$ctx      = Surface::context();
		$space_id = absint( $ctx['space_id'] ?? 0 );
		if ( $space_id <= 0 ) {
			return $descriptors;
		}

		$space_service = new SpaceService();
		$space         = $space_service->get_object( $space_id );
		if ( null === $space ) {
			return $descriptors;
		}

		$viewer     = absint( $ctx['viewer_id'] ?? 0 );
		$active_tab = isset( $ctx['active_tab'] ) && '' !== (string) $ctx['active_tab']
			? (string) $ctx['active_tab']
			: 'feed';

		// The one canonical roster gate — the same call the members page and
		// GET /spaces/{id}/members make. On a private or secret space, a non-member
		// sees WHO IS IN CHARGE (owner + moderators: they need that to decide
		// whether to request to join) but never WHO IS IN THE ROOM. So the
		// moderator list is always fetched; the regular-member preview and the
		// top-contributor list — both of which name ordinary members — are
		// fetched only when the roster is visible.
		$space_row        = $space_service->get( $space_id );
		$can_view_roster  = SpaceVisibility::can_view_roster( $space_row, $viewer );
		$can_view_content = SpaceVisibility::can_view_content( $space_row, $viewer );

		$member_svc   = new SpaceMemberService();
		$mods         = $this->to_objects(
			array_merge(
				$member_svc->get_members( $space_id, $viewer, 0, 0, array( 'role' => 'owner' ) ),
				$member_svc->get_members( $space_id, $viewer, 0, 0, array( 'role' => 'moderator' ) )
			)
		);
		$regulars     = $can_view_roster
			? $this->to_objects( $member_svc->get_members( $space_id, $viewer, 10, 0, array( 'role' => 'member' ) ) )
			: array();
		$contributors = $can_view_roster
			? $this->to_objects( $space_service->top_contributors( $space_id, 3 ) )
			: array();

		$meta = SpaceService::display_meta( $space );

		// Sub-spaces — children of THIS space, visibility-scoped (secret children
		// the viewer cannot see are dropped by get_subspaces, so the rail never
		// leaks them). Only a root space can hold children (depth is capped at 2).
		$is_root     = empty( $space->parent_id );
		$sub_allowed = '0' !== (string) get_option( 'buddynext_space_allow_sub', '1' );

		/*
		 * Two rights, and BOTH are required.
		 *
		 *   buddynext-manage-space   per-space: are you this space's owner or a moderator?
		 *   buddynext-spaces/create  site-wide capability: are you allowed to create a space AT ALL?
		 *
		 * An owner can restrict the site-wide capability to admins in Settings ->
		 * Roles; the "Add sub-space" CTA must honour both or it promises something
		 * the create endpoint refuses.
		 */
		$can_manage_sub = $is_root && $sub_allowed && $viewer > 0
			&& function_exists( 'buddynext_service' ) && is_object( buddynext_service( 'permissions' ) )
			&& buddynext_service( 'permissions' )->can(
				$viewer,
				'buddynext-manage-space',
				array( 'space_id' => $space_id )
			)
			&& function_exists( 'buddynext_can' ) && buddynext_can( $viewer, 'buddynext-spaces/create' );

		// The per-parent cap (Settings -> Spaces -> "Max Sub-Spaces", 0 = unlimited).
		// Counted with count_subspaces(), NOT the visibility-scoped $subspaces list
		// below — that list drops secret children the viewer cannot see, so
		// counting it would UNDERCOUNT and tell a manager there is room when the
		// server will refuse. This is the same total the create path enforces against.
		$max_sub  = (int) get_option( 'buddynext_space_max_sub_spaces', 0 );
		$sub_used = ( $can_manage_sub && $max_sub > 0 ) ? $space_service->count_subspaces( $space_id ) : 0;
		$sub_full = $max_sub > 0 && $sub_used >= $max_sub;

		// A gated (private/secret) parent's structure is content: a non-member
		// does not get its child list, on the page or from GET /spaces/{id}/subspaces.
		$subspaces = ( $is_root && $can_view_content )
			? $space_service->get_subspaces( $space_id, 24, 0, $viewer, current_user_can( 'manage_options' ) )
			: array();

		// Categories for the create-sub-space modal — only fetched for a manager
		// who can actually add one (the modal is not rendered otherwise).
		$sub_categories = $can_manage_sub
			? array_map(
				static fn( $c ) => (object) array(
					'id'   => (int) $c['id'],
					'name' => (string) $c['name'],
					'slug' => (string) $c['slug'],
				),
				$space_service->categories_with_counts()
			)
			: array();

		// Card: About this space. Qualitative context only (description + type +
		// created + category); the Members / Posts counts live in the hero stat
		// strip, so this card never duplicates a number. Body always has at least
		// the privacy badge, so it never self-hides.
		$descriptors[] = array(
			'id'       => 'space-about',
			'priority' => 10,
			'surfaces' => self::SURFACES,
			'title'    => __( 'About this space', 'buddynext' ),
			'icon'     => 'info',
			'render'   => function () use ( $space, $meta ): void {
				$this->render_about( $space, $meta );
			},
		);

		// Card: Sub-spaces. The space's children as a persistent navigation rail,
		// plus a manager-only "Add sub-space" CTA on a childless root so the first
		// one is discoverable. Hidden for a viewer with neither children to see nor
		// the right to add one.
		if ( ! empty( $subspaces ) || $can_manage_sub ) {
			$descriptors[] = array(
				'id'       => 'space-subspaces',
				'priority' => 20,
				'surfaces' => self::SURFACES,
				'title'    => __( 'Sub-spaces', 'buddynext' ),
				'icon'     => 'layers',
				'render'   => function () use ( $space, $space_id, $subspaces, $can_manage_sub, $max_sub, $sub_used, $sub_full, $sub_categories ): void {
					$this->render_subspaces( $space, $space_id, $subspaces, $can_manage_sub, $max_sub, $sub_used, $sub_full, $sub_categories );
				},
			);
		}

		// Card: Owner / Moderators. DMs are owned by WPMediaVerse, so only offer
		// the Message action when that dependency is present; otherwise the row
		// links to the profile. Title from the roles ACTUALLY present, never
		// assumed: mods is a full-fetch set, but an owner-less space must not be
		// mistitled "Owner".
		if ( ! empty( $mods ) ) {
			$descriptors[] = array(
				'id'       => 'space-owners',
				'priority' => 30,
				'surfaces' => self::SURFACES,
				'title'    => $this->mods_title( $mods ),
				'icon'     => 'shield',
				'render'   => function () use ( $mods ): void {
					$this->render_owners( $mods );
				},
			);
		}

		// Card: Members preview (regular members only — mods sit in the card
		// above). Skipped on the Members tab, where the full roster IS the page
		// body, so the rail does not echo the same list twice.
		if ( ! empty( $regulars ) && 'members' !== $active_tab ) {
			$descriptors[] = array(
				'id'            => 'space-members',
				'priority'      => 40,
				'surfaces'      => self::SURFACES,
				'title'         => __( 'Members', 'buddynext' ),
				'icon'          => 'users',
				'see_all_url'   => trailingslashit( PageRouter::space_url( $space_id ) ) . 'members/',
				'see_all_label' => __( 'See all members', 'buddynext' ),
				'render'        => function () use ( $regulars ): void {
					$this->render_members( $regulars );
				},
			);
		}

		// Card: Top contributors.
		if ( ! empty( $contributors ) ) {
			$descriptors[] = array(
				'id'       => 'space-top-contributors',
				'priority' => 50,
				'surfaces' => self::SURFACES,
				'title'    => __( 'Top contributors', 'buddynext' ),
				'icon'     => 'award',
				'render'   => function () use ( $contributors ): void {
					$this->render_contributors( $contributors );
				},
			);
		}

		return $descriptors;
	}

	/**
	 * Convert roster/contributor rows into objects, exposing user_login (falling
	 * back to user_nicename) so the markup keeps simple property access — mirrors
	 * space-members-panel's fallback to user_login when display_name is empty.
	 *
	 * @param array<int,array<string,mixed>> $rows Roster/contributor rows.
	 * @return array<int,object>
	 */
	private function to_objects( array $rows ): array {
		return array_map(
			static function ( array $r ): object {
				$r['user_login'] = $r['user_login'] ?? ( $r['user_nicename'] ?? '' );
				return (object) $r;
			},
			$rows
		);
	}

	/**
	 * Deterministic avatar tone slug based on a user id.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Tone slug (sky|cyan|emerald|lime|amber|coral).
	 */
	private function avatar_tone( int $user_id ): string {
		return \BuddyNext\Profile\AvatarService::identity_tone_for( $user_id );
	}

	/**
	 * Render a sidebar member avatar: the member's photo when they have one,
	 * their initials when they do not.
	 *
	 * All three lists in this sidebar (Owner/Mods, Members, Top Contributors)
	 * printed initials unconditionally - they only ever called
	 * AvatarService::initials_for() and never asked for an image. The member
	 * directory and the sibling MembersSidebarProvider both resolve a real photo,
	 * so the same member rendered as a photo in one widget and a coloured monogram
	 * in another on the same page.
	 *
	 * Resolved through core's get_avatar_url(), which is what every other caller
	 * in the plugin uses and what AvatarService hooks (`pre_get_avatar_data`), so a
	 * BuddyNext-uploaded avatar, a Gravatar and anything a third party filters in
	 * all work without this knowing the difference. The initials stay as the
	 * fallback, which is why the tone attribute is still emitted.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $name    Display name, used for the initials fallback.
	 * @return void
	 */
	private function render_member_avatar( int $user_id, string $name ): void {
		$url = $user_id > 0 ? (string) get_avatar_url( $user_id, array( 'size' => 40 ) ) : '';
		?>
		<span class="bn-avatar bn-sh-side-member__avatar"
			data-size="sm"
			data-tone="<?php echo esc_attr( $this->avatar_tone( $user_id ) ); ?>"
			aria-hidden="true"
		><?php if ( '' !== $url ) : ?>
			<img
				src="<?php echo esc_url( $url ); ?>"
				alt=""
				width="40"
				height="40"
				loading="lazy"
				decoding="async"
			>
		<?php else : ?>
			<?php echo esc_html( AvatarService::initials_for( $name ) ); ?>
		<?php endif; ?></span>
		<?php
	}

	/**
	 * The moderators card title, computed from the roles ACTUALLY present in
	 * $mods rather than assumed — an owner-less fetch must not be titled "Owner".
	 *
	 * @param array<int,object> $mods Owner + moderator rows.
	 * @return string
	 */
	private function mods_title( array $mods ): string {
		$has_owner = (bool) array_filter(
			$mods,
			static fn( $m ): bool => 'owner' === ( $m->role ?? '' )
		);
		$mod_count = count( $mods );

		if ( $has_owner ) {
			return $mod_count > 1
				? __( 'Owner & Moderators', 'buddynext' )
				: __( 'Owner', 'buddynext' );
		}

		return _n( 'Moderator', 'Moderators', $mod_count, 'buddynext' );
	}

	/**
	 * Renders the "About this space" card body.
	 *
	 * @param object              $space Hydrated space object.
	 * @param array<string,mixed> $meta  SpaceService::display_meta() output.
	 * @return void
	 */
	private function render_about( object $space, array $meta ): void {
		if ( ! empty( $space->description ) ) :
			?>
			<p class="bn-sh-side-text"><?php echo esc_html( $space->description ); ?></p>
			<?php
		endif;
		?>
		<div class="bn-sh-side-meta">
			<span class="bn-badge" data-tone="<?php echo esc_attr( (string) $meta['privacy_tone'] ); ?>"><?php echo esc_html( (string) $meta['privacy_label'] ); ?></span>
			<?php if ( ! empty( $space->created_at ) && function_exists( 'buddynext_icon' ) ) : ?>
				<span class="bn-sh-side-meta__row">
					<?php buddynext_icon( 'calendar' ); ?>
					<?php
					// translators: %s is the formatted date.
					printf( esc_html__( 'Created %s', 'buddynext' ), buddynext_date_local( (string) $space->created_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_date_local() returns esc_html()'d output.
					?>
				</span>
			<?php endif; ?>
			<?php if ( ! empty( $space->category_name ) && function_exists( 'buddynext_icon' ) ) : ?>
				<span class="bn-sh-side-meta__row">
					<?php buddynext_icon( 'hash' ); ?>
					<?php echo esc_html( (string) $space->category_name ); ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the "Sub-spaces" card body: the child-space list (or a manager-only
	 * empty-state hint), the "Add sub-space" CTA, and the create-sub-space modal.
	 *
	 * The modal is a fixed-position backdrop (DOM location is functionally
	 * irrelevant to it — same contract as the former inline partial), so keeping
	 * it inside this card's body does not change behaviour.
	 *
	 * @param object                         $space          Hydrated space object.
	 * @param int                            $space_id       Space id.
	 * @param array<int,array<string,mixed>> $subspaces      Visibility-scoped child rows.
	 * @param bool                           $can_manage_sub Whether the viewer may add a sub-space.
	 * @param int                            $max_sub        Max sub-spaces (0 = unlimited).
	 * @param int                            $sub_used       Sub-spaces already created.
	 * @param bool                           $sub_full       Whether the cap is reached.
	 * @param array<int,object>              $sub_categories Categories for the create modal.
	 * @return void
	 */
	private function render_subspaces( object $space, int $space_id, array $subspaces, bool $can_manage_sub, int $max_sub, int $sub_used, bool $sub_full, array $sub_categories ): void {
		if ( ! empty( $subspaces ) ) :
			?>
			<ul class="bn-sh-side-spaces">
				<?php
				foreach ( $subspaces as $sub ) :
					$sub_id    = (int) ( $sub['id'] ?? 0 );
					$sub_name  = (string) ( $sub['name'] ?? __( 'Space', 'buddynext' ) );
					$sub_slug  = (string) ( $sub['slug'] ?? '' );
					$sub_count = (int) ( $sub['member_count'] ?? 0 );
					?>
					<li class="bn-sh-side-space">
						<a class="bn-sh-side-space__id" href="<?php echo esc_url( $sub_slug && function_exists( 'buddynext_space_url' ) ? buddynext_space_url( $sub_slug ) : '' ); ?>">
							<span class="bn-avatar bn-sh-side-space__emblem" data-size="sm" data-tone="<?php echo esc_attr( $this->avatar_tone( $sub_id ) ); ?>" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $sub_name, 0, 1 ) ) ); ?></span>
							<span class="bn-sh-side-space__body">
								<span class="bn-sh-side-space__name"><?php echo esc_html( $sub_name ); ?></span>
								<span class="bn-sh-side-space__meta">
									<?php
									printf(
										/* translators: %s: formatted member count. */
										esc_html( _n( '%s member', '%s members', $sub_count, 'buddynext' ) ),
										esc_html( number_format_i18n( $sub_count ) )
									);
									?>
								</span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
		elseif ( $can_manage_sub ) :
			?>
			<p class="bn-sh-side-text"><?php esc_html_e( 'Organize this space into focused sub-spaces members can join on their own.', 'buddynext' ); ?></p>
			<?php
		endif;

		if ( $can_manage_sub ) :
			?>
			<div class="bn-sh-side-spaces__cta" data-wp-interactive="buddynext/spaces">
				<?php if ( $max_sub > 0 ) : ?>
					<p class="bn-sh-side-spaces__capacity">
						<?php
						printf(
							/* translators: 1: sub-spaces already created, 2: maximum allowed. */
							esc_html__( '%1$d of %2$d sub-spaces used', 'buddynext' ),
							(int) $sub_used,
							(int) $max_sub
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( $sub_full ) : ?>
					<button
						type="button"
						class="bn-btn bn-sh-side-spaces__add"
						data-variant="ghost"
						data-size="sm"
						disabled
						aria-describedby="bn-sub-full-<?php echo esc_attr( (string) $space_id ); ?>"
					>
						<?php
						if ( function_exists( 'buddynext_icon' ) ) {
							buddynext_icon( 'plus' ); }
						?>
						<?php esc_html_e( 'Add sub-space', 'buddynext' ); ?>
					</button>
					<p class="bn-sh-side-spaces__full" id="bn-sub-full-<?php echo esc_attr( (string) $space_id ); ?>">
						<?php esc_html_e( 'This space has reached its sub-space limit.', 'buddynext' ); ?>
					</p>
				<?php else : ?>
					<button
						type="button"
						class="bn-btn bn-sh-side-spaces__add"
						data-variant="ghost"
						data-size="sm"
						data-wp-on--click="actions.openCreate"
						data-bn-create-space-trigger
					>
						<?php
						if ( function_exists( 'buddynext_icon' ) ) {
							buddynext_icon( 'plus' ); }
						?>
						<?php esc_html_e( 'Add sub-space', 'buddynext' ); ?>
					</button>
				<?php endif; ?>
			</div>
			<?php
			if ( function_exists( 'buddynext_get_template' ) ) {
				echo '<div data-wp-interactive="buddynext/spaces">';
				buddynext_get_template(
					'partials/create-space-modal.php',
					array(
						'categories'   => $sub_categories,
						'fixed_parent' => (object) array(
							'id'   => $space_id,
							'name' => (string) $space->name,
						),
					)
				);
				echo '</div>';
			}
		endif;
	}

	/**
	 * Renders the "Owner / Moderators" card body.
	 *
	 * @param array<int,object> $mods Owner + moderator rows.
	 * @return void
	 */
	private function render_owners( array $mods ): void {
		$msgs_on = MessagesData::available();
		?>
		<ul class="bn-sh-side-members">
			<?php foreach ( $mods as $mod ) : ?>
				<?php
				$mod_uid   = (int) $mod->user_id;
				$mod_name  = $mod->display_name ?? __( 'Member', 'buddynext' );
				$mod_url   = PageRouter::profile_url( $mod_uid );
				$mod_owner = 'owner' === $mod->role;
				?>
				<li class="bn-sh-side-member bn-sh-side-mod">
					<a class="bn-sh-side-mod__id" href="<?php echo esc_url( $mod_url ); ?>">
						<?php $this->render_member_avatar( $mod_uid, (string) $mod_name ); ?>
						<span class="bn-sh-side-member__name">
							<?php echo esc_html( $mod_name ); ?>
							<span class="bn-badge" data-tone="<?php echo $mod_owner ? 'paid' : 'accent'; ?>">
								<?php echo $mod_owner ? esc_html__( 'Admin', 'buddynext' ) : esc_html__( 'Mod', 'buddynext' ); ?>
							</span>
						</span>
					</a>
					<?php if ( $msgs_on ) : ?>
						<a
							class="bn-btn bn-sh-side-mod__msg"
							data-variant="ghost"
							data-size="sm"
							href="<?php echo esc_url( add_query_arg( 'recipient', $mod_uid, PageRouter::messages_url() ) ); ?>"
							aria-label="
							<?php
							/* translators: %s: member display name. */
							echo esc_attr( sprintf( __( 'Message %s', 'buddynext' ), $mod_name ) );
							?>
							"
						>
						<?php
						if ( function_exists( 'buddynext_icon' ) ) {
							buddynext_icon( 'mail' ); }
						?>
						</a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Renders the "Members" preview card body (regular members only).
	 *
	 * @param array<int,object> $regulars Regular-member rows.
	 * @return void
	 */
	private function render_members( array $regulars ): void {
		?>
		<ul class="bn-sh-side-members">
			<?php foreach ( $regulars as $m ) : ?>
				<?php
				$uid   = (int) $m->user_id;
				$mname = $m->display_name ?? __( 'Member', 'buddynext' );
				$murl  = PageRouter::profile_url( $uid );
				?>
				<li class="bn-sh-side-member">
					<a class="bn-sh-side-member__id" href="<?php echo esc_url( $murl ); ?>">
						<?php $this->render_member_avatar( $uid, (string) $mname ); ?>
						<span class="bn-sh-side-member__name">
							<?php echo esc_html( $mname ); ?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Renders the "Top contributors" card body.
	 *
	 * @param array<int,object> $contributors Top-contributor rows.
	 * @return void
	 */
	private function render_contributors( array $contributors ): void {
		?>
		<ul class="bn-sh-side-members">
			<?php foreach ( $contributors as $rank => $c ) : ?>
				<?php
				$cuid  = (int) $c->user_id;
				$cname = $c->display_name ?? __( 'Member', 'buddynext' );
				$curl  = PageRouter::profile_url( $cuid );
				?>
				<li class="bn-sh-side-member">
					<span class="bn-sh-side-member__rank"><?php echo esc_html( (string) ( $rank + 1 ) ); ?></span>
					<a class="bn-sh-side-member__id" href="<?php echo esc_url( $curl ); ?>">
						<?php $this->render_member_avatar( $cuid, (string) $cname ); ?>
						<span class="bn-sh-side-member__name"><?php echo esc_html( $cname ); ?></span>
					</a>
					<span class="bn-sh-side-member__count">
						<?php
						// translators: %d: number of posts.
						printf( esc_html( _n( '%d post', '%d posts', (int) $c->post_count, 'buddynext' ) ), (int) $c->post_count );
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
