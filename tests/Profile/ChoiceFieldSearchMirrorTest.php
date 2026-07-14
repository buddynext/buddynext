<?php
/**
 * Tests that choice fields mirror their option LABEL into search, never the slug.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * Regression cover for the select/radio search mirror.
 *
 * The mirror used to record a choice value's sanitize_title() SLUG rather than its
 * option LABEL. A slug is not a harmless variation on its label — sanitize_title()
 * runs remove_accents(), which is lossy AND locale-dependent:
 *
 *   - de_DE: 'Flügelhorn' slugs to 'fluegelhorn' (ü -> ue), while the search term
 *     'Flügelhorn' collates to 'flugelhorn' (ü -> u) under utf8mb4_*_ci. The two can
 *     never meet, so the member was permanently unfindable. On en_US the same code is
 *     harmless (ü -> u on BOTH sides) — which is precisely why this shipped, and why
 *     it was first reported as "cannot reproduce".
 *   - any locale: 'French Horn' slugs to 'french-horn', so every multi-word label was
 *     unfindable everywhere.
 *
 * These assert the mirror's CONTENT. A test that only asserted "a mirror row exists"
 * would have passed throughout the entire life of the bug.
 *
 * @covers \BuddyNext\Profile\ProfileService::save_profile
 * @covers \BuddyNext\Profile\FieldType::searchable_text
 */
class ChoiceFieldSearchMirrorTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ProfileService
	 */
	private ProfileService $service;

	/**
	 * Test member.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * The select field under test.
	 *
	 * @var int
	 */
	private int $field_id;

	/**
	 * Create the schema and a searchable select field with a lossy-slug option set.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service = new ProfileService();
		$this->user_id = self::factory()->user->create();

		$this->field_id = (int) $this->service->create_field(
			array(
				'field_key'     => 'test_instrument',
				'label'         => 'Instrument',
				'type'          => 'select',
				'options'       => array( 'Flügelhorn', 'Trompete', 'French Horn' ),
				'visibility'    => 'public',
				'is_searchable' => 1,
				'group_name'    => 'general',
				'sort_order'    => 1,
			)
		);
	}

	/**
	 * The public search mirror usermeta for the field under test.
	 *
	 * @return string
	 */
	private function mirror(): string {
		return (string) get_user_meta( $this->user_id, 'bn_field_test_instrument', true );
	}

	/**
	 * A value carrying an umlaut mirrors the LABEL, not the accent-stripped slug.
	 *
	 * @return void
	 */
	public function test_umlaut_option_mirrors_the_label_not_the_slug(): void {
		$this->service->save_profile( $this->user_id, array( 'test_instrument' => 'Flügelhorn' ) );

		$this->assertSame(
			'Flügelhorn',
			$this->mirror(),
			'A choice field must mirror its option LABEL. Mirroring the slug made German members unfindable.'
		);
		$this->assertNotSame( 'flugelhorn', $this->mirror(), 'The mirror must not be the en_US slug.' );
		$this->assertNotSame( 'fluegelhorn', $this->mirror(), 'The mirror must not be the de_DE slug.' );
	}

	/**
	 * The umlaut case is locale-dependent, so pin it on a German locale too — that is
	 * the configuration the bug was reported from and the one en_US cannot reproduce.
	 *
	 * @return void
	 */
	public function test_umlaut_option_mirrors_the_label_on_a_german_locale(): void {
		$de = static fn(): string => 'de_DE';
		add_filter( 'locale', $de, 99 );

		$this->service->save_profile( $this->user_id, array( 'test_instrument' => 'Flügelhorn' ) );
		$mirror = $this->mirror();

		remove_filter( 'locale', $de, 99 );

		$this->assertSame(
			'Flügelhorn',
			$mirror,
			'On de_DE, remove_accents() expands ü to "ue" — mirroring the slug stored "fluegelhorn", which no search term can ever match.'
		);
	}

	/**
	 * A multi-word ASCII label breaks on EVERY locale, not just German — the slug
	 * hyphenates it. This is the half of the bug nobody reported.
	 *
	 * @return void
	 */
	public function test_multi_word_option_mirrors_the_label_not_the_hyphenated_slug(): void {
		$this->service->save_profile( $this->user_id, array( 'test_instrument' => 'French Horn' ) );

		$this->assertSame( 'French Horn', $this->mirror() );
		$this->assertNotSame( 'french-horn', $this->mirror(), 'A hyphenated slug never matches the words a member types.' );
	}

	/**
	 * A plain-ASCII single-word label is the case that always worked. It must keep
	 * working — it is the control that proves the fix did not simply invert the bug.
	 *
	 * @return void
	 */
	public function test_plain_ascii_option_still_mirrors_the_label(): void {
		$this->service->save_profile( $this->user_id, array( 'test_instrument' => 'Trompete' ) );

		$this->assertSame( 'Trompete', $this->mirror() );
	}

	/**
	 * The canonical STORED value stays the slug — only the mirror carries the label.
	 * The slug is the option's machine key and other code resolves options by it.
	 *
	 * @return void
	 */
	public function test_stored_value_remains_the_option_slug(): void {
		global $wpdb;

		$this->service->save_profile( $this->user_id, array( 'test_instrument' => 'French Horn' ) );

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}bn_profile_values WHERE field_id = %d AND user_id = %d",
				$this->field_id,
				$this->user_id
			)
		);

		$this->assertSame(
			'french-horn',
			$stored,
			'Storage is unchanged: the slug remains the machine key. Only the SEARCH MIRROR became the label.'
		);
	}
}
