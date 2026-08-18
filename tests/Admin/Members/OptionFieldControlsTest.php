<?php
/**
 * The admin member editor must render option fields with their saved value.
 *
 * It hand-rolled the select, multiselect and radio controls and printed the option
 * LABEL as each option's value:
 *
 *     <option value="<?php echo esc_attr( (string) $opt ); ?>" ...>
 *
 * Values are stored as SLUGS, so `selected( 'leo', 'Leo' )` never matched and every
 * option control on every member rendered EMPTY, whatever they had saved. FieldType
 * has always keyed its options slug => label — that is why display_text() can look up
 * $options[ sanitize_title( $value ) ] — and the member-facing editor, which renders
 * through FieldType::render_input(), round-trips correctly. The admin editor was the
 * only surface with a second implementation, and it was keyed wrong.
 *
 * Two consequences, both verified in the browser before the fix:
 *
 *   - DATA LOSS. An empty select posts an empty string, and the save handler writes
 *     any key that is set, so opening a member and pressing Save without touching
 *     anything destroyed the stored value — behind a green "Profile updated
 *     successfully." (Radio and multiselect survived: an empty one posts no key at
 *     all, so isset() skips it. The select is the one that loses data.)
 *   - UNSAVEABLE MEMBERS. A REQUIRED option field renders empty, fails validation,
 *     and because save_profile() is atomic the entire edit is rejected — with no way
 *     out, since re-picking stores a slug the next render again cannot match.
 *
 * @package BuddyNext\Tests\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Members;

use BuddyNext\Admin\Members\MemberEditForm;

/**
 * Option controls in the admin member editor.
 *
 * @covers \BuddyNext\Admin\Members\MemberEditForm::render_flat_field_input
 */
class OptionFieldControlsTest extends \WP_UnitTestCase {

	/**
	 * Render one field through the editor's private field renderer.
	 *
	 * Reflection because the method is private and the public entry point wants
	 * $_GET, a real user and a full page render. The unit under test is the control
	 * markup, so this reaches it directly.
	 *
	 * @param string       $type    Field type.
	 * @param array<int,string> $options Option labels, as stored.
	 * @param mixed        $value   Stored value.
	 * @return string Rendered HTML.
	 */
	private function render_field( string $type, array $options, $value ): string {
		$form   = new MemberEditForm();
		$method = new \ReflectionMethod( MemberEditForm::class, 'render_flat_field_input' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke(
			$form,
			array(
				'field_key' => 'qa_pick',
				'label'     => 'Pick',
				'type'      => $type,
				'options'   => $options,
				'value'     => $value,
			)
		);

		return (string) ob_get_clean();
	}

	// ── The complaint ────────────────────────────────────────────────────────────

	/**
	 * A select carries slug values and marks the saved one selected.
	 *
	 * @return void
	 */
	public function test_a_select_renders_slug_values_and_selects_the_saved_one(): void {
		$html = $this->render_field( 'select', array( 'Leo', 'Virgo', 'Aries' ), 'leo' );

		$this->assertStringContainsString(
			'value="leo"',
			$html,
			'Option values must be the stored SLUG. Printing the label means the posted value is a '
			. 'label, and the stored slug can never match it.'
		);
		$this->assertMatchesRegularExpression(
			'/value="leo"[^>]*selected/',
			$html,
			'The member has "leo" saved, so that option must render selected. It rendered empty, which '
			. 'is how an untouched Save destroyed the value.'
		);
	}

	/**
	 * A radio group checks the saved option.
	 *
	 * @return void
	 */
	public function test_a_radio_group_checks_the_saved_option(): void {
		$html = $this->render_field( 'radio', array( 'Long-term partner', 'Friends' ), 'long-term-partner' );

		$this->assertMatchesRegularExpression(
			'/value="long-term-partner"[^>]*checked/',
			$html,
			'the saved radio option must render checked'
		);
	}

	/**
	 * A multiselect checks every saved option.
	 *
	 * @return void
	 */
	public function test_a_multiselect_checks_every_saved_option(): void {
		$html = $this->render_field( 'multiselect', array( 'Women', 'Men', 'Non-binary' ), 'women,non-binary' );

		$this->assertMatchesRegularExpression( '/value="women"[^>]*checked/', $html, 'saved option must be checked' );
		$this->assertMatchesRegularExpression( '/value="non-binary"[^>]*checked/', $html, 'saved option must be checked' );
		$this->assertDoesNotMatchRegularExpression( '/value="men"[^>]*checked/', $html, 'an unsaved option must not be checked' );
	}

	/**
	 * The multiselect posts under `key[]`, not `key[][]`.
	 *
	 * Caught in the browser while fixing this: render_multiselect_input() appends its
	 * own `[]` to the name it is handed, so passing "key[]" produced "key[][]". The
	 * boxes rendered and checked correctly and it looked right — the save posted a
	 * nested array the handler could not read. A rendering-only assertion would have
	 * missed it, which is why the NAME is pinned separately from the values.
	 *
	 * @return void
	 */
	public function test_a_multiselect_posts_under_a_single_array_suffix(): void {
		$html = $this->render_field( 'multiselect', array( 'Women', 'Men' ), 'women' );

		$this->assertStringContainsString( 'name="qa_pick[]"', $html, 'multiselect must post as qa_pick[]' );
		$this->assertStringNotContainsString(
			'name="qa_pick[][]"',
			$html,
			'A doubled array suffix posts a nested array the save handler cannot read, so every '
			. 'multiselect edit is silently dropped.'
		);
	}

	// ── The value that makes the label lookup necessary ──────────────────────────

	/**
	 * The visible text stays the human label.
	 *
	 * Guards against "fixing" the values by printing slugs everywhere, which would
	 * show an admin "long-term-partner" instead of "Long-term partner".
	 *
	 * @return void
	 */
	public function test_the_visible_text_is_still_the_human_label(): void {
		$html = $this->render_field( 'select', array( 'Long-term partner' ), 'long-term-partner' );

		$this->assertStringContainsString(
			'>Long-term partner<',
			$html,
			'the option text a human reads must stay the label'
		);
	}
}
