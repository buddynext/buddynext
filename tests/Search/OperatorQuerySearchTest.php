<?php
/**
 * A hyphen, @ or quote in a member search is text, never a FULLTEXT operator.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Search\SearchService;

/**
 * @covers \BuddyNext\Search\SearchService
 */
class OperatorQuerySearchTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SearchService
	 */
	private SearchService $search;

	/**
	 * Members: handle alex-rivera (Alex Rivera), a name-different handle, and a near name.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Create the members and index them directly.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->search = new SearchService();
		global $wpdb;
		foreach ( array(
			'alex-rivera' => 'Alex Rivera',
			'jsmith'      => 'Johnathan Smithers',
			'alex-fisher' => 'Alex Fisher',
		) as $login => $name ) {
			$id = self::factory()->user->create(
				array(
					'user_login'    => $login,
					'user_nicename' => $login,
					'display_name'  => $name,
				)
			);
			$wpdb->replace(
				$wpdb->prefix . 'bn_search_index',
				array(
					'object_type'     => 'user',
					'object_id'       => $id,
					'title'           => $name,
					'content'         => '',
					'content_members' => '',
					'visibility'      => 'public',
				)
			);
			$this->ids[ $login ] = $id;
		}
	}

	public function test_a_hyphenated_handle_finds_its_member_first(): void {
		$ids = $this->search->match_member_ids( 'alex-rivera' );
		$this->assertSame( $this->ids['alex-rivera'], $ids[0] );
	}

	public function test_an_at_handle_is_not_an_operator_error(): void {
		$ids = $this->search->match_member_ids( '@alex-rivera' );
		$this->assertContains( $this->ids['alex-rivera'], $ids );
	}

	public function test_a_handle_that_is_not_in_the_name_still_finds_the_member(): void {
		$this->assertSame( array( $this->ids['jsmith'] ), $this->search->match_member_ids( '@jsmith' ) );
	}

	public function test_a_plain_name_search_still_finds_the_member(): void {
		$ids = $this->search->match_member_ids( 'alex rivera' );
		$this->assertContains( $this->ids['alex-rivera'], $ids );
	}

	public function test_unified_search_by_handle_lists_the_member_first(): void {
		$res = $this->search->search( 'alex-rivera', 'user', 10, 1, 0 );
		$this->assertSame( $this->ids['alex-rivera'], (int) $res['items'][0]['object_id'] );
	}
}
