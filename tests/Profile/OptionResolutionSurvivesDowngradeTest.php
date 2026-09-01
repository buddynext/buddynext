<?php
/**
 * One resolver for a field's choice list, and it must outlive the add-on.
 *
 * A choice field stores SLUGS and shows LABELS. Free and Pro each resolved that
 * mapping themselves, and the two implementations disagreed about which storage
 * shapes exist:
 *
 *   Free  understood a flat `slug => label` map, and collapsed Pro's canonical
 *         `[{value,label}]` list to the single entry `{"array":"Array"}` —
 *         because each item is an ARRAY being cast to string.
 *   Pro   understood the `[{value,label}]` list, and for a flat map read the
 *         LABEL as the value, because `foreach` over `{"alpha":"Alpha"}` yields
 *         "Alpha" and throws the key away.
 *
 * Each was right for the shape it was written against and wrong for the other,
 * so whether a field worked depended on which screen had created it. On a field
 * defined as a flat map, submitting the slug the control emitted was rejected as
 * "not in the allowed list" — a form refusing its own output — and the value
 * that did save was the label.
 *
 * ## Why this is a downgrade test
 *
 * Free's suite does not load Pro, so `multi_select_advanced` is unregistered
 * here. That is exactly a site whose licence lapsed, and it is the state the
 * mapping has to keep working in: the owner's labels are what people read, and
 * that intent does not expire with a licence. Without the field-keyed
 * resolution, a profile that read "Alpha, Beta" starts reading "alpha, beta" —
 * the member's own answer, in the site's internal spelling.
 *
 * ## The data that already exists
 *
 * Rows written by the old resolver hold labels. Correcting the resolver alone
 * would strand them: the control would render nothing selected and the next save
 * would drop the member's choice — losing data because of a bug they never saw.
 * So reads accept either form, and a save normalises it. There is no migration
 * to run and nothing for an owner to do.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\FieldType;
use WP_UnitTestCase;

/**
 * Canonical option resolution across storage shapes and plugin states.
 *
 * @covers \BuddyNext\Profile\FieldType::option_pairs
 * @covers \BuddyNext\Profile\FieldType::canonical_option_value
 * @covers \BuddyNext\Profile\FieldType::option_labels_for
 */
class OptionResolutionSurvivesDowngradeTest extends WP_UnitTestCase {

	/**
	 * A field carrying its choice list in one of the shapes we accept.
	 *
	 * @param mixed  $options Options payload.
	 * @param string $type    Field type slug.
	 * @return array<string, mixed>
	 */
	private function field( $options, string $type = 'multiselect' ): array {
		return array(
			'field_key'  => 'qa_choice',
			'label'      => 'Choice',
			'type'       => $type,
			'visibility' => 'public',
			'options'    => $options,
		);
	}

	/**
	 * Every shape an owner's choice list can be stored in resolves identically.
	 *
	 * The `{value,label}` list is what the Profile Fields screen writes, so it is
	 * the shape most real fields are in — and it was the one Free could not read.
	 *
	 * @return void
	 */
	public function test_every_storage_shape_resolves_to_the_same_pairs(): void {
		$expected = array(
			'alpha' => 'Alpha',
			'beta'  => 'Beta',
		);

		$shapes = array(
			'flat slug => label map' => array(
				'alpha' => 'Alpha',
				'beta'  => 'Beta',
			),
			'plain list of labels'   => array( 'Alpha', 'Beta' ),
			'value/label list'       => array(
				array(
					'value' => 'alpha',
					'label' => 'Alpha',
				),
				array(
					'value' => 'beta',
					'label' => 'Beta',
				),
			),
			'wrapped in choices'     => array(
				'choices' => array(
					array(
						'value' => 'alpha',
						'label' => 'Alpha',
					),
					array(
						'value' => 'beta',
						'label' => 'Beta',
					),
				),
			),
		);

		foreach ( $shapes as $what => $options ) {
			$this->assertSame(
				$expected,
				FieldType::option_pairs( $this->field( $options ) ),
				sprintf( 'A choice list stored as a %s did not resolve to slug => label.', $what )
			);
		}
	}

