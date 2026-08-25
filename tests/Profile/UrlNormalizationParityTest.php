<?php
/**
 * URL normalisation is keyed on field TYPE, so an owner-created url field gets
 * the same https auto-prefix + scheme validation as the seeded website /
 * social_* fields - it previously only reached a hardcoded key list, so a custom
 * url field stored a bare "example.com" without a scheme.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileController;

/**
 * @covers \BuddyNext\Profile\ProfileController::url_field_keys
 * @covers \BuddyNext\Profile\ProfileController::ensure_url_scheme
 */
class UrlNormalizationParityTest extends \WP_UnitTestCase {

	private ProfileController $controller;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->controller = new ProfileController();
	}

	private function invoke( string $method, ...$args ) {
		$m = new \ReflectionMethod( ProfileController::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $this->controller, ...$args );
	}

	public function test_scheme_prefix_only_when_missing(): void {
		$this->assertSame( 'https://example.com', $this->invoke( 'ensure_url_scheme', 'example.com' ) );
		$this->assertSame( 'https://example.com', $this->invoke( 'ensure_url_scheme', '  example.com  ' ) );
		$this->assertSame( 'https://example.com', $this->invoke( 'ensure_url_scheme', '//example.com' ) );
		$this->assertSame( 'http://x.test', $this->invoke( 'ensure_url_scheme', 'http://x.test' ) );
		$this->assertSame( 'https://x.test', $this->invoke( 'ensure_url_scheme', 'https://x.test' ) );
		$this->assertSame( '', $this->invoke( 'ensure_url_scheme', '   ' ) );
	}

	public function test_owner_created_url_field_is_covered_by_type(): void {
		$service = buddynext_service( 'profiles' );
		$service->create_field(
			array(
				'group_name' => 'Links',
				'field_key'  => 'my_portfolio',
				'label'      => 'My Portfolio',
				'type'       => 'url',
			)
		);

		$keys = (array) $this->invoke( 'url_field_keys' );

		// The seeded website field (type url) and the owner-created one both appear.
		$this->assertContains( 'website', $keys, 'Seeded url field must be covered.' );
		$this->assertContains( 'my_portfolio', $keys, 'Owner-created url field must be covered by type.' );

		// A non-url field (headline) must NOT appear.
		$this->assertNotContains( 'headline', $keys );
	}
}
