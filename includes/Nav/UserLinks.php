<?php
/**
 * User account / auth links — the single source of truth for BuddyNext's
 * dynamic, per-member navigation items.
 *
 * Each item is keyed by a `#bn-*` token. The token is the only thing stored in a
 * menu (as a Custom Link URL) or referenced by the header dropdown; at render
 * time it is resolved to the CURRENT member's URL and shown/hidden by login
 * state. One catalogue feeds three consumers:
 *
 *   - the header avatar dropdown (HeaderUserSection),
 *   - the Appearance → Menus "BuddyNext" metabox (NavMenuMetabox),
 *   - the render-time resolver for every WP menu (MenuRenderer).
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

use BuddyNext\Core\PageRouter;

/**
 * The catalogue of BuddyNext user/auth menu items and their per-user resolver.
 */
final class UserLinks {

	/**
	 * Visibility: only for logged-in members.
	 */
	public const LOGGEDIN = 'loggedin';

	/**
	 * Visibility: only for logged-out visitors.
	 */
	public const LOGGEDOUT = 'loggedout';

	/**
	 * The full catalogue, in display order.
	 *
	 * URLs are NOT included here — they are resolved per-user by resolve(). The
	 * list is filterable so developers can register their own items instead of
	 * being limited to the built-ins. A custom item may carry its own URL
	 * resolver so it stays per-member like the built-ins:
	 *
	 *     add_filter( 'buddynext_user_links', function ( array $items ) {
	 *         $items[] = array(
	 *             'token'      => '#bn-courses',
	 *             'label'      => __( 'My Courses', 'my-plugin' ),
	 *             'icon'       => 'graduation-cap',
	 *             'visibility' => 'loggedin',
	 *             'callback'   => fn( int $user_id ) => my_plugin_courses_url( $user_id ),
	 *         );
	 *         return $items;
	 *     } );
	 *
	 * The new item then appears in the Appearance → Menus metabox, the header
	 * dropdown, and resolves per-user in every menu — no core change needed.
	 *
	 * @return array<int,array{token:string,label:string,icon:string,visibility:string,callback?:callable,url?:string}>
	 */
	public static function catalogue(): array {
		$items = array(
			array(
				'token'      => '#bn-profile',
				'label'      => __( 'My Profile', 'buddynext' ),
				'icon'       => 'user',
				'visibility' => self::LOGGEDIN,
			),
			array(
				'token'      => '#bn-edit-profile',
				'label'      => __( 'Edit Profile', 'buddynext' ),
				'icon'       => 'edit',
				'visibility' => self::LOGGEDIN,
			),
			array(
				'token'      => '#bn-connections',
				'label'      => __( 'My Connections', 'buddynext' ),
				'icon'       => 'users',
				'visibility' => self::LOGGEDIN,
			),
			array(
				'token'      => '#bn-messages',
				'label'      => __( 'Messages', 'buddynext' ),
				'icon'       => 'messages-square',
				'visibility' => self::LOGGEDIN,
			),
			array(
				'token'      => '#bn-notifications',
				'label'      => __( 'Notifications', 'buddynext' ),
				'icon'       => 'bell',
				'visibility' => self::LOGGEDIN,
			),
			array(
				'token'      => '#bn-bookmarks',
				'label'      => __( 'Bookmarks', 'buddynext' ),
				'icon'       => 'bookmark',
				'visibility' => self::LOGGEDIN,
			),
			array(
				'token'      => '#bn-spaces',
				'label'      => __( 'Spaces', 'buddynext' ),
				'icon'       => 'grid',
				'visibility' => self::LOGGEDIN,
			),
			array(
				'token'      => '#bn-settings',
				'label'      => __( 'Settings', 'buddynext' ),
				'icon'       => 'settings',
				'visibility' => self::LOGGEDIN,
			),
			array(
				'token'      => '#bn-logout',
				'label'      => __( 'Log Out', 'buddynext' ),
				'icon'       => 'log-out',
				'visibility' => self::LOGGEDIN,
			),
			array(
				'token'      => '#bn-login',
				'label'      => __( 'Log In', 'buddynext' ),
				'icon'       => 'log-in',
				'visibility' => self::LOGGEDOUT,
			),
			array(
				'token'      => '#bn-register',
				'label'      => __( 'Register', 'buddynext' ),
				'icon'       => 'user-plus',
				'visibility' => self::LOGGEDOUT,
			),
		);

		// Drop the Messages item when messaging is not a usable entry point —
		// either the site owner turned direct messaging off (buddynext_enable_dm)
		// OR the WPMediaVerse engine that backs it is not active. The catalogue is
		// the single source of truth for the header dropdown, the menu metabox,
		// and the menu resolver, so removing it here hides messaging everywhere.
		if ( ! \BuddyNext\Messages\MessagesData::entry_enabled() ) {
			$items = array_values(
				array_filter(
					$items,
					static fn( array $item ): bool => '#bn-messages' !== ( $item['token'] ?? '' )
				)
			);
		}

		// Drop the Spaces item when the site owner has disabled the Spaces feature
		// (FeatureRegistry 'spaces', default-on — the authoritative toggle). The
		// /spaces/ route is already guarded in PageRouter::dispatch_hub_template();
		// removing it from this catalogue hides the nav link everywhere the
		// catalogue feeds (header dropdown, menu metabox, menu resolver) so the
		// hub is neither linked nor reachable when off.
		if ( function_exists( 'buddynext_service' )
			&& ! buddynext_service( 'features' )->is_enabled( 'spaces' )
		) {
			$items = array_values(
				array_filter(
					$items,
					static fn( array $item ): bool => '#bn-spaces' !== ( $item['token'] ?? '' )
				)
			);
		}

		/**
		 * Filters the BuddyNext user/auth menu catalogue.
		 *
		 * Add, remove, reorder, or relabel items. A custom item should use a
		 * `#bn-` token and may provide a `callback` ( callable( int $user_id ):
		 * string ) or a static `url` so it resolves per-member everywhere.
		 *
		 * @param array<int,array<string,mixed>> $items Catalogue rows.
		 */
		return (array) apply_filters( 'buddynext_user_links', $items );
	}

