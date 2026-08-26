<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Registers the built-in BuddyNext hubs into the HubRegistry.
 *
 * Core hubs ride the same seam an addon does: each descriptor carries its own
 * register_rules + resolve_template callback (PageRouter::register_feed_rules,
 * resolve_feed_template, …), so PageRouter has no hardcoded per-hub call list or
 * template switch to drift. The registry is the single source for the hub list,
 * slug-flush, default slug, backing pages and nav. Fires buddynext_register_hubs
 * for addon registration.
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
	 * Registers all 8 core hub descriptors and fires buddynext_register_hubs.
	 *
	 * @param HubRegistry $reg The hub registry to populate.
	 * @return void
	 */
	public static function register( HubRegistry $reg ): void {
		$reg->register( new HubDescriptor( 'feed', 'buddynext_slug_activity', 'activity', 'buddynext_page_activity', __( 'Activity', 'buddynext' ), '[buddynext_activity]', admin_label: __( 'Activity feed', 'buddynext' ), admin_desc: __( 'The main community feed — your community home.', 'buddynext' ), register_rules: array( PageRouter::class, 'register_feed_rules' ), resolve_template: array( PageRouter::class, 'resolve_feed_template' ) ) );
		$reg->register( new HubDescriptor( 'people', 'buddynext_slug_people', 'members', 'buddynext_page_people', __( 'Members', 'buddynext' ), '[buddynext_people]', admin_label: __( 'Members directory', 'buddynext' ), admin_desc: __( 'Member directory and individual profile URLs.', 'buddynext' ), register_rules: array( PageRouter::class, 'register_people_rules' ), resolve_template: array( PageRouter::class, 'resolve_people_template' ) ) );
		$reg->register( new HubDescriptor( 'spaces', 'buddynext_slug_spaces', 'spaces', 'buddynext_page_spaces', __( 'Spaces', 'buddynext' ), '[buddynext_spaces]', admin_label: __( 'Spaces', 'buddynext' ), admin_desc: __( 'Group/community spaces directory.', 'buddynext' ), register_rules: array( PageRouter::class, 'register_spaces_rules' ), resolve_template: array( PageRouter::class, 'resolve_spaces_template' ), feature: 'spaces' ) );
		$reg->register( new HubDescriptor( 'messages', 'buddynext_slug_messages', 'messages', 'buddynext_page_messages', __( 'Messages', 'buddynext' ), '[buddynext_messages]', admin_label: __( 'Messages', 'buddynext' ), admin_desc: __( 'Direct messages (requires WPMediaVerse).', 'buddynext' ), register_rules: array( PageRouter::class, 'register_messages_rules' ), resolve_template: array( PageRouter::class, 'resolve_messages_template' ) ) );
		$reg->register( new HubDescriptor( 'notifications', 'buddynext_slug_notifications', 'notifications', 'buddynext_page_notifications', __( 'Notifications', 'buddynext' ), '[buddynext_notifications]', admin_label: __( 'Notifications', 'buddynext' ), admin_desc: __( 'Activity notifications.', 'buddynext' ), register_rules: array( PageRouter::class, 'register_notifications_rules' ), resolve_template: array( PageRouter::class, 'resolve_notifications_template' ) ) );
		$reg->register( new HubDescriptor( 'auth', 'buddynext_slug_auth', 'login', 'buddynext_page_auth', __( 'Login', 'buddynext' ), '[buddynext_auth]', admin_label: __( 'Login / Register', 'buddynext' ), admin_desc: __( 'Login, registration, and password-reset forms.', 'buddynext' ), register_rules: array( PageRouter::class, 'register_auth_rules' ), resolve_template: array( PageRouter::class, 'resolve_auth_template' ) ) );
		$reg->register( new HubDescriptor( 'onboarding', 'buddynext_slug_onboarding', 'onboarding', 'buddynext_page_onboarding', __( 'Onboarding', 'buddynext' ), '[buddynext_onboarding]', backing_page: false, admin_label: __( 'Onboarding', 'buddynext' ), admin_desc: __( 'First-run member setup flow.', 'buddynext' ), register_rules: array( PageRouter::class, 'register_onboarding_rules' ), resolve_template: array( PageRouter::class, 'resolve_onboarding_template' ), feature: 'onboarding', admin_managed: false ) );

		// Community Admin — a core hub wired through the addon seam (its own
		// register_rules + resolve_template in CommunityAdminRoutes) so PageRouter
		// needs no new hub case. No backing WP page (an internal admin tool should
		// not clutter the owner's Pages list), matching onboarding.
		$reg->register(
			new HubDescriptor(
				'community_admin',
				'buddynext_slug_community_admin',
				'community-admin',
				'buddynext_page_community_admin',
				__( 'Community Admin', 'buddynext' ),
				'[buddynext_community_admin]',
				register_rules: array( CommunityAdminRoutes::class, 'register_rules' ),
				resolve_template: array( CommunityAdminRoutes::class, 'resolve_template' ),
				backing_page: false,
				admin_managed: false
			)
		);

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
