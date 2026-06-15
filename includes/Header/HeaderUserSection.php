<?php
/**
 * Header user section — a reusable, zero-JS logged-in user area for ANY theme.
 *
 * Renders the notification bell, a messages icon, and the member's avatar with a
 * profile dropdown (CSS-only) + log out. BuddyNext owns this render so it can be
 * dropped into any theme's header — as the `buddynext/header-user-menu` block /
 * block-based widget, the `[buddynext_user_menu]` shortcode, the
 * `buddynext_header_user_menu()` function, or a thin per-theme auto-place shim
 * (Reign, BuddyX, BuddyX-Pro). One source of truth, controlled centrally from BN.
 *
 * No JavaScript: the unread count is server-rendered and the dropdown opens via
 * CSS `:focus-within`.
 *
 * @package BuddyNext\Header
 */

declare( strict_types=1 );

namespace BuddyNext\Header;

use BuddyNext\Core\PageRouter;
use BuddyNext\Messages\MessagesData;
use BuddyNext\Nav\UserLinks;

/**
 * Renders BuddyNext's header user section.
 */
final class HeaderUserSection {

	/**
	 * Render the full section.
	 *
	 * Logged in → notification bell + messages icon + avatar dropdown. Logged out
	 * → Log In / Register, so the section is useful to EVERY visitor in any theme
	 * (the per-piece helpers below stay logged-in-only for themes that render
	 * their own guest links). Returns '' only when there is genuinely nothing to
	 * show (e.g. logged out with registration closed and no login surface).
	 *
	 * @return string Safe HTML.
	 */
	public static function render(): string {
		if ( ! is_user_logged_in() ) {
			return self::guest_section();
		}

		return '<div class="bn-header-user-section">'
			. self::notification_bell()
			. self::messages_link()
			. self::user_menu()
			. '</div>';
	}

	/**
	 * Logged-out section: Log In + Register (Register only when registration is
	 * open). Drives the block / shortcode / widget for guests so a theme that
	 * places the section once serves both members and visitors.
	 *
	 * @return string Safe HTML ('' for logged-in users or when no auth link
	 *                resolves).
	 */
	public static function guest_section(): string {
		if ( is_user_logged_in() ) {
			return '';
		}

		$links = '';
		foreach ( UserLinks::items( UserLinks::LOGGEDOUT ) as $item ) {
			$url = UserLinks::resolve( $item['token'], 0 );
			if ( '' === $url ) {
				continue;
			}
			$primary = '#bn-register' === $item['token'] ? ' bn-header-auth--primary' : '';
			$links  .= '<a class="bn-header-auth' . $primary . '" href="' . esc_url( $url ) . '">'
				. esc_html( $item['label'] ) . '</a>';
		}

		return '' === $links ? '' : '<div class="bn-header-user-section bn-header-user-section--guest">' . $links . '</div>';
	}

	/**
	 * Notification bell (icon + server-rendered unread badge) → /notifications/.
	 *
	 * @return string
	 */
	public static function notification_bell(): string {
		if ( ! is_user_logged_in() || ! function_exists( 'buddynext_get_template' ) ) {
			return '';
		}
		ob_start();
		buddynext_get_template( 'blocks/notification-bell.php', array() );
		return (string) ob_get_clean();
	}

	/**
	 * Messages icon → /messages/ (shown only when the messages feature is available).
	 *
	 * No unread badge yet — direct-message unread lives in WPMediaVerse and has no
	 * cheap per-request count; add one here when it exists.
	 *
	 * @return string
	 */
	public static function messages_link(): string {
		if ( ! is_user_logged_in() || ! MessagesData::dm_enabled() || ! MessagesData::available() ) {
			return '';
		}
		$url = PageRouter::messages_url();
		if ( '' === $url ) {
			return '';
		}
		return '<a class="bn-header-msg" href="' . esc_url( $url ) . '" aria-label="' . esc_attr__( 'Messages', 'buddynext' ) . '">'
			. '<span class="bn-header-msg__icon" aria-hidden="true">' . self::icon( 'messages-square' ) . '</span>'
			. '</a>';
	}

