<?php
/**
 * A Space's Location: a plain public text field, owner-written, folded into the Space search text.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Spaces\SpaceFieldRegistry;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Spaces\CoreSpaceFields
 */
class SpaceLocationFieldTest extends \WP_UnitTestCase {

	private int $owner;
	private int $member;
	private int $space;

	public function set_up(): void {
		parent::set_up();
		$this->owner  = self::factory()->user->create();
		$this->member = self::factory()->user->create();
		$this->space  = (int) ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Loc Space',
				'slug' => 'loc-' . strtolower( wp_generate_password( 6, false, false ) ),
				'type' => 'open',
			)
		);
		buddynext_service( 'space_members' )->join( $this->space, $this->member );
	}

	public function test_location_is_a_public_searchable_text_field(): void {
		$field = SpaceFieldRegistry::instance()->get_fields()['location'] ?? null;

		$this->assertNotNull( $field );
		$this->assertSame( 'text', $field['type'] );
		$this->assertSame( 'public', $field['visibility'] );
		$this->assertTrue( $field['searchable'] );
		$this->assertSame( 'owner', $field['writable_by'] );
	}

	public function test_the_owner_sets_it_and_the_space_search_text_carries_it(): void {
		$registry = SpaceFieldRegistry::instance();
		$result   = $registry->save_for_space( $this->space, array( 'location' => 'Bollington, Cheshire, UK' ), $this->owner );

		$this->assertEmpty( $result['errors'] );
		$this->assertSame( 'Bollington, Cheshire, UK', buddynext_get_space_field( $this->space, 'location' ) );
		$this->assertStringContainsString( 'Cheshire', $registry->searchable_public_text( $this->space ) );
	}

	public function test_a_member_who_is_not_the_owner_cannot_write_it(): void {
		$result = SpaceFieldRegistry::instance()->save_for_space( $this->space, array( 'location' => 'Elsewhere' ), $this->member );

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( '', (string) buddynext_get_space_field( $this->space, 'location' ) );
	}

	public function test_a_location_longer_than_100_characters_is_refused_on_every_write_path(): void {
		$registry = SpaceFieldRegistry::instance();

		$too_long = $registry->save_for_space( $this->space, array( 'location' => str_repeat( 'a', 101 ) ), $this->owner );
		$this->assertNotEmpty( $too_long['errors'] );
		$this->assertSame( '', (string) buddynext_get_space_field( $this->space, 'location' ) );

		$exact = $registry->save_for_space( $this->space, array( 'location' => str_repeat( 'a', 100 ) ), $this->owner );
		$this->assertEmpty( $exact['errors'] );
		$this->assertSame( 100, strlen( (string) buddynext_get_space_field( $this->space, 'location' ) ) );
	}
}
