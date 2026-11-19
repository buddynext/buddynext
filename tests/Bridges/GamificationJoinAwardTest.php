<?php
/**
 * One join pays one WB Gamification award (card 10526710521).
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace Jetonomy\Models {
	if ( ! class_exists( JoinRequest::class ) ) {
		/**
		 * Presence stub: the bridge only checks Jetonomy is active and reads its table.
		 */
		class JoinRequest {
		}
	}
}

namespace BuddyNext\Tests\Bridges {

	use BuddyNext\Bridges\GamificationBridge;
	use BuddyNext\Core\Installer;
	use BuddyNext\Feed\IntegrationActivity;
	use BuddyNext\Spaces\SpaceMemberService;

	/**
	 * @covers \BuddyNext\Bridges\GamificationBridge::skip_copied_awards
	 */
	class GamificationJoinAwardTest extends \WP_UnitTestCase {

		/**
		 * Award amounts WB Gamification ships for the three join triggers.
		 */
		private const POINTS = array(
			'bn_space_joined'                => 5,
			'jetonomy_space_joined'          => 5,
			'jetonomy_join_request_approved' => 10,
		);

		/**
		 * Awards the engine would have written in this test.
		 *
		 * @var array<int, array{0: string, 1: int}>
		 */
		private array $paid = array();

		public function set_up(): void {
			parent::set_up();
			Installer::run();
			global $wpdb;
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_join_requests (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					space_id BIGINT UNSIGNED NOT NULL,
					user_id BIGINT UNSIGNED NOT NULL,
					status VARCHAR(20) NOT NULL DEFAULT 'pending',
					reviewed_at DATETIME NULL,
					PRIMARY KEY (id),
					UNIQUE KEY space_user_status (space_id, user_id, status)
				)"
			);
			( new GamificationBridge() )->init();
			$this->paid = array();
		}

		/**
		 * Stand-in for WB Gamification's Engine::process(): the gate, then the ledger.
		 *
		 * @param string $action_id Trigger.
		 * @param int    $user_id   Member.
		 * @param int    $forum_id  Space/forum id in the metadata.
		 * @param string $at        When the event was recorded (UTC ISO).
		 */
		private function award( string $action_id, int $user_id, int $forum_id = 0, string $at = '' ): void {
			$event = (object) array(
				'action_id'  => $action_id,
				'user_id'    => $user_id,
				'metadata'   => array( 'space_id' => $forum_id ),
				'created_at' => '' !== $at ? $at : gmdate( 'Y-m-d\TH:i:s\Z' ),
			);
			if ( (bool) apply_filters( 'wb_gam_before_evaluate', true, $event ) ) {
				$this->paid[] = array( $action_id, self::POINTS[ $action_id ] );
			}
		}

		private function total(): int {
			return (int) array_sum( array_column( $this->paid, 1 ) );
		}

		private function open_space(): int {
			return (int) buddynext_service( 'spaces' )->create(
				self::factory()->user->create( array( 'role' => 'administrator' ) ),
				array(
					'name' => 'Join Award',
					'slug' => 'join-award-' . wp_rand( 1000, 99999 ),
					'type' => 'open',
				)
			);
		}

		private function approve( int $forum_id, int $user_id, string $reviewed_at ): void {
			global $wpdb;
			$wpdb->replace(
				$wpdb->prefix . 'jt_join_requests',
				array(
					'space_id'    => $forum_id,
					'user_id'     => $user_id,
					'status'      => 'approved',
					'reviewed_at' => $reviewed_at,
				)
			);
		}

		public function test_a_forum_side_join_pays_once(): void {
			$space = $this->open_space();
			$user  = self::factory()->user->create();
			add_action( 'buddynext_space_member_joined', fn( $s, $u ) => $this->award( 'bn_space_joined', (int) $u ), 10, 2 );

			// The forum pays its join; the space's copy of it (bridge mirror) does not.
			$this->award( 'jetonomy_space_joined', $user, 7 );
			IntegrationActivity::as_mirror( static fn() => ( new SpaceMemberService() )->join( $space, $user ) );

			$this->assertSame( array( array( 'jetonomy_space_joined', 5 ) ), $this->paid );
		}

		public function test_a_space_side_join_pays_once_and_a_repeat_pays_nothing(): void {
			$space   = $this->open_space();
			$user    = self::factory()->user->create();
			$members = new SpaceMemberService();
			add_action( 'buddynext_space_member_joined', fn( $s, $u ) => $this->award( 'bn_space_joined', (int) $u ), 10, 2 );

			$members->join( $space, $user );
			$members->join( $space, $user );

			$this->assertSame( array( array( 'bn_space_joined', 5 ) ), $this->paid );
		}

		public function test_an_approved_request_pays_one_award_of_ten(): void {
			$user = self::factory()->user->create();
			$this->approve( 7, $user, gmdate( 'Y-m-d H:i:s' ) );

			// Jetonomy fires the join, the space copy (mirror), then the approval.
			$this->award( 'jetonomy_space_joined', $user, 7 );
			IntegrationActivity::as_mirror( fn() => $this->award( 'bn_space_joined', $user, 7 ) );
			$this->award( 'jetonomy_join_request_approved', $user, 7 );

			$this->assertSame( array( array( 'jetonomy_join_request_approved', 10 ) ), $this->paid );
			$this->assertSame( 10, $this->total() );
		}

		public function test_leaving_and_rejoining_pays_the_new_join_once(): void {
			$user = self::factory()->user->create();
			// Approved into the forum a month ago, left, now joins again by themselves.
			$this->approve( 7, $user, gmdate( 'Y-m-d H:i:s', time() - MONTH_IN_SECONDS ) );

			$this->award( 'jetonomy_space_joined', $user, 7 );
			IntegrationActivity::as_mirror( fn() => $this->award( 'bn_space_joined', $user, 7 ) );

			$this->assertSame( 5, $this->total() );
		}

		public function test_an_approval_in_another_forum_does_not_cancel_a_join(): void {
			$user = self::factory()->user->create();
			$this->approve( 8, $user, gmdate( 'Y-m-d H:i:s' ) );

			$this->award( 'jetonomy_space_joined', $user, 7 );

			$this->assertSame( 5, $this->total() );
		}

		public function test_a_refusal_from_another_listener_stands(): void {
			$this->assertFalse( ( new GamificationBridge() )->skip_copied_awards( false, (object) array( 'action_id' => 'bn_space_joined' ) ) );
		}
	}
}
