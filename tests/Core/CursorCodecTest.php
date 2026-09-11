<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Tests for CursorCodec.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CursorCodec;

/**
 * @covers \BuddyNext\Core\CursorCodec
 */
class CursorCodecTest extends \WP_UnitTestCase {

	public function test_round_trip_without_tier(): void {
		$cursor  = CursorCodec::encode( '2026-08-04 10:00:00', 42 );
		$decoded = CursorCodec::decode( $cursor );

		$this->assertSame( '2026-08-04 10:00:00', $decoded['created_at'] );
		$this->assertSame( 42, $decoded['id'] );
		$this->assertNull( $decoded['tier'], 'A chronological cursor carries no tier.' );
	}

	public function test_round_trip_with_tier(): void {
		$cursor  = CursorCodec::encode( '2026-08-04 10:00:00', 42, 1 );
		$decoded = CursorCodec::decode( $cursor );

		$this->assertSame( '2026-08-04 10:00:00', $decoded['created_at'] );
		$this->assertSame( 42, $decoded['id'] );
		$this->assertSame( 1, $decoded['tier'] );
	}

	public function test_legacy_two_part_cursor_still_decodes(): void {
		// A cursor minted before tiers were encoded (raw "ts|id") must keep
		// decoding — clients in the wild hold these across a deploy.
		$legacy  = base64_encode( '2026-08-04 10:00:00|42' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$decoded = CursorCodec::decode( $legacy );

		$this->assertNotNull( $decoded );
		$this->assertSame( 42, $decoded['id'] );
		$this->assertNull( $decoded['tier'] );
	}

	public function test_malformed_cursor_decodes_to_null(): void {
		$this->assertNull( CursorCodec::decode( 'not-base64!!' ) );
		$this->assertNull( CursorCodec::decode( base64_encode( 'no-separator' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * A cursor survives an add_query_arg() roundtrip.
	 *
	 * The old hand-rolled base64 kept '=' padding, which add_query_arg() dropped,
	 * corrupting the pivot for two-thirds of ids (card 10284805802). This is the
	 * roundtrip every cursor consumer relies on, bookmarks included, now that
	 * BookmarkService encodes/decodes through this codec.
	 *
	 * @return void
	 */
	public function test_survives_add_query_arg_roundtrip(): void {
		// Ids that force '=' padding under standard base64.
		foreach ( array( 2, 22, 222 ) as $id ) {
			$cursor = CursorCodec::encode( '2026-08-04 10:00:00', $id );
			$url    = add_query_arg( array( 'cursor' => $cursor ), 'https://example.test/feed' );

			$query = array();
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$decoded = CursorCodec::decode( (string) ( $query['cursor'] ?? '' ) );

			$this->assertNotNull( $decoded, "Cursor for id {$id} did not survive add_query_arg()." );
			$this->assertSame( $id, $decoded['id'], "Cursor for id {$id} decoded to the wrong pivot." );
			$this->assertSame( '2026-08-04 10:00:00', $decoded['created_at'] );
		}
	}

	public function test_parse_trail_empty_is_no_trail(): void {
		$this->assertSame( array(), CursorCodec::parse_trail( '' ) );
		$this->assertSame( array( 'c1' ), CursorCodec::parse_trail( 'c1' ) );
		$this->assertSame( array( 'c1', 'c2' ), CursorCodec::parse_trail( 'c1,c2' ) );
	}

	public function test_push_trail_omits_page1_empty_cursor(): void {
		// Page 1's own cursor is '' and must never enter the trail.
		$this->assertSame( '', CursorCodec::push_trail( array(), '' ) );
		$this->assertSame( 'c1', CursorCodec::push_trail( array(), 'c1' ) );
		$this->assertSame( 'c1,c2', CursorCodec::push_trail( array( 'c1' ), 'c2' ) );
	}

	public function test_pop_trail_steps_back_one_page(): void {
		// Page 2 (trail []) -> previous is page 1: no cursor, no trail.
		$this->assertSame( array( 'after' => '', 'trail' => '' ), CursorCodec::pop_trail( array() ) );
		// Page 3 (trail [c1]) -> previous is page 2: bn_after=c1, no trail.
		$this->assertSame( array( 'after' => 'c1', 'trail' => '' ), CursorCodec::pop_trail( array( 'c1' ) ) );
		// Page 4 (trail [c1,c2]) -> previous is page 3: bn_after=c2, trail=c1.
		$this->assertSame( array( 'after' => 'c2', 'trail' => 'c1' ), CursorCodec::pop_trail( array( 'c1', 'c2' ) ) );
	}

	public function test_trail_round_trips_a_forward_then_backward_walk(): void {
		// Walk 1->2->3->4 building each next page's ?bn_prev, then walk back and
		// assert every step lands on the exact page it came from. Guards the whole
		// keyset back-navigation model against a regression to "Previous -> page 1".
		$c1 = 'AAAA';
		$c2 = 'BBBB';
		$c3 = 'CCCC';

		$p1_next = CursorCodec::push_trail( CursorCodec::parse_trail( '' ), '' );      // page 1 (after '')
		$this->assertSame( '', $p1_next, 'Page 2 carries no trail.' );

		$p2_next = CursorCodec::push_trail( CursorCodec::parse_trail( $p1_next ), $c1 ); // page 2 (after c1)
		$this->assertSame( $c1, $p2_next, 'Page 3 trail is [c1].' );

		$p3_next = CursorCodec::push_trail( CursorCodec::parse_trail( $p2_next ), $c2 ); // page 3 (after c2)
		$this->assertSame( "$c1,$c2", $p3_next, 'Page 4 trail is [c1,c2].' );

		// On page 4 (after c3, trail c1,c2), Previous -> page 3 (after c2, trail c1).
		$back = CursorCodec::pop_trail( CursorCodec::parse_trail( $p3_next ) );
		$this->assertSame( $c2, $back['after'] );
		$this->assertSame( $c1, $back['trail'] );

		// From page 3, Previous -> page 2 (after c1, no trail).
		$back = CursorCodec::pop_trail( CursorCodec::parse_trail( $back['trail'] ) );
		$this->assertSame( $c1, $back['after'] );
		$this->assertSame( '', $back['trail'] );

		// From page 2, Previous -> page 1 (no after, no trail).
		$back = CursorCodec::pop_trail( CursorCodec::parse_trail( $back['trail'] ) );
		$this->assertSame( '', $back['after'] );
		$this->assertSame( '', $back['trail'] );
	}
}
