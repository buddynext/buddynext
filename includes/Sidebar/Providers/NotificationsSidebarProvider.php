<?php
/**
 * Notifications right-sidebar provider (Free core).
 *
 * Registers the six self-chromed notifications sidecards — quick filters,
 * per-type breakdown, recent actors, preferences link, "this week" stats,
 * and muted list — that formerly lived inline in
 * `templates/notifications/index.php` as a single `buddynext_right_sidebar`
 * add_action() callback. The raw unread counts, active filter, and recent
 * actors now travel via `Surface::set( 'notifications', $sidebar_data )`
 * instead, and this provider reads them back through `Surface::context()`
 * and rebuilds the `$quick_filters` / `$sidebar_types` arrays the callback
 * used to build inline.
 *
 * Every descriptor is `chrome => false`: each partial renders its own
 * `.bn-notif-sidecard` wrapper, so SidebarRegistry echoes the body raw
 * instead of double-wrapping it — same pattern as FeedSidebarProvider and
 * ExploreSidebarProvider.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Sidebar\Surface;

/**
 * Notifications sidebar widget descriptors.
 */
class NotificationsSidebarProvider {

	/**
	 * Surface this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'notifications' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * Appends the six notifications descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( 'notifications' !== $surface ) {
			return $descriptors;
		}

		$ctx = Surface::context();

		$active_filter   = (string) ( $ctx['active_filter'] ?? '' );
		$total_unread    = (int) ( $ctx['total_unread'] ?? 0 );
		$reaction_unread = (int) ( $ctx['reaction_unread'] ?? 0 );
		$comment_unread  = (int) ( $ctx['comment_unread'] ?? 0 );
		$mention_unread  = (int) ( $ctx['mention_unread'] ?? 0 );
		$follow_unread   = (int) ( $ctx['follow_unread'] ?? 0 );
		$space_unread    = (int) ( $ctx['space_unread'] ?? 0 );
		$message_unread  = (int) ( $ctx['message_unread'] ?? 0 );
		$recent_actors   = (array) ( $ctx['recent_actors'] ?? array() );

		$quick_filters = array(
			array(
				'key'   => 'unread',
				'label' => __( 'Unread only', 'buddynext' ),
				'icon'  => 'circle-dot',
				'count' => $total_unread,
			),
			array(
				'key'   => 'mention',
				'label' => __( 'Mentions of you', 'buddynext' ),
				'icon'  => 'at-sign',
				'count' => $mention_unread,
			),
			array(
				'key'   => 'follow',
				'label' => __( 'People', 'buddynext' ),
				'icon'  => 'users',
				'count' => $follow_unread,
			),
			array(
				'key'   => 'space',
				'label' => __( 'Spaces', 'buddynext' ),
				'icon'  => 'home',
				'count' => $space_unread,
			),
		);

		$sidebar_types = array(
			'mention'  => array(
				'label' => __( 'Mentions', 'buddynext' ),
				'icon'  => 'at-sign',
				'count' => $mention_unread,
			),
			'reaction' => array(
				'label' => __( 'Reactions', 'buddynext' ),
				'icon'  => 'heart',
				'count' => $reaction_unread,
			),
			'comment'  => array(
				'label' => __( 'Comments', 'buddynext' ),
				'icon'  => 'message-circle',
				'count' => $comment_unread,
			),
			'follow'   => array(
				'label' => __( 'People', 'buddynext' ),
				'icon'  => 'users',
				'count' => $follow_unread,
			),
			'space'    => array(
				'label' => __( 'Spaces', 'buddynext' ),
				'icon'  => 'home',
				'count' => $space_unread,
			),
			'message'  => array(
				'label' => __( 'Messages', 'buddynext' ),
				'icon'  => 'mail',
				'count' => $message_unread,
			),
		);

		$descriptors[] = array(
			'id'       => 'notif-recent-actors',
			'priority' => 30,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function () use ( $recent_actors ): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/notifications-sidecard-recent-actors.php',
					array(
						'recent_actors' => $recent_actors,
					)
				);
			},
		);

		$descriptors[] = array(
			'id'       => 'notif-prefs',
			'priority' => 40,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function (): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template( 'parts/notifications-sidecard-prefs.php', array() );
			},
		);

		// "This week" engagement stats card (Pattern D-6) and the muted-list
		// management widget are both personal, so they only render for a
		// logged-in viewer — same gate as the original callback.
		if ( get_current_user_id() > 0 ) {
			$descriptors[] = array(
				'id'       => 'notif-this-week',
				'priority' => 50,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'render'   => static function (): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					buddynext_get_template(
						'parts/sidebar-this-week-stats.php',
						array(
							'user_id' => (int) get_current_user_id(),
						)
					);
				},
			);

			// The part returns early when the viewer has muted nobody, so this
			// descriptor is free to register unconditionally in the common case.
			$descriptors[] = array(
				'id'       => 'notif-muted',
				'priority' => 60,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'render'   => static function (): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					buddynext_get_template(
						'parts/notifications-sidecard-muted.php',
						array(
							'user_id' => (int) get_current_user_id(),
						)
					);
				},
			);
		}

		return $descriptors;
	}
}
