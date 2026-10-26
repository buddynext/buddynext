<?php
/**
 * The email digest carries only the rows the bell would show.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronService;
use BuddyNext\Notifications\NotificationPrefCatalogue;

/**
 * The digest read bn_notifications directly and skipped the bell's read gate,
 * so a row a partner had hidden (trashed reply, private item) was still emailed.
 */
class DigestVisibilityTest extends \WP_UnitTestCase {

	/**
	 * Unread rows the weekly digest would carry for a member.
	 *
	 * @param int $user_id Recipient.
	 * @return int[] Notification ids.
	 */
	private function digest_ids( int $user_id ): array {
		$cron = new CronService();
		$m    = new \ReflectionMethod( $cron, 'fetch_unread_notifications' );
		$m->setAccessible( true );
		return array_map( static fn( array $r ): int => (int) $r['id'], $m->invoke( $cron, $user_id, 7, 'weekly' ) );
	}

	public function test_a_row_the_bell_hides_is_not_emailed(): void {
		global $wpdb;

		$type = '';
		foreach ( ( new NotificationPrefCatalogue() )->all() as $t => $entry ) {
			if ( ! empty( $entry['can_email'] ) && 'weekly' === ( $entry['default_email_freq'] ?? '' ) ) {
				$type = (string) $t;
				break;
			}
		}
		$this->assertNotSame( '', $type, 'The catalogue has a weekly-digest type.' );

		$user_id = self::factory()->user->create();
		$ids     = array();
		foreach ( array( 'hidden', 'visible' ) as $label ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_notifications',
				array(
					'recipient_id' => $user_id,
					'sender_id'    => 1,
					'type'         => $type,
					'object_type'  => '',
					'object_id'    => 0,
					'data'         => '{}',
					'is_read'      => 0,
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$ids[ $label ] = (int) $wpdb->insert_id;
		}

		$hide = static fn( array $rows ): array => array_values(
			array_filter( $rows, static fn( $r ): bool => (int) $r['id'] !== $ids['hidden'] )
		);
		add_filter( 'buddynext_notification_visible_rows', $hide );
		$digest = $this->digest_ids( $user_id );
		remove_filter( 'buddynext_notification_visible_rows', $hide );

		$this->assertNotContains( $ids['hidden'], $digest, 'A row the bell hides is not emailed.' );
		$this->assertContains( $ids['visible'], $digest, 'An untouched row still is.' );
	}
}
