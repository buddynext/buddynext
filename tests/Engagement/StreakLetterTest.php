<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The streak strip shows one whole, distinct weekday letter in every language.
 *
 * The strip took `date_i18n( 'D', ... )[0]`, the first BYTE of the localized day
 * name: half a character in Korean, Arabic, Russian (rendered '?'). Taking the
 * first character instead still failed Arabic, where every day name starts with
 * the article ال. The strip now uses core's translated weekday initial.
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

	/**
	 * Core's initials before the test swapped them.
	 *
	 * @var array<string,string>
	 */
	private array $saved = array();

	public function set_up(): void {
		parent::set_up();
		global $wp_locale;
		$this->saved = $wp_locale->weekday_initial;
	}

	public function tear_down(): void {
		global $wp_locale;
		$wp_locale->weekday_initial = $this->saved;
		parent::tear_down();
	}

	/**
	 * Initials per weekday (Sunday first), and the letters the strip must show.
	 *
	 * @return array<string,array{0:array<int,string>,1:array<int,string>}>
	 */
	public function initials(): array {
		return array(
			// Core's ar pack; Wednesday's initial is three characters long.
			'Arabic'  => array( array( 'د', 'ن', 'ث', 'أرب', 'خ', 'ج', 'س' ), array( 'د', 'ن', 'ث', 'أ', 'خ', 'ج', 'س' ) ),
			'Korean'  => array( array( '일', '월', '화', '수', '목', '금', '토' ), array( '일', '월', '화', '수', '목', '금', '토' ) ),
			'English' => array( array( 'S', 'M', 'T', 'W', 'T', 'F', 'S' ), array( 'S', 'M', 'T', 'W', 'T', 'F', 'S' ) ),
		);
	}

	/**
	 * @dataProvider initials
	 *
	 * @param array<int,string> $initials Translated initials, Sunday first.
	 * @param array<int,string> $expected Letter per weekday, Sunday first.
	 */
	public function test_each_cell_shows_its_weekdays_initial( array $initials, array $expected ): void {
		global $wp_locale;
		foreach ( $initials as $w => $initial ) {
			$wp_locale->weekday_initial[ $wp_locale->get_weekday( $w ) ] = $initial;
		}

		$strip = ( new StreakService() )->summary( self::factory()->user->create() )['strip'];

		$this->assertCount( 7, $strip );
		foreach ( $strip as $cell ) {
			$this->assertSame( $expected[ (int) gmdate( 'w', strtotime( $cell['date'] ) ) ], $cell['letter'], $cell['date'] );
		}
	}
}