	/**
	 * Avatar (links to profile) + a CSS-only profile dropdown with quick links + log out.
	 *
	 * @return string
	 */
	public static function user_menu(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		$profile_url = PageRouter::profile_url( $user_id );
		$avatar      = get_avatar( $user_id, 64, '', $user->display_name, array( 'class' => 'bn-header-user__img' ) );

		$html  = '<div class="bn-header-user">';
		$html .= '<a class="bn-header-user__avatar" href="' . esc_url( $profile_url ) . '" aria-label="' . esc_attr( $user->display_name ) . '">' . $avatar . '</a>';

		// CSS-only disclosure: the caret button is focusable; :focus-within reveals
		// the dropdown and an outside click (blur) closes it. No JS.
		$html .= '<div class="bn-header-user__menu">';
		$html .= '<button type="button" class="bn-header-user__caret" aria-haspopup="true" aria-label="' . esc_attr__( 'Open profile menu', 'buddynext' ) . '">'
			. self::icon( 'chevron-down' ) . '</button>';
		$html .= '<div class="bn-header-user__dropdown" role="menu">';
		$html .= '<div class="bn-header-user__head"><a class="bn-header-user__name" href="' . esc_url( $profile_url ) . '" role="menuitem">' . esc_html( $user->display_name ) . '</a></div>';

		foreach ( self::links( $user_id ) as $link ) {
			$html .= '<a class="bn-header-user__item" role="menuitem" href="' . esc_url( $link['url'] ) . '">'
				. '<span class="bn-header-user__item-icon" aria-hidden="true">' . self::icon( $link['icon'] ) . '</span>'
				. '<span>' . esc_html( $link['label'] ) . '</span></a>';
		}

		$html .= '<a class="bn-header-user__item is-logout" role="menuitem" href="' . esc_url( wp_logout_url() ) . '">'
			. '<span class="bn-header-user__item-icon" aria-hidden="true">' . self::icon( 'log-out' ) . '</span>'
			. '<span>' . esc_html__( 'Log Out', 'buddynext' ) . '</span></a>';

		$html .= '</div></div></div>';

		return $html;
	}

	/**
	 * The dropdown quick links — a user-specific account menu for the member
	 * who is logged in (their own profile, settings, and activity).
	 *
	 * Every URL points at the CURRENT member: "My Profile" → their profile,
	 * "Edit Profile" → their edit screen, "Settings" → their settings, etc. The
	 * list is filterable so a theme or integration can present a site-controlled
	 * menu instead — and any URL it returns is still resolved per-user via the
	 * `#bn-*` tokens (see resolve_user_url()), so an admin's custom menu stays
	 * specific to whoever is logged in. Log Out is appended separately by
	 * user_menu() and is never part of this list.
	 *
	 * @param int $user_id Member.
	 * @return array<int,array{label:string,url:string,icon:string}>
	 */
	private static function links( int $user_id ): array {
		// Build the curated defaults from the shared catalogue (logged-in items,
		// minus Log Out which user_menu() appends separately), resolved to the
		// current member. One source of truth shared with the WP nav-menu items.
		$candidates = array();
		foreach ( UserLinks::items( UserLinks::LOGGEDIN ) as $item ) {
			if ( '#bn-logout' === $item['token'] ) {
				continue;
			}
			$url = UserLinks::resolve( $item['token'], $user_id );
			if ( '' === $url ) {
				continue;
			}
			$candidates[] = array(
				'label' => $item['label'],
				'url'   => $url,
				'icon'  => $item['icon'],
			);
		}

		$links = $candidates;

		/**
		 * Filters the BuddyNext header dropdown quick links.
		 *
		 * Return a list of `[ 'label' => string, 'url' => string, 'icon' => string ]`
		 * rows to replace or extend the defaults. `icon` is an optional BuddyNext
		 * icon slug (omit/empty for no icon). Log Out is always appended after.
		 *
		 * @param array<int,array{label:string,url:string,icon:string}> $links   Quick links.
		 * @param int                                                    $user_id Member ID.
		 */
		$links = (array) apply_filters( 'buddynext_header_user_menu_links', $links, $user_id );

		// Normalize so a misbehaving filter can never break the markup, and
		// resolve any `#bn-*` user token to the current member's URL so every
		// row is specific to whoever is logged in (even admin-built menus).
		$clean = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) || empty( $link['url'] ) || ! isset( $link['label'] ) || '' === (string) $link['label'] ) {
				continue;
			}
			$url = self::resolve_user_url( (string) $link['url'], $user_id );
			if ( '' === $url ) {
				continue;
			}
			$clean[] = array(
				'label' => (string) $link['label'],
				'url'   => $url,
				'icon'  => isset( $link['icon'] ) ? (string) $link['icon'] : '',
			);
		}

		return $clean;
	}

	/**
	 * Resolve a `#bn-*` user token to the current member's URL.
	 *
	 * Lets a theme's menu (e.g. a Reign "User Profile" custom link) target the
	 * logged-in member: a Custom Link whose URL is a BuddyNext token becomes that
	 * member's own page, so the same menu is correct for every user. Non-token
	 * URLs pass through unchanged. The dropdown only renders for logged-in
	 * members, so logged-out tokens (Log In / Register) are dropped here.
	 *
	 * @param string $url     The raw link URL (possibly a token).
	 * @param int    $user_id The current member.
	 * @return string Resolved URL ('' when the token resolves to nothing or does
	 *                not belong in a logged-in menu).
	 */
	private static function resolve_user_url( string $url, int $user_id ): string {
		if ( ! UserLinks::is_token( $url ) ) {
			return $url;
		}
		if ( UserLinks::LOGGEDOUT === UserLinks::visibility( $url ) ) {
			return '';
		}
		return UserLinks::resolve( $url, $user_id );
	}

	/**
	 * Inline icon SVG, or '' when the helper/slug is unavailable.
	 *
	 * @param string $slug Icon slug.
	 * @return string
	 */
	private static function icon( string $slug ): string {
		return function_exists( 'buddynext_get_icon' ) ? (string) buddynext_get_icon( $slug ) : '';
	}
}
