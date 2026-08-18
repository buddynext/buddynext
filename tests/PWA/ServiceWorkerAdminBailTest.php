<?php
/**
 * The service worker must never intercept wp-admin — including on a subdirectory install.
 *
 * The fetch handler already refused to touch wp-admin, but it asked the question
 * root-relatively:
 *
 *     url.pathname.indexOf('/wp-admin') === 0
 *
 * On a site installed in a subdirectory the admin path is `/community/wp-admin/…`,
 * so indexOf returns a positive offset rather than 0, the bail never fires, and the
 * worker caches wp-admin. The worker does claim the whole origin — it is served with
 * `Service-Worker-Allowed: /` and registered with scope `/` — so it really is in
 * front of those requests; only the guard was wrong.
 *
 * What that produces is the reported failure: `/wp-admin/load-scripts.php` is
 * WordPress's concatenated admin bundle and its contents differ per screen, so a
 * copy cached on one admin page is replayed on another and every jQuery-dependent
 * script there breaks — "jQuery is not defined", "$(...).sortable is not a
 * function", across core, the theme and other plugins.
 *
 * NOTE ON THE REPORT: the ticket states these files live under `/wp-includes/`.
 * They do not — `load-scripts.php` and `load-styles.php` are in `/wp-admin/`. That
 * matters, because on a ROOT install the existing bail already covered them, which
 * is why this could not be reproduced there.
 *
 * @package BuddyNext\Tests\PWA
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\PWA;

use BuddyNext\PWA\PwaService;

/**
 * Admin/login/REST bails must use the site's real paths.
 *
 * @covers \BuddyNext\PWA\PwaService
 */
class ServiceWorkerAdminBailTest extends \WP_UnitTestCase {

	/**
	 * Build the worker JavaScript the plugin would serve.
	 *
	 * @return string
	 */
	private function worker_js(): string {
		return ( new PwaService() )->get_service_worker_script();
	}

	/**
	 * Point WordPress at a subdirectory install for one test.
	 *
	 * @return void
	 */
	private function move_site_into_subdirectory(): void {
		$sub = static fn(): string => 'http://example.org/community';

		add_filter( 'site_url', $sub, 99 );
		add_filter( 'home_url', $sub, 99 );
	}

	// ── The complaint ────────────────────────────────────────────────────────────

	/**
	 * On a root install the admin bail names the real admin path.
	 *
	 * @return void
	 */
	public function test_the_admin_bail_uses_the_real_admin_path(): void {
		$js = $this->worker_js();

		$this->assertStringContainsString(
			'ADMIN_PATH',
			$js,
			'the admin bail must compare against an injected path, not a hardcoded literal'
		);
		$this->assertStringNotContainsString(
			"indexOf('/wp-admin')",
			$js,
			'A hardcoded "/wp-admin" only matches a site at the origin root. On a subdirectory '
			. 'install it never matches, so the worker caches wp-admin and replays a stale '
			. 'load-scripts.php bundle into it.'
		);
	}

	/**
	 * A subdirectory install gets its own admin path baked in.
	 *
	 * This is the case that could not be reproduced on a root install and is the
	 * whole reason the report looked impossible against the code.
	 *
	 * @return void
	 */
	public function test_a_subdirectory_install_bails_on_its_own_admin_path(): void {
		$this->move_site_into_subdirectory();

		$js = $this->worker_js();

		$this->assertStringContainsString(
			'/community/wp-admin/',
			$js,
			'On a subdirectory install the worker must know the admin lives at '
			. '/community/wp-admin/. Without it the bail is dead code and wp-admin gets cached.'
		);
	}

	/**
	 * The REST bail follows the site too.
	 *
	 * Same root-relative assumption, same failure mode: an uncaught REST request can
	 * be answered from cache, or with the offline page, inside wp-admin.
	 *
	 * @return void
	 */
	public function test_the_rest_bail_uses_the_real_rest_path(): void {
		$this->move_site_into_subdirectory();

		$js = $this->worker_js();

		$this->assertStringContainsString( 'REST_PATH', $js );
		$this->assertStringNotContainsString(
			"indexOf('/wp-json/')",
			$js,
			'the REST bail must not assume the origin root either'
		);
	}

	// ── What must keep working ───────────────────────────────────────────────────

	/**
	 * A root install still bails on /wp-admin/.
	 *
	 * Guards against "fixing" the subdirectory case in a way that stops protecting
	 * the overwhelmingly common one.
	 *
	 * @return void
	 */
	public function test_a_root_install_still_names_the_plain_admin_path(): void {
		$js = $this->worker_js();

		$this->assertMatchesRegularExpression(
			'~ADMIN_PATH\s*=\s*"/wp-admin/"~',
			$js,
			'a site at the origin root must still bail on /wp-admin/'
		);
	}

	/**
	 * The login screen is still excluded.
	 *
	 * @return void
	 */
	public function test_the_login_screen_is_still_excluded(): void {
		$js = $this->worker_js();

		$this->assertStringContainsString( 'LOGIN_PATH', $js );
		$this->assertMatchesRegularExpression(
			'~LOGIN_PATH\s*=\s*"[^"]*wp-login\.php"~',
			$js,
			'the login path must still be injected and still point at wp-login.php'
		);
	}
}
