<?php
/**
 * Block template: Header User Menu.
 *
 * The full BuddyNext logged-in header section — notification bell, messages
 * icon, and avatar with a CSS-only profile dropdown + log out. Renders nothing
 * for logged-out visitors. Reused by the `buddynext/header-user-menu` block,
 * the `[buddynext_user_menu]` shortcode, and the per-theme auto-place shim.
 *
 * No block variables — always renders for the current user.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

// HeaderUserSection::render() returns escaped markup (empty when logged out).
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from escaped pieces inside HeaderUserSection.
echo \BuddyNext\Header\HeaderUserSection::render();
