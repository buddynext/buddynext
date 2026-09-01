<?php
/**
 * Search-result snippet: plain-text excerpt with the matched terms marked.
 *
 * Lives here rather than as a closure in templates/search/results.php because
 * three result sections call it, it does real work (tag removal, entity
 * decoding, multibyte windowing) and it sits on an escaping path — none of
 * which can be tested while it is an anonymous function inside a template.
 *
 * @package BuddyNext\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the highlighted snippet shown under each search result.
 */
class SearchSnippet {

	/**
	 * Characters of context kept around the first match.
	 */
	private const WINDOW = 200;

	/**
	 * Characters of lead-in shown before the match.
	 */
	private const LEAD = 60;

	/**
	 * Render a snippet: markup removed, terms wrapped in `<mark>`.
	 *
	 * Returns HTML that is already escaped apart from the `<mark>` tags this
	 * adds, so callers echo it without escaping again.
	 *
	 * @param string $text  Raw indexed content, which may be rich HTML.
	 * @param string $query The search query.
	 * @return string Safe HTML.
	 */
	public static function render( string $text, string $query ): string {
		$text = self::to_plain_text( $text );

		if ( '' === $query ) {
			return esc_html( mb_substr( $text, 0, self::WINDOW ) );
		}

		return self::mark( self::window( $text, $query ), $query );
	}

	/**
	 * Reduce rich HTML to readable plain text.
	 *
	 * STRIPS the markup rather than escaping it into view. Indexed job and
	 * listing bodies are rich HTML, and escaping them turned every snippet into
	 * a wall of literal tags: "…scalable backend services with PHP
	 * 8+</li><li>Design RESTful APIs…". Escaping was right for safety and wrong
	 * for reading — the tags are formatting, not content, and a snippet has no
	 * use for them.
	 *
	 * Each tag becomes a SPACE rather than being deleted. `wp_strip_all_tags()`
	 * removes them outright, and rich bodies carry no whitespace across a tag
	 * boundary, so "team.</p><h3>What you'll do</h3><ul><li>Build" collapsed to
	 * "team.What you'll doBuild" — which reads worse than the leaked markup did.
	 * Script and style bodies are dropped whole first (the same two steps
	 * `wp_strip_all_tags()` takes) so nothing inside them reaches the snippet.
	 *
	 * @param string $text Raw content.
	 * @return string Plain text, whitespace collapsed.
	 */
	private static function to_plain_text( string $text ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', ' ', $text );
		$text = (string) preg_replace( '/<[^>]*>/', ' ', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * The excerpt window around the first match.
	 *
	 * Multibyte-safe throughout: the byte functions cut a multibyte character at
	 * the window edges, which renders as a replacement glyph on any non-ASCII
	 * content.
	 *
	 * @param string $text  Plain text.
	 * @param string $query The search query.
	 * @return string
	 */
	private static function window( string $text, string $query ): string {
		$pos = mb_stripos( $text, $query );

		if ( false === $pos ) {
			return mb_substr( $text, 0, self::WINDOW );
		}

		$start = max( 0, $pos - self::LEAD );

		return ( $start > 0 ? '…' : '' ) . mb_substr( $text, $start, self::WINDOW );
	}

	/**
	 * Escape, then wrap each query term in `<mark>`.
	 *
	 * Escaping happens FIRST and the terms are matched in their escaped form, so
	 * a query containing HTML cannot inject a tag through the highlight pass.
	 *
	 * @param string $text  The excerpt window.
	 * @param string $query The search query.
	 * @return string Safe HTML.
	 */
	private static function mark( string $text, string $query ): string {
		$escaped = esc_html( $text );
		$terms   = array_filter( array_map( 'trim', explode( ' ', $query ) ) );

		foreach ( $terms as $term ) {
			$escaped = (string) preg_replace(
				'/(' . preg_quote( esc_html( $term ), '/' ) . ')/i',
				'<mark>$1</mark>',
				$escaped
			);
		}

		return $escaped;
	}
}
