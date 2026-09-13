<?php
/**
 * Spaces-directory right-sidebar provider (Free core).
 *
 * Ships a full, ready-made sidebar for the Spaces directory so the column is
 * never a single thin card — BuddyNext exposes no widget-add UI, so each page
 * must carry its own relevant defaults. Registers up to five titled cards:
 * "Suggested for you" (personalized), "Your spaces" (managed + joined),
 * "New spaces" (recently created), "Popular this week" (most-joined) and
 * "Community pulse" (a compact stats strip). "Suggested" and "Your spaces"
 * are member-only and skip when empty; "New spaces", "Popular this week" and
 * "Community pulse" always render (guest and member) so the directory reads
 * as a living community out of the box.
 *
 * These descriptors formerly lived inline in `templates/spaces/directory.php`
 * as a single `buddynext_right_sidebar` callback. They use the registry's
 * DEFAULT chrome (no `chrome => false`), so each `render` closure echoes ONLY
 * the inner body and SidebarRegistry wraps it in `parts/sidebar-card.php`
 * using the descriptor's `title`/`icon` — same pattern as MembersSidebarProvider.
 * Owners who want a leaner column drop any card via `buddynext_sidebar_widgets`.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceSuggestionService;

/**
 * Spaces-directory sidebar widget descriptors.
 */
class SpacesDirectorySidebarProvider {

	/**
	 * Surface this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'spaces' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * Appends the spaces-directory descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( 'spaces' !== $surface ) {
			return $descriptors;
		}

		$current_user_id = get_current_user_id();
		$space_service   = new SpaceService();
		$cat_by_id       = $this->categories_by_id( $space_service );

		// Card: Suggested for you (members only) — personalized discovery (social
		// proof + category affinity + popularity). Empty (member already in
		// everything / nothing fits) → the descriptor is not added, and
		// "Popular this week" below shows as the fallback. Logged-out visitors
		// get "Popular this week" only.
		if ( $current_user_id ) {
			$suggested = ( new SpaceSuggestionService() )->suggest( $current_user_id, 5 );

			if ( ! empty( $suggested ) ) {
				$descriptors[] = array(
					'id'       => 'spaces-suggested',
					'priority' => 20,
					'surfaces' => self::SURFACES,
					'title'    => __( 'Suggested for you', 'buddynext' ),
					'icon'     => 'sparkles',
					'render'   => function () use ( $suggested, $cat_by_id ): void {
						$this->render_space_list( $suggested, $cat_by_id );
					},
				);
			}
		}

		// Card: Your spaces (members only) — split into "You manage" (owner/mod)
		// and "You joined" (member) via the same member_role filter the directory
		// uses, so the spaces a member is responsible for are easy to find.
		if ( $current_user_id ) {
			$managed = $space_service->list_spaces(
				array(
					'member'      => $current_user_id,
					'viewer'      => $current_user_id,
					'member_role' => 'manage',
					'per_page'    => 5,
				)
			);
			$joined  = $space_service->list_spaces(
				array(
					'member'      => $current_user_id,
					'viewer'      => $current_user_id,
					'member_role' => 'joined',
					'per_page'    => 5,
				)
			);

			if ( ! empty( $managed ) || ! empty( $joined ) ) {
				$descriptors[] = array(
					'id'       => 'spaces-yours',
					'priority' => 30,
					'surfaces' => self::SURFACES,
					'title'    => __( 'Your spaces', 'buddynext' ),
					'icon'     => 'users',
					'render'   => function () use ( $managed, $joined, $cat_by_id ): void {
						$this->render_your_spaces( $managed, $joined, $cat_by_id );
					},
				);
			}
		}

		// Card: New spaces — the most recently created spaces, so the directory
		// always surfaces fresh activity even for a member who already sees
		// personalized suggestions. A discovery card, distinct from the
		// popularity-ordered "Popular this week" below.
		$newest = $space_service->list_spaces(
			array(
				'type'     => 'open',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 4,
				'viewer'   => $current_user_id,
				'is_admin' => current_user_can( 'manage_options' ),
			)
		);
		if ( ! empty( $newest ) ) {
			$descriptors[] = array(
				'id'       => 'spaces-new',
				'priority' => 35,
				'surfaces' => self::SURFACES,
				'title'    => __( 'New spaces', 'buddynext' ),
				'icon'     => 'clock',
				'render'   => function () use ( $newest, $cat_by_id ): void {
					$this->render_space_list( $newest, $cat_by_id );
				},
			);
		}

		// Card: Popular this week — the most-joined open spaces. Always shown
		// (guest AND member): "Suggested for you" is personalized affinity while
		// this is community-wide popularity, so the two answer different
		// questions and are worth showing together. Owners who ship a leaner
		// sidebar can drop it via the buddynext_sidebar_widgets filter.
		$featured = $space_service->list_spaces(
			array(
				'type'     => 'open',
				'orderby'  => 'member_count',
				'order'    => 'DESC',
				'per_page' => 5,
				'viewer'   => $current_user_id,
				'is_admin' => current_user_can( 'manage_options' ),
			)
		);
		if ( ! empty( $featured ) ) {
			$descriptors[] = array(
				'id'       => 'spaces-popular',
				'priority' => 40,
				'surfaces' => self::SURFACES,
				'title'    => __( 'Popular this week', 'buddynext' ),
				'icon'     => 'star',
				'render'   => function () use ( $featured, $cat_by_id ): void {
					$this->render_space_list( $featured, $cat_by_id );
				},
			);
		}

		// Card: Community pulse — a compact stats strip so the directory sidebar
		// carries a sense of scale (how many spaces, how open the community is)
		// even before any list renders. Always shown.
		$pulse = $this->community_pulse( $space_service, $current_user_id );
		if ( $pulse['spaces'] > 0 ) {
			$descriptors[] = array(
				'id'       => 'spaces-pulse',
				'priority' => 50,
				'surfaces' => self::SURFACES,
				'title'    => __( 'Community pulse', 'buddynext' ),
				'icon'     => 'activity',
				'render'   => function () use ( $pulse ): void {
					$this->render_pulse( $pulse );
				},
			);
		}

		return $descriptors;
	}

	/**
	 * Category id → row map, used to resolve a hydrated space row's
	 * category_slug for the emblem helper (list_spaces() rows only carry
	 * category_id). Mirrors the map templates/spaces/directory.php builds
	 * from the same service call for the main grid.
	 *
	 * @param SpaceService $space_service Space service instance.
	 * @return array<int,array<string,mixed>>
	 */
	private function categories_by_id( SpaceService $space_service ): array {
		$cat_by_id = array();
		foreach ( $space_service->categories_with_counts( 0, true ) as $cat_row ) {
			$cat_by_id[ (int) $cat_row['id'] ] = $cat_row;
		}
		return $cat_by_id;
	}

