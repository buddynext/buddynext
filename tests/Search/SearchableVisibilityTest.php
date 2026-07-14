<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * "Searchable" must mean searchable — and never to someone who may not see the value.
 *
 * WHY THIS EXISTS — Zoho #40859 (Markus Kaufmann), card 10086461636:
 *
 *     "Not found in the member search, even though the checkbox is checked"
 *
 * He was right, and the reason was invisible to him. A profile field's value was only ever mirrored
 * into the search index when its effective visibility was `public`. Tick "searchable" on a field
 * that members-only can see and NOTHING happened: no mirror, no index entry, no warning, no error.
 * The owner configured a thing, believed it, and was wrong — which is worse than the feature not
 * existing, because at least an absent feature does not lie.
 *
 * The fix is not to relax the privacy rule. It is to stop pretending the rule is not there:
 *
 *   public   → search_index.content          → matched for EVERYONE, including strangers.
 *   members  → search_index.content_members  → matched ONLY for a logged-in viewer.
 *   others   → not indexed (followers / connections / private).
 *
 * TWO COLUMNS, NOT ONE COLUMN AND A FLAG. The anonymous query never even names content_members, so
 * the boundary is structural rather than conditional — there is no boolean left for a later change
 * to get wrong. These tests are what hold that line.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Profile\MemberDirectoryService;
use BuddyNext\Profile\ProfileService;
use BuddyNext\Search\SearchService;
use WP_UnitTestCase;

/**
 * Searchable fields are indexed by who is allowed to see them.
 */
class SearchableVisibilityTest extends WP_UnitTestCase {

	/**
	 * Search under test.
	 *
	 * @var SearchService
	 */
	private SearchService $search;

	/**
	 * Profiles.
	 *
	 * @var ProfileService
	 */
	private ProfileService $profiles;

	/**
	 * The member whose profile carries the values.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Somebody else, logged in.
	 *
	 * @var int
	 */
	private int $viewer;

	/**
	 * A public searchable field and a members-only searchable field.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->search   = new SearchService();
		$this->profiles = new ProfileService();
		$this->member   = (int) $this->factory->user->create();
		$this->viewer   = (int) $this->factory->user->create();

		// PUBLIC group → public searchable field.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_groups',
			array(
				'group_key'  => 'zz_pub_group',
				'label'      => 'ZZ Public',
				'type'       => 'flat',
				'visibility' => 'public',
				'sort_order' => 96,
			)
		);
		$pub_group = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'      => $pub_group,
				'field_key'     => 'zz_pub',
				'label'         => 'ZZ Public Field',
				'type'          => 'text',
				'is_searchable' => 1,
				'visibility'    => 'public',
				'sort_order'    => 1,
			)
		);

		// MEMBERS group → the field that used to be silently un-searchable.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_groups',
			array(
				'group_key'  => 'zz_mem_group',
				'label'      => 'ZZ Members',
				'type'       => 'flat',
				'visibility' => 'members',
				'sort_order' => 97,
			)
		);
		$mem_group = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'      => $mem_group,
				'field_key'     => 'zz_mem',
				'label'         => 'ZZ Members Field',
				'type'          => 'text',
				'is_searchable' => 1,
				'visibility'    => 'public',
				'sort_order'    => 1,
			)
		);

		wp_cache_flush();
		// The fields above are seeded straight into the tables, so the admin save path — which is
		// what normally invalidates this — never runs. Bust the memo the way a field edit would.
		MemberDirectoryService::flush_mirror_keys_memo();

		$this->profiles->save_profile(
			$this->member,
			array(
				'zz_pub' => 'zzuniquepublicvalue',
				'zz_mem' => 'zzuniquemembersvalue',
			)
		);

		$this->profiles->index_user( $this->member );
		wp_cache_flush();
	}

	/**
	 * Search and report how many members came back.
	 *
	 * @param string $query     Term.
	 * @param int    $viewer_id 0 = anonymous.
	 * @return int
	 */
	private function hits( string $query, int $viewer_id ): int {
		$res = $this->search->search( $query, 'user', 10, 1, $viewer_id );

		return count( (array) ( $res['items'] ?? array() ) );
	}

	// ── the mirrors ────────────────────────────────────────────────────────────────

