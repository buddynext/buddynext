<?php
/**
 * The Members directory and Explore return the same members for a term.
 *
 * Regression cover for "profile fields are found in the Explore search but not
 * in the Members search". Both surfaces query the same column of the same table;
 * the directory was simply a strictly narrower matcher in three ways — no prefix
 * wildcard, no members-tier column, and no short-query LIKE path. A customer
 * reasonably read that as "profile fields are not searchable under Members".
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Search\SearchService;

/**
 * Directory vs unified search parity.
 *
 * @covers \BuddyNext\Search\SearchService::match_member_ids
 */
class MemberSearchParityTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SearchService
	 */
	private SearchService $search;

	/**
	 * Create the schema and the service under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->search = new SearchService();
	}

	/**
	 * Index a member row directly, so the test does not depend on the indexer.
	 *
	 * @param int    $user_id        Member.
	 * @param string $content        Public searchable text.
	 * @param string $members_only   Members-visibility searchable text.
	 * @return void
	 */
	private function index_member( int $user_id, string $content, string $members_only = '' ): void {
		global $wpdb;

		// REPLACE, not INSERT: creating a user already indexes it, and
		// (object_type, object_id) is unique — a plain insert fails on the
		// duplicate and leaves the fixture silently unset.
		$ok = $wpdb->replace(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type'     => 'user',
				'object_id'       => $user_id,
				'title'           => '',
				'content'         => $content,
				'content_members' => $members_only,
			)
		);

		// A silently failing fixture makes every assertion below vacuous.
		$this->assertNotFalse( $ok, 'index fixture write failed: ' . $wpdb->last_error );
		$this->assertSame(
			$content,
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT content FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'user' AND object_id = %d",
					$user_id
				)
			)
		);
	}

	/**
	 * A prefix of an indexed word matches, as it does in Explore.
	 *
	 * This is the reported symptom: the bare term could only match a whole
	 * indexed word of at least innodb_ft_min_token_size characters.
	 *
	 * @return void
	 */
	public function test_prefix_of_a_word_matches(): void {
		$user_id = self::factory()->user->create();
		$this->index_member( $user_id, 'Priyanka Nair backend engineer' );

		$this->assertContains(
			$user_id,
			$this->search->match_member_ids( 'Priy', 500, $user_id ),
			'A prefix must match, matching Explore.'
		);
	}

	/**
	 * A logged-in viewer also matches members-visibility values.
	 *
	 * @return void
	 */
	public function test_logged_in_viewer_matches_members_tier_values(): void {
		$viewer  = self::factory()->user->create();
		$subject = self::factory()->user->create();
		$this->index_member( $subject, 'public bio text', 'Flugelhorn' );

		$this->assertContains(
			$subject,
			$this->search->match_member_ids( 'Flugelhorn', 500, $viewer )
		);
	}

	/**
	 * An anonymous searcher never reaches members-visibility values.
	 *
	 * The privacy boundary is the reason $viewer_id exists — if this assertion
	 * ever fails, a field a member limited to members is leaking to strangers.
	 *
	 * @return void
	 */
	public function test_anonymous_searcher_cannot_match_members_tier_values(): void {
		$subject = self::factory()->user->create();
		$this->index_member( $subject, 'public bio text', 'Flugelhorn' );

		$this->assertNotContains(
			$subject,
			$this->search->match_member_ids( 'Flugelhorn', 500, 0 ),
			'Members-visibility values must never match for a logged-out searcher.'
		);
	}

	/**
	 * A term shorter than the FULLTEXT minimum token still matches.
	 *
	 * @return void
	 */
	public function test_short_terms_match_via_the_like_path(): void {
		$user_id = self::factory()->user->create();
		$this->index_member( $user_id, 'Lisbon' );

		$this->assertContains(
			$user_id,
			$this->search->match_member_ids( 'Li', 500, $user_id ),
			'Short terms route to LIKE, exactly as search() does.'
		);
	}

	/**
	 * A term that matches nothing returns nothing.
	 *
	 * Mutation guard: a matcher widened until everything matches would satisfy
	 * every assertion above and fail here.
	 *
	 * @return void
	 */
	public function test_a_non_matching_term_returns_nothing(): void {
		$user_id = self::factory()->user->create();
		$this->index_member( $user_id, 'Lisbon' );

		$this->assertSame( array(), $this->search->match_member_ids( 'zzzznope', 500, $user_id ) );
	}

	/**
	 * Members that tie on every sort key still page without repeats.
	 *
	 * Bulk-imported members share updated_at; without a unique last sort key
	 * MySQL may order the tied rows differently for each LIMIT/OFFSET, so page 2
	 * repeated members from page 1 and skipped others.
	 *
	 * @return void
	 */
	public function test_tied_members_page_without_repeats(): void {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$ids[] = self::factory()->user->create();
			$this->index_member( end( $ids ), 'Lisbon' );
		}
		$wpdb->query( "UPDATE {$wpdb->prefix}bn_search_index SET updated_at = '2026-01-01 00:00:00' WHERE object_type = 'user'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$seen = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$items = (array) ( $this->search->search( 'Li', 'user', 20, $page, $ids[0] )['items'] ?? array() );
			foreach ( $items as $item ) {
				$seen[] = (int) ( is_array( $item ) ? ( $item['object_id'] ?? $item['id'] ?? 0 ) : ( $item->object_id ?? $item->id ?? 0 ) );
			}
		}

		$this->assertCount( 60, $seen );
		$this->assertSame( count( $seen ), count( array_unique( $seen ) ), 'A member appeared on two pages.' );
		$this->assertEqualsCanonicalizing( $ids, $seen );
	}

	/**
	 * Every paged search query ends its ORDER BY on the unique row id.
	 *
	 * The repeat above depends on how MySQL orders tied rows under LIMIT, which a
	 * small test table does not reliably reproduce (the live directory did, at
	 * 120 tied members). So this pins the cause directly: a paged query whose last
	 * sort key is not unique can repeat and skip rows between pages.
	 *
	 * @return void
	 */
	public function test_paged_search_queries_end_on_a_unique_sort_key(): void {
		$user_id = self::factory()->user->create();
		$this->index_member( $user_id, 'Lisbon harbour' );

		$paged = array();
		$spy   = static function ( $sql ) use ( &$paged ) {
			if ( false !== stripos( (string) $sql, 'bn_search_index' ) && preg_match( '/LIMIT\s+\d+\s+OFFSET/i', (string) $sql ) ) {
				$paged[] = (string) $sql;
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		$this->search->search( 'Li', 'user', 20, 2, $user_id );
		$this->search->search( 'Lisbon', 'user', 20, 2, $user_id );
		remove_filter( 'query', $spy );

		$this->assertNotEmpty( $paged, 'No paged search query was captured.' );
		foreach ( $paged as $sql ) {
			$this->assertMatchesRegularExpression( '/ORDER BY .*si\.id (ASC|DESC)\s+LIMIT/is', $sql );
		}
	}
}
