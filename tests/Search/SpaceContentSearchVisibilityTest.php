<?php
/**
 * Content inside a private or secret Space is not searchable by outsiders.
 *
 * A post carries its own `privacy` column, and the indexer decided the search
 * row's visibility from that column alone. So a post written as PUBLIC inside a
 * PRIVATE space was indexed with `visibility = 'public'` — and the guest gate in
 * SearchService is literally `si.visibility = 'public'`, so anonymous visitors
 * got the title and body back in full. The `space_id` was stored correctly the
 * whole time, which is what made it look safe: the member clause
 * (`si.space_id IN (viewer's spaces)`) only ever ADDS access, so nothing
 * downstream could take back what the indexer had already granted.
 *
 * Three call sites each decided visibility for themselves and all three got it
 * wrong the same way, which is why the rule now lives at the single write door,
 * SearchService::index(), rather than at any of them:
 *
 *  - SearchIndexListener::async_index_post()   — the single-post path
 *  - SearchIndexListener's full-reindex loop   — held a byte-identical copy of
 *    the broken line, so a reindex re-published everything the first fix demoted
 *  - JetonomyBridge::on_post_created()         — passed the literal 'public'
 *
 * The tests below assert the DOOR, not the callers: that is the only assertion
 * that a fourth caller cannot walk past.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Search\SearchService;

/**
 * The space ceiling on indexed visibility.
 *
 * @covers \BuddyNext\Search\SearchService::index
 */
class SpaceContentSearchVisibilityTest extends \WP_UnitTestCase {

	/**
	 * Search service under test.
	 *
	 * @var SearchService
	 */
	private $search;

	/**
	 * Space id per type, keyed by type slug.
	 *
	 * @var array<string, int>
	 */
	private $spaces = array();

	/**
	 * Author of the indexed content.
	 *
	 * @var int
	 */
	private $author = 0;

	/**
	 * Create the schema, one space of each type, and an author.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->search = new SearchService();
		$this->author = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$spaces = buddynext_service( 'spaces' );

		foreach ( array( 'open', 'private', 'secret' ) as $type ) {
			$space_id = $spaces->create(
				$this->author,
				array(
					'name' => 'QA ' . $type . ' space',
					'slug' => 'qa-' . $type . '-space',
					'type' => $type,
				)
			);
			$this->assertIsInt( $space_id, 'Could not create the ' . $type . ' fixture space.' );
			$this->spaces[ $type ] = (int) $space_id;
		}
	}

	/**
	 * Read back the stored visibility for an indexed row.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	private function stored_visibility( string $object_type, int $object_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT visibility FROM {$wpdb->prefix}bn_search_index WHERE object_type = %s AND object_id = %d",
				$object_type,
				$object_id
			)
		);
	}

	/**
	 * The reported bug, at the door: asking for 'public' inside a private space
	 * stores 'private' regardless.
	 *
	 * @return void
	 */
	public function test_public_content_in_a_private_space_is_stored_private(): void {
		$this->search->index( 'post', 9001, '', 'confidential body', $this->author, 'public', $this->spaces['private'] );

		$this->assertSame(
			'private',
			$this->stored_visibility( 'post', 9001 ),
			'A public post inside a PRIVATE space was indexed as publicly searchable.'
		);
	}

	/**
	 * Secret behaves identically — the card named both.
	 *
	 * @return void
	 */
	public function test_public_content_in_a_secret_space_is_stored_private(): void {
		$this->search->index( 'post', 9002, '', 'confidential body', $this->author, 'public', $this->spaces['secret'] );

		$this->assertSame( 'private', $this->stored_visibility( 'post', 9002 ) );
	}

	/**
	 * The ceiling must not become a blanket demotion: an open space is exactly
	 * the case that has to keep working, and a fix that privatised everything
	 * would still pass the two tests above.
	 *
	 * @return void
	 */
	public function test_public_content_in_an_open_space_stays_public(): void {
		$this->search->index( 'post', 9003, '', 'open body', $this->author, 'public', $this->spaces['open'] );

		$this->assertSame(
			'public',
			$this->stored_visibility( 'post', 9003 ),
			'The ceiling demoted content in an OPEN space, which breaks public search.'
		);
	}

	/**
	 * Content with no space at all is untouched — profile rows, site-wide posts.
	 *
	 * @return void
	 */
	public function test_content_outside_any_space_is_untouched(): void {
		$this->search->index( 'post', 9004, '', 'no space', $this->author, 'public', 0 );

		$this->assertSame( 'public', $this->stored_visibility( 'post', 9004 ) );
	}

	/**
	 * The ceiling only ever lowers. A row the caller already decided is private
	 * must not be promoted just because its space happens to be open — the
	 * caller may have had its own reason (a private account, a draft).
	 *
	 * @return void
	 */
	public function test_the_ceiling_never_promotes_a_private_row(): void {
		$this->search->index( 'post', 9005, '', 'authors private post', $this->author, 'private', $this->spaces['open'] );

		$this->assertSame(
			'private',
			$this->stored_visibility( 'post', 9005 ),
			'A row the caller marked private was promoted to public by the space ceiling.'
		);
	}

	/**
	 * A space that cannot be resolved fails CLOSED. An index row pointing at a
	 * deleted space is exactly the case where guessing 'public' leaks.
	 *
	 * @return void
	 */
	public function test_an_unresolvable_space_fails_closed(): void {
		$this->search->index( 'post', 9006, '', 'orphan', $this->author, 'public', 999999 );

		$this->assertSame( 'private', $this->stored_visibility( 'post', 9006 ) );
	}

	/**
	 * End-to-end through the real gate: a guest does not get the row back and a
	 * member of that space does. Asserting only the stored column would not
	 * prove the member half still works, and a fix that privatised everything
	 * would look identical from the column's point of view.
	 *
	 * @return void
	 */
	public function test_a_guest_cannot_search_it_but_a_space_member_can(): void {
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		buddynext_service( 'space_members' )->join( $this->spaces['private'], $member );

		$this->search->index(
			'post',
			9007,
			'',
			'zebrafishqa confidential',
			$this->author,
			'public',
			$this->spaces['private']
		);

		$guest = $this->search->search( 'zebrafishqa', 'post', 20, 1, 0 );
		$this->assertSame( 0, (int) $guest['total'], 'An anonymous visitor searched private-space content.' );

		$seen = $this->search->search( 'zebrafishqa', 'post', 20, 1, $member );
		$this->assertSame(
			1,
			(int) $seen['total'],
			'A member of the space lost access to their own space content.'
		);
	}

	/**
	 * A non-member who is logged in is still an outsider. The member clause is
	 * driven by space membership, not by being authenticated, and this is the
	 * assertion that catches anyone "fixing" the guest gate by keying it on
	 * `viewer_id > 0`.
	 *
	 * @return void
	 */
	public function test_a_logged_in_non_member_is_still_excluded(): void {
		$outsider = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->search->index(
			'post',
			9008,
			'',
			'zebrafishqb confidential',
			$this->author,
			'public',
			$this->spaces['secret']
		);

		$result = $this->search->search( 'zebrafishqb', 'post', 20, 1, $outsider );

		$this->assertSame( 0, (int) $result['total'], 'A logged-in non-member searched secret-space content.' );
	}
}
