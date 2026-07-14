<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Free must never emit SQL against a table Free does not own.
 *
 * WHY THIS EXISTS (card 10086769480)
 *
 * Free's SearchService builds EXISTS subqueries against bn_subscriptions, bn_membership_tiers,
 * bn_member_label_assignments, bn_member_labels and bn_analytics_events. Every one of those tables
 * is created by buddynext-PRO. On a Free-only site they do not exist.
 *
 * A comment sitting directly above that SQL says:
 *
 *     "When Pro is inactive, no caller populates these args so no clause is emitted
 *      and the missing tables are never referenced."
 *
 * That is false, and Free itself is what makes it false: SearchController::collect_advanced_args()
 * reads tier_slug / member_label / active_within_days straight off the REST request and merges them
 * into the same seam at priority 5. Free populates the args. Free consumes the args. Pro is not in
 * the loop at all.
 *
 * So on a Free-only site:
 *
 *     GET /wp-json/buddynext/v1/search?q=a&type=user&member_label=vip     ← permission_callback: __return_true
 *       → Free merges the arg
 *       → Free emits `EXISTS (SELECT 1 FROM wp_bn_member_labels ...)`
 *       → MySQL 1146: table doesn't exist
 *       → get_results() returns null → (array) null → []
 *       → HTTP 200, ZERO results, no error shown to anyone, a DB error in the log on every hit.
 *
 * Search silently returns nothing, to anonymous visitors, and the only person who would ever find
 * out is someone reading the error log. The comment guarantees nobody grepping for the bug finds
 * it either — it asserts the exact opposite of what the code does.
 *
 * These tests run in Free's own suite, where Pro's tables genuinely do not exist. That is not a
 * simulation of the Free-only site; it IS the Free-only site.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Search\SearchService;
use WP_UnitTestCase;

/**
 * Free-only search must work, not silently return nothing.
 */
class FreeOnlySearchTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SearchService
	 */
	private SearchService $search;

	/**
	 * A member who should be findable.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Every query the search ran.
	 *
	 * @var array<int,string>
	 */
	private array $queries = array();

	/**
	 * One indexed member to find.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->search = new SearchService();
		$this->member = (int) $this->factory->user->create(
			array(
				'user_login'   => 'findme',
				'display_name' => 'Findme Person',
			)
		);

		// Index them the way the site would.
		do_action( 'buddynext_index_user', $this->member );

		wp_cache_flush();
	}

	/**
	 * Record every query run inside the callback.
	 *
	 * @return void
	 */
	private function record_queries(): void {
		$this->queries = array();
		add_filter(
			'query',
			function ( $sql ) {
				$this->queries[] = (string) $sql;

				return $sql;
			}
		);
	}

	/**
	 * Assert Free never named a Pro-owned table.
	 *
	 * @return void
	 */
	private function assert_no_pro_tables(): void {
		// Every table here is created by buddynext-pro's Installer, not Free's.
		$pro_tables = array(
			'bn_subscriptions',
			'bn_membership_tiers',
			'bn_member_label_assignments',
			'bn_member_labels',
			'bn_analytics_events',
		);

		foreach ( $this->queries as $sql ) {
			foreach ( $pro_tables as $table ) {
				$this->assertStringNotContainsStringIgnoringCase(
					$table,
					$sql,
					"Free emitted SQL against {$table} — a PRO-owned table that does not exist on a "
					. 'Free-only site. FREE-PRO-SEAM.md §7: Free must NEVER reference a Pro-owned '
					. 'table. The query fails with MySQL 1146, get_results() returns null, and the '
					. "search silently returns zero results to an anonymous visitor.\n\nSQL: " . $sql
				);
			}
		}
	}

	/**
	 * The headline bug: a member_label filter on a Free-only site kills the whole search.
	 *
	 * @return void
	 */
	public function test_a_pro_filter_does_not_break_search_on_a_free_only_site(): void {
		$this->record_queries();

		// Exactly what the public REST route forwards: ?q=findme&type=user&member_label=vip.
		$args = array( 'member_label' => 'vip' );
		add_filter( 'buddynext_search_query_args', static fn( array $a ): array => array_merge( $a, $args ), 5 );

		$results = $this->search->search( 'findme', 'user', 20, 1, 0 );

		$this->assert_no_pro_tables();

		$this->assertNotEmpty(
			$results['items'] ?? array(),
			'Search returned ZERO results because Free queried a Pro table that does not exist. The '
			. 'visitor sees an empty page and HTTP 200 — no error, no explanation. Free must ignore '
			. 'a filter it cannot serve, never poison the whole query with it.'
		);
	}

	/**
	 * Same for the tier filter.
	 *
	 * @return void
	 */
	public function test_a_tier_filter_does_not_break_search_on_a_free_only_site(): void {
		$this->record_queries();

		add_filter( 'buddynext_search_query_args', static fn( array $a ): array => array_merge( $a, array( 'tier_slug' => 'gold' ) ), 5 );

		$results = $this->search->search( 'findme', 'user', 20, 1, 0 );

		$this->assert_no_pro_tables();
		$this->assertNotEmpty( $results['items'] ?? array(), 'a tier filter must not empty the search on a Free-only site' );
	}

	/**
	 * And the activity filter (bn_analytics_events is Pro's too).
	 *
	 * @return void
	 */
	public function test_an_activity_filter_does_not_break_search_on_a_free_only_site(): void {
		$this->record_queries();

		add_filter( 'buddynext_search_query_args', static fn( array $a ): array => array_merge( $a, array( 'active_within_days' => 30 ) ), 5 );

		$results = $this->search->search( 'findme', 'user', 20, 1, 0 );

		$this->assert_no_pro_tables();
		$this->assertNotEmpty( $results['items'] ?? array(), 'an activity filter must not empty the search on a Free-only site' );
	}

	/**
	 * The seam must exist, so Pro can actually answer it.
	 *
	 * Moving the SQL out of Free is only half the fix. If Free removed the clauses and offered
	 * nothing in their place, Pro's advanced search would simply stop working — a regression
	 * dressed up as a fix.
	 *
	 * @return void
	 */
	public function test_pro_can_contribute_its_own_where_clause_through_the_seam(): void {
		$fired = false;

		add_filter(
			'buddynext_search_advanced_where',
			static function ( array $clause, array $args ) use ( &$fired ): array {
				$fired = true;

				// Pro would emit its EXISTS here, against its own tables.
				if ( isset( $args['member_label'] ) ) {
					$clause['where']   .= ' AND 1 = %d';
					$clause['params'][] = 1;
				}

				return $clause;
			},
			10,
			2
		);

		add_filter( 'buddynext_search_query_args', static fn( array $a ): array => array_merge( $a, array( 'member_label' => 'vip' ) ), 5 );

		$results = $this->search->search( 'findme', 'user', 20, 1, 0 );

		$this->assertTrue(
			$fired,
			'Free must offer buddynext_search_advanced_where so the owner of those tables can answer '
			. 'it. Without the seam, deleting the SQL from Free would just delete the feature.'
		);
		$this->assertNotEmpty( $results['items'] ?? array(), 'and a satisfied clause still returns the member' );
	}

	/**
	 * A search with NO advanced filters must be untouched by any of this.
	 *
	 * @return void
	 */
	public function test_an_ordinary_search_still_works(): void {
		$results = $this->search->search( 'findme', 'user', 20, 1, 0 );

		$this->assertNotEmpty( $results['items'] ?? array(), 'plain search must keep working' );
	}
}
