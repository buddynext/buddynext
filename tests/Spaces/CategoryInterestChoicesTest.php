<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Interests offer only the categories a member may pick.
 *
 * @package BuddyNext\Tests\Spaces
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Onboarding\OnboardingService;
use BuddyNext\Profile\FieldType;
use BuddyNext\Spaces\SpaceCategoryService;
use WP_UnitTestCase;

/**
 * Onboarding and the profile Interests field used every category, so a category
 * the owner hid from the directory, or set to "Subspaces only", still turned up
 * as an interest chip that led nowhere.
 *
 * @covers \BuddyNext\Spaces\SpaceCategoryService::get_for_members
 * @covers \BuddyNext\Profile\FieldType::category_options
 * @covers \BuddyNext\Onboarding\OnboardingService::step_list
 */
class CategoryInterestChoicesTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, no categories.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_space_categories" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_flush();
	}

	/**
	 * Create a category and return its id.
	 *
	 * @param string $name        Name.
	 * @param bool   $show_in_dir Shown in the directory.
	 * @param string $level       both|main|sub.
	 * @return int
	 */
	private function category( string $name, bool $show_in_dir, string $level = 'both' ): int {
		$id = ( new SpaceCategoryService() )->create(
			array(
				'name'        => $name,
				'show_in_dir' => $show_in_dir,
				'level'       => $level,
			)
		);
		return (int) $id;
	}

	/**
	 * Whether the onboarding wizard has an Interests step.
	 *
	 * @return bool
	 */
	private function has_interests_step(): bool {
		return in_array( 'interests', array_column( ( new OnboardingService() )->step_list(), 'key' ), true );
	}

	/**
	 * Hidden and subspace-only categories are not offered; labels still resolve.
	 */
	public function test_only_member_facing_categories_are_offered(): void {
		$open   = $this->category( 'Hiking', true );
		$main   = $this->category( 'Clubs', true, 'main' );
		$hidden = $this->category( 'Staff only', false );
		$sub    = $this->category( 'Club events', true, 'sub' );

		$offered = array_map( 'intval', array_keys( FieldType::category_options( true ) ) );
		sort( $offered );
		$this->assertSame( array( $open, $main ), $offered );

		$all = array_map( 'intval', array_keys( FieldType::category_options() ) );
		foreach ( array( $open, $main, $hidden, $sub ) as $id ) {
			$this->assertContains( $id, $all, 'labels and saved values still resolve every category' );
		}
	}

	/**
	 * With nothing a member may pick, the onboarding Interests step is skipped.
	 */
	public function test_interests_step_needs_an_offerable_category(): void {
		$this->category( 'Staff only', false );
		$this->category( 'Club events', true, 'sub' );
		$this->assertFalse( $this->has_interests_step(), 'hidden and subspace-only categories do not make an Interests step' );

		$this->category( 'Hiking', true );
		$this->assertTrue( $this->has_interests_step() );
	}

	/**
	 * The profile Interests control offers member-facing categories and keeps a member's earlier hidden pick.
	 */
	public function test_profile_control_keeps_an_earlier_pick_of_a_hidden_category(): void {
		$open   = $this->category( 'Hiking', true );
		$hidden = $this->category( 'Staff only', false );
		$sub    = $this->category( 'Club events', true, 'sub' );
		$field  = array(
			'field_key' => 'interests',
			'type'      => 'category_multiselect',
			'label'     => 'Interests',
		);

		$fresh = FieldType::render_input( $field, '', 'interests' );
		$this->assertStringContainsString( 'value="' . $open . '"', $fresh );
		$this->assertStringNotContainsString( 'value="' . $hidden . '"', $fresh );
		$this->assertStringNotContainsString( 'value="' . $sub . '"', $fresh );

		$held = FieldType::render_input( $field, (string) $hidden, 'interests' );
		$this->assertMatchesRegularExpression( '/value="' . $hidden . '"[^>]*checked/', $held, 'an earlier pick stays checked, so saving does not drop it' );
	}
}
