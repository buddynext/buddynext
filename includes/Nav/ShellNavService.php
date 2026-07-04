<?php
/**
 * Shell navigation resolver.
 *
 * Single source of truth for the app shell's left-rail / bottom-bar navigation:
 * the canonical item list (Feed, Explore, Members, Spaces, Notifications, Messages
 * + the personal "You" group), the owner overrides applied via the
 * `buddynext_rail_items` filter (Nav\NavOverrides), and the live unread badge
 * counts. Both the server-rendered rail template and the REST shell-nav endpoint
 * consume this, so the web shell and the native app can never drift apart.
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

use BuddyNext\Core\PageRouter;
use BuddyNext\Messages\MessagesData;

/**
 * Builds and resolves the app-shell navigation.
 */
class ShellNavService {

	/**
	 * Live unread badge counts for the shell.
	 *
	 * @param int $user_id Viewing user (0 = logged out → zeros).
	 * @return array{notifications:int,messages:int}
	 */
	public function badges( int $user_id ): array {
		$notifications = 0;
		$messages      = 0;

		if ( $user_id > 0 && function_exists( 'buddynext_service' ) ) {
			$notifications = (int) buddynext_service( 'notifications' )->unread_count( $user_id );
			if ( class_exists( '\BuddyNext\Messages\MessagesData' ) ) {
				$messages = (int) MessagesData::unread_count( $user_id );
			}
		}

		return array(
			'notifications' => $notifications,
			'messages'      => $messages,
		);
	}

	/**
	 * The canonical shell items BEFORE owner overrides, with show-flags + badges.
	 *
	 * This is the array the rail template and the REST endpoint both start from, so
	 * the item set is defined exactly once.
	 *
	 * @param int $user_id Viewing user.
	 * @return array<int,array<string,mixed>>
	 */
	public function base_items( int $user_id ): array {
		$badges         = $this->badges( $user_id );
		$logged_in      = $user_id > 0;
		$spaces_enabled = ! function_exists( 'buddynext_service' )
			|| ! is_object( buddynext_service( 'features' ) )
			|| buddynext_service( 'features' )->is_enabled( 'spaces' );
		$messages_on    = class_exists( '\BuddyNext\Messages\MessagesData' ) && MessagesData::entry_enabled();

		$items = array(
			array(
				'key'   => 'feed',
				'label' => __( 'Feed', 'buddynext' ),
				'url'   => PageRouter::activity_url(),
				'icon'  => 'home',
				'show'  => true,
			),
			array(
				'key'   => 'explore',
				'label' => __( 'Explore', 'buddynext' ),
				'url'   => PageRouter::explore_url(),
				'icon'  => 'globe',
				'show'  => true,
			),
			array(
				'key'   => 'people',
				'label' => __( 'Members', 'buddynext' ),
				'url'   => PageRouter::people_url(),
				'icon'  => 'users',
				'show'  => true,
			),
			array(
				'key'   => 'spaces',
				'label' => __( 'Spaces', 'buddynext' ),
				'url'   => PageRouter::spaces_url(),
				'icon'  => 'hash',
				'show'  => $spaces_enabled,
			),
			array(
				'key'   => 'notifications',
				'label' => __( 'Notifications', 'buddynext' ),
				'url'   => PageRouter::notifications_url(),
				'icon'  => 'bell',
				'badge' => $badges['notifications'],
				'show'  => $logged_in,
			),
			array(
				'key'   => 'messages',
				'label' => __( 'Messages', 'buddynext' ),
				'url'   => PageRouter::messages_url(),
				'icon'  => 'message-circle',
				'badge' => $badges['messages'],
				'show'  => $logged_in && $messages_on,
			),
		);

		if ( $logged_in ) {
			$items[] = array(
				'key'   => 'profile',
				'label' => __( 'Profile', 'buddynext' ),
				'url'   => PageRouter::profile_url( $user_id ),
				'icon'  => 'user',
				'show'  => true,
				'group' => 'you',
				'order' => 200,
			);
			$items[] = array(
				'key'   => 'edit-profile',
				'label' => __( 'Edit Profile', 'buddynext' ),
				'url'   => PageRouter::edit_profile_url( $user_id ),
				'icon'  => 'edit',
				'show'  => true,
				'group' => 'you',
				'order' => 210,
			);
			$items[] = array(
				'key'   => 'bookmarks',
				'label' => __( 'Bookmarks', 'buddynext' ),
				'url'   => PageRouter::bookmarks_url(),
				'icon'  => 'bookmark',
				'show'  => true,
				'group' => 'you',
				'order' => 220,
			);
			$items[] = array(
				'key'   => 'settings',
				'label' => __( 'Settings', 'buddynext' ),
				'url'   => PageRouter::settings_url(),
				'icon'  => 'settings',
				'show'  => true,
				'group' => 'you',
				'order' => 230,
			);
		}

		return $items;
	}

	/**
	 * Fully resolved shell nav — base items + owner overrides + badges, shaped for
	 * the REST payload (hidden items dropped, sorted by order).
	 *
	 * @param int    $user_id Viewing user.
	 * @param string $hub     Current hub slug (passed to the override filter).
	 * @return array{items:array<int,array<string,mixed>>,badges:array{notifications:int,messages:int}}
	 */
	public function resolve( int $user_id, string $hub = '' ): array {
		/** This filter is documented in templates/shell/rail.php */
		$items = (array) apply_filters( 'buddynext_rail_items', $this->base_items( $user_id ), $hub );

		$out = array();
		foreach ( $items as $item ) {
			if ( empty( $item['show'] ) ) {
				continue;
			}
			$out[] = array(
				'key'   => (string) ( $item['key'] ?? '' ),
				'label' => (string) ( $item['label'] ?? '' ),
				'url'   => (string) ( $item['url'] ?? '' ),
				'icon'  => (string) ( $item['icon'] ?? '' ),
				'badge' => isset( $item['badge'] ) ? (int) $item['badge'] : 0,
				'group' => (string) ( $item['group'] ?? 'primary' ),
				'order' => isset( $item['order'] ) ? (int) $item['order'] : 0,
			);
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => $a['order'] <=> $b['order']
		);

		return array(
			'items'  => $out,
			'badges' => $this->badges( $user_id ),
		);
	}
}
