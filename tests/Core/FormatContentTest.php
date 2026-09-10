<?php
/**
 * Tests for buddynext_format_content() URL auto-linkification.
 *
 * Focused on the bare-URL pass added so pasted links render clickable, plus
 * the guarantees that protect it: a URL fragment is not turned into a hashtag,
 * trailing sentence punctuation stays out of the link, and the existing
 * markdown-link / plain-text behaviour is unchanged.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * Content-formatter URL auto-linkification tests.
 *
 * @covers ::buddynext_format_content
 */
class FormatContentTest extends \WP_UnitTestCase {

	/**
	 * A bare http(s) URL becomes a clickable anchor with the safe rel set.
	 */
	public function test_bare_url_is_linkified(): void {
		$html = buddynext_format_content( 'Docs are at https://example.com/buddynext now' );

		$this->assertStringContainsString(
			'<a href="https://example.com/buddynext" class="bn-autolink" rel="noopener nofollow ugc">https://example.com/buddynext</a>',
			$html
		);
	}

	/**
	 * A "#fragment" inside a URL must stay part of the URL, not become a
	 * separate hashtag link nested inside the anchor.
	 */
	public function test_url_fragment_is_not_a_hashtag(): void {
		$html = buddynext_format_content( 'See https://example.com/guide#setup here' );

		$this->assertStringContainsString( 'href="https://example.com/guide#setup"', $html );
		// The fragment is inside the autolink, so no hashtag anchor is emitted.
		$this->assertStringNotContainsString( 'bn-hashtag', $html );
	}

	/**
	 * Trailing sentence punctuation is excluded from the linked URL.
	 */
	public function test_trailing_punctuation_excluded_from_url(): void {
		$html = buddynext_format_content( 'Visit https://example.com.' );

		$this->assertStringContainsString( 'href="https://example.com"', $html );
		// The period sits after the closing tag, not inside the href.
		$this->assertStringContainsString( '</a>.', $html );
	}

	/**
	 * An explicit [label](url) markdown link still renders as a markdown link
	 * (its target URL is not double-processed by the bare-URL pass).
	 */
	public function test_markdown_link_is_not_double_linked(): void {
		$html = buddynext_format_content( '[the docs](https://example.com/x)' );

		$this->assertStringContainsString( 'class="bn-md-link"', $html );
		$this->assertStringContainsString( '>the docs</a>', $html );
		$this->assertStringNotContainsString( 'bn-autolink', $html );
	}

	/**
	 * Plain text with no URL is returned unchanged.
	 */
	public function test_plain_text_unchanged(): void {
		$this->assertSame( 'just some words', buddynext_format_content( 'just some words' ) );
	}

	/**
	 * An ampersand is encoded exactly ONCE whether the caller passes raw text or the
	 * wp_kses_post-escaped stored value — no more '&amp;amp;' rendering as a literal
	 * '&amp;' (card 10280255141).
	 *
	 * @return void
	 */
	public function test_ampersand_is_encoded_exactly_once(): void {
		$this->assertSame( 'Tom &amp; Jerry', buddynext_format_content( 'Tom & Jerry' ), 'Raw ampersand encodes once.' );
		$this->assertSame( 'Tom &amp; Jerry', buddynext_format_content( 'Tom &amp; Jerry' ), 'Pre-escaped ampersand must not double-encode.' );
		$this->assertStringNotContainsString( '&amp;amp;', buddynext_format_content( 'A & B &amp; C' ) );
	}

	/**
	 * The decode step must not open an XSS hole: an escaped or raw tag is re-escaped,
	 * never emitted as a live element.
	 *
	 * @return void
	 */
	public function test_tags_are_re_escaped_not_executed(): void {
		$this->assertSame( '&lt;script&gt;x&lt;/script&gt;', buddynext_format_content( '&lt;script&gt;x&lt;/script&gt;' ) );
		$this->assertSame( '&lt;b&gt;raw&lt;/b&gt;', buddynext_format_content( '<b>raw</b>' ) );
	}
}
