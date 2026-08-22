<?php
/**
 * A member card must not print the same sentence twice.
 *
 * The card shows a headline and, beneath it, a bio. People routinely write a
 * headline and then open their bio with that same sentence, so the card rendered
 * it twice — measured in the browser on a real profile:
 *
 *   .bn-md-card__headline  "Trail runner, data scientist, plant collector"
 *   .bn-md-card__bio       "Trail runner, data scientist, plant collector. Data
 *                           Scientist at ..."
 *
 * The guard that was supposed to prevent this used `strcasecmp()` — exact
 * equality. It catches a bio that IS the headline and misses the prefix case,
 * which is the one people actually produce.
 *
 * Hiding the bio outright would have been the easy fix and the wrong one: the tail
 * is usually the only new information on the card. The repeated opening is
 * trimmed and the remainder kept.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\MemberDirectoryService;
use WP_UnitTestCase;

/**
 * Bio / headline de-duplication on member cards.
 *
 * @covers \BuddyNext\Profile\MemberDirectoryService::bio_beyond_headline
 */
class BioBeyondHeadlineTest extends WP_UnitTestCase {

	/**
	 * The reported case: the bio opens with the headline, then adds more.
	 *
	 * @return void
	 */
	public function test_a_repeated_opening_is_trimmed_and_the_rest_kept(): void {
		$result = MemberDirectoryService::bio_beyond_headline(
			'Trail runner, data scientist, plant collector. Data Scientist at Northwind.',
			'Trail runner, data scientist, plant collector'
		);

		$this->assertSame( 'Data Scientist at Northwind.', $result );
	}

	/**
	 * A bio that is only the headline shows nothing.
	 *
	 * @return void
	 */
	public function test_a_bio_identical_to_the_headline_shows_nothing(): void {
		$this->assertSame(
			'',
			MemberDirectoryService::bio_beyond_headline( 'Trail runner', 'trail runner' ),
			'Case differences must not make a duplicate look like new information.'
		);
	}

	/**
	 * A bio saying something else is shown untouched.
	 *
	 * The case that matters most: this must not become a fix that eats bios.
	 *
	 * @return void
	 */
	public function test_an_unrelated_bio_is_untouched(): void {
		$bio = 'Baking sourdough since 2019 and still learning.';

		$this->assertSame( $bio, MemberDirectoryService::bio_beyond_headline( $bio, 'Trail runner' ) );
	}

	/**
	 * With no headline, the bio is shown as written.
	 *
	 * @return void
	 */
	public function test_no_headline_means_the_bio_is_shown(): void {
		$bio = 'Trail runner, data scientist.';

		$this->assertSame( $bio, MemberDirectoryService::bio_beyond_headline( $bio, '' ) );
	}

	/**
	 * A trivial remainder is not worth a second line.
	 *
	 * "Trail runner." under "Trail runner" adds a line and no information.
	 *
	 * @return void
	 */
	public function test_a_trivial_remainder_is_dropped(): void {
		$this->assertSame(
			'',
			MemberDirectoryService::bio_beyond_headline( 'Trail runner. Yes.', 'Trail runner' )
		);
	}

	/**
	 * An encoded ampersand on one side does not defeat the match.
	 *
	 * Carried over from the guard this replaces, which decoded entities for the
	 * same reason — losing that would have been a silent regression.
	 *
	 * @return void
	 */
	public function test_entity_encoding_does_not_defeat_the_match(): void {
		$this->assertSame(
			'Building things in public.',
			MemberDirectoryService::bio_beyond_headline(
				'Design &amp; research. Building things in public.',
				'Design & research'
			)
		);
	}

	/**
	 * An empty bio stays empty.
	 *
	 * @return void
	 */
	public function test_an_empty_bio_shows_nothing(): void {
		$this->assertSame( '', MemberDirectoryService::bio_beyond_headline( '', 'Trail runner' ) );
	}
}
