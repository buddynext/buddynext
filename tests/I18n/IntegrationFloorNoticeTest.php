<?php
/**
 * The notice for an integration below its version floor: Jetonomy says the whole bridge is off, others keep the generic line,
 * and the Jetonomy line is translated in every shipped language with its version placeholder intact.
 *
 * @package BuddyNext\Tests\I18n
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\I18n;

use BuddyNext\Admin\IntegrationControlsAdmin;
use WP_UnitTestCase;

/**
 * Checks the floor notice and its catalogue entries.
 */
class IntegrationFloorNoticeTest extends WP_UnitTestCase {

	private const MSGID = 'Discussions need Jetonomy %s or newer. Update Jetonomy to turn them on; your forums and posts are kept.';

	/**
	 * Each shipped catalogue, keyed by locale.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function locale_provider(): array {
		$out = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/languages/buddynext-*.po' ) as $file ) {
			$out[ basename( (string) $file, '.po' ) ] = array( (string) $file );
		}
		return $out;
	}

	/**
	 * Jetonomy (whole bridge off) and another integration (partly version-bound) read differently.
	 */
	public function test_the_notice_matches_what_the_floor_turns_off(): void {
		$this->assertSame(
			'Discussions need Jetonomy 2.1.0 or newer. Update Jetonomy to turn them on; your forums and posts are kept.',
			IntegrationControlsAdmin::floor_notice( array( 'min_version' => '2.1.0', 'floor_disables' => true ) )
		);
		$this->assertSame(
			'Update to 2.6.2 or newer to enable every feature this integration offers.',
			IntegrationControlsAdmin::floor_notice( array( 'min_version' => '2.6.2' ) )
		);
		$items = ( new \BuddyNext\Bridges\JetonomyBridge() )->register_integration( array() );
		$this->assertTrue( $items['jetonomy']['floor_disables'] );
	}

	/**
	 * Every shipped language translates it, keeping exactly one %s.
	 *
	 * @dataProvider locale_provider
	 *
	 * @param string $file Catalogue path.
	 */
	public function test_the_notice_is_translated_with_its_version_placeholder( string $file ): void {
		$po = (string) file_get_contents( $file );
		$this->assertSame( 1, preg_match( '/^msgid "' . preg_quote( self::MSGID, '/' ) . '"\nmsgstr "(.+)"$/m', $po, $m ), basename( $file ) );
		$this->assertSame( 1, substr_count( $m[1], '%s' ), basename( $file ) . ' keeps %s once' );
		$this->assertStringNotContainsString( "\u{2014}", $m[1] );
	}
}
