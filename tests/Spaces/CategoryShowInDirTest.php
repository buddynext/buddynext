<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A category the owner switched off must actually leave the directory.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceCategoryService;
use WP_UnitTestCase;

/**
 * show_in_dir saved, persisted, and did nothing.
 *
 * Every listing read SELECT * with no WHERE, so a category the owner had switched off still
 * appeared in the directory chips and in the public REST list. Another option that lies.
 *
 * The admin must still see it - you cannot manage a category you cannot see - so this is not a
 * blanket WHERE. These tests pin BOTH halves, because "fixing" it by hiding the category from
 * the admin too would be a worse bug than the one we started with.
 *
 * @covers \BuddyNext\Spaces\SpaceCategoryService
 */
class CategoryShowInDirTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Ids in a category list.
	 *
	 * @param array<int, array<string, mixed>> $rows Category rows.
	 * @return array<int, int>
	 */
	private function ids( array $rows ): array {
		return array_map( static fn( array $c ): int => (int) $c['id'], $rows );
	}

	/**
	 * A hidden category leaves the member-facing list and stays in the admin one.
	 *
	 * @return void
	 */
	public function test_a_hidden_category_leaves_the_directory_but_not_the_admin(): void {
		$service = new SpaceCategoryService();

		$shown  = $service->create(
			array(
				'name'        => 'Shown',
				'show_in_dir' => true,
			)
		);
		$hidden = $service->create(
			array(
				'name'        => 'Hidden',
				'show_in_dir' => false,
			)
		);

		$shown_id  = (int) ( is_array( $shown ) ? ( $shown['id'] ?? 0 ) : $shown );
		$hidden_id = (int) ( is_array( $hidden ) ? ( $hidden['id'] ?? 0 ) : $hidden );

		$member_facing = $this->ids( $service->get_all( true ) );
		$admin_facing  = $this->ids( $service->get_all( false ) );

		$this->assertContains( $shown_id, $member_facing, 'A visible category is missing from the directory.' );
		$this->assertNotContains(
			$hidden_id,
			$member_facing,
			'A category the owner switched OFF is still listed to members. show_in_dir saved and did nothing.'
		);

		$this->assertContains(
			$hidden_id,
			$admin_facing,
			'The hidden category vanished from the ADMIN list too. An owner cannot manage a category they cannot see - that is a worse bug than the one being fixed.'
		);
	}

	/**
	 * The counts listing honours it too - that is what the directory chip row actually reads.
	 *
	 * @return void
	 */
	public function test_the_counts_listing_honours_the_toggle(): void {
		$service = new SpaceCategoryService();

		$hidden    = $service->create(
			array(
				'name'        => 'Hidden With Counts',
				'show_in_dir' => false,
			)
		);
		$hidden_id = (int) ( is_array( $hidden ) ? ( $hidden['id'] ?? 0 ) : $hidden );

		$this->assertNotContains(
			$hidden_id,
			$this->ids( $service->get_all_with_counts( true ) ),
			'The directory chip row reads the counts listing, and it still shows the hidden category.'
		);
		$this->assertContains(
			$hidden_id,
			$this->ids( $service->get_all_with_counts( false ) ),
			'The admin counts listing must still show every category.'
		);
	}

	/**
	 * Switching the toggle takes effect immediately - the two lists are separately cached.
	 *
	 * The member-facing list has its own cache key. Miss it in the flush and the category
	 * lingers in the directory until the TTL expires, so the toggle still looks broken.
	 *
	 * @return void
	 */
	public function test_toggling_takes_effect_immediately(): void {
		$service = new SpaceCategoryService();

		$cat = $service->create(
			array(
				'name'        => 'Toggle Me',
				'show_in_dir' => true,
			)
		);
		$id  = (int) ( is_array( $cat ) ? ( $cat['id'] ?? 0 ) : $cat );

		// Prime the member-facing cache.
		$this->assertContains( $id, $this->ids( $service->get_all( true ) ) );

		$service->update( $id, array( 'show_in_dir' => false ) );

		$this->assertNotContains(
			$id,
			$this->ids( $service->get_all( true ) ),
			'Switching show_in_dir OFF did not take effect - the member-facing list is served from a cache key the flush does not clear.'
		);

		$service->update( $id, array( 'show_in_dir' => true ) );

		$this->assertContains(
			$id,
			$this->ids( $service->get_all( true ) ),
			'Switching it back ON did not bring the category back.'
		);
	}
}
