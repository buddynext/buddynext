<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Registers the built-in BuddyNext hubs into the HubRegistry.
 *
 * Core hubs keep their existing rewrite + template logic in PageRouter
 * (register_rules / resolve_template stay null here); the registry unifies the
 * hub LIST (slug-flush, default slug, backing pages, nav) so addons register
 * the same way. Fires buddynext_register_hubs for addon registration.
 *
 * @package BuddyNext\Core
 * @since 1.0.4
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Registers the built-in BuddyNext hubs into the HubRegistry.
 */
final class CoreHubs {
	/**
	 * Registers all 7 core hub descriptors and fires buddynext_register_hubs.
	 *
	 * @param HubRegistry $reg The hub registry to populate.
	 * @return void
	 */
	public static function register( HubRegistry $reg ): void {
		$reg->register( new HubDescriptor( 'feed', 'buddynext_slug_activity', 'activity', 'buddynext_page_activity', __( 'Activity', 'buddynext' ), '[buddynext_activity]' ) );
		$reg->register( new HubDescriptor( 'people', 'buddynext_slug_people', 'members', 'buddynext_page_people', __( 'Members', 'buddynext' ), '[buddynext_people]' ) );
		$reg->register( new HubDescriptor( 'spaces', 'buddynext_slug_spaces', 'spaces', 'buddynext_page_spaces', __( 'Spaces', 'buddynext' ), '[buddynext_spaces]' ) );
		$reg->register( new HubDescriptor( 'messages', 'buddynext_slug_messages', 'messages', 'buddynext_page_messages', __( 'Messages', 'buddynext' ), '[buddynext_messages]' ) );
		$reg->register( new HubDescriptor( 'notifications', 'buddynext_slug_notifications', 'notifications', 'buddynext_page_notifications', __( 'Notifications', 'buddynext' ), '[buddynext_notifications]' ) );
		$reg->register( new HubDescriptor( 'auth', 'buddynext_slug_auth', 'login', 'buddynext_page_auth', __( 'Login', 'buddynext' ), '[buddynext_auth]' ) );
		$reg->register( new HubDescriptor( 'onboarding', 'buddynext_slug_onboarding', 'onboarding', 'buddynext_page_onboarding', __( 'Onboarding', 'buddynext' ), '[buddynext_onboarding]', backing_page: false ) );

		/**
		 * Fires after core hubs are registered.
		 *
		 * Addons use this hook to register HubDescriptors with their own
		 * register_rules and resolve_template callbacks.
		 *
		 * @since 1.0.4
		 * @param HubRegistry $reg The shared hub registry.
		 */
		do_action( 'buddynext_register_hubs', $reg );
	}
}