	/**
	 * Resolves category_slug onto a hydrated space row from its category_id.
	 *
	 * @param array<string,mixed>            $space     Hydrated space row.
	 * @param array<int,array<string,mixed>> $cat_by_id Category id → row map.
	 * @return array<string,mixed>
	 */
	private function resolve_slug( array $space, array $cat_by_id ): array {
		$cat_id                 = isset( $space['category_id'] ) ? (int) $space['category_id'] : 0;
		$space['category_slug'] = $cat_id && isset( $cat_by_id[ $cat_id ] )
			? (string) $cat_by_id[ $cat_id ]['slug']
			: '';
		return $space;
	}

	/**
	 * Emblem for a sidebar space row: the real space avatar when set, else the
	 * category glyph. Local copy of the `bn_space_side_emblem()` helper
	 * formerly declared inline in templates/spaces/directory.php — the
	 * provider owns this sidebar-only concern independently of the template.
	 *
	 * @param array<string,mixed> $space Hydrated space row (avatar_url, category_slug).
	 * @return string Safe markup (escaped img, or wp_kses()-sanitized SVG).
	 */
	private function side_emblem( array $space ): string {
		$avatar = isset( $space['avatar_url'] ) ? (string) $space['avatar_url'] : '';
		if ( '' !== $avatar ) {
			return '<img src="' . esc_url( $avatar ) . '" alt="" width="28" height="28" loading="lazy">';
		}
		return function_exists( 'bn_space_category_icon' )
			? bn_space_category_icon( isset( $space['category_slug'] ) ? (string) $space['category_slug'] : '' )
			: '';
	}

