<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * An advanced choice field saved under 1.2.4 keeps its choices through an edit.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Fields saved under 1.2.4 keep their list under the string key `choices`. The editor skipped
 * every string key, so the Options box rendered empty, and a label-only save then wrote the
 * blank box back as NULL: every choice gone, from one label edit.
 *
 * @covers \BuddyNext\Admin\Members\ProfileFieldsManager
 */
class AdvancedChoiceFieldSurvivesLabelEditTest extends WP_UnitTestCase {

	/**
	 * Stored shape: value/label pairs under `choices`, as 1.2.4 wrote them.
	 *
	 * @var array<string,mixed>
	 */
	private const LEGACY = array(
		'choices' => array(
			array(
				'value' => 'en',
				'label' => 'English',
			),
			array(
				'value' => 'hi',
				'label' => 'Hindi',
			),
		),
	);

	/**
	 * Fresh schema, an administrator, and the Pro-registered type this shape belongs to.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		add_filter(
			'buddynext_field_types',
			static function ( array $types ): array {
				$types['multi_select_advanced'] = array(
					'label'                 => 'Multi-select (advanced)',
					'value_kind'            => 'multi',
					'is_choice'             => true,
					'is_searchable_capable' => true,
				);
				return $types;
			}
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Seed a legacy-shaped field.
	 *
	 * @return int Field id.
	 */
	private function seed_field(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_groups',
			array(
				'group_key'  => 'about-you',
				'label'      => 'About you',
				'type'       => 'flat',
				'visibility' => 'public',
				'sort_order' => 0,
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'   => (int) $wpdb->insert_id,
				'field_key'  => 'languages',
				'label'      => 'Languages',
				'type'       => 'multi_select_advanced',
				'options'    => wp_json_encode( self::LEGACY ),
				'visibility' => 'public',
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $wpdb->insert_id;
	}

	/**
	 * Post the edit form the way a browser does, then read the stored options back.
	 *
	 * @param int    $field_id Field id.
	 * @param string $options  Value of the Options textarea.
	 * @return array<mixed>|null Decoded stored options.
	 */
	private function save_edit( int $field_id, string $options ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this IS the nonce; simulating the admin form post.
		$_POST    = array(
			'field_id'   => (string) $field_id,
			'label'      => 'Languages spoken',
			'type'       => 'multi_select_advanced',
			'visibility' => 'public',
			'options'    => $options,
			'_wpnonce'   => wp_create_nonce( 'bn_edit_profile_field_' . $field_id ),
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		try {
			( new \BuddyNext\Admin\Members\ProfileFieldsManager() )->handle_edit_field();
		} catch ( \Exception $e ) {
			unset( $e ); // wp_safe_redirect()+exit; the write already happened.
		}
		$_POST    = array();
		$_REQUEST = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT options FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $field_id ) );

		return json_decode( (string) $raw, true );
	}

	/**
	 * The Options box shows the stored choices, so the owner can see what members see.
	 *
	 * @return void
	 */
	public function test_the_options_box_is_filled_from_the_choices_list(): void {
		$render = new \ReflectionMethod( \BuddyNext\Admin\Members\ProfileFieldsManager::class, 'options_to_textarea' );
		$render->setAccessible( true );

		$this->assertSame( "en\nhi", $render->invoke( new \BuddyNext\Admin\Members\ProfileFieldsManager(), self::LEGACY ) );
	}

	/**
	 * THE BUG: a label-only save, with the box left as rendered, keeps the stored list as is.
	 *
	 * @return void
	 */
	public function test_a_label_only_save_keeps_the_stored_choices(): void {
		$field_id = $this->seed_field();

		$this->assertEquals( self::LEGACY, $this->save_edit( $field_id, "en\r\nhi" ), 'A label edit rewrote or wiped the choices.' );
	}

	/**
	 * Negative case: a real edit of the list wins, and the old `choices` key cannot shadow it.
	 *
	 * @return void
	 */
	public function test_an_edited_list_replaces_the_choices(): void {
		$field_id = $this->seed_field();

		$this->assertSame( array( 'en', 'hi', 'fr' ), $this->save_edit( $field_id, "en\r\nhi\r\nfr" ) );
	}
}
