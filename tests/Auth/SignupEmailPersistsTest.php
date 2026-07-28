<?php
/**
 * The signup email field survives a failed submit.
 *
 * Submitting the registration form with a validation error (a short password,
 * say) correctly kept the form open and showed the field error — but wiped the
 * EMAIL back to empty while name and password kept what the visitor typed. Any
 * mistyped password therefore cost them their email address too, on every
 * attempt, which is an easy source of signup drop-off.
 *
 * The cause was not in the store. Email is the only field on this form carrying
 * a server-rendered `value` attribute (it exists so an invitation can prefill
 * the address), and a plain `value` is a CONTROLLED prop to the Interactivity
 * API's renderer: every re-render wrote the server value back over the DOM. On a
 * normal, non-invite signup that value is the empty string, so the re-render
 * triggered by populating the field errors blanked the input. Name and password
 * carry no `value` attribute at all, which is exactly why they survived — and
 * why this looked like an email-specific bug rather than a rendering one.
 *
 * Binding `data-wp-bind--value` makes the re-render write back the store's
 * current value instead of the stale server one. The same fix, for the same
 * reason, already exists in templates/parts/profile-edit-hero.php.
 *
 * These assertions are on the RENDERED MARKUP because that is where the defect
 * lived; no PHP function was wrong.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Core\Installer;

/**
 * Markup contract for the signup email input.
 */
class SignupEmailPersistsTest extends \WP_UnitTestCase {

	/**
	 * Boot the schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Render the signup template and return its markup.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		require BUDDYNEXT_DIR . 'templates/auth/signup.php';

		return (string) ob_get_clean();
	}

	/**
	 * Isolate the email input's opening tag.
	 *
	 * @param string $html Rendered markup.
	 * @return string
	 */
	private function email_input( string $html ): string {
		$this->assertStringContainsString( 'bn-signup-email', $html, 'The signup form did not render an email field.' );

		$start = strrpos( substr( $html, 0, strpos( $html, 'id="bn-signup-email"' ) ), '<input' );
		$this->assertIsInt( $start );

		$end = strpos( $html, '>', $start );

		return substr( $html, $start, ( $end - $start ) + 1 );
	}

	/**
	 * The regression guard: the email input binds its value to the store.
	 *
	 * Without this binding the field is uncontrolled in the DOM but controlled
	 * in the vdom, and every re-render resets it to the server value.
	 *
	 * @return void
	 */
	public function test_the_email_input_binds_its_value(): void {
		$input = $this->email_input( $this->render() );

		$this->assertStringContainsString(
			'data-wp-bind--value="context.email"',
			$input,
			'The email input lost its value binding, so a failed submit will wipe what the visitor typed.'
		);
	}

	/**
	 * The store must be seeded with the same key the input binds to, or the
	 * binding paints an empty field over a valid server prefill.
	 *
	 * @return void
	 */
	public function test_the_context_seeds_the_bound_key(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression(
			'/data-wp-context=\'[^\']*"email":/',
			$html,
			'context.email is bound by the input but never seeded.'
		);
	}

	/**
	 * The two fields that were NEVER broken must stay that way — they work
	 * precisely because they carry no server `value`, and adding one would give
	 * them the same bug. This is the assertion that stops someone "making the
	 * form consistent" by adding value attributes everywhere.
	 *
	 * @return void
	 */
	public function test_name_and_password_carry_no_unbound_server_value(): void {
		$html = $this->render();

		foreach ( array( 'bn-signup-name', 'bn-signup-password' ) as $id ) {
			$start = strrpos( substr( $html, 0, strpos( $html, 'id="' . $id . '"' ) ), '<input' );
			$this->assertIsInt( $start, $id . ' was not rendered.' );
			$tag = substr( $html, $start, ( strpos( $html, '>', $start ) - $start ) + 1 );

			if ( str_contains( $tag, 'value="' ) ) {
				$this->assertStringContainsString(
					'data-wp-bind--value',
					$tag,
					$id . ' gained a server value attribute without a binding — it will now be wiped on re-render, exactly like email was.'
				);
			}
		}
	}

	/**
	 * Every field the visitor types into is either unbound-and-valueless or
	 * bound. Stated as one rule over the whole form so a field added later is
	 * covered without editing this test.
	 *
	 * @return void
	 */
	public function test_no_typed_field_has_a_server_value_without_a_binding(): void {
		$html = $this->render();

		preg_match_all( '/<input\b[^>]*>/s', $html, $matches );

		foreach ( $matches[0] as $tag ) {
			// Only inputs the Interactivity renderer owns can be reset by it.
			if ( ! str_contains( $tag, 'data-wp-' ) ) {
				continue;
			}
			// Hidden inputs are meant to carry the server value; resetting them is correct.
			if ( str_contains( $tag, 'type="hidden"' ) ) {
				continue;
			}
			if ( ! preg_match( '/\svalue="/', $tag ) ) {
				continue;
			}

			$this->assertStringContainsString(
				'data-wp-bind--value',
				$tag,
				'A typed field carries a server value with no binding, so a re-render will wipe it: ' . substr( $tag, 0, 160 )
			);
		}
	}
}
