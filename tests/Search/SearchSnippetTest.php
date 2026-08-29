<?php
/**
 * Search-result snippet rendering.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Search\SearchSnippet;
use WP_UnitTestCase;

/**
 * Indexed job and listing bodies are rich HTML. The snippet builder escaped
 * them, so every result rendered a wall of literal tags under its title.
 */
class SearchSnippetTest extends WP_UnitTestCase {

	/**
	 * Markup never reaches the reader as literal text.
	 *
	 * @return void
	 */
	public function test_html_tags_are_removed_not_escaped(): void {
		$out = SearchSnippet::render( '<p>Build APIs</p><ul><li>PHP 8+</li></ul>', 'APIs' );

		$this->assertStringNotContainsString( '&lt;', $out );
		$this->assertStringNotContainsString( '<li>', $out );
		$this->assertStringNotContainsString( '<p>', $out );
	}

	/**
	 * A tag boundary becomes a space. Deleting tags outright joined the words on
	 * either side ("team.What you'll doBuild"), which reads worse than the
	 * leaked markup it replaced.
	 *
	 * @return void
	 */
	public function test_a_tag_boundary_becomes_a_space(): void {
		$out = SearchSnippet::render( '<p>growing team.</p><h3>What you do</h3>', '' );

		$this->assertSame( 'growing team. What you do', $out );
	}

	/**
	 * Entities are decoded, so a stored "We&#039;re" reads as "We're".
	 *
	 * @return void
	 */
	public function test_entities_are_decoded(): void {
		$out = SearchSnippet::render( '<p>We&#039;re hiring</p>', '' );

		$this->assertSame( 'We&#039;re hiring', $out );
	}

	/**
	 * Script and style bodies never reach the snippet.
	 *
	 * @return void
	 */
	public function test_script_and_style_bodies_are_dropped(): void {
		$out = SearchSnippet::render( '<style>.a{color:red}</style>Hello<script>alert(1)</script>', '' );

		$this->assertSame( 'Hello', $out );
	}

	/**
	 * The matched term is marked.
	 *
	 * @return void
	 */
	public function test_the_query_term_is_marked(): void {
		$out = SearchSnippet::render( 'Senior PHP Developer wanted', 'Developer' );

		$this->assertStringContainsString( '<mark>Developer</mark>', $out );
	}

	/**
	 * A query containing HTML cannot inject a tag through the highlight pass.
	 *
	 * @return void
	 */
	public function test_an_html_query_cannot_inject_markup(): void {
		$out = SearchSnippet::render( 'harmless text', '<img src=x onerror=alert(1)>' );

		$this->assertStringNotContainsString( '<img', $out );
		$this->assertStringNotContainsString( 'onerror=alert', $out );
	}

	/**
	 * Windowing is multibyte-safe. The byte functions cut a multibyte character
	 * in half at the window edge, which renders as a replacement glyph.
	 *
	 * @return void
	 */
	public function test_the_window_does_not_split_a_multibyte_character(): void {
		$out = SearchSnippet::render( str_repeat( 'é', 400 ), '' );

		$this->assertSame( 200, mb_strlen( $out ) );
		$this->assertStringNotContainsString( "\xEF\xBF\xBD", $out ); // U+FFFD.
	}

	/**
	 * The window follows the match rather than always starting at the top, so a
	 * term deep in a long body is visible in its snippet.
	 *
	 * @return void
	 */
	public function test_the_window_follows_a_late_match(): void {
		$out = SearchSnippet::render( str_repeat( 'padding ', 100 ) . 'needle here', 'needle' );

		$this->assertStringContainsString( '<mark>needle</mark>', $out );
		$this->assertStringStartsWith( '…', $out );
	}
}
