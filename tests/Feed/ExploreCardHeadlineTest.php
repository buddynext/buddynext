<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * An Explore card leads with what the member wrote (card 10435762058). A link
 * preview's page title used to replace the member's words on every post that
 * carried one, so a shared link read as the linked site's own headline.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @coversNothing Template partial: templates/partials/explore-card.php.
 */
class ExploreCardHeadlineTest extends \WP_UnitTestCase {

	private int $author;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->author = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function card_for( string $type, string $content, string $kind ): string {
		$posts = new PostService();
		$id    = (int) $posts->create( $this->author, array( 'content' => $content, 'type' => 'discussion' === $type ? 'text' : $type ) );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array(
				'type'      => $type,
				'link_url'  => 'https://example.org/',
				'link_meta' => wp_json_encode(
					array(
						'url'   => 'https://example.org/',
						'title' => 'Linked Site Page Title',
					)
				),
			),
			array( 'id' => $id )
		);
		wp_cache_flush();

		ob_start();
		buddynext_get_template(
			'partials/explore-card.php',
			array(
				'card'            => array(
					'kind' => $kind,
					'post' => $posts->get( $id ),
				),
				'current_user_id' => $this->author,
			)
		);
		$html = (string) ob_get_clean();
		preg_match( '#class="ec-title"[^>]*>(.*?)</#s', $html, $m );
		return trim( html_entity_decode( $m[1] ?? '' ) );
	}

	public function test_member_text_leads_over_the_link_title(): void {
		$this->assertSame( 'Worth a read for anyone new here https://example.org', $this->card_for( 'text', 'Worth a read for anyone new here https://example.org', 'post-text' ) );
	}

	public function test_a_bare_url_post_shows_the_link_title(): void {
		$this->assertSame( 'Linked Site Page Title', $this->card_for( 'link', 'https://example.org', 'post-text' ) );
	}

	public function test_a_synced_discussion_keeps_its_topic_title(): void {
		$this->assertSame( 'Linked Site Page Title', $this->card_for( 'discussion', 'started a discussion', 'post-forum' ) );
	}
}
