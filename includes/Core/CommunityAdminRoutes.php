<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Routing for the Community Admin hub.
 *
 * Community Admin is a first-class BuddyNext hub, but — unlike the 7 core hubs
 * whose rewrite + template logic still lives inline in PageRouter — it is wired
 * through the addon seam HubDescriptor exposes (register_rules / resolve_template),
 * so PageRouter needs no new hub case. CoreHubs registers the descriptor pointing
 * at the two static methods here.
 *
 * The hub renders /{slug}/ (overview) and /{slug}/{section}/ for the fixed set of
 * management sections. The active section arrives as the bn_ca_section query var
 * (see templates/community-admin.php), which the panel reads before falling back
 * to the legacy ?bn_admin= param the shortcode embed still uses.
 *
 * @package BuddyNext\Core
 * @since 1.1.5
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Registers the Community Admin hub's rewrite rules and template resolution.
 */
final class CommunityAdminRoutes {

	/**
	 * Register the hub's rewrite rules.
	 *
	 * Invoked with no arguments from PageRouter::register_rewrites() for every
	 * registered HubDescriptor whose register_rules is callable. The slug is
	 * resolved here from the option (PageRouter::hub_slug() is private, so this
	 * mirrors its option + trim + fallback exactly).
	 *
	 * One generic sub-route captures any /{slug}/{section}/ segment into
	 * bn_ca_section rather than enumerating sections here — the panel template
	 * owns the canonical section list (its sidebar), sanitises the value and
	 * falls back to Overview for anything it does not recognise, so the router
	 * never has to be kept in sync with it.
	 *
	 * @return void
	 */
	public static function register_rules(): void {
		// %bn_ca_section% auto-registers as a public query var (same mechanism the
		// settings/space section tags use), so no query_vars filter entry is needed.
		add_rewrite_tag( '%bn_ca_section%', '([a-z-]+)' );

		$slug = trim( (string) get_option( 'buddynext_slug_community_admin', 'community-admin' ) );
		if ( '' === $slug ) {
			$slug = 'community-admin';
		}
		$prefix = preg_quote( $slug, '/' );

		add_rewrite_rule(
			'^' . $prefix . '/([a-z-]+)/?$',
			'index.php?bn_hub=community_admin&bn_ca_section=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . $prefix . '/?$',
			'index.php?bn_hub=community_admin',
			'top'
		);
	}

	/**
	 * Resolve the template for the Community Admin hub.
	 *
	 * Invoked from PageRouter::resolve_hub_template()'s registry default branch
	 * with the hub key. One template renders every section (it switches on
	 * bn_ca_section internally), so there is no per-section file.
	 *
	 * @param string $hub The hub key being dispatched.
	 * @return string|null Template filename, or null when this is not our hub.
	 */
	public static function resolve_template( string $hub ): ?string {
		return 'community_admin' === $hub ? 'community-admin.php' : null;
	}
}
