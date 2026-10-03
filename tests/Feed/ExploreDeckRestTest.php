<?php
/**
 * GET /feed/explore/deck: the Explore deck as typed JSON cards.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\ExploreService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * /feed/explore ignored the filter and /feed/explore/page returned HTML only,
 * so the app could not show the Members / Spaces / Media chips or the mixed deck.
 *
 * @covers \BuddyNext\Feed\FeedController::explore_deck
 */
class ExploreDeckRestTest extends WP_UnitTestCase {

	/**
	 * Fresh schema and decks.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		ExploreService::flush_decks();
	}

	/**
	 * The deck for a filter.
	 *
	 * @param string $filter Filter.
	 * @return array<string,mixed>
	 */
	private function deck( string $filter ): array {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/feed/explore/deck' );
		$request->set_param( 'filter', $filter );
		return rest_do_request( $request )->get_data();
	}

	/**
	 * Same cards as the web deck, typed and enriched.
	 *
	 * @return void
	 */
	public function test_deck_is_typed_and_enriched(): void {
		$author = self::factory()->user->create( array( 'display_name' => 'Deck Author' ) );
		( new \BuddyNext\Feed\PostService() )->create( $author, array( 'content' => 'Hello explore' ) );
		ExploreService::flush_decks();

		$posts = $this->deck( 'posts' );
		$this->assertSame( 'posts', $posts['filter'] );
		$this->assertNotEmpty( $posts['items'] );
		$first = $posts['items'][0];
		$this->assertArrayHasKey( 'post', $first );
		$this->assertArrayHasKey( 'author', $first['post'], 'Posts are enriched like the feed.' );
		$this->assertArrayHasKey( 'viewer_state', $first['post'] );

		$members = $this->deck( 'members' );
		foreach ( $members['items'] as $item ) {
			$this->assertSame( 'member', $item['kind'] );
			$this->assertArrayHasKey( 'display_name', $item['member'], 'Members are hydrated like the directory.' );
		}

		$web = ( new ExploreService() )->deck( 'members', null, 20 );
		$this->assertSame(
			array_map( static fn( $c ) => (int) $c['user_id'], $web['items'] ),
			array_map( static fn( $i ) => (int) $i['member']['user_id'], $members['items'] ),
			'Same members, same order as the web deck.'
		);

		$bad = new WP_REST_Request( 'GET', '/buddynext/v1/feed/explore/deck' );
		$bad->set_param( 'filter', 'nope' );
		$this->assertSame( 400, rest_do_request( $bad )->get_status(), 'Unknown filter is refused.' );
	}
}
