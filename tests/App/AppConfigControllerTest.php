<?php
/**
 * Tests for the mobile app's bootstrap config endpoint.
 *
 * @package BuddyNext\Tests\App
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\App;

/**
 * GET /buddynext/v1/app/config — the handshake a native client makes first.
 *
 * @covers \BuddyNext\App\AppConfigController
 */
class AppConfigControllerTest extends \WP_UnitTestCase {

	/**
	 * The route under test.
	 *
	 * @var string
	 */
	private string $route = '/buddynext/v1/app/config';

	/**
	 * Clear any branding a previous test set.
	 *
	 * Routes are NOT registered here: REST\Router wires this controller on
	 * rest_api_init, and rest_get_server() fires that. Registering by hand would
	 * both duplicate the plugin's own wiring and trip core's "routes must be
	 * registered on rest_api_init" notice — and it would test a route the plugin
	 * never actually serves.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( 'buddynext_brand_color' );
		delete_option( 'buddynext_default_theme' );
		delete_option( 'buddynext_logo_url' );
		remove_all_filters( 'buddynext_app_config' );
	}

	/**
	 * The plugin's own Router serves the route — not a hand-registered copy.
	 *
	 * @return void
	 */
	public function test_route_is_registered_by_the_plugin(): void {
		$this->assertArrayHasKey(
			'/buddynext/v1/app/config',
			rest_get_server()->get_routes( 'buddynext/v1' ),
			'REST\Router must wire the controller, or the app has no bootstrap on a real site.'
		);
	}

	/**
	 * Tear down filters so one test cannot leak a config into the next.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_app_config' );
		remove_all_filters( 'buddynext_min_app_version' );
		parent::tear_down();
	}

	/**
	 * Dispatch the route and return the payload.
	 *
	 * @return array<string,mixed>
	 */
	private function get_config(): array {
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', $this->route ) );

		$this->assertSame( 200, $response->get_status() );

		return (array) $response->get_data();
	}

	/**
	 * The config exposes the site's time contract so the app can render
	 * timestamps in the owner's WordPress timezone (self-hosted, not per-device).
	 *
	 * @return void
	 */
	public function test_config_exposes_the_site_time_contract(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );
		update_option( 'gmt_offset', 5.5 );

		$time = $this->get_config()['time'] ?? null;