	/**
	 * Catalogue rows for a given visibility (e.g. all logged-in items).
	 *
	 * @param string $visibility self::LOGGEDIN | self::LOGGEDOUT.
	 * @return array<int,array{token:string,label:string,icon:string,visibility:string}>
	 */
	public static function items( string $visibility ): array {
		return array_values(
			array_filter(
				self::catalogue(),
				static fn( array $item ): bool => $item['visibility'] === $visibility
			)
		);
	}

	/**
	 * Whether a URL is a BuddyNext dynamic token.
	 *
	 * Every `#bn-*` URL counts (so an unknown one is dropped rather than left as
	 * a dead link), as does any token a developer registered in the catalogue.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_token( string $url ): bool {
		$key = strtolower( trim( $url ) );
		if ( '#bn-' === substr( $key, 0, 4 ) ) {
			return true;
		}
		foreach ( self::catalogue() as $item ) {
			if ( strtolower( (string) ( $item['token'] ?? '' ) ) === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The visibility for a token, or '' when the token is unknown.
	 *
	 * @param string $token A `#bn-*` token.
	 * @return string self::LOGGEDIN | self::LOGGEDOUT | ''.
	 */
	public static function visibility( string $token ): string {
		$key = strtolower( trim( $token ) );
		foreach ( self::catalogue() as $item ) {
			if ( $item['token'] === $key ) {
				return $item['visibility'];
			}
		}
		return '';
	}

