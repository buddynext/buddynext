<?php
/**
 * Registration field markup: one renderer for the sign-up and finish-sign-up forms.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\PendingSignup;
use BuddyNext\Core\Installer;
use BuddyNext\Profile\FieldType;

/**
 * REGRESSION (card 10506691398): the social "finish sign-up" form tagged only the
 * FIRST control of a group with data-bn-reg-field, so picking any radio or
 * member-type option but the first sent an empty value and a required field
 * returned 422 forever. Its labels also pointed at ids that did not exist.
 *
 * @covers \BuddyNext\Profile\FieldType::render_registration_input
 */
class RegistrationFieldMarkupTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema, open registration, no terms gate.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_set_current_user( 0 );
		update_option( 'users_can_register', '1' );
		update_option( 'buddynext_reg_mode', 'open' );
		update_option( 'buddynext_require_terms', '' );
	}

	/**
	 * Clear the pending-signup token.
	 */
	public function tear_down(): void {
		unset( $_GET['bn_pending'] );
		parent::tear_down();
	}

	/**
	 * Seed required-at-registration fields of every group type plus one text field.
	 *
	 * @return void
	 */
	private function seed_fields(): void {
		$members = buddynext_service( 'member_types' );
		foreach ( array( 'reg_student', 'reg_teacher' ) as $slug ) {
			$members->create(
				array(
					'slug'        => $slug,
					'name'        => ucfirst( substr( $slug, 4 ) ),
					'self_select' => true,
				)
			);
		}

		$service = buddynext_service( 'profiles' );
		$fields  = array(
			'reg_role'    => array(
				'radio',
				array(
					'one'   => 'One',
					'two'   => 'Two',
					'three' => 'Three',
				),
			),
			'reg_topics'  => array(
				'multiselect',
				array(
					'php' => 'PHP',
					'js'  => 'JS',
					'css' => 'CSS',
				),
			),
			'member_type' => array( 'member_type', null ),
			'reg_company' => array( 'text', null ),
		);
		$order   = 0;
		foreach ( $fields as $key => $def ) {
			$service->create_field(
				array(
					'group_name'       => 'basic_info',
					'field_key'        => $key,
					'label'            => ucfirst( $key ),
					'type'             => $def[0],
					'options'          => $def[1],
					'is_required'      => 1,
					'show_on_register' => 1,
					'visibility'       => 'public',
					'sort_order'       => $order++,
				)
			);
		}
	}

	/**
	 * Render auth/complete.php for a parked social sign-up.
	 *
	 * @return string
	 */
	private function render_complete(): string {
		$_GET['bn_pending'] = PendingSignup::park(
			array(
				'provider'       => 'google',
				'uid'            => 'uid-' . wp_generate_password( 6, false ),
				'email'          => 'social_' . wp_generate_password( 6, false ) . '@example.com',
				'email_verified' => true,
				'name'           => 'Social Person',
				'picture'        => '',
			)
		);

		ob_start();
		include BUDDYNEXT_DIR . 'templates/auth/complete.php';
		return (string) ob_get_clean();
	}

	/**
	 * Parse markup into a DOMXPath.
	 *
	 * @param string $html Markup.
	 * @return \DOMXPath
	 */
	private function xpath( string $html ): \DOMXPath {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		return new \DOMXPath( $doc );
	}

	/**
	 * Every control of every group is tagged; only radios carry `required`.
	 */
	public function test_finish_signup_tags_every_control_and_requires_only_radios(): void {
		$this->seed_fields();
		$xp = $this->xpath( $this->render_complete() );

		foreach ( array( 'bn_field_reg_role', 'bn_field_member_type' ) as $name ) {
			$radios = $xp->query( '//input[@type="radio"][@name="' . $name . '"]' );
			$this->assertGreaterThanOrEqual( 2, $radios->length, "$name renders a radio group" );
			foreach ( $radios as $radio ) {
				$this->assertTrue( $radio->hasAttribute( 'data-bn-reg-field' ), "every $name option is collected" );
				$this->assertTrue( $radio->hasAttribute( 'required' ), "a required radio group is required natively ($name)" );
			}
		}

		$boxes = $xp->query( '//input[@type="checkbox"][@name="bn_field_reg_topics[]"]' );
		$this->assertSame( 3, $boxes->length );
		foreach ( $boxes as $box ) {
			$this->assertTrue( $box->hasAttribute( 'data-bn-reg-field' ), 'every multiselect option is collected' );
			$this->assertFalse( $box->hasAttribute( 'required' ), 'required on a checkbox would mean tick them all' );
		}

		$text = $xp->query( '//input[@name="bn_field_reg_company"]' );
		$this->assertSame( 1, $text->length );
		$this->assertTrue( $text->item( 0 )->hasAttribute( 'data-bn-reg-field' ) );
		$this->assertTrue( $text->item( 0 )->hasAttribute( 'required' ) );
	}

	/**
	 * Every label-for resolves to a real id; every group names its visible label.
	 */
	public function test_finish_signup_labels_resolve(): void {
		$this->seed_fields();
		$xp = $this->xpath( $this->render_complete() );

		$labels = $xp->query( '//label[contains(concat(" ", @class, " "), " bn-auth-label ")][@for]' );
		$this->assertGreaterThan( 0, $labels->length );
		foreach ( $labels as $label ) {
			$for = $label->getAttribute( 'for' );
			$this->assertSame( 1, $xp->query( '//*[@id="' . $for . '"]' )->length, "label for=\"$for\" points at a real control" );
		}
		$this->assertSame( 'bn-field-bn_field_reg_company', $labels->item( 0 )->getAttribute( 'for' ) );

		$groups = $xp->query( '//fieldset' );
		$this->assertSame( 3, $groups->length, 'radio, member_type and multiselect render groups' );
		foreach ( $groups as $group ) {
			$id = $group->getAttribute( 'aria-labelledby' );
			$this->assertNotSame( '', $id, 'a group is named by its visible label' );
			$this->assertSame( 1, $xp->query( '//*[@id="' . $id . '"]' )->length, "aria-labelledby=\"$id\" resolves" );
		}
	}

	/**
	 * The helper itself: tags, required and ids, so signup.php gets the same markup.
	 */
	public function test_helper_contract(): void {
		$radio = FieldType::render_registration_input(
			array(
				'field_key' => 'pick',
				'type'      => 'radio',
				'options'   => array(
					'a' => 'A',
					'b' => 'B',
				),
			),
			'bn_field_pick',
			true
		);
		$this->assertTrue( $radio['is_group'] );
		$this->assertSame( 'bn-field-bn_field_pick', $radio['control_id'] );
		$this->assertSame( 'bn-field-bn_field_pick-label', $radio['label_id'] );
		$this->assertSame( 2, substr_count( $radio['html'], 'data-bn-reg-field required type="radio"' ) );
		$this->assertStringContainsString( 'aria-labelledby="bn-field-bn_field_pick-label"', $radio['html'] );

		$optional = FieldType::render_registration_input(
			array(
				'field_key' => 'pick',
				'type'      => 'radio',
				'options'   => array(
					'a' => 'A',
					'b' => 'B',
				),
			),
			'bn_field_pick',
			false
		);
		$this->assertStringNotContainsString( ' required', $optional['html'], 'an optional group is not required' );

		$multi = FieldType::render_registration_input(
			array(
				'field_key' => 'many',
				'type'      => 'multiselect',
				'options'   => array(
					'a' => 'A',
					'b' => 'B',
					'c' => 'C',
				),
			),
			'bn_field_many',
			true
		);
		$this->assertSame( 3, substr_count( $multi['html'], '<input data-bn-reg-field type="checkbox"' ) );
		$this->assertStringNotContainsString( ' required', $multi['html'] );
	}

	/**
	 * Both registration templates use the one helper; no third copy of the tagging.
	 */
	public function test_both_templates_share_the_helper(): void {
		foreach ( array( 'signup.php', 'complete.php' ) as $file ) {
			$source = (string) file_get_contents( BUDDYNEXT_DIR . 'templates/auth/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertStringContainsString( 'render_registration_input(', $source, "$file renders through the shared helper" );
			$this->assertStringNotContainsString( 'data-bn-reg-field ', $source, "$file must not hand-tag controls" );
		}
	}
}
