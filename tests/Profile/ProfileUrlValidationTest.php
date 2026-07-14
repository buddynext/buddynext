<?php
/**
 * Tests that a member's profile URL is validated by FORM, not by SSRF-fetchability.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * Regression cover for profile URL validation.
 *
 * ProfileController::validate_profile_payload() used wp_http_validate_url() — which
 * is WordPress's SSRF guard, not a URL validator. It answers "is it safe for THIS
 * SERVER to fetch that address", so it rejects private hosts, loopback, odd ports and
 * unusual TLDs. None of that says anything about whether a member may put the link on
 * their profile: we never fetch it, we render it.
 *
 * The blast radius was the real problem. The edit form submits EVERY field, so a
 * single stale link anywhere in the payload 422'd the WHOLE profile save — and the
 * error did not name the URL field, so the member saw an unrelated change (a radio, a
 * bio) silently refuse to stick. It is very likely what a customer reported as
 * "the radio doesn't save".
 *
 * These drive the REST route on purpose. The SERVICE accepts these values happily —
 * the rejection only exists at the REST layer, so a service-level test would pass
 * while the bug stayed live.
 *
 * @covers \BuddyNext\Profile\ProfileController
 */
class ProfileUrlValidationTest extends \WP_Test_REST_TestCase {

	/**
	 * Member whose profile is exercised.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Fresh install + an authenticated member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->user_id = self::factory()->user->create();
		wp_set_current_user( $this->user_id );

		// The companion field the URL must not take down with it.
		( new \BuddyNext\Profile\ProfileService() )->create_field(
			array(
				'field_key'  => 'bio',
				'label'      => 'Bio',
				'type'       => 'textarea',
				'visibility' => 'public',
				'group_name' => 'general',
			)
		);
	}

	/**
	 * Read a stored profile field back through the service (the canonical read path).
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function stored( string $key ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT v.value
				   FROM {$wpdb->prefix}bn_profile_values v
				   JOIN {$wpdb->prefix}bn_profile_fields f ON f.id = v.field_id
				  WHERE f.field_key = %s AND v.user_id = %d",
				$key,
				$this->user_id
			)
		);
	}

	/**
	 * PUT /me/profile with the given payload.
	 *
	 * @param array<string, mixed> $params Body params.
	 * @return \WP_REST_Response
	 */
	private function save( array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * A reserved-TLD URL is a legitimate profile link and must save.
	 *
	 * .example is RFC 2606's reserved documentation TLD — it is exactly what demo and
	 * example data SHOULD use, and our own seeder ships one (DemoDataService). The
	 * SSRF guard rejected it, so a fresh demo install contained a member who could not
	 * save his own profile.
	 *
	 * @return void
	 */
	public function test_reserved_tld_url_is_accepted(): void {
		$response = $this->save( array( 'website' => 'https://alexrivera.example' ) );

		$this->assertNotSame(
			422,
			$response->get_status(),
			'A .example URL is a valid profile link. wp_http_validate_url() rejected it because the SERVER could not usefully fetch it — which is not the question being asked.'
		);
	}

	/**
	 * THE CUSTOMER-FACING BUG: one odd URL must not reject the whole payload.
	 *
	 * The edit form submits every field. Under the SSRF guard, a stale link in
	 * `website` 422'd the entire save, so the bio (and every other field) was silently
	 * discarded — with an error that never named the URL.
	 *
	 * @return void
	 */
	public function test_an_unusual_url_does_not_reject_the_entire_profile_save(): void {
		$response = $this->save(
			array(
				'website' => 'https://intranet.local',
				'bio'     => 'This bio must survive.',
			)
		);

		$this->assertNotSame( 422, $response->get_status(), 'One unusual URL must not 422 the whole profile save.' );
		$this->assertSame(
			'This bio must survive.',
			$this->stored( 'bio' ),
			'The bio was discarded because an UNRELATED URL field failed an SSRF check. This is what members reported as "my change does not save".'
		);
	}

	/**
	 * A genuinely malformed URL is still rejected — the validator was loosened, not removed.
	 *
	 * @return void
	 */
	public function test_a_malformed_url_is_still_rejected(): void {
		$response = $this->save( array( 'website' => 'https://not a url' ) );

		$this->assertSame( 422, $response->get_status(), 'A malformed URL must still be refused.' );
	}

	/**
	 * A non-http(s) scheme is still rejected — javascript: must never reach a profile link.
	 *
	 * @return void
	 */
	public function test_a_non_http_scheme_is_still_rejected(): void {
		$response = $this->save( array( 'website' => 'javascript:alert(1)' ) );

		$this->assertSame(
			422,
			$response->get_status(),
			'Only http/https may back a profile link. Loosening the SSRF check must not open the scheme.'
		);
	}

	/**
	 * A hostname with no dot is rejected — "localhost" is not a profile website.
	 *
	 * @return void
	 */
	public function test_a_bare_hostname_is_rejected(): void {
		$response = $this->save( array( 'website' => 'https://localhost' ) );

		$this->assertSame( 422, $response->get_status(), 'A bare hostname is not a real profile URL.' );
	}
}
