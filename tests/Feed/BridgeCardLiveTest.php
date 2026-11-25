<?php
/**
 * A bridge card shows live facts and buttons, for old and new cards alike.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\IntegrationActivity;

/**
 * @covers \BuddyNext\Feed\IntegrationActivity::prime_live
 * @covers \BuddyNext\Feed\IntegrationActivity::actions
 * @covers \BuddyNext\Feed\IntegrationActivity::enrich_item
 * @covers \BuddyNext\Feed\IntegrationActivity::render_bridge_card
 */
class BridgeCardLiveTest extends \WP_UnitTestCase {

	/**
	 * Register a card type and clear the per-request live answers.
	 */
	public function set_up(): void {
		parent::set_up();
		$prop = new \ReflectionProperty( IntegrationActivity::class, 'live' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
		add_filter(
			'buddynext_integration_card_types',
			static fn( array $t ): array => $t + array(
				'widget' => array(
					'label' => 'Widget',
					'icon'  => 'link',
				),
			)
		);
	}

	/**
	 * Render a card the way the post body does.
	 *
	 * @param int   $id        Post id.
	 * @param array $link_meta Stored link meta.
	 * @return string
	 */
	private function render( int $id, array $link_meta ): string {
		return IntegrationActivity::render_bridge_card(
			array(
				'bn_post_id'   => $id,
				'bn_post_type' => 'widget',
				'link_preview' => array(
					'url'   => 'https://example.test/w/' . $id,
					'title' => 'Widget ' . $id,
				),
				'link_meta'    => $link_meta,
			)
		);
	}

	/**
	 * Buttons: at most two, real addresses, the first is the main one.
	 */
	public function test_actions_are_cleaned_and_capped(): void {
		$out = IntegrationActivity::actions(
			array(
				array(
					'label' => '<b>Open</b>',
					'url'   => 'https://example.test/a',
				),
				array(
					'label' => 'Bad',
					'url'   => 'javascript:alert(1)',
				),
				array(
					'label' => '',
					'url'   => 'https://example.test/empty',
				),
				array(
					'label' => str_repeat( 'x', 50 ),
					'url'   => 'https://example.test/b',
				),
				array(
					'label' => 'Third',
					'url'   => 'https://example.test/c',
				),
			)
		);
		$this->assertCount( 2, $out );
		$this->assertSame( 'Open', $out[0]['label'] );
		$this->assertSame( 30, mb_strlen( $out[1]['label'] ) );
	}

	/**
	 * A card with no live answer and no actions renders exactly as it did: no button row.
	 */
	public function test_old_card_renders_as_before(): void {
		$html = $this->render(
			1,
			array(
				'facts' => array(
					array(
						'icon' => 'tag',
						'text' => 'Stored fact',
					),
				),
			)
		);
		$this->assertStringContainsString( 'Stored fact', $html );
		$this->assertStringNotContainsString( 'bn-post-card__bridge-actions', $html );
	}

	/**
	 * A page is asked once per type, the answer wins over the stored facts, and an unanswered card keeps its own.
	 */
	public function test_live_answer_wins_and_the_page_is_one_call(): void {
		$calls = 0;
		add_filter(
			'buddynext_card_live',
			static function ( array $answers, string $type, array $cards ) use ( &$calls ): array {
				++$calls;
				$answers[10] = array(
					'facts'   => array(
						array(
							'icon' => 'tag',
							'text' => 'Live fact',
						),
					),
					'actions' => array(
						array(
							'label' => 'Open widget',
							'url'   => 'https://example.test/open',
						),
						array(
							'label' => 'Preview',
							'url'   => 'https://example.test/preview',
						),
					),
				);
				return $answers;
			},
			10,
			3
		);

		$stored = array(
			'facts' => array(
				array(
					'icon' => 'tag',
					'text' => 'Stored fact',
				),
			),
		);
		IntegrationActivity::prime_live(
			array(
				array(
					'id'        => 10,
					'type'      => 'widget',
					'link_meta' => $stored,
				),
				array(
					'id'        => 11,
					'type'      => 'widget',
					'link_meta' => wp_json_encode( $stored ),
				),
				array(
					'id'   => 12,
					'type' => 'text',
				),
			),
			0
		);

		$this->assertSame( 1, $calls, 'One call for the whole page of widget cards.' );

		$live = $this->render( 10, $stored );
		$this->assertStringContainsString( 'Live fact', $live );
		$this->assertStringNotContainsString( 'Stored fact', $live );
		$this->assertStringContainsString( 'Open widget', $live );
		$this->assertStringContainsString( 'data-variant="primary"', $live );
		$this->assertSame( 1, substr_count( $live, 'data-variant="primary"' ), 'Only the first button is the main one.' );
		$this->assertSame( 1, substr_count( $live, 'data-variant="secondary"' ) );

		$unanswered = $this->render( 11, $stored );
		$this->assertStringContainsString( 'Stored fact', $unanswered );
		$this->assertStringNotContainsString( 'bn-post-card__bridge-actions', $unanswered );
	}

	/**
	 * REST carries the same facts and buttons the page shows.
	 */
	public function test_rest_item_carries_the_card(): void {
		add_filter(
			'buddynext_card_actions',
			static fn( array $a ): array => array(
				array(
					'label' => 'Go',
					'url'   => 'https://example.test/go',
				),
			)
		);
		$item = IntegrationActivity::enrich_item(
			array(
				'id'        => 20,
				'type'      => 'widget',
				'link_meta' => array(
					'facts' => array(
						array(
							'icon' => '',
							'text' => 'From REST',
						),
					),
				),
			),
			0
		);
		$this->assertSame( 'From REST', $item['card']['facts'][0]['text'] );
		$this->assertSame( 'Go', $item['card']['actions'][0]['label'] );

		$plain = IntegrationActivity::enrich_item(
			array(
				'id'   => 21,
				'type' => 'text',
			),
			0
		);
		$this->assertArrayNotHasKey( 'card', $plain );
	}
}
