<?php
/**
 * Core space fields — registers BuddyNext's own built-in per-space settings
 * through the same SpaceFieldRegistry a third party uses (no two-tier system).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the eight built-in space options as core space fields.
 *
 * These were historically per-space wp_options (bn_space_{id}_*), which
 * autoloaded and bypassed any developer API. They are now first-class space
 * fields: stored in bn_space_meta, rendered + saved through the field engine,
 * and exposed over REST — identical to a developer-registered field. The values
 * (members|mods|owner, all|mentions_only|none, booleans) match the legacy
 * options exactly so existing data carries over unchanged.
 */
final class CoreSpaceFields {

	/**
	 * Hook the registry's collection action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'buddynext_register_space_fields', array( $this, 'register_fields' ) );
	}

	/**
	 * Register the eight built-in fields.
	 *
	 * @param SpaceFieldRegistry $registry The field registry.
	 * @return void
	 */
	public function register_fields( SpaceFieldRegistry $registry ): void {
		// ── Permissions ──────────────────────────────────────────────────────
		$registry->register(
			'require_join_approval',
			array(
				'label'      => __( 'Require approval to join', 'buddynext' ),
				'type'       => 'boolean',
				'default'    => '0',
				'section'    => 'permissions',
				'sort_order' => 10,
				'visibility' => 'members',
				'core'       => true,
			)
		);
		$registry->register(
			'who_can_post',
			array(
				'label'      => __( 'Who can post', 'buddynext' ),
				'type'       => 'select',
				'default'    => 'members',
				'options'    => array(
					'members' => __( 'All members', 'buddynext' ),
					'mods'    => __( 'Moderators and owner', 'buddynext' ),
					'owner'   => __( 'Owner only', 'buddynext' ),
				),
				'section'    => 'permissions',
				'sort_order' => 20,
				'visibility' => 'members',
				'core'       => true,
			)
		);
		$registry->register(
			'who_can_invite',
			array(
				'label'      => __( 'Who can invite new members', 'buddynext' ),
				'type'       => 'select',
				'default'    => 'mods',
				'options'    => array(
					'members' => __( 'All members', 'buddynext' ),
					'mods'    => __( 'Moderators and owner', 'buddynext' ),
					'owner'   => __( 'Owner only', 'buddynext' ),
				),
				'section'    => 'permissions',
				'sort_order' => 30,
				'visibility' => 'members',
				'core'       => true,
			)
		);

		// ── Moderation ───────────────────────────────────────────────────────
		$registry->register(
			'banned_words',
			array(
				'label'       => __( 'Banned words', 'buddynext' ),
				'description' => __( 'One word or phrase per line. Posts containing these are held for review.', 'buddynext' ),
				'type'        => 'textarea',
				'default'     => '',
				'section'     => 'moderation',
				'sort_order'  => 10,
				'visibility'  => 'members',
				'core'        => true,
			)
		);

		// ── Notifications ────────────────────────────────────────────────────
		$registry->register(
			'default_notification_pref',
			array(
				'label'      => __( 'Default notifications for new members', 'buddynext' ),
				'type'       => 'select',
				'default'    => 'all',
				'options'    => array(
					'all'           => __( 'All activity', 'buddynext' ),
					'mentions_only' => __( 'Mentions only', 'buddynext' ),
					'none'          => __( 'None', 'buddynext' ),
				),
				'section'    => 'notifications',
				'sort_order' => 10,
				'visibility' => 'members',
				'core'       => true,
			)
		);

		// ── Integrations ─────────────────────────────────────────────────────
		$registry->register(
			'push_to_feed',
			array(
				'label'      => __( 'Push space posts to the activity feed', 'buddynext' ),
				'type'       => 'boolean',
				'default'    => '1',
				'section'    => 'integrations',
				'sort_order' => 10,
				'visibility' => 'members',
				'core'       => true,
			)
		);
		$registry->register(
			'mvs_media_tab',
			array(
				'label'      => __( 'Show the Media tab in this space', 'buddynext' ),
				'type'       => 'boolean',
				'default'    => '0',
				'section'    => 'integrations',
				'sort_order' => 20,
				'visibility' => 'members',
				'core'       => true,
			)
		);
		$registry->register(
			'jetonomy_forum_id',
			array(
				'label'      => __( 'Linked forum ID', 'buddynext' ),
				'type'       => 'number',
				'default'    => '0',
				'section'    => 'integrations',
				'sort_order' => 30,
				'visibility' => 'members',
				'core'       => true,
			)
		);
	}
}