		$this->assertIsArray( $time );
		$this->assertSame( 'Asia/Kolkata', $time['site_timezone'] );
		$this->assertSame( 5.5, $time['gmt_offset'] );
		// server_utc is UTC ISO-8601 with a Z suffix (the transport format).
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			(string) $time['server_utc']
		);

		delete_option( 'timezone_string' );
		delete_option( 'gmt_offset' );
	}

	/**
	 * Readable with no session at all.
	 *
	 * The app reads this before it has credentials — to theme the sign-in screen,
	 * and to say "this site doesn't have the app" without asking anyone to log in.
	 *
	 * @return void
	 */
	public function test_is_public(): void {
		wp_set_current_user( 0 );

		$data = $this->get_config();

		$this->assertArrayHasKey( 'app_enabled', $data );
	}

	/**
	 * The whole point of the endpoint: 200, not 404.
	 *
	 * A Pro-only route would 404 on a free site, which the app cannot tell apart
	 * from a wrong URL, a firewall, or a site that is not BuddyNext. Answering
	 * honestly is what lets the app say "this community doesn't have the app"
	 * instead of "something went wrong".
	 *
	 * @return void
	 */
	public function test_free_site_answers_200_with_app_disabled_rather_than_404(): void {
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', $this->route ) );

		$this->assertSame( 200, $response->get_status(), 'A free site must answer, not 404.' );
		$this->assertFalse( $response->get_data()['app_enabled'] );
	}

	/**
	 * Fail closed, and strictly.
	 *
	 * The app unlocks on === true. A truthy-but-not-true value must not read as
	 * yes, or a site could unlock the app by accident.
	 *
	 * @return void
	 */
	public function test_app_enabled_is_false_without_pro(): void {
		$data = $this->get_config();

		$this->assertFalse( $data['app_enabled'] );
		$this->assertNotSame( 1, $data['app_enabled'], 'app_enabled must be a strict bool, not truthy.' );
	}

	/**
	 * Pro's seam. Free ships the door; this proves it opens.
	 *
	 * @return void
	 */
	public function test_pro_can_flip_app_enabled_through_the_filter(): void {
		add_filter(
			'buddynext_app_config',
			static function ( array $data ): array {
				$data['app_enabled'] = true;
				return $data;
			}
		);

		$this->assertTrue( $this->get_config()['app_enabled'] );
	}

	/**
	 * An unset accent stays unset.
	 *
	 * Appearance treats empty as "inherit the theme's palette" — it is opt-in. If
	 * this defaulted to a colour, every site that never chose a brand would ship
	 * the app in a colour its owner never picked.
	 *
	 * @return void
	 */
	public function test_unset_accent_is_emitted_empty_not_defaulted(): void {
		$data = $this->get_config();

		$this->assertSame( '', $data['branding']['accent_color'] );
	}

	/**
	 * The legacy default means the same as unset.
	 *
	 * Appearance's own rule (`Theme/Appearance.php`): empty OR #0073aa means the
	 * owner never chose, so the theme palette stands. The app must see the same
	 * absence the web does.
	 *
	 * @return void
	 */
	public function test_legacy_default_accent_is_treated_as_unset(): void {
		update_option( 'buddynext_brand_color', '#0073aa' );

		$this->assertSame( '', $this->get_config()['branding']['accent_color'] );
	}

	/**
	 * A real accent is passed through.
	 *
	 * @return void
	 */
	public function test_accent_is_emitted_when_set(): void {
		update_option( 'buddynext_brand_color', '#7C3AED' );

		$this->assertSame( '#7C3AED', $this->get_config()['branding']['accent_color'] );
	}

	/**
	 * Junk in the option must not reach the app.
	 *
	 * @return void
	 */
	public function test_malformed_accent_is_sanitized_away(): void {
		update_option( 'buddynext_brand_color', 'javascript:alert(1)' );

		$this->assertSame( '', $this->get_config()['branding']['accent_color'] );
	}

	/**
	 * The scheme default is tri-state and must survive as one.
	 *
	 * 'auto' means follow the device, which is what the web does for a visitor who
	 * has not chosen. Collapsing it to light would open the app light on a site
	 * whose owner set dark.
	 *
	 * @return void
	 */
	public function test_color_scheme_default_is_auto_when_unset(): void {
		$this->assertSame( 'auto', $this->get_config()['branding']['color_scheme_default'] );
	}

	/**
	 * A dark default reaches the app.
	 *
	 * @return void
	 */
	public function test_color_scheme_default_honours_a_dark_site(): void {
		update_option( 'buddynext_default_theme', 'dark' );

		$this->assertSame( 'dark', $this->get_config()['branding']['color_scheme_default'] );
	}

	/**
	 * An unknown scheme falls back to auto, not to a guess.
	 *
	 * @return void
	 */
	public function test_unknown_color_scheme_falls_back_to_auto(): void {
		update_option( 'buddynext_default_theme', 'sepia' );

		$this->assertSame( 'auto', $this->get_config()['branding']['color_scheme_default'] );
	}

	/**
	 * Flags come from the registry, so the app sees what the site resolves.
	 *
	 * @return void
	 */
	public function test_features_reflect_the_registry(): void {
		$features = $this->get_config()['features'];

		$this->assertIsArray( $features );
		$this->assertArrayHasKey( 'feed', $features );
		$this->assertTrue( $features['feed'], 'feed is mandatory and must resolve true.' );
		$this->assertArrayHasKey( 'webhooks', $features );
		$this->assertFalse( $features['webhooks'], 'webhooks is opt-in and off by default.' );
	}

	/**
	 * A bridge whose plugin is absent must report false.
	 *
	 * This is what stops the app mounting a module against routes that do not
	 * exist. career_board's guard is defined('WCB_VERSION'), which no test site
	 * defines.
	 *
	 * @return void
	 */
	public function test_absent_partner_plugin_reports_its_feature_off(): void {
		$features = $this->get_config()['features'];

		$this->assertFalse(
			$features['career_board'],
			'A bridge feature must report false when its plugin is not installed, or the app mounts a dead module.'
		);
	}

	/**
	 * The version floor fails OPEN.
	 *
	 * The asymmetry with app_enabled is deliberate: a licence gate that fails open
	 * leaks revenue, but a version gate that fails closed walls off a whole
	 * community behind an update that may not exist.
	 *
	 * @return void
	 */
	public function test_min_app_version_is_empty_by_default(): void {
		$this->assertSame( '', $this->get_config()['min_app_version'] );
	}

	/**
	 * A site can set a floor.
	 *
	 * @return void
	 */
	public function test_min_app_version_is_filterable(): void {
		add_filter( 'buddynext_min_app_version', static fn(): string => '1.2.0' );

		$this->assertSame( '1.2.0', $this->get_config()['min_app_version'] );
	}

	/**
	 * The contract version is present so the app can refuse a payload it does not
	 * understand rather than guess at moved semantics.
	 *
	 * @return void
	 */
	public function test_contract_version_is_emitted(): void {
		$this->assertSame( 1, $this->get_config()['contract_version'] );
	}

	/**
	 * The legal block always has its shape, so the app can rely on the keys.
	 *
	 * @return void
	 */
	public function test_legal_block_shape_is_stable(): void {
		$legal = $this->get_config()['legal'];

		foreach ( array( 'privacy_url', 'terms_url', 'eula_url', 'guidelines_url', 'abuse_contact' ) as $key ) {
			$this->assertArrayHasKey( $key, $legal );
			$this->assertIsString( $legal[ $key ] );
		}
	}

	/**
	 * Terms resolve from the page the registration form already links.
	 *
	 * @return void
	 */
	public function test_terms_url_resolves_from_the_terms_page(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'buddynext_terms_page_id', $page_id );

		$this->assertSame( get_permalink( $page_id ), $this->get_config()['legal']['terms_url'] );

		delete_option( 'buddynext_terms_page_id' );
	}

	/**
	 * The viewer-state ceiling is knowable.
	 *
	 * The route silently truncates over 100 ids, so the app has to be told the
	 * number rather than discover it as cards rendering un-reacted.
	 *
	 * @return void
	 */
	public function test_limits_expose_the_viewer_state_ceiling(): void {
		$this->assertSame( 100, $this->get_config()['limits']['viewer_state_max_ids'] );
	}
}
