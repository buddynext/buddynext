<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The two scale residuals with real production blast radius.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\EmailSender;
use BuddyNext\Search\SearchService;
use WP_UnitTestCase;

/**
 * A broadcast re-queried the same template per recipient; a typo full-scanned the index.
 *
 * The other seven items on that card are bounded, background, or self-scoped. These two are
 * the ones a real site would actually feel:
 *
 *   - EmailSender::get_template() ran on EVERY send. A 10,000-recipient broadcast issued
 *     10,000 identical queries for a row that does not vary by recipient.
 *   - The search fuzzy fallback (LIKE '%term%' + SOUNDEX) fires whenever FULLTEXT matches
 *     nothing — i.e. on any typo — from an endpoint that needs no login. Both predicates are
 *     non-sargable, so a stranger could full-scan bn_search_index by asking for gibberish.
 *
 * @covers \BuddyNext\Search\SearchService
 */
class ScaleResidualsTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_delete( 'bn_search_index_rows', 'buddynext' );
	}

	/**
	 * The template is fetched ONCE per send run, not once per recipient.
	 *
	 * Counts queries, because the query count is the thing that was wrong.
	 *
	 * @return void
	 */
	public function test_a_broadcast_does_not_requery_the_template_per_recipient(): void {
		global $wpdb;

		$sender = new EmailSender(
			new \BuddyNext\Notifications\NotificationPrefService(),
			new \BuddyNext\Notifications\NotificationPrefCatalogue()
		);

		$fetch = new \ReflectionMethod( EmailSender::class, 'get_template' );
		$fetch->setAccessible( true );

		// First call may hit the DB; every later call for the same type must not.
		$fetch->invoke( $sender, 'digest' );

		$before = $wpdb->num_queries;

		for ( $i = 0; $i < 50; $i++ ) {
			$fetch->invoke( $sender, 'digest' );
		}

		$used = $wpdb->num_queries - $before;

		$this->assertSame(
			0,
			$used,
			"50 sends of the same template cost {$used} queries. A 10,000-recipient broadcast issues 10,000 identical queries for a row that does not vary by recipient."
		);
	}

	/**
	 * SOUNDEX is never applied to the CONTENT column.
	 *
	 * Not merely slow — meaningless. SOUNDEX() encodes the first letter plus a few consonants
	 * of the WHOLE string into a 4-character code, so on a document body it is in effect
	 * SOUNDEX(first word). It was a non-sargable full scan computing an answer that was also
	 * wrong.
	 *
	 * @return void
	 */
	public function test_soundex_is_never_applied_to_the_content_column(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Search/SearchService.php' );

		$this->assertStringNotContainsString(
			'SOUNDEX(si.content)',
			$src,
			'SOUNDEX on the content column is back. It full-scans bn_search_index to compute a phonetic code for an entire document body, which is not a meaningful thing to compare against a search term.'
		);
	}

	/**
	 * The fuzzy full scan does not run on a large index.
	 *
	 * @return void
	 */
	public function test_fuzzy_is_refused_on_a_large_index(): void {
		$search = new SearchService();

		$affordable = new \ReflectionMethod( SearchService::class, 'fuzzy_is_affordable' );
		$affordable->setAccessible( true );

		// Pretend the index is enormous.
		add_filter( 'buddynext_search_fuzzy_max_rows', static fn(): int => 0 );

		$this->assertFalse(
			$affordable->invoke( $search, 'engineer' ),
			'The fuzzy fallback still runs on an index the site owner has ruled out. It is a full scan reachable by any stranger typing gibberish at a public endpoint.'
		);
	}

	/**
	 * A one- or two-character term never triggers the fuzzy scan.
	 *
	 * LIKE '%a%' matches nearly every row, and SOUNDEX on a two-letter term is noise.
	 *
	 * @return void
	 */
	public function test_fuzzy_is_refused_for_a_very_short_term(): void {
		$search = new SearchService();

		$affordable = new \ReflectionMethod( SearchService::class, 'fuzzy_is_affordable' );
		$affordable->setAccessible( true );

		$this->assertFalse( $affordable->invoke( $search, 'a' ) );
		$this->assertFalse( $affordable->invoke( $search, 'ab' ) );
		$this->assertTrue( $affordable->invoke( $search, 'abc' ), 'A normal term must still get the fuzzy fallback on a small index.' );
	}
}
