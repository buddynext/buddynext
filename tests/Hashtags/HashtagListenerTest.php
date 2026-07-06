<?php
/**
 * Tests that hashtags follow post edits.
 *
 * Regression guard for card 10062125012: buddynext_post_updated was fired but
 * had no hashtag consumer, so an edited post kept its original tag set — removed
 * #tags lingered on the tag page and newly added #tags never appeared.
 *
 * @package BuddyNext\Tests\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Hashtags;

use BuddyNext\Core\Installer;
use BuddyNext\Hashtags\HashtagListener;
use BuddyNext\Hashtags\HashtagService;

/**
 * @covers \BuddyNext\Hashtags\HashtagListener
 */
class HashtagListenerTest extends \WP_UnitTestCase {

	private HashtagService $service;
	private HashtagListener $listener;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service  = new HashtagService();
		$this->listener = new HashtagListener( $this->service );
	}

	/**
	 * Insert a published post and return its id.
	 *
	 * @param string $content Post content.
	 * @return int Post id.
	 */
	private function seed_post( string $content ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => self::factory()->user->create(),
				'content'    => $content,
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return string[] Slugs currently linked to the post, sorted.
	 */
	private function linked_slugs( int $post_id ): array {
		global $wpdb;
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT h.slug FROM {$wpdb->prefix}bn_post_hashtags ph
				 JOIN {$wpdb->prefix}bn_hashtags h ON h.id = ph.hashtag_id
				 WHERE ph.post_id = %d ORDER BY h.slug",
				$post_id
			)
		);
		return array_map( 'strval', (array) $slugs );
	}

	public function test_listener_hooks_the_post_updated_event(): void {
		$this->listener->register();
		$this->assertNotFalse( has_action( 'buddynext_post_updated', array( $this->listener, 'on_post_updated' ) ) );
	}

	public function test_edit_replaces_the_tag_set(): void {
		global $wpdb;
		$post = $this->seed_post( 'first #alpha and #beta' );
		$this->service->sync( 'post', $post, $this->service->extract( 'first #alpha and #beta' ) );
		$this->assertSame( array( 'alpha', 'beta' ), $this->linked_slugs( $post ) );

		// Edit the stored content, then run the async worker (what the scheduled
		// job invokes): a removed tag unlinks, a new one links, a kept one stays.
		$wpdb->update( $wpdb->prefix . 'bn_posts', array( 'content' => 'edited #beta and #gamma' ), array( 'id' => $post ) );
		$this->listener->async_index_hashtags( 'post', $post, '' );

		$this->assertSame( array( 'beta', 'gamma' ), $this->linked_slugs( $post ), 'alpha unlinked, gamma linked, beta kept' );
	}

	public function test_content_only_edits_trigger_a_resync(): void {
		// A save that did not write the content column changes no tags, so
		// on_post_updated must not dispatch. With Action Scheduler absent in the
		// test env, dispatch falls back to running the worker inline — so a
		// no-content edit that DID dispatch would (wrongly) clear the tag set.
		$post = $this->seed_post( 'keep #delta here' );
		$this->service->sync( 'post', $post, $this->service->extract( 'keep #delta here' ) );

		$this->listener->on_post_updated(
			$post,
			0,
			array(
				'privacy'   => 'private',
				'edited_at' => '2026-07-04 00:00:00',
			)
		);
		$this->assertSame( array( 'delta' ), $this->linked_slugs( $post ), 'privacy-only edit leaves tags untouched' );
	}
}