	/**
	 * A value stored as a label is read as its slug.
	 *
	 * This is the data the old resolver wrote. Without this the member's choice
	 * renders unselected and the next save drops it.
	 *
	 * @return void
	 */
	public function test_a_value_stored_as_a_label_resolves_to_its_slug(): void {
		// The slug and the label must NOT be each other's slugified form, or this
		// test passes without ever reaching the label match: `sanitize_title(
		// 'Alpha' )` is already `alpha`, so an `alpha => Alpha` pair is resolved by
		// the slugify step alone. Written that way first, and deleting the label
		// match left it green - a test that could not fail for the thing it names.
		// Real option lists look like this one far more often than like alpha/beta.
		$field = $this->field(
			array(
				'eng' => 'Engineering',
				'ops' => 'Operations',
			)
		);

		$this->assertSame( 'eng', FieldType::canonical_option_value( $field, 'eng' ), 'A canonical slug must pass through unchanged.' );
		$this->assertSame(
			'eng',
			FieldType::canonical_option_value( $field, 'Engineering' ),
			'A value stored as a label was not healed back to its slug, so the member\'s choice renders unselected and the next save drops it.'
		);
		$this->assertSame( 'eng', FieldType::canonical_option_value( $field, 'ENGINEERING' ), 'Label matching must not be case-sensitive.' );
	}

	/**
	 * A value matching no option is left alone.
	 *
	 * Guards the guard: an aggressive resolver that forced every value onto some
	 * option would silently rewrite members' answers.
	 *
	 * @return void
	 */
	public function test_an_unrecognised_value_is_not_forced_onto_an_option(): void {
		$field = $this->field(
			array(
				'alpha' => 'Alpha',
				'beta'  => 'Beta',
			)
		);

		$this->assertSame( 'gamma', FieldType::canonical_option_value( $field, 'gamma' ) );
	}

	/**
	 * With the type unregistered, the profile still shows the owner's labels.
	 *
	 * The downgrade case. `multi_select_advanced` is not registered in this suite,
	 * so this is the real code path a lapsed-licence site runs.
	 *
	 * @return void
	 */
	public function test_labels_survive_the_type_being_unregistered(): void {
		$this->assertFalse(
			FieldType::is_registered_type( 'multi_select_advanced' ),
			'Fixture: this test is about an unregistered type. If Pro is loaded here it proves nothing.'
		);

		$field = $this->field(
			array(
				'choices' => array(
					array(
						'value' => 'alpha',
						'label' => 'Alpha',
					),
					array(
						'value' => 'beta',
						'label' => 'Beta',
					),
				),
			),
			'multi_select_advanced'
		);

		$this->assertSame(
			'Alpha, Beta',
			FieldType::display_text( $field, 'alpha,beta' ),
			'The profile is showing slugs - the member\'s answer in the site\'s internal spelling.'
		);
		$this->assertStringContainsString(
			'Alpha, Beta',
			FieldType::render_display( $field, 'alpha,beta' ),
			'The rendered profile row is showing slugs.'
		);
		$this->assertSame(
			'Alpha, Beta',
			FieldType::searchable_text( $field, 'alpha,beta' ),
			'The search mirror is indexing slugs, so a member who picked "Alpha" is only findable by searching "alpha".'
		);
	}

	/**
	 * Free text is never quietly relabelled.
	 *
	 * `option_labels_for()` returns null unless the field HAS a choice list and
	 * EVERY part of the value resolves. Without both halves, a plain text field
	 * whose value happened to match an option label elsewhere would be rewritten.
	 *
	 * @return void
	 */
	public function test_a_value_is_only_relabelled_when_it_is_wholly_a_choice(): void {
		$no_options = array(
			'field_key' => 'qa_free',
			'label'     => 'Free text',
			'type'      => 'text',
		);
		$this->assertNull(
			FieldType::option_labels_for( $no_options, 'Alpha' ),
			'A field with no choice list must never relabel its value.'
		);

		$field = $this->field(
			array(
				'alpha' => 'Alpha',
				'beta'  => 'Beta',
			)
		);
		$this->assertNull(
			FieldType::option_labels_for( $field, 'alpha,gamma' ),
			'A value holding an option that no longer exists must be left alone rather than half-translated.'
		);
		$this->assertSame(
			'Alpha, Beta',
			FieldType::option_labels_for( $field, 'alpha,beta' ),
			'A wholly resolvable value should resolve.'
		);
	}

	/**
	 * An empty value stays empty.
	 *
	 * @return void
	 */
	public function test_an_empty_value_resolves_to_nothing(): void {
		$field = $this->field(
			array(
				'alpha' => 'Alpha',
			)
		);

		$this->assertSame( '', FieldType::canonical_option_value( $field, '' ) );
		$this->assertNull( FieldType::option_labels_for( $field, '' ) );
	}
}
