<?php
/**
 * App config REST controller — the mobile app's bootstrap handshake.
 *
 * @package BuddyNext
 */

declare(strict_types=1);

namespace BuddyNext\App;

use BuddyNext\Core\FeatureRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves the one call a native client makes before it does anything else:
 * whether the app may run here, how to theme itself, and which features exist.
 *
 * Public on purpose. The app reads this BEFORE authenticating — it has to theme
 * the connect and sign-in screens in the site's colours, and it has to be able to
 * say "this site doesn't have the app" without asking for credentials first.
 * There is nothing here a logged-out visitor cannot already read off the page.
 *
 * ── Why this lives in Free ────────────────────────────────────────────────────
 *
 * The app is a Pro benefit, so the obvious home is Pro (and PRO-ROADMAP.md:623
 * proposed exactly that: GET /buddynext-pro/v1/mobile/config). It is the wrong
 * home, for two reasons.
 *
 * First, the flag source is Free's. FeatureRegistry is a Free class; routing its
 * own catalogue out through a Pro endpoint would make Free's features
 * unreadable without Pro.
 *
 * Second, and decisively: a Pro-only route does not exist on a free-only site, so
 * the app would get a 404 — indistinguishable from a wrong URL, a firewall, or a
 * site that isn't BuddyNext at all. The app cannot tell "no Pro" from "no site",
 * and the member gets the wrong error. Answering 200 with app_enabled:false is
 * the difference between "this community doesn't have the app" and "something is
 * broken". Free flag off is a 403; Pro flag off is a 404 — so never infer a
 * capability from a probe. This endpoint is the reason the app never has to.
 *
 * Pro extends by filter (Pro CLAUDE.md rule 3): it flips app_enabled on a valid
 * licence, adds its legal block, and may override branding. That mirrors Jetonomy
 * 1.6.0, which is the shipped reference for this handshake.
 *
 * @since 1.0.9
 */
class AppConfigController {

	/**
	 * Payload shape version.
	 *
	 * Bumped when a field's MEANING changes, never for additive fields. The app
	 * refuses a contract it does not understand rather than guessing at a payload
	 * whose semantics moved under it.
	 *
	 * @var int
	 */
	private const CONTRACT_VERSION = 1;

