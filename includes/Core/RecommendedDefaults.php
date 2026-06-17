<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Recommended first-run defaults.
 *
 * One source of truth for the option values that give a brand-new community the
 * full BuddyNext experience on day one — discovery, messaging, the engagement
 * surfaces (polls, reactions, shares, bookmarks, link previews, emoji), default
 * notifications, and baseline spam protection.
 *
 * Two consumers:
 *  - Installer::run() seeds these on a FRESH install (add_option — never
 *    overwrites an existing value, so upgrades and owner edits are untouched).
 *  - The "Recommended for new communities" apply button re-applies the bundle
 *    on demand (update_option — an explicit owner choice, so it overwrites).
 *
 * Registration mode is intentionally absent: per owner decision it defers to
 * WordPress's "Anyone can register" setting via buddynext_default_reg_mode().
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Canonical recommended-default option values + their seed/apply helpers.
 */
class RecommendedDefaults {

	/**
	 * Option name => recommended value. Booleans turn a feature on; strings set
	 * a sensible community-first default.
	 *
	 * @var array<string, bool|string>
	 */
	private const MAP = array(
		// Discovery + reach.
		'buddynext_public_explore'             => true,
		'buddynext_enable_community_nav'       => true,
		// Direct messaging.
		'buddynext_enable_dm'                  => true,
		'buddynext_default_dm_access'          => 'members',
		// Activity feed engagement surfaces.
		'buddynext_default_post_privacy'       => 'public',
		'buddynext_allow_polls'                => true,
		'buddynext_allow_shares'               => true,
		'buddynext_allow_bookmarks'            => true,
		'buddynext_enable_link_preview'        => true,
		'buddynext_enable_emoji_picker'        => true,
		// Integrations — surface Jetonomy discussions in the feed out of the box.
		'buddynext_jetonomy_feed_sync'       => '1',
		// Notifications on by default for every new member.
		'buddynext_notif_default_follow'       => true,
		'buddynext_notif_default_connection'   => true,
		'buddynext_notif_default_reaction'     => true,
		'buddynext_notif_default_comment'      => true,
		'buddynext_notif_default_mention'      => true,
		'buddynext_notif_default_space_join'   => true,
		// Safety baseline so an open community is not defenceless on day one.
		'buddynext_reg_spam_protection'        => true,
		'buddynext_reg_challenge'              => true,
		// Moderation thresholds — present and usable, but lenient. The goal is a
		// welcoming community, not one that auto-kicks newcomers: members are
		// only warned/suspended after repeated, sustained violations. Zero here
		// would read as an unconfigured (blank) screen, so we ship real numbers.
		'buddynext_auto_hide_threshold'        => 5,
		'buddynext_mod_queue_alert_threshold'  => 20,
		'buddynext_strike_warn_threshold'      => 2,
		'buddynext_strike_suspend_threshold'   => 4,
		'buddynext_strike_perma_ban_threshold' => 6,
	);

	/**
	 * The recommended map, filterable so Pro / custom code can extend the bundle.
	 *
	 * @return array<string, bool|string>
	 */
	public static function map(): array {
		/**
		 * Filter the recommended first-run default option values.
		 *
		 * @param array<string, bool|string> $map Option name => recommended value.
		 */
		$map = apply_filters( 'buddynext_recommended_defaults', self::MAP );
		return is_array( $map ) ? $map : self::MAP;
	}

	/**
	 * Seed defaults on a fresh install. add_option() is a no-op when the option
	 * already exists, so this never overwrites an upgraded site or owner edits.
	 *
	 * @return void
	 */
	public static function seed(): void {
		foreach ( self::map() as $option => $value ) {
			add_option( $option, $value );
		}
	}

	/**
	 * Apply the recommended bundle on demand (owner clicked "Apply"). Overwrites
	 * current values with the recommended ones.
	 *
	 * @return void
	 */
	public static function apply(): void {
		foreach ( self::map() as $option => $value ) {
			update_option( $option, $value );
		}
	}
}