	/**
	 * A members-visible searchable field must produce a members-tier mirror.
	 *
	 * Before: no mirror at all. The checkbox did nothing, and said nothing.
	 *
	 * @return void
	 */
	public function test_a_members_visible_searchable_field_is_mirrored(): void {
		$this->assertSame(
			'zzuniquemembersvalue',
			(string) get_user_meta( $this->member, ProfileService::MEMBERS_MIRROR_PREFIX . 'zz_mem', true ),
			'Ticking "searchable" on a members-visible field wrote NOTHING. The owner configured it, '
			. 'believed it, and was wrong — which is worse than the feature not existing.'
		);
	}

	/**
	 * ...and it must NOT land in the public mirror, or it leaks.
	 *
	 * @return void
	 */
	public function test_a_members_visible_value_never_lands_in_the_public_mirror(): void {
		$this->assertSame(
			'',
			(string) get_user_meta( $this->member, 'bn_field_zz_mem', true ),
			'A members-only value in the PUBLIC mirror is a privacy leak: the public mirror is what '
			. 'the anonymous search path reads.'
		);
	}

	// ── the boundary ───────────────────────────────────────────────────────────────

	/**
	 * THE LINE. An anonymous searcher must never match a members-only value.
	 *
	 * If this test ever goes red, a member's members-only profile data is being handed to strangers.
	 * It is the single most important assertion in this file.
	 *
	 * @return void
	 */
	public function test_an_anonymous_searcher_cannot_find_a_members_only_value(): void {
		$this->assertSame(
			0,
			$this->hits( 'zzuniquemembersvalue', 0 ),
			'PRIVACY LEAK: a value the member chose to show only to members was returned to a '
			. 'logged-OUT searcher.'
		);
	}

	/**
	 * A logged-in member CAN find it — which is the whole point of the change.
	 *
	 * @return void
	 */
	public function test_a_logged_in_member_can_find_a_members_only_value(): void {
		$this->assertSame(
			1,
			$this->hits( 'zzuniquemembersvalue', $this->viewer ),
			'This is what the owner meant when they ticked "searchable" on a members-visible field. '
			. 'Before, it silently matched nothing for anybody.'
		);
	}

	// ── nothing regressed ──────────────────────────────────────────────────────────

	/**
	 * A public searchable field still works for a stranger.
	 *
	 * @return void
	 */
	public function test_a_public_searchable_field_is_still_found_by_anyone(): void {
		$this->assertSame( 1, $this->hits( 'zzuniquepublicvalue', 0 ), 'public search must not regress for guests' );
		$this->assertSame( 1, $this->hits( 'zzuniquepublicvalue', $this->viewer ), 'nor for members' );
	}

	/**
	 * The COUNT and the ROWS must ask the same question.
	 *
	 * THIS SUITE CANNOT SEE THE PATH THAT BROKE. The Installer DROPs the FULLTEXT index under
	 * PHPUnit (InnoDB FULLTEXT cannot see rows inside the transaction each test is wrapped in), so
	 * every test here runs the LIKE fallback — and the FULLTEXT branch, which is what production
	 * actually runs, is exercised by nothing.
	 *
	 * It duly broke. The COUNT query used the shared $search_condition (with the members tier OR'd
	 * in) while the rows query rebuilt `MATCH(si.title, si.content)` inline. So the count found the
	 * member and the fetch did not: search reported a total and returned an empty list. Green suite,
	 * broken feature. It surfaced only by seeding a real member on a real site and searching.
	 *
	 * The structural fix was to delete the second copy — one condition, used by both queries. This
	 * test guards the invariant that the copy cannot come back: whatever the total says it found,
	 * that many rows must come back.
	 *
	 * @return void
	 */
	public function test_the_total_and_the_returned_rows_agree(): void {
		$res = $this->search->search( 'zzuniquemembersvalue', 'user', 10, 1, $this->viewer );

		$this->assertSame(
			(int) ( $res['total'] ?? 0 ),
			count( (array) ( $res['items'] ?? array() ) ),
			'The COUNT and the ROWS disagreed — search reported a total it then failed to return. '
			. 'That is what happens when one condition is written out twice.'
		);
	}
}
