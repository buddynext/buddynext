<?php
/**
 * Tests for the Jetonomy notification mirror.
 *
 * Jetonomy 1.5.0 fires one central hook carrying message + url; BN mirrors every
 * notification into its center as `jt.notification` (collect-only — never emails,
 * Jetonomy owns its emails). Verified against the jetonomy 1.5.0-dev hook
 * signature (2026-06-14).
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridgeListener;
use BuddyNext\Notifications\NotificationPrefCatalogue;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\JetonomyBridgeListener
 */
class JetonomyBridgeListenerTest extends \WP_UnitTestCase {

	private int $author_id;
	private int $actor_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_replies (
				id BIGINT UNSIGNED NOT NULL, author_id BIGINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Jetonomy\Core\Plugin is aliased in tests/bootstrap.php so register() runs.
		( new JetonomyBridgeListener() )->register();
		$this->author_id = self::factory()->user->create();
		$this->actor_id  = self::factory()->user->create();
	}

	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_replies" );
		parent::tear_down();
	}

	private function notif_row( int $recipient ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT type, data FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d ORDER BY id DESC LIMIT 1", $recipient )
		);
	}

	public function test_notification_is_mirrored_with_message_and_url(): void {
		do_action(
			'jetonomy_notification_created',
			0,
			$this->author_id,
			'reply',
			'post',
			5,
			'Alex replied to your discussion.',
			'http://example.org/community/s/general/t/hello/'
		);

		$row = $this->notif_row( $this->author_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'jt.notification', $row->type );
		$data = json_decode( (string) $row->data, true );
		$this->assertSame( 'Alex replied to your discussion.', $data['message'] ?? '' );
		$this->assertSame( 'http://example.org/community/s/general/t/hello/', $data['url'] ?? '' );
	}

	public function test_empty_message_is_skipped(): void {
		do_action( 'jetonomy_notification_created', 0, $this->author_id, 'reply', 'post', 5, '', 'http://x/' );
		$this->assertNull( $this->notif_row( $this->author_id ) );
	}

	public function test_blocked_actor_is_suppressed(): void {
		global $wpdb;
		// The reply (object_id 9) is authored by the actor we will block.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'jt_replies', array( 'id' => 9, 'author_id' => $this->actor_id ), array( '%d', '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'bn_blocks', array( 'blocker_id' => $this->author_id, 'blocked_id' => $this->actor_id ), array( '%d', '%d' ) );

		do_action( 'jetonomy_notification_created', 0, $this->author_id, 'reply', 'reply', 9, 'Blocked user replied.', 'http://x/' );

		$this->assertNull( $this->notif_row( $this->author_id ), 'a blocked member must not notify you' );
	}

	public function test_render_seams_return_stored_message_and_url(): void {
		$data    = array( 'message' => 'Mention from Sam.', 'url' => 'http://x/m/1' );
		$message = apply_filters( 'buddynext_notification_message', '', 'jt.notification', '', 0, $data );
		$url     = apply_filters( 'buddynext_notification_url', '', 'jt.notification', 0, 0, $data );
		$this->assertSame( 'Mention from Sam.', $message );
		$this->assertSame( 'http://x/m/1', $url );
	}

	public function test_mirrored_type_is_collect_only_no_email(): void {
		$this->assertFalse(
			( new NotificationPrefCatalogue() )->can_email( 'jt.notification' ),
			'Jetonomy owns its emails — BN must only collect/display'
		);
	}
}
