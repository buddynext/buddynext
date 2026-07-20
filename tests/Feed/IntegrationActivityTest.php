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
}
