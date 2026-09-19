<?php
/**
 * Tests for the shared integration feed-activity helper.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\IntegrationActivity;
use BuddyNext\Core\Installer;

/**
 * Tests the shared integration feed-activity helper (publish/remove/render).
 *
 * @covers \BuddyNext\Feed\IntegrationActivity
 */
class IntegrationActivityTest extends \WP_UnitTestCase {

	/**
	 * A seeded member id used as the activity author.
	 *
	 * @var int
	 */
	private int $member_id;

	/**
	 * Install the schema and seed a member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->member_id = self::factory()->user->create();
	}

	/**
	 * A bare publish() records a public link post for the member.
	 *
	 * @return void
	 */
	public function test_publish_creates_a_link_post(): void {
		global $wpdb;

		$url = 'https://example.test/discussions/55/';
		$id  = IntegrationActivity::publish( $this->member_id, 'started a discussion', $url, 'Welcome thread' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT user_id, type, link_url FROM {$wpdb->prefix}bn_posts WHERE id = %d", $id ),
			ARRAY_A
		);
		$this->assertSame( (int) $this->member_id, (int) $row['user_id'] );
		$this->assertSame( 'link', $row['type'] );
		$this->assertSame( $url, $row['link_url'] );
	}

	/**
	 * A typed publish() records the type and merges the meta into link_meta.
	 *
	 * @return void
	 */
	public function test_publish_accepts_a_typed_type_and_merges_meta_into_link_meta(): void {
		global $wpdb;

		$url = 'https://example.test/event/88/';
		$id  = IntegrationActivity::publish(
			$this->member_id,
			'is attending',
			$url,
			'Scale Test Event',
			'event',
			'',
			0,
			array(
				'image'    => 'https://example.test/cover.jpg',
				'event_id' => 88,
				'city'     => 'Lagos',
				'relation' => 'attending',
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT type, link_meta FROM {$wpdb->prefix}bn_posts WHERE id = %d", $id ),
			ARRAY_A
		);
		$this->assertSame( 'event', $row['type'], 'the event type is accepted (was rejected as invalid_post_type before)' );

		$meta = json_decode( (string) $row['link_meta'], true );
		$this->assertSame( 'Scale Test Event', $meta['title'], 'defaults preserved' );
		$this->assertSame( 'https://example.test/cover.jpg', $meta['image'], 'meta overrides the default image' );
		$this->assertSame( 88, $meta['event_id'], 'typed payload carried' );
		$this->assertSame( 'Lagos', $meta['city'] );
		$this->assertSame( 'attending', $meta['relation'] );
	}

	/**
	 * A second identical publish() does not create a duplicate card.
	 *
	 * @return void
	 */
	public function test_publish_is_idempotent(): void {
		$url    = 'https://example.test/discussions/56/';
		$first  = IntegrationActivity::publish( $this->member_id, 'started a discussion', $url );
		$second = IntegrationActivity::publish( $this->member_id, 'started a discussion', $url );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $second, 'a second identical card is not created' );
	}

	/**
	 * Rejects a missing member id or link url.
	 *
	 * @return void
	 */
	public function test_publish_rejects_invalid_input(): void {
		$this->assertInstanceOf( \WP_Error::class, IntegrationActivity::publish( 0, 'x', 'https://x/' ) );
		$this->assertInstanceOf( \WP_Error::class, IntegrationActivity::publish( $this->member_id, 'x', '' ) );
	}

	/**
	 * withdraw() then restore() brings back the SAME card — same id, same original
	 * date — instead of the delete + re-create that resurfaced the thread as new.
	 *
	 * This is the core of card 10320560928: an unpublish → republish round trip must
	 * not mint a new card. withdraw() hides the card ('draft', kept out of feeds);
	 * restore() brings it back 'published'.
	 *
	 * @return void
	 */
	public function test_withdraw_then_restore_preserves_the_same_card(): void {
		global $wpdb;

		$url = 'https://example.test/discussions/221/';
		$id  = IntegrationActivity::publish( $this->member_id, 'started a discussion', $url, 'Critique thread', 'discussion' );
		$this->assertGreaterThan( 0, $id );

		$before = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, created_at FROM {$wpdb->prefix}bn_posts WHERE id = %d", $id ),
			ARRAY_A
		);
		$this->assertSame( 'published', $before['status'] );

