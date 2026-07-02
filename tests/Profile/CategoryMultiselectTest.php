<?php
/**
 * Tests for the category_multiselect field type (live options, one
 * bn_profile_values row per pick).
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\FieldType;
use BuddyNext\Profile\ProfileService;
use BuddyNext\Spaces\SpaceCategoryService;

/**
 * End-to-end behaviour of the category_multiselect field type.
 *
 * @covers \BuddyNext\Profile\FieldType
 * @covers \BuddyNext\Profile\ProfileService::save_profile
 */
class CategoryMultiselectTest extends \WP_UnitTestCase {

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
	 * The category_multiselect field under test.
	 *
	 * @var int
	 */
	private int $field_id;

	/**
	 * Seeded category IDs keyed by name.
	 *
	 * @var array<string,int>
	 */
	private array $cats = array();

	/**
	 * Create schema, categories, and the field under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service = new ProfileService();
		$this->user_id = self::factory()->user->create();

		$categories = new SpaceCategoryService();
		foreach ( array( 'Chess', 'Cooking', 'Cycling' ) as $name ) {
			$id = $categories->create( array( 'name' => $name ) );
			if ( ! is_wp_error( $id ) ) {
				$this->cats[ $name ] = (int) $id;
			} else {
				// Slug conflict from a starter seed: resolve the existing row.
				global $wpdb;
				$this->cats[ $name ] = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}bn_space_categories WHERE slug = %s",
						sanitize_title( $name )
					)
				);
			}
		}

		$this->field_id = (int) $this->service->create_field(
			array(
				'field_key'     => 'test_interests',
				'label'         => 'Test Interests',
				'type'          => 'category_multiselect',
				'visibility'    => 'public',
				'is_searchable' => 1,
				'group_name'    => 'general',
				'sort_order'    => 1,
			)
		);
	}

	/**
	 * Return this user's stored value rows for the test field, ordered by entry.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function value_rows(): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_index, value FROM {$wpdb->prefix}bn_profile_values
				 WHERE user_id = %d AND field_id = %d ORDER BY entry_index ASC",
				$this->user_id,
				$this->field_id
			),
			ARRAY_A
		);
	}

	/**
	 * Each pick lands in its own bn_profile_values row (entry_index 0..n).
	 *
	 * @return void
	 */
	public function test_save_stores_one_row_per_pick(): void {
		$picks  = array( $this->cats['Chess'], $this->cats['Cycling'] );
		$result = $this->service->save_profile( $this->user_id, array( 'test_interests' => $picks ) );

		$this->assertTrue( $result );

		$rows = $this->value_rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( '0', (string) $rows[0]['entry_index'] );
		$this->assertSame( (string) $this->cats['Chess'], $rows[0]['value'] );
		$this->assertSame( '1', (string) $rows[1]['entry_index'] );
		$this->assertSame( (string) $this->cats['Cycling'], $rows[1]['value'] );
	}

	/**
	 * A smaller re-save deletes the surplus rows from the previous save.
	 *
	 * @return void
	 */
	public function test_resave_smaller_selection_prunes_surplus_rows(): void {
		$this->service->save_profile(
			$this->user_id,
			array( 'test_interests' => array_values( $this->cats ) )
		);
		$this->assertCount( 3, $this->value_rows() );

		$this->service->save_profile(
			$this->user_id,
			array( 'test_interests' => array( $this->cats['Cooking'] ) )
		);

		$rows = $this->value_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $this->cats['Cooking'], $rows[0]['value'] );
	}

	/**
	 * Clearing the selection removes every stored row.
	 *
	 * @return void
	 */
	public function test_empty_selection_clears_all_rows(): void {
		$this->service->save_profile(
			$this->user_id,
			array( 'test_interests' => array( $this->cats['Chess'] ) )
		);
		$this->assertCount( 1, $this->value_rows() );

		$this->service->save_profile( $this->user_id, array( 'test_interests' => array() ) );

		$this->assertCount( 0, $this->value_rows() );
	}

	/**
	 * IDs outside the live taxonomy are silently dropped on save.
	 *
	 * @return void
	 */
	public function test_unknown_and_invalid_ids_are_dropped(): void {
		$this->service->save_profile(
			$this->user_id,
			array( 'test_interests' => array( $this->cats['Chess'], 999999, 'nonsense', -3 ) )
		);

		$rows = $this->value_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $this->cats['Chess'], $rows[0]['value'] );
	}

	/**
	 * The profile read path re-aggregates per-pick rows into an ordered array.
	 *
	 * @return void
	 */
	public function test_get_profile_aggregates_picks_into_array_value(): void {
		$picks = array( $this->cats['Cooking'], $this->cats['Chess'] );
		$this->service->save_profile( $this->user_id, array( 'test_interests' => $picks ) );

		$profile = $this->service->get_profile( $this->user_id, $this->user_id );
		$values  = array_column( (array) $profile['fields'], 'value', 'field_key' );

		$this->assertSame(
			array( (string) $this->cats['Cooking'], (string) $this->cats['Chess'] ),
			$values['test_interests']
		);
	}

	/**
	 * The bn_field_{key} search mirror carries names, never raw IDs.
	 *
	 * @return void
	 */
	public function test_search_mirror_carries_category_names(): void {
		$this->service->save_profile(
			$this->user_id,
			array( 'test_interests' => array( $this->cats['Chess'], $this->cats['Cycling'] ) )
		);

		$mirror = (string) get_user_meta( $this->user_id, 'bn_field_test_interests', true );

		$this->assertStringContainsString( 'Chess', $mirror );
		$this->assertStringContainsString( 'Cycling', $mirror );
		$this->assertStringNotContainsString( (string) $this->cats['Chess'], $mirror );
	}

	/**
	 * REST payload shape: an array of integer category IDs.
	 *
	 * @return void
	 */
	public function test_rest_value_returns_int_id_array(): void {
		$field = array( 'type' => 'category_multiselect' );
		$value = FieldType::rest_value( $field, implode( ',', array( $this->cats['Chess'], $this->cats['Cooking'] ) ) );

		$this->assertSame( array( $this->cats['Chess'], $this->cats['Cooking'] ), $value );
	}

	/**
	 * Every renderer degrades gracefully when the taxonomy is empty.
	 *
	 * @return void
	 */
	public function test_renderers_degrade_to_nothing_without_categories(): void {
		global $wpdb;

		// Simulate an owner with zero categories: empty the taxonomy and bust
		// the service cache so options resolve to an empty list.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_space_categories" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_flush();

		$field = array(
			'field_key' => 'test_interests',
			'label'     => 'Test Interests',
			'type'      => 'category_multiselect',
		);

		$input = FieldType::render_input( $field, '', 'test_interests' );
		$this->assertStringNotContainsString( '<input', $input );
		$this->assertStringContainsString( 'No categories are available yet.', $input );

		// Stored picks whose categories were deleted resolve to nothing — never
		// to raw-ID chips or errors.
		$this->assertSame( '', FieldType::render_display( $field, '1,2' ) );
		$this->assertSame( '', FieldType::searchable_text( $field, '1,2' ) );
		$this->assertSame( '', FieldType::sanitize( $field, array( 1, 2 ) ) );
	}
}
