<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Wires WordPress action hooks into the notification routing layer.
 *
 * Each hook handler delegates to NotificationService::create() so that
 * cross-plugin events (follows, space joins, etc.) produce the correct
 * in-app notification rows without any coupling back to the caller.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

/**
 * Registers action hooks and routes them to NotificationService.
 *
 * All bridge-specific notification hooks have been extracted to dedicated
 * listener classes:
 *
 * - Jetonomy events  → JetonomyBridgeListener
 * - Gamification events → GamificationBridgeListener
 *
 * Both are registered inside the buddynext_load_bridges block in Plugin::init()
 * so they fire at plugins_loaded:25, after all bridge plugins have booted.
 */
class EventListener {
}
