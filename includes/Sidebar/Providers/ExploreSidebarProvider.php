<?php
/**
 * Explore-page right-sidebar provider (Free core).
 *
 * Registers the "community heartbeat" cards — a Pro pulse-chart seat plus
 * trending tags, people to discover, and a category browser — that formerly
 * lived inline in `templates/feed/parts/explore-aside.php`. Distinct from
 * FeedSidebarProvider: these are discovery signals scoped to the single
 * `explore` surface, not the personal-feed rail shared by six surfaces.
 *
 * Every card descriptor is `chrome => false`: its `render` closure emits the
 * card's own `<div class="bn-sidebar-card">` wrapper (moved verbatim into
 * `templates/parts/sidebar-explore-*.php`), so SidebarRegistry echoes the
 * body raw instead of double-wrapping it. The pulse widget has no chrome at
 * all — it only re-fires the `buddynext_explore_aside_pulse` action so Pro's
 * live community-pulse card keeps its injection seat at the same position.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Feed\ExploreService;
use BuddyNext\Hashtags\HashtagService;
use BuddyNext\Spaces\SpaceService;

/**
 * Explore-aside sidebar widget descriptors.
 */
class ExploreSidebarProvider {

	/**
	 * Surface this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'explore' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * Appends the four Explore-aside descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( 'explore' !== $surface ) {
			return $descriptors;
		}

		$uid = get_current_user_id();

		// Pro extension point — mirrors the do_action() that formerly sat at the
		// top of templates/feed/parts/explore-aside.php:40. Priority 10 keeps it
		// first, matching the original placement.
		$descriptors[] = array(
			'id'       => 'explore-pulse',
			'priority' => 10,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function () use ( $uid ): void {
				/**
				 * Fires at the top of the Explore aside. Pro hooks a live
				 * community-pulse card (the wireframe's chart) here.
				 *
				 * @since 1.0.0
				 *
				 * @param int $uid Viewing user ID.
				 */
				do_action( 'buddynext_explore_aside_pulse', $uid );
			},
		);

		$descriptors[] = array(
			'id'       => 'explore-trending-tags',
			'priority' => 20,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function (): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/sidebar-explore-trending-tags.php',
					array( 'bn_trending' => ( new HashtagService() )->trending( 5 ) )
				);
			},
		);

		$descriptors[] = array(
			'id'       => 'explore-people',
			'priority' => 30,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function () use ( $uid ): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/sidebar-explore-people.php',
					array(
						'bn_people' => ( new ExploreService() )->suggested_member_ids( 4 ),
						'bn_viewer' => $uid,
					)
				);
			},
		);

		$descriptors[] = array(
			'id'       => 'explore-browse',
			'priority' => 40,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function (): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/sidebar-explore-browse.php',
					array( 'bn_categories' => ( new SpaceService() )->categories_with_counts( 6 ) )
				);
			},
		);

		return $descriptors;
	}
}
