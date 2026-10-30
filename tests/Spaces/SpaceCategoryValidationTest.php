<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A space's category is checked at the one door every save goes through.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceCategoryService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * Create and update stored any category id, including one that does not exist,
 * and the pickers disagreed on whether a hidden category could be chosen.
 */
class SpaceCategoryValidationTest extends WP_UnitTestCase {

	private SpaceService $spaces;

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->spaces = new SpaceService();
		$this->owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		delete_option( 'buddynext_space_default_category' );
	}

	private function category( string $name, bool $show_in_dir = true ): int {
		$made = ( new SpaceCategoryService() )->create(
			array(
				'name'        => $name,
				'show_in_dir' => $show_in_dir,
			)
		);
		return (int) ( is_array( $made ) ? ( $made['id'] ?? 0 ) : $made );
	}

	private function create_space( array $data ): int|\WP_Error {
		static $n = 0;
		++$n;
		return $this->spaces->create(
			$this->owner,
			$data + array(
				'name' => 'Category check ' . $n,
				'slug' => 'category-check-' . $n . '-' . wp_rand(),
				'type' => 'open',
			)
		);
	}

	private function stored_category( int $space_id ): ?int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT category_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id ) );
		return null === $value ? null : (int) $value;
	}

	public function test_create_refuses_a_missing_category(): void {
		$result = $this->create_space( array( 'category_id' => 987654 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'buddynext_invalid_category', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_create_without_a_category_takes_the_default_unless_it_was_deleted(): void {
		$default = $this->category( 'Default pick' );
		update_option( 'buddynext_space_default_category', $default );
		$id = $this->create_space( array() );
		$this->assertSame( $default, $this->stored_category( (int) $id ) );

		update_option( 'buddynext_space_default_category', 987654 );
		$id = $this->create_space( array() );
		$this->assertIsInt( $id, 'a deleted default category must not block creating a space' );
		$this->assertNull( $this->stored_category( $id ) );
	}

	public function test_update_refuses_a_missing_category_and_keeps_the_old_one(): void {
		$keep = $this->category( 'Keep me' );
		$id   = (int) $this->create_space( array( 'category_id' => $keep ) );

		$result = $this->spaces->update( $id, $this->owner, array( 'category_id' => 987654 ) );

		$this->assertWPError( $result );
		$this->assertSame( $keep, $this->stored_category( $id ) );
	}

	public function test_clearing_the_category_stores_null_not_zero(): void {
		$id = (int) $this->create_space( array( 'category_id' => $this->category( 'Clear me' ) ) );

		$this->spaces->update( $id, $this->owner, array( 'category_id' => 0 ) );

		$this->assertNull( $this->stored_category( $id ) );
	}

	public function test_a_hidden_category_is_pickable_everywhere(): void {
		$hidden = $this->category( 'Staff only', false );

		$this->assertContains( $hidden, array_map( static fn( object $c ): int => $c->id, $this->spaces->category_choices() ) );

		$id = $this->create_space( array( 'category_id' => $hidden ) );
		$this->assertIsInt( $id );
		$this->assertSame( $hidden, $this->stored_category( $id ) );
	}
}
