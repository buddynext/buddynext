<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The streak strip's weekday letter is a whole character in every script.
 *
 * The strip took `date_i18n( 'D', ... )[0]`, the first BYTE of the localized day
 * name. Korean, Arabic, Russian and every other non-Latin script use more than one
 * byte per character, so each cell held half a character and rendered as '?'.
 *
 * @package BuddyNext\Tests\Engagement
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Engagement;

use BuddyNext\Engagement\StreakService;

/**
 * @covers \BuddyNext\Engagement\StreakService::summary
 */
class StreakLetterTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'date_i18n' );
		parent::tear_down();
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function day_names(): array {
		return array(
			'Korean'  => array( '수', '수' ),
			'Arabic'  => array( 'الأربعاء', 'ا' ),
			'Russian' => array( 'Ср', 'С' ),
			'English' => array( 'Wed', 'W' ),
		);
	}

	/**
	 * @dataProvider day_names
	 *
	 * @param string $day_name Localized short day name date_i18n() returns.
	 * @param string $letter   The letter the strip must show.
	 */
	public function test_each_cell_shows_the_first_character_of_the_day_name( string $day_name, string $letter ): void {
		add_filter( 'date_i18n', static fn() => $day_name );

		$strip = ( new StreakService() )->summary( self::factory()->user->create() )['strip'];

		$this->assertCount( 7, $strip );
		foreach ( $strip as $cell ) {
			$this->assertSame( $letter, $cell['letter'] );
			$this->assertTrue( mb_check_encoding( $cell['letter'], 'UTF-8' ), 'A cell must never hold a broken UTF-8 byte.' );
		}
	}
}
