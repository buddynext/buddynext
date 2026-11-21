<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The facts line of a bridge card: cleaned, capped at four, hidden when empty, and open to a filter.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\IntegrationActivity;
use WP_UnitTestCase;

class BridgeCardFactsTest extends WP_UnitTestCase {

	private function card( array $link_meta, array $link = array() ): string {
		return IntegrationActivity::render_bridge_card(
			array(
				'link_preview' => array_merge(
					array(
						'url'   => 'https://example.test/job/1/',
						'title' => 'A job',
						'desc'  => 'Some description.',
					),
					$link
				),
				'link_meta'    => $link_meta,
				'bn_post_type' => 'link',
			),
			'briefcase',
			'Job Listing'
		);
	}

	public function test_empty_facts_are_left_out_and_the_rest_render_in_order(): void {
		$html = $this->card(
			array(
				'facts' => array(
					array(
						'icon' => 'building',
						'text' => 'Northwind Photo Co.',
					),
					array(
						'icon' => 'map-pin',
						'text' => '',
					),
					array(
						'icon' => 'banknote',
						'text' => 'USD 90,000-130,000 per year',
					),
				),
			)
		);
		$this->assertStringContainsString( 'bn-post-card__bridge-facts', $html );
		$this->assertStringContainsString( 'Northwind Photo Co.', $html );
		$this->assertStringContainsString( 'USD 90,000-130,000 per year', $html );
		$this->assertSame( 2, substr_count( $html, '<li>' ), 'The empty place is not drawn.' );
		$this->assertLessThan( strpos( $html, 'USD 90,000' ), strpos( $html, 'Northwind' ), 'Order kept.' );
	}

	public function test_no_facts_means_no_list_at_all(): void {
		$this->assertStringNotContainsString( 'bridge-facts', $this->card( array() ) );
		$this->assertStringNotContainsString(
			'bridge-facts',
			$this->card(
				array(
					'facts' => array(
						array(
							'icon' => 'tag',
							'text' => '  ',
						),
					),
				)
			)
		);
	}

	public function test_at_most_four_facts_each_one_short_line_with_no_markup(): void {
		$clean = IntegrationActivity::facts(
			array(
				array( 'text' => 'One' ),
				array( 'text' => '<b>Two</b>' ),
				array( 'text' => 'Three' ),
				array( 'text' => 'Four' ),
				array( 'text' => 'Five' ),
				'not an array',
			)
		);
		$this->assertCount( 4, $clean );
		$this->assertSame( 'Two', $clean[1]['text'], 'Markup is stripped.' );
		$long = IntegrationActivity::facts( array( array( 'text' => str_repeat( 'x', 200 ) ) ) );
		$this->assertSame( 80, mb_strlen( $long[0]['text'] ), 'A fact stays one short line.' );
	}

	public function test_facts_can_come_from_the_hydrated_preview(): void {
		$html = $this->card(
			array(),
			array(
				'facts' => array(
					array(
						'icon' => 'clock',
						'text' => '2:41',
					),
				),
			)
		);
		$this->assertStringContainsString( '2:41', $html, 'A bridge that resolves facts per viewer (MediaVerse) passes them in link_preview.' );
	}

	public function test_a_site_can_change_the_facts_with_the_filter(): void {
		$add = static function ( array $facts ): array {
			$facts[] = array(
				'icon' => 'tag',
				'text' => 'Featured',
			);
			return $facts;
		};
		add_filter( 'buddynext_card_facts', $add );
		$html = $this->card( array() );
		remove_filter( 'buddynext_card_facts', $add );
		$this->assertStringContainsString( 'Featured', $html );

		add_filter( 'buddynext_card_facts', '__return_empty_array' );
		$html = $this->card(
			array(
				'facts' => array(
					array(
						'icon' => 'tag',
						'text' => 'Hidden by the site',
					),
				),
			)
		);
		remove_filter( 'buddynext_card_facts', '__return_empty_array' );
		$this->assertStringNotContainsString( 'Hidden by the site', $html );
	}

	public function test_a_partner_card_goes_into_an_open_space_only(): void {
		$owner = self::factory()->user->create();
		$make  = static fn( string $type ): int => (int) buddynext_service( 'spaces' )->create(
			$owner,
			array(
				'name' => 'Card space ' . $type . wp_rand(),
				'slug' => 'card-space-' . $type . '-' . wp_rand( 10000, 99999 ),
				'type' => $type,
			)
		);
		$open  = $make( 'open' );

		$this->assertSame( $open, IntegrationActivity::card_space( $open ), 'An open Space gets the card in its own feed.' );
		$this->assertSame( 0, IntegrationActivity::card_space( $make( 'private' ) ), 'A private Space: the card stays site-wide.' );
		$this->assertSame( 0, IntegrationActivity::card_space( $make( 'secret' ) ), 'A secret Space too.' );
		$this->assertSame( 0, IntegrationActivity::card_space( 0 ) );
		$this->assertSame( 0, IntegrationActivity::card_space( 999999 ), 'A Space that does not exist.' );

		$other = $make( 'open' );
		$this->assertSame( $other, IntegrationActivity::card_space( array( $make( 'private' ), $other, $open ) ), 'Several Spaces: the first open one wins, in the order given.' );
		$this->assertSame( 0, IntegrationActivity::card_space( array( $make( 'secret' ), 0 ) ), 'No open Space in the list: site-wide.' );

		$never = static fn(): int => 0;
		add_filter( 'buddynext_partner_card_space', $never );
		$this->assertSame( 0, IntegrationActivity::card_space( $open ), 'A site can keep every card site-wide.' );
		remove_filter( 'buddynext_partner_card_space', $never );
	}

	public function test_a_small_set_runs_inline_and_a_large_one_is_queued_in_chunks(): void {
		$ran = array();
		IntegrationActivity::run_in_chunks( 'bn_test_chunk_hook', range( 1, 10 ), static function ( $id ) use ( &$ran ) {
			$ran[] = $id;
		} );
		$this->assertSame( range( 1, 10 ), $ran, 'A small set runs now, in order.' );

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}
		$ran = array();
		IntegrationActivity::run_in_chunks( 'bn_test_chunk_hook', range( 1, 230 ), static function ( $id ) use ( &$ran ) {
			$ran[] = $id;
		} );
		$this->assertSame( array(), $ran, 'A large set is not run inside the request.' );
		$this->assertCount( 3, as_get_scheduled_actions( array( 'hook' => 'bn_test_chunk_hook', 'status' => 'pending' ), 'ids' ), '230 items in chunks of 100.' );
		as_unschedule_all_actions( 'bn_test_chunk_hook' );
	}
}