		// Unpublish → the card is withdrawn, not deleted: the SAME row survives,
		// hidden from feeds ('draft').
		$this->assertTrue( IntegrationActivity::withdraw( $url, 'discussion' ) );
		$withdrawn = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, created_at FROM {$wpdb->prefix}bn_posts WHERE id = %d", $id ),
			ARRAY_A
		);
		$this->assertNotNull( $withdrawn, 'the card row is preserved, not deleted' );
		$this->assertSame( 'draft', $withdrawn['status'], 'a withdrawn card is hidden from every feed' );

		// Republish → the SAME card comes back published, with its original date.
		$this->assertTrue( IntegrationActivity::restore( $url, 'discussion' ) );
		$after = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, created_at FROM {$wpdb->prefix}bn_posts WHERE id = %d", $id ),
			ARRAY_A
		);
		$this->assertSame( (int) $before['id'], (int) $after['id'], 'same card id, not a new one' );
		$this->assertSame( 'published', $after['status'] );
		$this->assertSame( $before['created_at'], $after['created_at'], 'the original date is kept — the thread does not resurface as new' );
	}

	/**
	 * A comment on the card survives the round trip because the card id — which the
	 * comment's object_id points at — never changes. The delete + re-create bug left
	 * comments pointing at a post that no longer existed.
	 *
	 * @return void
	 */
	public function test_comments_are_not_orphaned_by_a_withdraw_restore_round_trip(): void {
		global $wpdb;

		$url = 'https://example.test/discussions/222/';
		$id  = IntegrationActivity::publish( $this->member_id, 'started a discussion', $url, 'Thread with replies', 'discussion' );
		$this->assertGreaterThan( 0, $id );

		// A member reply on the feed card, keyed on the card id.
		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'user_id'     => $this->member_id,
				'object_type' => 'post',
				'object_id'   => $id,
				'content'     => 'a reply',
			),
			array( '%d', '%s', '%d', '%s' )
		);

		IntegrationActivity::withdraw( $url, 'discussion' );
		IntegrationActivity::restore( $url, 'discussion' );

		$still_valid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments c
				 JOIN {$wpdb->prefix}bn_posts p ON p.id = c.object_id
				 WHERE c.object_id = %d",
				$id
			)
		);
		$this->assertSame( 1, $still_valid, 'the reply still points at a card that exists — never orphaned' );
	}

	/**
	 * withdraw()/restore() never fight moderation: a card a moderator hid to
	 * under_review is neither withdrawn (it is not 'published') nor restored (it is
	 * not 'draft'), so an author toggling the source cannot un-hide reported content.
	 *
	 * @return void
	 */
	public function test_withdraw_and_restore_leave_a_moderated_card_alone(): void {
		global $wpdb;

		$url = 'https://example.test/discussions/223/';
		$id  = IntegrationActivity::publish( $this->member_id, 'started a discussion', $url, 'Reported thread', 'discussion' );

		// A moderator hid it.
		$wpdb->update( $wpdb->prefix . 'bn_posts', array( 'status' => 'under_review' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		$this->assertFalse( IntegrationActivity::withdraw( $url, 'discussion' ), 'withdraw only touches a published card' );
		$this->assertFalse( IntegrationActivity::restore( $url, 'discussion' ), 'restore only touches a withdrawn (draft) card' );

		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_posts WHERE id = %d", $id ) );
		$this->assertSame( 'under_review', $status, 'the moderator hold is untouched' );
	}

	/**
	 * Builds a linked bridge card from the post-body args.
	 *
	 * @return void
	 */
	public function test_render_bridge_card_builds_a_linked_card(): void {
		$html = IntegrationActivity::render_bridge_card(
			array(
				'bn_post_type' => 'course',
				'post_content' => 'completed a course',
				'link_preview' => array(
					'url'   => 'https://example.test/courses/php-101/',
					'title' => 'PHP 101',
				),
			),
			'graduation-cap',
			'Course'
		);

		$this->assertStringContainsString( 'bn-post-card__bridge-card--course', $html, 'the type modifier is applied' );
		$this->assertStringContainsString( 'Course', $html, 'the source label renders' );
		$this->assertStringContainsString( 'href="https://example.test/courses/php-101/"', $html, 'the card links OUT to the partner page' );
		$this->assertStringContainsString( 'PHP 101', $html, 'the linked title is the content title' );
	}

	/**
	 * Returns an empty string with no url so the seam uses plain text.
	 *
	 * @return void
	 */
	public function test_render_bridge_card_without_a_url_falls_back_to_text(): void {
		$html = IntegrationActivity::render_bridge_card(
			array(
				'bn_post_type' => 'badge',
				'post_content' => 'earned a badge',
				'link_preview' => array( 'url' => '' ),
			),
			'award',
			'Badge'
		);

		$this->assertSame( '', $html, 'no link → empty so the seam falls back to the plain-text body' );
	}

	/**
	 * Uses the trimmed verb when the card has no title.
	 *
	 * @return void
	 */
	public function test_render_bridge_card_falls_back_to_trimmed_verb_when_untitled(): void {
		$html = IntegrationActivity::render_bridge_card(
			array(
				'bn_post_type' => 'listing',
				'post_content' => 'added a new listing',
				'link_preview' => array( 'url' => 'https://example.test/l/9/' ),
			),
			'store',
			'Listing'
		);

		$this->assertStringContainsString( 'added a new listing', $html, 'a titleless card shows the trimmed verb' );
	}

	/**
	 * Deletes the card for a partner page.
	 *
	 * @return void
	 */
	public function test_remove_deletes_the_card(): void {
		global $wpdb;

		$url = 'https://example.test/discussions/77/';
		IntegrationActivity::publish( $this->member_id, 'started a discussion', $url );

		$removed = IntegrationActivity::remove( $url );
		$this->assertGreaterThan( 0, $removed );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE link_url = %s", $url )
		);
		$this->assertSame( 0, $count );
	}

	/**
	 * Removes every card for one partner id, and a different id whose value merely
	 * shares a digit prefix (60 vs 600) is NOT matched.
	 *
	 * @return void
	 */
	public function test_remove_by_meta_matches_the_exact_id_only(): void {
		global $wpdb;

		// Two cards for entity 60 (an organizer card + an attendee card).
		IntegrationActivity::publish( $this->member_id, 'scheduled an event', 'https://example.test/event/a/', 'A', 'event', '', 0, array( 'event_id' => 60 ) );
		IntegrationActivity::publish( $this->member_id, 'is attending', 'https://example.test/event/a/?bn_rsvp=5', 'A', 'event', '', 0, array( 'event_id' => 60 ) );
		// A different entity whose id shares a prefix — must survive a 60 removal.
		IntegrationActivity::publish( $this->member_id, 'scheduled an event', 'https://example.test/event/b/', 'B', 'event', '', 0, array( 'event_id' => 600 ) );

		$removed = IntegrationActivity::remove_by_meta( 'event', 'event_id', 60 );
		$this->assertSame( 2, $removed, 'both event-60 cards are removed by the stamped id' );

		$survives = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE type = 'event' AND link_url = %s", 'https://example.test/event/b/' )
		);
		$this->assertSame( 1, $survives, 'event 600 is not matched by a 60 removal (exact id, not a LIKE)' );
	}

	/**
	 * withdraw_by_meta()/restore_by_meta() move every card for one partner id between
	 * hidden and live WITHOUT deleting — an event's organizer + attendee cards
	 * withdrawn together when it is cancelled and restored together when reinstated,
	 * same ids and comments. A different id sharing a digit prefix is untouched.
	 *
	 * @return void
	 */
	public function test_withdraw_and_restore_by_meta_move_the_whole_set_reversibly(): void {
		global $wpdb;

		IntegrationActivity::publish( $this->member_id, 'scheduled an event', 'https://example.test/ev/a/', 'A', 'event', '', 0, array( 'event_id' => 70 ) );
		IntegrationActivity::publish( $this->member_id, 'is attending', 'https://example.test/ev/a/?bn_rsvp=5', 'A', 'event', '', 0, array( 'event_id' => 70 ) );
		IntegrationActivity::publish( $this->member_id, 'scheduled an event', 'https://example.test/ev/b/', 'B', 'event', '', 0, array( 'event_id' => 700 ) );

		$published_70 = static function () use ( $wpdb ): int {
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts p
				 WHERE type = 'event' AND status = 'published'
				   AND CAST( JSON_UNQUOTE( JSON_EXTRACT( link_meta, '$.event_id' ) ) AS UNSIGNED ) = 70"
			);
		};
		$ids_70 = static function () use ( $wpdb ): array {
			return array_map(
				'intval',
				(array) $wpdb->get_col(
					"SELECT id FROM {$wpdb->prefix}bn_posts
					 WHERE type = 'event'
					   AND CAST( JSON_UNQUOTE( JSON_EXTRACT( link_meta, '$.event_id' ) ) AS UNSIGNED ) = 70
					 ORDER BY id"
				)
			);
		};

		$this->assertSame( 2, $published_70(), 'both event-70 cards start on the feed' );
		$before = $ids_70();

		// Withdraw the whole event-70 set.
		$this->assertSame( 2, IntegrationActivity::withdraw_by_meta( 'event', 'event_id', 70 ) );
		$this->assertSame( 0, $published_70(), 'both event-70 cards leave the feed' );
		$this->assertSame( $before, $ids_70(), 'the rows are preserved, not deleted' );
		// A prefix-sharing id (700) is untouched by a 70 withdrawal.
		$status_700 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_posts WHERE type = 'event' AND link_url = %s", 'https://example.test/ev/b/' ) );
		$this->assertSame( 'published', $status_700, 'event 700 is not touched by a 70 withdrawal (exact id, not a LIKE)' );

		// Restore the set — same ids, back on the feed.
		$this->assertSame( 2, IntegrationActivity::restore_by_meta( 'event', 'event_id', 70 ) );
		$this->assertSame( 2, $published_70(), 'both event-70 cards are back on the feed' );
		$this->assertSame( $before, $ids_70(), 'the SAME card ids — no duplicates, no orphaned comments' );
	}
}
