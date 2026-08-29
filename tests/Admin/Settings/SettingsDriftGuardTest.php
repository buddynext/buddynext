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
	/**
	 * Options that legitimately do NOT register through register_settings().
	 *
	 * The guard below exists to catch an option silently dropped from the save
	 * path. An option that MOVED to a different save path is not that, but it is
	 * indistinguishable from it unless the move is written down - which is how
	 * buddynext_brand_color sat as a permanent red for six days after 67e83000
	 * moved the Brand colour field from Settings > General to the Appearance tab.
	 *
	 * Anything listed here must be saved by a real, guarded handler; the test below
	 * proves that rather than taking the list on trust.
	 *
	 * @var array<string,string> option => the hook that saves it.
	 */
	/**
	 * Options the product RETIRED, with the commit that retired each.
	 *
	 * A retired option is not a drop either, but the guard cannot tell the
	 * difference on its own - and unlike SAVED_ELSEWHERE these have no handler to
	 * point at, because there is deliberately nothing left to save. They are
	 * recorded rather than deleted so that "why is this key gone" has an answer in
	 * the file that used to require it.
	 *
	 * All four became switches in the Features catalog (buddynext_features), which
	 * is now the single control for each capability.
	 *
	 * @var array<string,string> retired option => commit that retired it.
	 */
	private const RETIRED = array(
		'buddynext_enable_dm'       => 'f8d04b6a', // -> features catalog 'messages'.
		'buddynext_allow_polls'     => '6c7351f9', // -> features catalog 'polls'.
		'buddynext_allow_shares'    => '6c7351f9', // -> features catalog 'shares'.
		'buddynext_allow_bookmarks' => '0bcdf67f', // -> features catalog 'bookmarks'.
	);

	private const SAVED_ELSEWHERE = array(
		'buddynext_brand_color' => 'admin_post_bn_appearance_save',
	);

	private const LEGACY_KEYS = array(
		// General.
		'buddynext_site_name',
		'buddynext_description',
		'buddynext_public_explore',
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
		// Collect every missing key rather than dying on the first. assertArrayHasKey
		// in a loop reports one name and hides the rest, which is how this guard sat
		// red for days pointing at a single option while several others were also
		// absent - fixing the named one just revealed the next.
		$missing = array();
		foreach ( self::LEGACY_KEYS as $key ) {
			if ( ! array_key_exists( $key, $wp_registered_settings ) ) {
				$missing[] = $key;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Options dropped from the save path: \n  " . implode( "\n  ", $missing )
		);

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

	/**
	 * An option excused from the guard must still have a real way to be saved.
	 *
	 * Without this, SAVED_ELSEWHERE would be a list anyone could add a key to in
	 * order to make a genuine drop go quiet. Requiring a registered handler on the
	 * named hook makes the excuse cost as much as the fix.
	 *
	 * @return void
	 */
	public function test_options_saved_elsewhere_have_a_real_handler(): void {
		// The tab registers its own admin_post hook; nothing in the test bootstrap
		// runs the admin boot, so register it here rather than asserting against a
		// hook table the harness never populated.
		( new \BuddyNext\Admin\AppearanceTab() )->register();

		foreach ( self::SAVED_ELSEWHERE as $option => $hook ) {
			$this->assertTrue(
				has_action( $hook ),
				"{$option} is excused from register_settings() but nothing is listening on {$hook}."
			);
		}
	}

	/**
	 * A retired option really is gone, not merely unregistered.
	 *
	 * The risk with RETIRED is the same as with SAVED_ELSEWHERE: it could become a
	 * place to park a key that was dropped by accident. A retired option must have
	 * no descriptor and no legacy-map entry anywhere in Settings - if one is still
	 * declared, it was not retired, it was dropped.
	 *
	 * @return void
	 */
	public function test_retired_options_are_declared_nowhere(): void {
		$declared = $this->descriptor_keys( new Settings() );

		foreach ( array_keys( self::RETIRED ) as $option ) {
			$this->assertNotContains(
				$option,
				$declared,
				"{$option} is listed as retired but Settings still declares it."
			);
		}
	}
}
