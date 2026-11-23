<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Reaction alt text and the Features tab group headings come from translatable labels.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\IconService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\IconService::render_emoji
 * @covers \BuddyNext\Admin\Settings
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
		return 'buddynext' === $domain && in_array( $text, array( 'Like', 'Core (always on)' ), true ) ? 'XX ' . $text : $translation;
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

	/**
	 * A plugin names its own feature groups through the filter.
	 *
	 * @return void
	 */
	public function test_feature_group_headings_come_from_the_filter(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'buddynext_feature_group_labels', static fn( $labels ) => array( 'core' => 'Kern' ) + $labels );

		ob_start();
		( new \ReflectionMethod( \BuddyNext\Admin\Settings::class, 'render_tab_features' ) )->invoke( new \BuddyNext\Admin\Settings() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h3 class="bn-feature-group">Kern</h3>', $html );
	}
}
