<?php
/**
 * A bridge can ask whether a card already carries a link_meta value, live or withdrawn.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\IntegrationActivity;

/**
 * @covers \BuddyNext\Feed\IntegrationActivity::has_card_by_meta
 */
class IntegrationActivityHasCardTest extends \WP_UnitTestCase {

	public function test_it_finds_a_live_card_and_a_withdrawn_one_but_not_another_value_or_a_removed_one(): void {
		Installer::run();
		$member = self::factory()->user->create();
		IntegrationActivity::publish( $member, 'x', 'https://example.test/c1/', 'C1', 'event', '', 0, array( 'event_id' => 501, 'series_id' => 31 ) );

		$this->assertTrue( IntegrationActivity::has_card_by_meta( 'event', 'series_id', 31 ) );
		$this->assertFalse( IntegrationActivity::has_card_by_meta( 'event', 'series_id', 32 ), 'Another series.' );
		$this->assertFalse( IntegrationActivity::has_card_by_meta( 'event', 'series_id', 0 ), 'No value, no card.' );

		IntegrationActivity::withdraw_by_meta( 'event', 'event_id', 501 );
		$this->assertTrue( IntegrationActivity::has_card_by_meta( 'event', 'series_id', 31 ), 'A withdrawn card still counts.' );

		IntegrationActivity::remove_by_meta( 'event', 'event_id', 501 );
		$this->assertFalse( IntegrationActivity::has_card_by_meta( 'event', 'series_id', 31 ), 'A removed card does not.' );
	}
}
