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
}
