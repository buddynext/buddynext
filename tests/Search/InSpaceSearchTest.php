<?php
/**
 * Searching inside one space.
 *
 * A space's own posts get harder to find exactly as the space succeeds. The
 * scope is expressed as a `scope_space_id` arg on the buddynext_search_query_args
 * seam, ANDed onto the index query.
 *
 * Two things here are easy to get wrong and are pinned by name:
 *
 * 1. The key is NOT `space_id`. That name was already taken by one of Pro's five
 *    entitlement-gated advanced keys, meaning "members of this space", and
 *    AdvancedSearchFilters::apply_pro_args() STRIPS it from the args for any
 *    viewer without search.saved_advanced — every anonymous visitor included. A
 *    Free feature keyed on it would work on a Free-only site and silently stop
 *    scoping the moment monetization was switched on, showing a member the whole
 *    community's posts under a space's own search box.
 *
 * 2. The scope NARROWS; it never widens. It is ANDed with the visibility gate
 *    that search already applies, so pointing it at a space the viewer cannot
 *    read returns nothing rather than that space's contents.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Search\SearchService;

/**
 * The `scope_space_id` search scope.
 *
 * @covers \BuddyNext\Search\SearchService::search
 */
class InSpaceSearchTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SearchService
	 */
	private SearchService $search;

	/**
	 * Space holding the content we scope to.
	 *
	 * @var int
	 */
	private int $space_a = 0;

	/**
	 * A second space, whose content must be excluded by the scope.
	 *
	 * @var int
	 */
	private int $space_b = 0;

	/**
	 * A private space, used for the leak assertion.
	 *
	 * @var int
	 */
	private int $space_private = 0;

	/**
	 * Index a known corpus across REAL spaces.
	 *
	 * The spaces have to exist: index() clamps content to its space's visibility
	 * ceiling, and an unknown space id fails closed to `private`. Indexing against
	 * invented ids would therefore store everything as private and the assertions
	 * would pass or fail for reasons unrelated to the scope.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		\BuddyNext\Core\Installer::run();

		$this->search = new SearchService();
		$owner        = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$spaces       = buddynext_service( 'spaces' );

		$make = function ( string $slug, string $type ) use ( $spaces, $owner ): int {
			$id = $spaces->create(
				$owner,
				array(
					'name' => 'QA ' . $slug,
					'slug' => $slug,
					'type' => $type,
				)
			);
			$this->assertIsInt( $id, 'Could not create the ' . $slug . ' fixture space.' );
			return (int) $id;
		};

		$this->space_a       = $make( 'qa-scope-a', 'open' );
		$this->space_b       = $make( 'qa-scope-b', 'open' );
		$this->space_private = $make( 'qa-scope-private', 'private' );

		// Distinct token so the assertions cannot collide with fixture content
		// another test left in the index.
		$this->search->index( 'post', 91001, 'Alpha', 'zorptastic in space A', 1, 'public', $this->space_a );
		$this->search->index( 'post', 91002, 'Beta', 'zorptastic also in space A', 1, 'public', $this->space_a );
		$this->search->index( 'post', 91003, 'Gamma', 'zorptastic over in space B', 1, 'public', $this->space_b );
		$this->search->index( 'post', 91004, 'Delta', 'zorptastic with no space at all', 1, 'public', 0 );
	}

	/**
	 * Run a search with the space scope applied for the duration of the call.
	 *
	 * @param string $query    Search term.
	 * @param int    $space_id Space to scope to (0 = no scope).
	 * @param string $type     Object type filter.
	 * @param int    $viewer   Viewer user ID.
	 * @return array{items: array[], total: int}
	 */
	private function scoped( string $query, int $space_id, string $type = 'post', int $viewer = 0 ): array {
		$scope = static function ( array $args ) use ( $space_id ): array {
			$args['scope_space_id'] = $space_id;
			return $args;
		};

		add_filter( 'buddynext_search_query_args', $scope, 5 );
		$result = $this->search->search( $query, $type, 20, 1, $viewer );
		remove_filter( 'buddynext_search_query_args', $scope, 5 );

		return $result;
	}

	/**
	 * Object IDs from a result set.
	 *
	 * @param array $result Search result.
	 * @return array<int,int>
	 */
	private function ids( array $result ): array {
		$ids = array_map(
			static fn( $row ): int => (int) ( $row['object_id'] ?? 0 ),
			(array) ( $result['items'] ?? array() )
		);
		sort( $ids );
		return $ids;
	}

	/**
	 * The scope returns only that space's content.
	 *
	 * @return void
	 */
	public function test_the_scope_returns_only_that_spaces_posts(): void {
		$unscoped = $this->ids( $this->search->search( 'zorptastic', 'post', 20, 1, 0 ) );
		$this->assertContains( 91003, $unscoped, 'precondition: the other space\'s post is findable without a scope' );

		$scoped = $this->ids( $this->scoped( 'zorptastic', $this->space_a ) );

		$this->assertSame( array( 91001, 91002 ), $scoped );
		$this->assertNotContains( 91003, $scoped, 'a post in another space must not appear' );
		$this->assertNotContains( 91004, $scoped, 'a post in no space must not appear' );
	}

	/**
	 * The scope narrows: it can never return more than the unscoped search.
	 *
	 * @return void
	 */
	public function test_the_scope_only_ever_narrows(): void {
		$unscoped = $this->search->search( 'zorptastic', 'post', 20, 1, 0 );
		$scoped   = $this->scoped( 'zorptastic', $this->space_a );

		$this->assertLessThanOrEqual(
			(int) $unscoped['total'],
			(int) $scoped['total'],
			'a scoped search returning MORE than the unscoped one means the scope widened access'
		);
	}

	/**
	 * A space with no matching content returns nothing, not everything.
	 *
	 * The failure mode this guards is an ignored scope: if the arg were dropped,
	 * the query would silently fall back to community-wide results, which is the
	 * shape of the Pro-key collision described in the class docblock.
	 *
	 * @return void
	 */
	public function test_an_unrelated_space_returns_nothing(): void {
		$this->assertSame( 0, (int) $this->scoped( 'zorptastic', 999777 )['total'] );
	}

	/**
	 * Zero / absent scope leaves the search unscoped.
	 *
	 * @return void
	 */
	public function test_a_zero_scope_is_ignored(): void {
		$this->assertSame(
			$this->ids( $this->search->search( 'zorptastic', 'post', 20, 1, 0 ) ),
			$this->ids( $this->scoped( 'zorptastic', 0 ) ),
			'scope_space_id = 0 must behave exactly like no scope at all'
		);
	}

	/**
	 * A member search is NOT scoped by this key.
	 *
	 * User rows carry no space_id, so ANDing the column on a member search would
	 * match nothing — turning Pro's working "members of this space" filter into a
	 * silent zero-result search. The exclusion is what keeps the two meanings of
	 * "restrict to this space" from colliding.
	 *
	 * @return void
	 */
	public function test_a_member_search_is_not_space_scoped(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Zorptastic Person' ) );
		$this->search->index( 'user', $user_id, 'Zorptastic Person', 'zorptastic member bio', $user_id, 'public', 0 );

		$scoped = $this->scoped( 'zorptastic', $this->space_a, 'user' );

		$this->assertContains(
			$user_id,
			$this->ids( $scoped ),
			'a member search must ignore scope_space_id — user rows have no space_id, so applying it '
			. 'would return zero results instead of leaving the Pro member filter to answer'
		);
	}

	/**
	 * Private space content stays invisible to a non-member, scope or not.
	 *
	 * The scope is ANDed with the visibility gate rather than replacing it, so
	 * naming a private space is not a way to read it.
	 *
	 * @return void
	 */
	public function test_scoping_to_a_private_space_does_not_reveal_it(): void {
		$this->search->index( 'post', 91005, 'Secret', 'zorptastic behind the gate', 1, 'public', $this->space_private );

		// The ceiling should already have stored this as private despite being
		// indexed 'public'; assert it, because if that ever stopped holding, the
		// scope assertion below would pass for the wrong reason.
		global $wpdb;
		$stored = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT visibility FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				91005
			)
		);
		$this->assertSame( 'private', $stored, 'precondition: content in a private space is indexed private' );

		$this->assertSame(
			0,
			(int) $this->scoped( 'zorptastic', $this->space_private )['total'],
			'a viewer with no membership must not reach private content by naming its space'
		);
	}
}
