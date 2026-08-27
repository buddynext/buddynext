<?php
/**
 * Drift guard: every historically-registered option must stay declared.
 *
 * The migration moves options out of SETTINGS_MAP/TAB_OPTIONS and into the
 * descriptor registry. This test pins the full set of plain option keys so a
 * conversion can never silently drop one — the exact "what's getting missed"
 * failure the registry exists to prevent.
 *
 * @package BuddyNext\Tests\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\Settings;

/**
 * Asserts descriptor coverage of every plain option key.
 *
 * @covers \BuddyNext\Admin\Settings
 */
class SettingsDriftGuardTest extends \WP_UnitTestCase {

	/**
	 * Every plain (non-array) option that must be declared via settings_fields().
	 *
	 * Excludes the three array options registered bespoke (buddynext_features,
	 * buddynext_social_login, buddynext_enabled_reactions), which carry custom
	 * UI and their own register_setting() calls.
	 *
	 * @var string[]
	 */
	private const LEGACY_KEYS = array(
		// General.
		'buddynext_site_name',
		'buddynext_brand_color',
		'buddynext_description',
		'buddynext_public_explore',
		'buddynext_enable_dm',
		'buddynext_default_dm_access',
		'buddynext_enable_community_nav',
		'buddynext_enable_community_rail',
		'buddynext_enable_community_mobile_nav',
		'buddynext_member_dir_columns',
		'buddynext_spaces_dir_columns',
		// Registration.
		'buddynext_reg_mode',
		'buddynext_email_verify',
		'buddynext_reg_spam_protection',
		'buddynext_reg_challenge',
		'buddynext_reg_rate_limit',
		'buddynext_login_redirect',
		'buddynext_logout_redirect',
		'buddynext_onboarding_redirect',
		'buddynext_auth_panel_show',
		'buddynext_auth_panel_heading',
		'buddynext_auth_panel_tagline',
		'buddynext_auth_panel_quote',
		'buddynext_auth_panel_image',
		'buddynext_terms_page_id',
		'buddynext_allowed_domains',
		// Social.
		'buddynext_default_post_privacy',
		'buddynext_allow_polls',
		'buddynext_allow_shares',
		'buddynext_allow_bookmarks',
		'buddynext_enable_link_preview',
		'buddynext_enable_emoji_picker',
		'buddynext_feed_new_posts_indicator',
		'buddynext_post_edit_window',
		'buddynext_connection_require_note',
		// Spaces.
		'buddynext_space_creation_role',
		'buddynext_space_max_sub_spaces',
		'buddynext_space_max_per_member',
		'buddynext_space_allow_sub',
		'buddynext_space_default_type',
		'buddynext_space_default_category',
		// Moderation.
		'buddynext_auto_hide_threshold',
		'buddynext_strike_warn_threshold',
		'buddynext_strike_suspend_threshold',
		'buddynext_strike_perma_ban_threshold',
		'buddynext_mod_queue_alert_threshold',
		'buddynext_banned_words',
		'buddynext_blocked_domains',
		'buddynext_blocked_ips',
		'buddynext_banned_hashtags',
		'buddynext_post_rate_limit',
		'buddynext_comment_rate_limit',
		'buddynext_new_member_post_threshold',
		'buddynext_duplicate_post_window',
		// buddynext_premod_mode + buddynext_premod_new_member_count are RETIRED,
		// not dropped by accident — which is the only thing this guard exists to
		// catch. Pre-moderation lost its owner-facing setting in 1.1.6 and is
		// developer-only now, driven by the filters of the same names. Leaving the
		// keys listed here would demand a save path for a setting that no longer
		// has a UI. See PreModerationService for why the feature went this way.
		// Notifications.
		'buddynext_notif_default_follow',
		'buddynext_notif_default_connection',
		'buddynext_notif_default_reaction',
		'buddynext_notif_default_comment',
		'buddynext_notif_default_mention',
		'buddynext_notif_default_space_join',
		'buddynext_digest_frequency',
		'buddynext_admin_alert_email',
		// Email.
		'buddynext_email_from_name',
		'buddynext_email_from_address',
		'buddynext_email_reply_to',
		'buddynext_email_footer_text',
		// Privacy.
		'buddynext_google_indexing',
		'buddynext_cookie_consent',
		'buddynext_data_retention_days',
		'buddynext_allow_data_export',
		'buddynext_allow_account_deletion',
		// Webhooks.
		'buddynext_webhook_secret',
	);

	/**
	 * Descriptor-declared keys (via settings_fields()).
	 *
	 * @param Settings $settings Page under test.
	 * @return string[]
	 */
	private function descriptor_keys( Settings $settings ): array {
		$keys = array();
		foreach ( $settings->settings_fields() as $section ) {
			foreach ( $section->fields as $field ) {
				if ( in_array( $field->type, array( 'custom', 'readonly' ), true ) ) {
					continue;
				}
				$keys[] = $field->key;
			}
		}
		return $keys;
	}

	/**
	 * Every legacy option is registered exactly once after register_settings() —
	 * whether via the descriptor registry or the remaining SETTINGS_MAP. This is
	 * the migration's no-breakage guarantee: no option is dropped from the save
	 * path, and none is double-registered (which would split its save group).
	 *
	 * @return void
	 */
	public function test_every_legacy_key_registered_exactly_once(): void {
		$settings = new Settings();
		$settings->register_settings();

		global $wp_registered_settings;
		foreach ( self::LEGACY_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $wp_registered_settings, "Option dropped from save path: {$key}" );
		}

		// No key may live in BOTH the descriptor registry and any legacy map - that
		// would register it under two groups and break its tab's save. SETTINGS_MAP
		// is fully retired; this guards against a future re-introduction.
		$map             = ( new \ReflectionClass( Settings::class ) )->getConstants()['SETTINGS_MAP'] ?? array();
		$map_keys        = array_keys( $map );
		$descriptor_keys = $this->descriptor_keys( $settings );
		$this->assertSame(
			array(),
			array_values( array_intersect( $map_keys, $descriptor_keys ) ),
			'Option double-registered (in both a legacy map and descriptors)'
		);
	}
}
