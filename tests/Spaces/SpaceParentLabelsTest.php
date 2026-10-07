<?php
/**
 * "in {parent}" labels for sub-spaces.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * A sub-space names its parent on My spaces, the profile and the directory
 * (card 10370685295), but never a hidden parent the viewer may not know exists.
 */
class SpaceParentLabelsTest extends WP_UnitTestCase {

	/**
	 * Create a space and return its id.
	 *
	 * @param int    $owner     Owner.
	 * @param string $type      Space type.
	 * @param int    $parent_id Parent space id, 0 for none.
	 * @return int
	 */
	private function space( int $owner, string $type, int $parent_id = 0 ): int {
		$data = array(
			'name' => ucfirst( $type ) . ' ' . wp_rand( 1000, 9999 ),
			'slug' => $type . '-' . wp_rand( 100000, 999999 ),
			'type' => $type,
		);
		if ( $parent_id > 0 ) {
			$data['parent_id'] = $parent_id;
		}
		$id = buddynext_service( 'spaces' )->create( $owner, $data );
		$this->assertIsInt( $id );
		return (int) $id;
	}

	/**
	 * Listed parents are named; a hidden parent only for its members and admins.
	 *
	 * @return void
	 */
	public function test_labels_follow_the_parent_visibility(): void {
		Installer::run();
		$owner    = self::factory()->user->create();
		$stranger = self::factory()->user->create();
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$open   = $this->space( $owner, 'open' );
		$secret = $this->space( $owner, 'secret' );
		$labels = buddynext_service( 'spaces' )->parent_labels( array( $open, $secret, 0, $open ), $stranger );

		$this->assertArrayHasKey( $open, $labels, 'A listed parent is named for anyone.' );
		$this->assertStringContainsString( '/spaces/', $labels[ $open ]['url'] );
		$this->assertArrayNotHasKey( $secret, $labels, 'A hidden parent is not revealed to a non-member.' );
		$this->assertArrayHasKey( $secret, buddynext_service( 'spaces' )->parent_labels( array( $secret ), $owner ), 'Its members see it.' );
		$this->assertArrayHasKey( $secret, buddynext_service( 'spaces' )->parent_labels( array( $secret ), $admin ) );
		$this->assertSame( array(), buddynext_service( 'spaces' )->parent_labels( array( 0, null ), $owner ) );
	}

	/**
	 * Membership rows carry parent_id, and the REST item names the parent.
	 *
	 * @return void
	 */
	public function test_membership_rows_and_rest_item_carry_the_parent(): void {
		Installer::run();
		$owner  = self::factory()->user->create();
		$parent = $this->space( $owner, 'open' );
		$child  = $this->space( $owner, 'open', $parent );

		$rows = buddynext_service( 'space_members' )->membership_rows( $owner, 10 );
		$byid = array_column( $rows, 'parent_id', 'id' );
		$this->assertSame( $parent, (int) $byid[ $child ] );
		$this->assertEmpty( $byid[ $parent ] );

		wp_set_current_user( $owner );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/buddynext/v1/users/' . $owner . '/spaces' ) );
		$items    = array_column( (array) $response->get_data(), null, 'id' );
		$this->assertSame( $parent, $items[ $child ]['parent']['id'] );
		$this->assertNull( $items[ $parent ]['parent'] );
	}
}
