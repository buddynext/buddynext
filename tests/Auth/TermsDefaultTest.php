<?php
/**
 * The terms-consent default, and the two ways a fixed answer gets it wrong.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\RegistrationPolicy;
use BuddyNext\Core\Installer;

/**
 * `buddynext_require_terms` shipped defaulting to a fixed `true` while
 * `buddynext_terms_page_id` defaulted to 0, so every fresh install carried a
 * permanent admin notice - "consent is switched on, but no Terms page is set" - on
 * every BuddyNext screen, for a feature the owner had never turned on. A warning
 * that is present before the owner has done anything is furniture, and it teaches
 * people to ignore the notices that matter.
 *
 * The obvious fix, flipping the default to a fixed `false`, is worse. A site that
 * HAS a Terms page and simply never touched this setting has been enforcing all
 * along on the ON default; flipping it would quietly drop a legal gate the owner
 * believes is up. Nobody would see that happen.
 *
 * So the default is derived from whether a page exists. These tests pin all three
 * cases, because the middle one is a silent regression and nothing else would
 * catch it.
 *
 * @covers \BuddyNext\Auth\RegistrationPolicy::terms_default
 */
class TermsDefaultTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		delete_option( 'buddynext_require_terms' );
		delete_option( 'buddynext_terms_page_id' );
	}

	/**
	 * The value the plugin uses when the owner has never said either way.
	 *
	 * @return bool
	 */
	private function effective(): bool {
		return (bool) get_option( 'buddynext_require_terms', RegistrationPolicy::terms_default() );
	}

	public function test_a_fresh_install_does_not_demand_consent_it_cannot_collect(): void {
		$this->assertFalse( RegistrationPolicy::terms_default() );
		$this->assertFalse( $this->effective(), 'No page, no setting: nothing to consent to, so nothing is switched on.' );
	}

	public function test_a_site_with_a_terms_page_keeps_enforcing_without_being_asked(): void {
		// The regression this guards. Such a site has been enforcing on the old ON
		// default; a fixed `false` would silently stop collecting consent.
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'buddynext_terms_page_id', $page );

		$this->assertTrue( RegistrationPolicy::terms_default() );
		$this->assertTrue( $this->effective(), 'A site with a Terms page must not silently stop collecting consent.' );
	}

	public function test_an_explicit_choice_beats_the_derived_default_both_ways(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'buddynext_terms_page_id', $page );
		update_option( 'buddynext_require_terms', '' );
		$this->assertFalse( $this->effective(), 'An owner who turned it off keeps it off, page or no page.' );

		delete_option( 'buddynext_terms_page_id' );
		update_option( 'buddynext_require_terms', '1' );
		$this->assertTrue( $this->effective(), 'An owner who turned it on keeps it on - and that is when the notice is right to appear.' );
	}
}
