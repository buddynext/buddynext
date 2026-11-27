<?php
/**
 * The bell's counts leave out the rows its list hides.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Notifications\NotificationService;
use BuddyNext\SocialGraph\BlockService;

/**
 * A partner plugin hides rows from the list through `buddynext_notification_visible_rows`; the badge and the
 * tab counts used to count them anyway.
 */
class HiddenRowsCountTest extends \WP_UnitTestCase {

	private const PARTNER_TYPE = 'career_board.job_removed';

	private NotificationService $service;
	private int $member;

	public function set_up(): void {
		parent::set_up();
		$this->service = buddynext_service( 'notifications' );
		$this->member  = self::factory()->user->create();
	}

	/**
	 * Insert one unread bell row.
	 *
	 * @param string $type   Type.
	 * @param int    $sender Sender id.
	 * @return int Row id.
	 */
	private function row( string $type, int $sender = 1 ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_notifications',
			array(
				'recipient_id' => $this->member,
				'sender_id'    => $sender,
				'type'         => $type,
				'object_type'  => '',
				'object_id'    => 0,
				'data'         => '{}',
				'is_read'      => 0,
				'in_app'       => 1,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Hide the given row ids from the bell, as a partner would.
	 *
	 * @param int[] $ids Row ids.
	 * @return callable The filter, for removal.
	 */
	private function hide( array $ids ): callable {
		$hide = static fn( array $rows ): array => array_values(
			array_filter( $rows, static fn( $r ): bool => ! in_array( (int) $r['id'], $ids, true ) )
		);
		add_filter( 'buddynext_notification_visible_rows', $hide );
		return $hide;
	}

	/**
	 * Every count the bell and the tabs show, for the member.
	 *
	 * @return array<string,mixed>
	 */
	private function counts(): array {
		wp_cache_flush();
		return array(
			'unread' => $this->service->unread_count( $this->member ),
			'unseen' => $this->service->unseen_count( $this->member ),
			'all'    => $this->service->count_for_user( $this->member, 'all' ),
			'by_type' => $this->service->unread_counts_by_type( $this->member ),
			'by_tab' => $this->service->unread_counts_by_tab( $this->member ),
		);
	}

	public function test_a_row_the_bell_hides_is_not_counted_anywhere(): void {
		$this->row( 'bn.mention' );
		$hidden = $this->row( self::PARTNER_TYPE );
		$this->row( self::PARTNER_TYPE );

		$before = $this->counts();
		$this->assertSame( 3, $before['unread'], 'precondition: SQL counts all three' );

		$hide   = $this->hide( array( $hidden ) );
		$counts = $this->counts();
		remove_filter( 'buddynext_notification_visible_rows', $hide );

		$this->assertSame( 2, $counts['unread'] );
		$this->assertSame( 2, $counts['unseen'] );
		$this->assertSame( 2, $counts['all'] );
		$this->assertSame( 1, $counts['by_type'][ self::PARTNER_TYPE ] );
		$this->assertSame( 1, $counts['by_type']['bn.mention'] );
		$this->assertSame( 2, $counts['by_tab']['unread'] );

		// The list and the badge agree.
		$hide  = $this->hide( array( $hidden ) );
		$items = $this->service->list_for_user( $this->member, null, 50, 'unread' );
		remove_filter( 'buddynext_notification_visible_rows', $hide );
		$this->assertCount( $counts['unread'], $items['items'] );
	}

	public function test_a_blocked_authors_row_leaves_the_badge_on_block_and_returns_on_unblock(): void {
		$author = self::factory()->user->create();
		$this->row( self::PARTNER_TYPE, $author );
		$this->row( self::PARTNER_TYPE, 1 );

		$blocks = new BlockService();
		$rule   = function ( array $rows ) use ( $blocks ): array {
			return array_values( array_filter( $rows, fn( $r ) => ! $blocks->is_blocked( $this->member, (int) $r['sender_id'] ) ) );
		};
		add_filter( 'buddynext_notification_visible_rows', $rule );

		wp_cache_flush();
		$this->assertSame( 2, $this->service->unread_count( $this->member ) );

		// No notification is written; only the block changes.
		$blocks->block( $this->member, $author );
		$this->assertSame( 1, $this->service->unread_count( $this->member ), 'blocking drops the row from the badge' );
		$this->assertSame( 1, $this->service->unseen_count( $this->member ) );

		$blocks->unblock( $this->member, $author );
		$this->assertSame( 2, $this->service->unread_count( $this->member ), 'unblocking brings it back' );

		remove_filter( 'buddynext_notification_visible_rows', $rule );
	}

	public function test_the_window_bounds_how_many_rows_are_checked(): void {
		$extra = 20;
		$ids   = array();
		for ( $i = 0; $i < NotificationService::HIDDEN_WINDOW + $extra; $i++ ) {
			$ids[] = $this->row( self::PARTNER_TYPE );
		}
		$seen = 0;
		$spy  = static function ( array $rows ) use ( &$seen ): array {
			$seen = max( $seen, count( $rows ) );
			return array();
		};
		add_filter( 'buddynext_notification_visible_rows', $spy );
		wp_cache_flush();
		$count = $this->service->unread_count( $this->member );
		remove_filter( 'buddynext_notification_visible_rows', $spy );

		$this->assertSame( NotificationService::HIDDEN_WINDOW, $seen, 'no more than the window is read' );
		$this->assertSame( $extra, $count, 'rows past the window are counted as they were' );
	}
}
