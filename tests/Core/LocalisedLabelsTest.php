<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Reaction alt text comes from the translatable reaction names.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\IconService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\IconService::render_emoji
 */
class LocalisedLabelsTest extends WP_UnitTestCase {

	/**
	 * Swap a string for its "translation".
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate( $translation, $text, $domain ) {
		return 'buddynext' === $domain && 'Like' === $text ? 'XX ' . $text : $translation;
	}

	/**
	 * The default alt text of a reaction emoji is its translated name.
	 *
	 * @return void
	 */
	public function test_reaction_alt_text_is_translated(): void {
		add_filter( 'gettext', array( $this, 'translate' ), 10, 3 );
		$html = IconService::render_emoji( 'like' );
		remove_filter( 'gettext', array( $this, 'translate' ), 10 );

		$this->assertStringContainsString( 'alt="XX Like"', $html );
	}
}
