<?php
/**
 * The `buddynext_inbox_active` seam: a partner asks whether BuddyNext shows its
 * notifications before it hides its own bell.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

/**
 * @covers \BuddyNext\Notifications\IntegrationNotificationListener::filter_inbox_active
 */
class InboxActiveSeamTest extends \WP_UnitTestCase {

	/**
	 * Member the partner renders for.
	 *
	 * @var int
	 */
	private int $member;

	public function set_up(): void {
		parent::set_up();
		$this->member = self::factory()->user->create();
	}

	public function tear_down(): void {
		remove_all_filters( 'wcb_community_notification_types' );
		parent::tear_down();
	}

	/**
	 * Career Board declares its types: it has adopted the contract.
	 */
	private function adopt_career_board(): void {
		add_filter(
			'wcb_community_notification_types',
			static function ( array $types ): array {
				$types['application_received'] = array( 'label' => 'New applications' );
				return $types;
			}
		);
	}

	private function ask( string $source, int $user_id ): bool {
		return (bool) apply_filters( 'buddynext_inbox_active', false, $source, $user_id );
	}

	public function test_true_once_the_source_has_adopted_the_contract(): void {
		$this->adopt_career_board();

		$this->assertTrue( $this->ask( 'career_board', $this->member ), 'BuddyNext mirrors Career Board, so its bell may stand down' );
		$this->assertTrue( $this->ask( 'wcb', $this->member ), 'the plugin may ask by its own hook prefix' );
	}

	public function test_false_while_the_source_has_not_adopted(): void {
		// A Career Board older than the contract: BuddyNext mirrors nothing, so
		// hiding its bell would leave the member with no job notifications.
		$this->assertFalse( $this->ask( 'career_board', $this->member ) );
	}

	public function test_false_for_unknown_source_or_no_member(): void {
		$this->adopt_career_board();

		$this->assertFalse( $this->ask( 'not_a_source', $this->member ) );
		$this->assertFalse( $this->ask( 'career_board', 0 ), 'a logged-out visitor has no inbox' );
	}
}
