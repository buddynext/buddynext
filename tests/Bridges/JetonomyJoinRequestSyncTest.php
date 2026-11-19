<?php
/**
 * A join request for a private space's forum is one request, asked or answered in
 * either place: approving it in the forum's queue admits the member to the space and
 * the forum, with one notice and one award of 10.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace {
	require_once __DIR__ . '/stubs/jetonomy-membership.php';
}

namespace BuddyNext\Tests\Bridges {

	use BuddyNext\Bridges\GamificationBridge;
	use BuddyNext\Bridges\JetonomyBridge;
	use BuddyNext\Core\Installer;
	use BuddyNext\Feed\IntegrationActivity;
	use BuddyNext\Spaces\SpaceMemberService;
	use BuddyNext\Spaces\SpaceService;
	use Jetonomy\Models\SpaceMember;

	/**
	 * @covers \BuddyNext\Bridges\JetonomyBridge
	 * @covers \BuddyNext\Spaces\SpaceMemberService::approval_refusal
	 */
	class JetonomyJoinRequestSyncTest extends \WP_UnitTestCase {

		private const FORUM = 4343;

		private int $owner;

		private int $member;

		private JetonomyBridge $bridge;

		private SpaceMemberService $members;

		/**
		 * Awards the engine would have written, as action => points.
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
					reviewed_by BIGINT UNSIGNED NULL,
					reviewed_at DATETIME NULL,
					PRIMARY KEY (id),
					UNIQUE KEY space_user_status (space_id, user_id, status)
				)"
			);
			SpaceMember::reset();
			( new GamificationBridge() )->init();
			$this->owner   = self::factory()->user->create();
			$this->member  = self::factory()->user->create();
			$this->bridge  = new JetonomyBridge();
			$this->members = new SpaceMemberService();
			$this->paid    = array();
			add_action( 'buddynext_space_member_joined', fn( $s, $u ) => $this->award( 'bn_space_joined', 5, (int) $u ), 10, 2 );
		}

		/**
		 * Stand-in for WB Gamification's Engine::process(): the gate, then the ledger.
		 *
		 * @param string $action_id Trigger.
		 * @param int    $points    Points it pays.
		 * @param int    $user_id   Member.
		 */
		private function award( string $action_id, int $points, int $user_id ): void {
			$event = (object) array(
				'action_id'  => $action_id,
				'user_id'    => $user_id,
				'metadata'   => array( 'space_id' => self::FORUM ),
				'created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			);
			if ( (bool) apply_filters( 'wb_gam_before_evaluate', true, $event ) ) {
				$this->paid[] = array( $action_id, $points );
			}
		}

		private function space( string $type = SpaceService::TYPE_PRIVATE ): int {
			$id = (int) ( new SpaceService() )->create(
				$this->owner,
				array(
					'name' => 'Request sync',
					'slug' => 'request-sync-' . wp_rand( 1000, 99999 ),
					'type' => $type,
				)
			);
			update_space_meta( $id, 'jetonomy_forum_id', (string) self::FORUM );
			return $id;
		}

		/**
		 * Jetonomy's approve(): claim the request as approved by the reviewer.
		 *
		 * @param int $reviewer Reviewer.
		 */
		private function claim_approved( int $reviewer ): void {
			global $wpdb;
			$wpdb->insert(
				$wpdb->prefix . 'jt_join_requests',
				array(
					'space_id'    => self::FORUM,
					'user_id'     => $this->member,
					'status'      => 'approved',
					'reviewed_by' => $reviewer,
					'reviewed_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		/**
		 * Jetonomy's approve() after the claim: the join filter, the roster row, the join
		 * hook, then the approval hook (its award).
		 *
		 * @return true|\WP_Error What the join filter answered.
		 */
		private function forum_admits() {
			$proceed = $this->bridge->veto_forum_join( true, $this->member, self::FORUM );
			if ( is_wp_error( $proceed ) ) {
				return $proceed;
			}
			SpaceMember::add( self::FORUM, $this->member );
			$this->award( 'jetonomy_space_joined', 5, $this->member );
			$this->bridge->on_forum_member_joined( self::FORUM, $this->member );
			$this->award( 'jetonomy_join_request_approved', 10, $this->member );
			return true;
		}

		private function notices( int $recipient, string $type ): int {
			global $wpdb;
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = %s", $recipient, $type ) );
		}

		private function pending_forum_requests(): int {
			global $wpdb;
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_join_requests WHERE space_id = %d AND user_id = %d AND status = 'pending'", self::FORUM, $this->member ) );
		}

		private function assert_admitted_once( int $space ): void {
			$this->assertSame( 'active', $this->members->get_status( $space, $this->member ), 'the approval admits to the space' );
			$this->assertTrue( SpaceMember::is_member( self::FORUM, $this->member ), 'the forum row stays' );
			$this->assertContains( (string) self::FORUM, (array) get_user_meta( $this->member, 'buddynext_jt_granted_forum', false ), 'leaving the space takes the row away again' );
			$this->assertSame( 1, $this->notices( $this->member, 'bn.space_request_approved' ), 'one approval notice' );
			$this->assertSame( array( array( 'jetonomy_join_request_approved', 10 ) ), $this->paid, 'one award of 10' );
		}

		public function test_a_forum_approval_with_no_space_request_admits_in_both(): void {
			$space = $this->space();
			$this->claim_approved( $this->owner );

			$this->assertTrue( $this->forum_admits() );

			$this->assert_admitted_once( $space );
			$this->assertSame( 0, $this->notices( $this->owner, 'bn.space_join_requested' ), 'nobody is asked to review a request already approved' );
		}

		public function test_a_forum_approval_of_a_pending_space_request_admits_in_both(): void {
			$space = $this->space();
			$this->members->request_join( $space, $this->member );
			$this->claim_approved( $this->owner );

			$this->assertTrue( $this->forum_admits() );

			$this->assert_admitted_once( $space );
		}

		public function test_the_forum_answer_is_dropped_once_the_space_has_answered(): void {
			$this->space();
			$this->claim_approved( $this->owner );
			$this->forum_admits();

			$payload = $this->bridge->drop_duplicate_report_notice(
				array(
					'type'         => 'join_request_result',
					'recipient_id' => $this->member,
					'object_type'  => 'space',
					'object_id'    => self::FORUM,
				),
				'jetonomy'
			);

			$this->assertSame( 0, $payload['recipient_id'], 'no second bell row' );
			$this->assertFalse( $this->bridge->filter_forum_email( true, $this->member, 'join_request_result', 'join_request_result' ), 'and no second email' );
		}

		public function test_a_reviewer_the_space_does_not_know_is_refused(): void {
			$space = $this->space();
			$this->members->request_join( $space, $this->member );
			// Can manage forums in Jetonomy, holds no role in the space and no manage_options.
			$this->claim_approved( self::factory()->user->create() );

			$refused = $this->forum_admits();

			$this->assertWPError( $refused );
			$this->assertSame( 'bn_space_approval_refused', $refused->get_error_code() );
			$this->assertSame( 'pending', $this->members->get_status( $space, $this->member ) );
			$this->assertSame( array(), $this->paid );
		}

		public function test_a_second_approval_finds_nothing_pending(): void {
			$space = $this->space();
			$this->claim_approved( $this->owner );
			$this->forum_admits();

			$again = $this->members->approve_request( $space, $this->owner, $this->member );

			$this->assertWPError( $again );
			$this->assertSame( 'no_pending_request', $again->get_error_code() );
		}

		public function test_a_forum_denial_or_withdrawal_clears_the_space_request(): void {
			$space = $this->space();

			$this->members->request_join( $space, $this->member );
			$this->bridge->on_forum_request_denied( self::FORUM, $this->member, $this->owner );
			$this->assertNull( $this->members->get_status( $space, $this->member ), 'denied in the forum' );

			$this->members->request_join( $space, $this->member );
			$this->bridge->on_forum_request_withdrawn( self::FORUM, $this->member );
			$this->assertNull( $this->members->get_status( $space, $this->member ), 'withdrawn in the forum' );
		}

		public function test_a_forum_request_is_held_by_the_space(): void {
			$space = $this->space();

			$this->bridge->on_forum_request_created( self::FORUM, $this->member );

			$this->assertSame( 'pending', $this->members->get_status( $space, $this->member ) );
		}

		public function test_a_space_decision_leaves_no_forum_request_pending(): void {
			global $wpdb;
			$space = $this->space();
			add_action( 'buddynext_space_join_approved', array( $this->bridge, 'settle_forum_request' ), 10, 2 );
			add_action( 'buddynext_space_join_declined', array( $this->bridge, 'settle_forum_request' ), 10, 2 );

			foreach ( array( 'approve_request', 'decline_request' ) as $decide ) {
				$wpdb->insert(
					$wpdb->prefix . 'jt_join_requests',
					array(
						'space_id' => self::FORUM,
						'user_id'  => $this->member,
					)
				);
				$this->members->request_join( $space, $this->member );
				$this->members->{$decide}( $space, $this->owner, $this->member );

				$this->assertSame( 0, $this->pending_forum_requests(), $decide . ' settles the forum request too' );
				$this->members->leave( $space, $this->member );
			}
		}

		public function test_an_open_space_forum_join_is_unchanged(): void {
			$space = $this->space( SpaceService::TYPE_OPEN );
			$this->claim_approved( $this->owner );

			$this->assertTrue( $this->forum_admits() );

			$this->assertSame( 'active', $this->members->get_status( $space, $this->member ) );
			$this->assertSame( 0, $this->notices( $this->member, 'bn.space_request_approved' ), 'an open space has no request to approve' );
		}

		public function test_a_plan_gated_open_space_still_refuses_the_forum_admission(): void {
			$this->space( SpaceService::TYPE_OPEN );
			$this->claim_approved( $this->owner );
			add_filter( 'buddynext_can_join_space', '__return_false' );

			$refused = $this->forum_admits();

			$this->assertWPError( $refused );
			$this->assertSame( 'bn_space_join_refused', $refused->get_error_code() );
		}

		public function test_a_forum_join_with_no_approval_still_goes_to_the_space_queue(): void {
			$space = $this->space();
			SpaceMember::add( self::FORUM, $this->member );

			$this->bridge->on_forum_member_joined( self::FORUM, $this->member );

			$this->assertFalse( SpaceMember::is_member( self::FORUM, $this->member ), 'the forum is not a way round the space' );
			$this->assertSame( 'pending', $this->members->get_status( $space, $this->member ) );
			$this->assertFalse( IntegrationActivity::is_mirror() );
		}
	}
}
