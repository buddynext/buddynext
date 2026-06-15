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

/**
 * Renders BuddyNext's header user section.
 */
final class HeaderUserSection {

	/**
	 * Render the full section (bell + messages + avatar dropdown).
	 *
	 * @return string Safe HTML (empty for logged-out visitors).
	 */
	public static function render(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		return '<div class="bn-header-user-section">'
			. self::notification_bell()
			. self::messages_link()
			. self::user_menu()
			. '</div>';
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
		if ( ! is_user_logged_in() || ! MessagesData::available() ) {
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
		$html .= '<div class="bn-header-user__head"><span class="bn-header-user__name">' . esc_html( $user->display_name ) . '</span></div>';

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
	 * The dropdown quick links (full set), each only when its URL resolves.
	 *
	 * The list is filterable so a theme or integration can present a real,
	 * site-controlled menu instead of (or alongside) these defaults — e.g. the
	 * Reign compatibility layer feeds in the site's assigned "User Profile" nav
	 * menu. Log Out is appended separately by user_menu() and is never part of
	 * this list, so a filter can never accidentally drop it.
	 *
	 * @param int $user_id Member.
	 * @return array<int,array{label:string,url:string,icon:string}>
	 */
	private static function links( int $user_id ): array {
		$candidates = array(
			array(
				'label' => __( 'View Profile', 'buddynext' ),
				'url'   => PageRouter::profile_url( $user_id ),
				'icon'  => 'user',
			),
			array(
				'label' => __( 'Edit Profile', 'buddynext' ),
				'url'   => PageRouter::edit_profile_url( $user_id ),
				'icon'  => 'settings',
			),
			array(
				'label' => __( 'Messages', 'buddynext' ),
				'url'   => PageRouter::messages_url(),
				'icon'  => 'messages-square',
			),
			array(
				'label' => __( 'Notifications', 'buddynext' ),
				'url'   => PageRouter::notifications_url(),
				'icon'  => 'bell',
			),
			array(
				'label' => __( 'Bookmarks', 'buddynext' ),
				'url'   => PageRouter::bookmarks_url(),
				'icon'  => 'bookmark',
			),
			array(
				'label' => __( 'Spaces', 'buddynext' ),
				'url'   => PageRouter::spaces_url(),
				'icon'  => 'grid',
			),
		);

		$links = array_values( array_filter( $candidates, static fn( array $l ): bool => '' !== (string) $l['url'] ) );

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

		// Normalize so a misbehaving filter can never break the markup: keep only
		// well-formed rows that have a label and a non-empty URL.
		$clean = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) || empty( $link['url'] ) || ! isset( $link['label'] ) || '' === (string) $link['label'] ) {
				continue;
			}
			$clean[] = array(
				'label' => (string) $link['label'],
				'url'   => (string) $link['url'],
				'icon'  => isset( $link['icon'] ) ? (string) $link['icon'] : '',
			);
		}

		return $clean;
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
