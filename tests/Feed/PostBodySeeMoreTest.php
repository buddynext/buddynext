<?php
/**
 * See more on a long post expands it in the feed, and stays a real permalink.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\PageRouter;

/**
 * A long text post previews its first lines in a feed. A plain click on See
 * more expands it in place (post-card store actions.expandBody, bound below);
 * the href remains the permalink for no-JS readers, new-tab clicks and crawlers.
 * The permalink itself never clips.
 *
 * @coversNothing
 */
class PostBodySeeMoreTest extends \WP_UnitTestCase {

	/**
	 * Render the post-body part.
	 *
	 * @param string $content Post text.
	 * @param string $context Rendering surface.
	 * @return string
	 */
	private function render( string $content, string $context ): string {
		ob_start();
		buddynext_get_template(
			'parts/post-body.php',
			array(
				'bn_post_id'   => 42,
				'bn_post_type' => 'text',
				'post_content' => $content,
				'context'      => $context,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * A long post in a feed is clipped, with an in-place See more.
	 *
	 * @return void
	 */
	public function test_long_feed_post_expands_in_place(): void {
		$html = $this->render( str_repeat( 'A long community update. ', 30 ), 'home' );

		$this->assertStringContainsString( 'bn-post-card__content--preview', $html );
		$this->assertStringContainsString( 'id="bn-post-body-42"', $html );
		$this->assertStringContainsString( 'data-wp-class--bn-post-card__content--preview="!context.bodyExpanded"', $html );
		$this->assertStringContainsString( 'data-wp-on--click="actions.expandBody"', $html );
		$this->assertStringContainsString( 'aria-controls="bn-post-body-42"', $html );
		$this->assertStringContainsString( 'data-wp-bind--hidden="context.bodyExpanded"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( PageRouter::post_url( 42 ) ) . '"', $html, 'Still a real link to the permalink.' );
	}

	/**
	 * Short posts and the permalink show everything, with no See more.
	 *
	 * @return void
	 */
	public function test_short_posts_and_the_permalink_are_not_clipped(): void {
		foreach ( array(
			'short feed post' => $this->render( 'Short update.', 'home' ),
			'permalink'       => $this->render( str_repeat( 'A long community update. ', 30 ), '' ),
		) as $label => $html ) {
			$this->assertStringNotContainsString( 'bn-post-card__content--preview', $html, $label );
			$this->assertStringNotContainsString( 'actions.expandBody', $html, $label );
		}
	}
}
