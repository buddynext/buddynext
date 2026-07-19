<?php
/**
 * Member-directory right-sidebar provider (Free core).
 *
 * Registers the two titled sidebar cards — online-now and by-type — that
 * formerly lived inline in `templates/directory/members.php` as
 * `buddynext_right_sidebar` callbacks. Distinct from FeedSidebarProvider and
 * ExploreSidebarProvider: these descriptors use the registry's DEFAULT
 * chrome (no `chrome => false`), so each `render` closure echoes ONLY the
 * inner `<ul>` body and SidebarRegistry wraps it in `parts/sidebar-card.php`
 * using the descriptor's `title`/`icon`.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Core\PageRouter;
use BuddyNext\Core\Container;

/**
 * Member-directory sidebar widget descriptors.
 */
class MembersSidebarProvider {

	/**
	 * Surface this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'members' );

	/**
	 * Avatar tone palette — cycles deterministically by user ID. Mirrors
	 * the `$bn_avatar_tones` array formerly declared inline in
	 * templates/directory/members.php.
	 *
	 * @var array<int,string>
	 */
	private const AVATAR_TONES = array( 'accent', 'success', 'jetonomy', 'media', 'events', 'warn', 'danger', 'info' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * Appends the two member-directory descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( 'members' !== $surface ) {
			return $descriptors;
		}

		$current_user_id = get_current_user_id();

		// Online-now: most-recently-active members within the online window, via
		// the same directory service (block-restrict aware) members.php reads.
		$online_rows = ( function_exists( 'buddynext_service' ) && is_object( buddynext_service( 'member_directory' ) ) )
			? buddynext_service( 'member_directory' )->online_now( $current_user_id, 6 )
			: array();

		if ( ! empty( $online_rows ) ) {
			$descriptors[] = array(
				'id'       => 'members-online-now',
				'priority' => 20,
				'surfaces' => self::SURFACES,
				'title'    => sprintf(
					/* translators: %s: number of online members */
					__( 'Online now (%s)', 'buddynext' ),
					number_format_i18n( count( $online_rows ) )
				),
				'icon'     => 'users',
				'render'   => static function () use ( $online_rows ): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					echo '<ul class="bn-member-row-list">';
					foreach ( $online_rows as $row ) {
						$row_id = (int) $row['ID'];
						buddynext_get_template(
							'parts/sidebar-member-row.php',
							array(
								'row_user_id' => $row_id,
								'row_name'    => (string) $row['display_name'],
								'row_handle'  => (string) ( $row['handle'] ?? '' ),
								'row_url'     => PageRouter::profile_url( $row_id ),
								'row_avatar'  => (string) get_avatar_url( $row_id, array( 'size' => 40 ) ),
								'row_tone'    => self::AVATAR_TONES[ $row_id % count( self::AVATAR_TONES ) ],
								'row_online'  => true,
							)
						);
					}
					echo '</ul>';
				},
			);
		}

		// NOTE: no "By type" widget — the directory toolbar already has an "All
		// member types" dropdown facet, so a sidebar type list would duplicate the
		// same filter. Type filtering lives in the toolbar; the sidebar carries
		// presence (online-now) + discovery (below) instead.

		// Discovery widgets fill the directory sidebar below online-now (a short
		// card, so the column had dead space). Viewer-centric + self-chromed,
		// reusing the shared feed partials/service.
		$service = ( function_exists( 'buddynext_service' ) && Container::instance()->has( 'sidebar_widgets' ) )
			? buddynext_service( 'sidebar_widgets' )
			: null;

		if ( is_object( $service ) && $current_user_id > 0 && method_exists( $service, 'suggested_follows' ) ) {
			$suggested = (array) $service->suggested_follows( $current_user_id, 3 );
			if ( ! empty( $suggested ) ) {
				$members_url   = PageRouter::people_url();
				$descriptors[] = array(
					'id'       => 'members-people-to-follow',
					'priority' => 40,
					'surfaces' => self::SURFACES,
					'chrome'   => false,
					'render'   => static function () use ( $suggested, $members_url ): void {
						if ( ! function_exists( 'buddynext_get_template' ) ) {
							return;
						}
						buddynext_get_template(
							'parts/sidebar-people-to-follow.php',
							array(
								'sbar_suggested'   => $suggested,
								'sbar_members_url' => $members_url,
							)
						);
					},
				);
			}
		}

		if ( is_object( $service ) && method_exists( $service, 'trending_hashtags' ) ) {
			$trending = (array) $service->trending_hashtags( 5 );
			if ( ! empty( $trending ) ) {
				$descriptors[] = array(
					'id'       => 'members-whats-happening',
					'priority' => 50,
					'surfaces' => self::SURFACES,
					'chrome'   => false,
					'render'   => static function () use ( $trending ): void {
						if ( ! function_exists( 'buddynext_get_template' ) ) {
							return;
						}
						buddynext_get_template(
							'parts/sidebar-trending-topics.php',
							array( 'sbar_trending' => $trending )
						);
					},
				);
			}
		}

		return $descriptors;
	}
}
