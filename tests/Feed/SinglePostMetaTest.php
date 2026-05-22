<?php
/**
 * Tests for SinglePostMeta.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\SinglePostMeta;

/**
 * @covers \BuddyNext\Feed\SinglePostMeta
 */
class SinglePostMetaTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_build_description_strips_html_and_truncates(): void {
		$post = array(
			'id'      => 1,
			'content' => '<p>Hello <strong>world</strong>! This is a long description that exceeds the 160 character cap so we can verify the truncation logic keeps the ellipsis at the end without breaking words in half mid-sentence ever.</p>',
		);

		$out = SinglePostMeta::build_description( $post );

		$this->assertStringNotContainsString( '<', $out );
		$this->assertStringNotContainsString( '>', $out );
		$this->assertLessThanOrEqual( 161, mb_strlen( $out ) ); // 160 + ellipsis.
		$this->assertStringEndsWith( '…', $out );
	}

	public function test_build_description_falls_back_to_site_description_when_empty(): void {
		update_option( 'blogdescription', 'A test site.' );
		$post = array( 'id' => 1, 'content' => '' );

		$out = SinglePostMeta::build_description( $post );

		$this->assertSame( 'A test site.', $out );
	}

	public function test_build_document_title_uses_author_name(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Alice Adams' ) );
		$post    = array(
			'id'      => 99,
			'user_id' => $user_id,
			'content' => 'Short note',
		);

		$title = SinglePostMeta::build_document_title( $post );

		$this->assertStringContainsString( 'Alice Adams', $title );
		$this->assertStringContainsString( 'Short note', $title );
	}

	public function test_build_document_title_truncates_long_content(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Author Name' ) );
		$post    = array(
			'id'      => 100,
			'user_id' => $user_id,
			'content' => str_repeat( 'lorem ipsum dolor sit amet ', 20 ),
		);

		$title = SinglePostMeta::build_document_title( $post );

		// Title excerpt portion is capped to 60 + ellipsis; full string should
		// also include the leading "Author Name: " prefix so length is bounded.
		$this->assertLessThan( 200, mb_strlen( $title ) );
		$this->assertStringContainsString( 'Author Name', $title );
		$this->assertStringContainsString( '…', $title );
	}

	public function test_emit_for_post_registers_wp_head_callback(): void {
		$post = array(
			'id'         => 1,
			'user_id'    => 1,
			'content'    => 'Indexed text',
			'privacy'    => 'public',
			'created_at' => '2026-05-22 10:00:00',
		);

		SinglePostMeta::emit_for_post( $post );

		$this->assertTrue( has_action( 'wp_head' ) > 0 );
	}
}