	/**
	 * Renders a flat `<ul>` of spaces with name + member-count meta — the
	 * shared row shape used by "Suggested for you" and "Popular this week".
	 *
	 * @param array<int,array<string,mixed>> $spaces    Hydrated space rows.
	 * @param array<int,array<string,mixed>> $cat_by_id Category id → row map.
	 * @return void
	 */
	private function render_space_list( array $spaces, array $cat_by_id ): void {
		if ( ! function_exists( 'buddynext_space_url' ) ) {
			return;
		}
		?>
		<ul class="bn-sd-side-list">
			<?php
			foreach ( $spaces as $space ) :
				$space = $this->resolve_slug( $space, $cat_by_id );
				?>
				<li>
					<a href="<?php echo esc_url( buddynext_space_url( (string) $space['slug'] ) ); ?>" class="bn-sd-side-row">
						<span class="bn-sd-side-row__icon" aria-hidden="true"><?php echo $this->side_emblem( $space ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns esc_url()'d img or wp_kses()-sanitized SVG. ?></span>
						<span class="bn-sd-side-row__main">
							<span><?php echo esc_html( (string) $space['name'] ); ?></span>
							<span class="bn-sd-side-row__meta">
							<?php
							$member_count = (int) $space['member_count'];
							/* translators: %s: formatted member count. */
							printf( esc_html( _n( '%s member', '%s members', $member_count, 'buddynext' ) ), esc_html( number_format_i18n( $member_count ) ) );
							?>
							</span>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Renders the "Your spaces" body: a "You manage" group followed by a
	 * "You joined" group, each an unlabeled-count `<ul>` — either group is
	 * skipped when empty.
	 *
	 * @param array<int,array<string,mixed>> $managed   Spaces the viewer manages.
	 * @param array<int,array<string,mixed>> $joined    Spaces the viewer joined.
	 * @param array<int,array<string,mixed>> $cat_by_id Category id → row map.
	 * @return void
	 */
	private function render_your_spaces( array $managed, array $joined, array $cat_by_id ): void {
		if ( ! function_exists( 'buddynext_space_url' ) ) {
			return;
		}
		$groups = array(
			array(
				'label'  => __( 'You manage', 'buddynext' ),
				'spaces' => $managed,
			),
			array(
				'label'  => __( 'You joined', 'buddynext' ),
				'spaces' => $joined,
			),
		);
		foreach ( $groups as $group ) :
			if ( empty( $group['spaces'] ) ) {
				continue;
			}
			?>
			<p class="bn-sd-side-grouplabel"><?php echo esc_html( (string) $group['label'] ); ?></p>
			<ul class="bn-sd-side-list">
				<?php
				foreach ( $group['spaces'] as $space ) :
					$space = $this->resolve_slug( $space, $cat_by_id );
					?>
					<li>
						<a href="<?php echo esc_url( buddynext_space_url( (string) $space['slug'] ) ); ?>" class="bn-sd-side-row">
							<span class="bn-sd-side-row__icon" aria-hidden="true"><?php echo $this->side_emblem( $space ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns esc_url()'d img or wp_kses()-sanitized SVG. ?></span>
							<span><?php echo esc_html( (string) $space['name'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
		endforeach;
	}

	/**
	 * Compute the directory-scale figures for the "Community pulse" card:
	 * total visible spaces, how many are open to join, and the combined
	 * membership across them. Reads through SpaceService so the sidebar never
	 * touches bn_ tables directly.
	 *
	 * @param SpaceService $space_service Space service instance.
	 * @param int          $viewer_id     Current viewer (0 for guests).
	 * @return array{spaces:int,open:int,members:int}
	 */
	private function community_pulse( SpaceService $space_service, int $viewer_id ): array {
		$is_admin = current_user_can( 'manage_options' );

		$all  = $space_service->list_spaces_with_total(
			array(
				'viewer'   => $viewer_id,
				'is_admin' => $is_admin,
				'per_page' => 100,
			)
		);
		$open = $space_service->list_spaces_with_total(
			array(
				'type'     => 'open',
				'viewer'   => $viewer_id,
				'is_admin' => $is_admin,
				'per_page' => 1,
			)
		);

		$rows    = isset( $all['items'] ) && is_array( $all['items'] ) ? $all['items'] : array();
		$members = 0;
		foreach ( $rows as $row ) {
			$members += isset( $row['member_count'] ) ? (int) $row['member_count'] : 0;
		}

		return array(
			'spaces'  => isset( $all['total'] ) ? (int) $all['total'] : count( $rows ),
			'open'    => isset( $open['total'] ) ? (int) $open['total'] : 0,
			'members' => $members,
		);
	}

	/**
	 * Render the "Community pulse" body: three compact stat rows.
	 *
	 * @param array{spaces:int,open:int,members:int} $pulse Precomputed figures.
	 * @return void
	 */
	private function render_pulse( array $pulse ): void {
		$stats = array(
			array(
				'value' => $pulse['spaces'],
				'label' => _n( 'space', 'spaces', $pulse['spaces'], 'buddynext' ),
			),
			array(
				'value' => $pulse['open'],
				'label' => __( 'open to join', 'buddynext' ),
			),
			array(
				'value' => $pulse['members'],
				'label' => _n( 'membership', 'memberships', $pulse['members'], 'buddynext' ),
			),
		);
		?>
		<ul class="bn-sd-pulse">
			<?php foreach ( $stats as $stat ) : ?>
				<li class="bn-sd-pulse__row">
					<span class="bn-sd-pulse__value"><?php echo esc_html( number_format_i18n( (int) $stat['value'] ) ); ?></span>
					<span class="bn-sd-pulse__label"><?php echo esc_html( (string) $stat['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
