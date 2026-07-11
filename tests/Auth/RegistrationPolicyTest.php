<?php
/**
 * Tests for RegistrationPolicy — the shared signup decision.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\RegistrationPolicy;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Auth\RegistrationPolicy
 */
class RegistrationPolicyTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema + open registration for each case.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		update_option( 'users_can_register', '1' );
		update_option( 'buddynext_reg_mode', 'open' );
		delete_option( 'buddynext_allowed_domains' );
	}

	/**
	 * The allowlist is an ACCESS policy, not a spam heuristic: it must bind on
	 * every door, including social login, which historically bypassed it — a
	 * corporate community restricted to one domain was wide open to any Google
	 * account.
	 */
	public function test_allowed_domains_binds_on_the_social_door(): void {
		update_option( 'buddynext_allowed_domains', "acme.com\n" );
		$policy = new RegistrationPolicy();

		$this->assertTrue(
			$policy->check_access( 'someone@acme.com', null, RegistrationPolicy::SOURCE_SOCIAL )
		);

		$blocked = $policy->check_access( 'someone@gmail.com', null, RegistrationPolicy::SOURCE_SOCIAL );
		$this->assertWPError( $blocked );
		$this->assertSame( 'bn_reg_domain', $blocked->get_error_code() );
	}

	/**
	 * reg_mode = closed must be reachable, and it must reject every door.
	 */
	public function test_closed_mode_rejects_every_source(): void {
		update_option( 'buddynext_reg_mode', 'closed' );
		$policy = new RegistrationPolicy();

		$sources = array(
			RegistrationPolicy::SOURCE_FORM,
			RegistrationPolicy::SOURCE_APP,
			RegistrationPolicy::SOURCE_SOCIAL,
			RegistrationPolicy::SOURCE_CORE,
		);

		foreach ( $sources as $source ) {
			$result = $policy->check_access( 'a@b.com', null, $source );
			$this->assertWPError( $result, "source {$source} should be rejected" );
			$this->assertSame( 'bn_reg_closed', $result->get_error_code() );
		}
	}

	/**
	 * Invite-only rejects a signup with no token, and admits one with a good token.
	 */
	public function test_invite_mode_requires_a_valid_token(): void {
		update_option( 'buddynext_reg_mode', 'invite' );
		$policy = new RegistrationPolicy();

		$result = $policy->check_access( 'a@b.com', null, RegistrationPolicy::SOURCE_FORM );
		$this->assertWPError( $result );
		$this->assertSame( 'bn_reg_invite', $result->get_error_code() );

		$result = $policy->check_access( 'a@b.com', 'not-a-real-token', RegistrationPolicy::SOURCE_FORM );
		$this->assertWPError( $result, 'a bogus token must not open an invite-only community' );
	}

	/**
	 * An unmet requirement is reported as MISSING, not as a hard error. This is
	 * what lets social login park a pending signup and collect the data on a real
	 * form, instead of creating an account with an empty required field.
	 */
	public function test_missing_reports_unmet_terms_and_required_field(): void {
		// Seed through the service, never raw SQL: create_field() resolves the
		// group and primes the caches that get_registration_fields() reads.
		buddynext_service( 'profiles' )->create_field(
			array(
				'group_name'       => 'basic_info',
				'field_key'        => 'company',
				'label'            => 'Company',
				'type'             => 'text',
				'is_required'      => 1,
				'show_on_register' => 1,
			)
		);
		update_option( 'buddynext_require_terms', '1' );

		$policy = new RegistrationPolicy();

		$missing = $policy->missing( array( 'email' => 'a@b.com' ) );
		$this->assertContains( 'terms', $missing );
		$this->assertContains( 'bn_field_company', $missing );

		$satisfied = $policy->missing(
			array(
				'email'            => 'a@b.com',
				'terms_agreed'     => true,
				'bn_field_company' => 'Wbcom',
			)
		);
		$this->assertSame( array(), $satisfied );
	}

	/**
	 * Terms are an owner lever, not a hardcoded mandate. An owner running a
	 * casual community must be able to switch the gate off.
	 */
	public function test_terms_requirement_is_an_owner_lever(): void {
		// Note: update_option( $k, false ) is a no-op when the option is unset —
		// WP early-returns because the new value equals the old (false). Store the
		// same falsy string the Settings API writes when the box is unticked.
		update_option( 'buddynext_require_terms', '0' );

		$policy = new RegistrationPolicy();

		$this->assertNotContains( 'terms', $policy->missing( array( 'email' => 'a@b.com' ) ) );
	}
}
