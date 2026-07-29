<?php
/**
 * Spaces-directory right-sidebar provider (Free core).
 *
 * Registers the three titled sidebar cards — suggested, your spaces, popular
 * — that formerly lived inline in `templates/spaces/directory.php` as a
 * single `buddynext_right_sidebar` callback. Distinct from FeedSidebarProvider
 * and ExploreSidebarProvider: these descriptors use the registry's DEFAULT
 * chrome (no `chrome => false`), so each `render` closure echoes ONLY the
 * inner `<ul>` body and SidebarRegistry wraps it in `parts/sidebar-card.php`
 * using the descriptor's `title`/`icon` — same pattern as Task 6's
 * MembersSidebarProvider.
 *
 * "Suggested for you" and "Popular this week" are mutually exclusive: the
 * popular descriptor is added only when suggested did not render (guest, or
 * a logged-in member with no suggestions yet), reproducing the original
 * `$bn_suggested_shown` gate exactly.
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
		$suggested_shown = false;

		// Card: Suggested for you (members only) — personalized discovery (social
		// proof + category affinity + popularity). Empty (member already in
		// everything / nothing fits) → the descriptor is not added, and
		// "Popular this week" below shows as the fallback. Logged-out visitors
		// get "Popular this week" only.
		if ( $current_user_id ) {
			$suggested = ( new SpaceSuggestionService() )->suggest( $current_user_id, 5 );

			if ( ! empty( $suggested ) ) {
				$suggested_shown = true;

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

		// Card: Popular this week — shown to logged-out visitors (who can't get
		// suggestions) and as the fallback for a logged-in member with no
		// suggestions. Suppressed when "Suggested for you" rendered, so the two
		// never overlap.
		if ( ! $suggested_shown ) {
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
}
