<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A comment made in the media lightbox shows in the open thread under it.
 *
 * @package BuddyNext\Tests\Feed
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use WP_UnitTestCase;

/**
 * On /p/<id>/ the lightbox comment raised the card's count (buddynext:post-counts)
 * but the open thread stayed stale until reload. The same event now names the
 * comment added or removed, and the post card places or drops it in place.
 *
 * @coversNothing
 */
class LightboxThreadSyncTest extends WP_UnitTestCase {

	/**
	 * Read a plugin file.
	 *
	 * @param string $path Path under the plugin root.
	 * @return string
	 */
	private function source( string $path ): string {
		return (string) file_get_contents( BUDDYNEXT_DIR . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * The lightbox names the comment on the count event.
	 *
	 * @return void
	 */
	public function test_lightbox_names_the_changed_comment(): void {
		$js = $this->source( 'assets/js/media/lightbox.js' );

		$this->assertStringContainsString( 'publishCommentCount( postId, data, { added: data && data.id } )', $js );
		$this->assertStringContainsString( 'publishCommentCount( postId, data, { removed: c.id } )', $js );
	}

	/**
	 * The post card mirrors it in an open thread.
	 *
	 * @return void
	 */
	public function test_post_card_updates_the_open_thread(): void {
		$js = $this->source( 'assets/js/feed/post-card.js' );

		$this->assertStringContainsString( 'bnShowLiveComments( list, [ added ] );', $js );
		$this->assertStringContainsString( 'd.removedCommentId', $js );
	}
}
