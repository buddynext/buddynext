<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Cards published before the facts line get one in the background, from the bridge's own builder.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\CardFactsBackfill;
use BuddyNext\Feed\IntegrationActivity;
use WP_UnitTestCase;

class CardFactsBackfillTest extends WP_UnitTestCase {

	private function facts_of( string $url ): ?array {
		global $wpdb;
		$meta = $wpdb->get_var( $wpdb->prepare( "SELECT link_meta FROM {$wpdb->prefix}bn_posts WHERE link_url = %s LIMIT 1", $url ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$meta = json_decode( (string) $meta, true );
		return is_array( $meta ) && array_key_exists( 'facts', $meta ) ? (array) $meta['facts'] : null;
	}

	public function test_a_card_without_facts_gets_them_once_and_other_cards_are_left_alone(): void {
		$user  = self::factory()->user->create();
		$old   = 'https://example.test/badge/old/';
		$other = 'https://example.test/other/old/';
		IntegrationActivity::publish( $user, 'earned the Old badge', $old, 'Old', 'badge', '', 0, array() );
		IntegrationActivity::publish( $user, 'something', $other, 'Other', 'link', '', 0, array() );
		$this->assertNull( $this->facts_of( $old ), 'A card from before the facts line has none.' );

		$calls = 0;
		$build = static function ( $builders ) use ( &$calls ) {
			$builders['badge'] = static function ( array $card ) use ( &$calls ): array {
				++$calls;
				return array(
					array(
						'icon' => 'zap',
						'text' => '12 points',
					),
				);
			};
			return $builders;
		};
		add_filter( 'buddynext_card_facts_backfill', $build );

		( new CardFactsBackfill() )->run( 0 );
		$facts = $this->facts_of( $old );
		$this->assertSame( '12 points', $facts[0]['text'] ?? '', 'The bridge builder filled the card.' );
		$this->assertNull( $this->facts_of( $other ), 'A type with no builder is untouched.' );

		( new CardFactsBackfill() )->run( 0 );
		$this->assertSame( 1, $calls, 'A card that has facts is not visited again.' );

		remove_filter( 'buddynext_card_facts_backfill', $build );
	}

	public function test_a_card_whose_partner_has_no_facts_is_marked_so_it_is_not_revisited(): void {
		$user = self::factory()->user->create();
		$url  = 'https://example.test/badge/empty/';
		IntegrationActivity::publish( $user, 'earned the Empty badge', $url, 'Empty', 'badge', '', 0, array() );

		$calls = 0;
		$build = static function ( $builders ) use ( &$calls ) {
			$builders['badge'] = static function ( array $card ) use ( &$calls ): array {
				++$calls;
				return array();
			};
			return $builders;
		};
		add_filter( 'buddynext_card_facts_backfill', $build );
		( new CardFactsBackfill() )->run( 0 );
		( new CardFactsBackfill() )->run( 0 );
		remove_filter( 'buddynext_card_facts_backfill', $build );

		$this->assertSame( array(), $this->facts_of( $url ), 'An empty list is written.' );
		$this->assertSame( 1, $calls );
	}
}