	/**
	 * Resolve a token to the URL for a specific member.
	 *
	 * Built-in tokens resolve through PageRouter; developer-registered tokens
	 * resolve through their `callback`/`url`. A final `buddynext_user_link_url`
	 * filter lets any URL be overridden. Returns '' when nothing resolves
	 * (logged-in tokens need a real member ID), so the caller drops the item.
	 *
	 * @param string $token   A `#bn-*` token.
	 * @param int    $user_id The member to resolve for (0 = none).
	 * @return string Absolute URL, or '' when not resolvable.
	 */
	public static function resolve( string $token, int $user_id ): string {
		$key = strtolower( trim( $token ) );
		$url = self::core_url( $key, $user_id );

		// Not a built-in — let a developer-registered catalogue item resolve it.
		if ( null === $url ) {
			$url = self::custom_url( $key, $user_id );
		}

		/**
		 * Filters the resolved URL for a BuddyNext menu token.
		 *
		 * @param string $url     Resolved URL ('' when not resolvable).
		 * @param string $token   The `#bn-*` token.
		 * @param int    $user_id The member it was resolved for.
		 */
		return (string) apply_filters( 'buddynext_user_link_url', $url, $key, $user_id );
	}

	/**
	 * Resolve a custom (developer-registered) token via its catalogue entry.
	 *
	 * @param string $token   Lower-cased token.
	 * @param int    $user_id The member to resolve for.
	 * @return string Resolved URL, or '' when none is provided.
	 */
	private static function custom_url( string $token, int $user_id ): string {
		foreach ( self::catalogue() as $item ) {
			if ( strtolower( (string) ( $item['token'] ?? '' ) ) !== $token ) {
				continue;
			}
			if ( isset( $item['callback'] ) && is_callable( $item['callback'] ) ) {
				return (string) call_user_func( $item['callback'], $user_id );
			}
			if ( ! empty( $item['url'] ) ) {
				return (string) $item['url'];
			}
			return '';
		}
		return '';
	}

	/**
	 * Resolve a built-in token, or null when the token is not a built-in.
	 *
	 * @param string $token   Lower-cased token.
	 * @param int    $user_id The member to resolve for (0 = none).
	 * @return string|null URL ('' when N/A), or null when not a built-in token.
	 */
	private static function core_url( string $token, int $user_id ): ?string {
		switch ( $token ) {
			case '#bn-profile':
				return $user_id > 0 ? PageRouter::profile_url( $user_id ) : '';
			case '#bn-edit-profile':
				return $user_id > 0 ? PageRouter::edit_profile_url( $user_id ) : '';
			case '#bn-connections':
				return $user_id > 0 ? PageRouter::connections_url( $user_id ) : '';
			case '#bn-messages':
				return PageRouter::messages_url();
			case '#bn-notifications':
				return PageRouter::notifications_url();
			case '#bn-bookmarks':
				return PageRouter::bookmarks_url();
			case '#bn-spaces':
				return PageRouter::spaces_url();
			case '#bn-settings':
				return PageRouter::settings_url();
			case '#bn-logout':
				return wp_logout_url( home_url( '/' ) );
			case '#bn-login':
				return self::auth_url_or( 'wp_login_url' );
			case '#bn-register':
				return self::register_url();
			default:
				return null;
		}
	}

	/**
	 * The BuddyNext auth hub URL when configured, else a WordPress fallback.
	 *
	 * BuddyNext's auth hub serves both login and registration, so both tokens
	 * point at it when present; otherwise we fall back to the core URL.
	 *
	 * @param string $fallback Fallback function name: wp_login_url|wp_registration_url.
	 * @return string
	 */
	private static function auth_url_or( string $fallback ): string {
		$auth = PageRouter::auth_url();
		if ( '' !== $auth ) {
			return $auth;
		}
		return (string) call_user_func( $fallback );
	}

	/**
	 * The registration URL, or '' when registration is closed.
	 *
	 * The BuddyNext auth hub owns its own registration policy, so when it is
	 * configured the Register link always points there. Otherwise we only offer
	 * Register when WordPress registration is open — so the item is hidden
	 * everywhere (header guest area + menus) rather than leading to a dead end.
	 *
	 * @return string
	 */
	private static function register_url(): string {
		$auth = PageRouter::auth_url();
		if ( '' !== $auth ) {
			return $auth;
		}
		return get_option( 'users_can_register' ) ? wp_registration_url() : '';
	}
}