	/**
	 * Register the public route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/app/config',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_config' ),
				// Public: read before login, to theme the sign-in screen and to
				// answer "does this site have the app" without credentials.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /app/config — the bootstrap payload.
	 *
	 * @param WP_REST_Request $request The config request.
	 * @return WP_REST_Response
	 */
	public function get_config( WP_REST_Request $request ): WP_REST_Response {
		$data = array(
			'contract_version' => self::CONTRACT_VERSION,

			// Fail closed. The app unlocks on === true and nothing else, so a site
			// running a version of this plugin that predates the field, or a Pro
			// that never answered, reads as "no" rather than as "yes by accident".
			// Pro flips this via buddynext_app_config, and only on a valid licence.
			'app_enabled'      => false,

			'pro_active'       => defined( 'BUDDYNEXTPRO_VERSION' ),

			// Fails OPEN, unlike app_enabled: an empty or malformed floor must not
			// wall off every member of the site behind an update that may not
			// exist. A licence gate that fails open leaks revenue; a version gate
			// that fails closed bricks a community over a typo.
			'min_app_version'  => $this->min_app_version(),

			'branding'         => $this->branding(),
			'features'         => $this->features(),
			'limits'           => $this->limits(),

			'legal'            => $this->legal(),
		);

		/**
		 * Filter the mobile app's bootstrap config.
		 *
		 * Pro uses this to flip app_enabled on a valid licence, fill the legal
		 * block, and add its own feature flags. Site owners can override branding.
		 *
		 * @since 1.0.9
		 *
		 * @param array           $data    The assembled config payload.
		 * @param WP_REST_Request $request The config request.
		 */
		$data = apply_filters( 'buddynext_app_config', $data, $request );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Branding for the pre-auth screens.
	 *
	 * Every value here is already the site's answer on the web; this only exposes
	 * it. Note the empty-string cases are meaningful and must not be defaulted:
	 * Appearance treats an empty accent (or the legacy #0073aa) as "inherit the
	 * theme's palette", so emitting a colour there would invent a brand the owner
	 * never chose. The app reads empty as "use your own default".
	 *
	 * app_name has no option of its own yet, so it falls back to the site name —
	 * the same thing the web header shows.
	 *
	 * @return array{app_name:string,accent_color:string,logo_url:string,login_bg_url:string,color_scheme_default:string}
	 */
	private function branding(): array {
		$accent = (string) sanitize_hex_color( (string) get_option( 'buddynext_brand_color', '' ) );

		// Appearance's own opt-in rule: empty or the legacy default means the
		// owner never picked an accent. Pass the absence through rather than
		// dressing it up as a choice.
		if ( '#0073aa' === strtolower( $accent ) ) {
			$accent = '';
		}

		// auto | light | dark. 'auto' means follow the device, which is what the
		// web does for a visitor who has not chosen — so the app must honour the
		// tri-state and not collapse it to light.
		$scheme = (string) get_option( 'buddynext_default_theme', 'auto' );
		if ( ! in_array( $scheme, array( 'auto', 'light', 'dark' ), true ) ) {
			$scheme = 'auto';
		}

		return array(
			'app_name'             => (string) get_bloginfo( 'name' ),
			'accent_color'         => $accent,
			'logo_url'             => (string) get_option( 'buddynext_logo_url', '' ),
			'login_bg_url'         => '',
			'color_scheme_default' => $scheme,
		);
	}

	/**
	 * The feature flag set, keyed by slug.
	 *
	 * Resolved through FeatureRegistry rather than read off the option, so the
	 * app sees the same answer the site does: mandatory tiers, unmet dependencies
	 * and absent partner plugins are all already folded in. A bridge whose plugin
	 * is not installed reports false here, which is what stops the app mounting a
	 * module against routes that do not exist.
	 *
	 * @return array<string,bool>
	 */
	private function features(): array {
		$registry = buddynext_service( 'features' );
		if ( ! $registry instanceof FeatureRegistry ) {
			// No registry means no honest answer, and an empty set reads as "no
			// features" — which is the fail-closed direction.
			return array();
		}

		$flags = array();
		foreach ( array_keys( $registry->catalog() ) as $slug ) {
			$flags[ (string) $slug ] = $registry->is_enabled( (string) $slug );
		}

		return $flags;
	}

	/**
	 * Server-side limits the app must respect locally.
	 *
	 * Emitted so the client can refuse over-long input before spending a round
	 * trip, and so a member is told the ceiling instead of discovering it as an
	 * opaque error.
	 *
	 * @return array<string,int>
	 */
	private function limits(): array {
		return array(
			'connect_note_max_length' => (int) apply_filters( 'buddynext_connect_note_max_length', 500 ),
			'max_connections'         => (int) apply_filters( 'buddynext_max_connections', 5000 ),
			'max_following'           => (int) apply_filters( 'buddynext_max_following', 7500 ),
			// The batch ceiling on GET /feed/viewer-state. The app chunks on this, so
			// it reads from the route's own constant — restating the literal here is
			// how the two drift and the app starts chunking at the wrong size.
			'viewer_state_max_ids'    => \BuddyNext\Feed\FeedController::VIEWER_STATE_MAX_IDS,
		);
	}

	/**
	 * Legal links. Required for App Store review.
	 *
	 * Sourced from what the site already has: WordPress's own privacy page, and
	 * the terms page the registration form already links. The rest have no
	 * producer yet and are emitted empty rather than invented — an empty string is
	 * an honest "this site has not set one", which the app answers with Apple's
	 * standard EULA. A placeholder URL would be worse than nothing: it would pass
	 * review and then 404 for a member.
	 *
	 * @return array<string,string>
	 */
	private function legal(): array {
		$terms_id  = (int) get_option( 'buddynext_terms_page_id', 0 );
		$terms_url = $terms_id > 0 ? (string) get_permalink( $terms_id ) : '';

		return array(
			'privacy_url'    => (string) get_privacy_policy_url(),
			'terms_url'      => $terms_url,
			'eula_url'       => '',
			'guidelines_url' => '',
			'abuse_contact'  => '',
		);
	}

	/**
	 * The lowest app version this site will serve.
	 *
	 * Empty by default: most sites never set a floor, and the app treats an empty
	 * or unparseable value as "no floor". See the fail-open note in get_config().
	 *
	 * @return string
	 */
	private function min_app_version(): string {
		return (string) apply_filters( 'buddynext_min_app_version', '' );
	}
}
