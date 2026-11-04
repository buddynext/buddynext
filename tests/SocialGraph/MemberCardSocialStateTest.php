<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * One answer for what a viewer can do about a member, on every member card.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The server card, GET /members and search rows each worked out follow state their
 * own way: a pending request read as "not following", and the plan filter was skipped.
 *
 * @covers \BuddyNext\SocialGraph\PrivacyService::social_state_for
 */
class MemberCardSocialStateTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, clean cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
		wp_set_current_user( 0 );
	}

	/**
	 * A member with onboarding done.
	 *
	 * @return int
	 */
	private function member(): int {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $uid, 'bn_onboarding_complete', 1 );
		return $uid;
	}

	/**
	 * A private account, with the viewer's request waiting on it.
	 *
	 * @param int $viewer Viewer.
	 * @return int
	 */
	private function private_with_request( int $viewer ): int {
		$uid = $this->member();
		update_user_meta( $uid, \BuddyNext\SocialGraph\FollowService::PRIVATE_META, 1 );
		$this->assertTrue( buddynext_service( 'follows' )->follow( $viewer, $uid ) );
		return $uid;
	}

	/**
	 * Members from REST, keyed by user ID.
	 *
	 * @param int[] $ids Member IDs.
	 * @param int   $viewer Viewer.
	 * @return array<int, array<string, mixed>>
	 */
	private function rest_items( array $ids, int $viewer ): array {
		$controller = new \BuddyNext\Profile\MemberDirectoryController();
		return array_column( $controller->hydrate_members( $ids, $viewer ), null, 'user_id' );
	}

	/**
	 * The server-rendered card.
	 *
	 * @param int $member Member.
	 * @param int $viewer Viewer.
	 * @return string
	 */
	private function card( int $member, int $viewer ): string {
		wp_set_current_user( $viewer );
		ob_start();
		buddynext_get_template(
			'parts/member-directory-grid.php',
			array(
				'members'   => array( get_userdata( $member ) ),
				'viewer_id' => $viewer,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * A request sent to a private account is Requested on all three, and survives a reload.
	 *
	 * @return void
	 */
	public function test_pending_request_reads_as_requested_everywhere(): void {
		$viewer = $this->member();
		$target = $this->private_with_request( $viewer );
		$plain  = $this->member();
		wp_cache_flush();

		$items = $this->rest_items( array( $target, $plain ), $viewer );
		$this->assertTrue( $items[ $target ]['is_pending'] );
		$this->assertFalse( $items[ $target ]['is_following'] );
		$this->assertFalse( $items[ $plain ]['is_pending'], 'A member with no request is not pending.' );

		$card = $this->card( $target, $viewer );
		$this->assertMatchesRegularExpression( '/data-state="pending"/', $card );
		$this->assertStringContainsString( 'Requested', $card );
		$this->assertStringNotContainsString( 'Following', wp_strip_all_tags( $card ) );
		$this->assertStringContainsString( '&quot;followPending&quot;:true', $card );

		$rows = ( new \ReflectionMethod( buddynext_service( 'search' ), 'enrich_members' ) )->invoke( buddynext_service( 'search' ), array( $target ), $viewer );
		$this->assertTrue( $rows[0]['social']['is_pending'] );
	}

	/**
	 * The plan seam reaches the card and REST; the toast name is the public handle.
	 *
	 * @return void
	 */
	public function test_can_follow_filter_gates_card_and_rest(): void {
		$viewer = $this->member();
		$target = $this->member();
		wp_update_user(
			array(
				'ID'           => $target,
				'display_name' => 'Display Name With Spaces',
			)
		);

		$card = $this->card( $target, $viewer );
		$this->assertStringContainsString( 'bn-md-card__follow', $card, 'Open by default.' );
		$this->assertStringContainsString( '&quot;handle&quot;:&quot;' . \BuddyNext\Core\PageRouter::member_handle( $target ) . '&quot;', $card );

		add_filter( 'buddynext_can_follow', '__return_false' );
		add_filter( 'buddynext_can_connect', '__return_false' );
		$items = $this->rest_items( array( $target ), $viewer );
		$card  = $this->card( $target, $viewer );
		remove_filter( 'buddynext_can_follow', '__return_false' );
		remove_filter( 'buddynext_can_connect', '__return_false' );

		$this->assertFalse( $items[ $target ]['can_follow'] );
		$this->assertFalse( $items[ $target ]['can_connect'] );
		$this->assertStringNotContainsString( 'bn-md-card__follow', $card );
		$this->assertStringNotContainsString( 'bn-md-card__connect-primary', $card );
		$this->assertStringNotContainsString( 'bn-social-upgrade', $card, 'No upgrade URL, so no upgrade control.' );
	}

	/**
	 * A plan refusal offers the upgrade only where paying would help.
	 *
	 * @return void
	 */
	public function test_upgrade_path_only_when_the_plan_is_the_reason(): void {
		$viewer  = $this->member();
		$open    = $this->member();
		$closed  = $this->member();
		$privacy = buddynext_service( 'privacy' );
		$privacy->set_preference( $closed, 'who_can_follow', 'nobody' );
		$privacy->set_preference( $closed, 'who_can_connect', 'nobody' );

		$gate = static fn(): string => 'https://example.test/plans/';
		add_filter( 'buddynext_can_follow', '__return_false' );
		add_filter( 'buddynext_can_connect', '__return_false' );
		add_filter( 'buddynext_social_upgrade_url', $gate );
		$states = $privacy->social_state_for( $viewer, array( $open, $closed, $viewer ) );
		$card   = $this->card( $open, $viewer );
		remove_filter( 'buddynext_can_follow', '__return_false' );
		remove_filter( 'buddynext_can_connect', '__return_false' );
		remove_filter( 'buddynext_social_upgrade_url', $gate );

		$this->assertSame( 'https://example.test/plans/', $states[ $open ]['upgrade_url'] );
		$this->assertSame( 'Upgrade to follow', $states[ $open ]['upgrade_label'] );
		$this->assertSame( '', $states[ $closed ]['upgrade_url'], 'Paying cannot open a member who takes no one.' );
		$this->assertSame( '', $states[ $viewer ]['upgrade_url'], 'Never for yourself.' );
		$this->assertStringContainsString( 'https://example.test/plans/', $card );
	}

	/**
	 * Guests, blocked pairs and suspended viewers are offered nothing.
	 *
	 * @return void
	 */
	public function test_no_control_for_those_who_cannot_act(): void {
		$viewer = $this->member();
		$target = $this->member();

		$guest = $this->rest_items( array( $target ), 0 )[ $target ];
		$this->assertFalse( $guest['can_interact'] );
		$this->assertFalse( $guest['can_follow'] );

		buddynext_service( 'blocks' )->block( $target, $viewer );
		$blocked = $this->rest_items( array( $target ), $viewer )[ $target ];
		$this->assertFalse( $blocked['can_follow'] );
		$this->assertFalse( $blocked['can_connect'] );
		$this->assertFalse( $blocked['is_pending'] );

		$held = $this->member();
		buddynext_service( 'moderation' )->suspend_user( $held, 1, 'test' );
		$state = buddynext_service( 'privacy' )->social_state_for( $held, array( $this->member() ) );
		$this->assertFalse( array_values( $state )[0]['may_interact'] );
		$this->assertFalse( array_values( $state )[0]['can_follow'] );
	}

	/**
	 * The cost of the answer does not grow with the page.
	 *
	 * @return void
	 */
	public function test_query_count_does_not_grow_with_the_page(): void {
		global $wpdb;

		$viewer = $this->member();
		$few    = array();
		$many   = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$few[] = $this->member();
		}
		for ( $i = 0; $i < 30; $i++ ) {
			$many[] = $this->member();
		}
		foreach ( array_merge( $few, $many ) as $uid ) {
			buddynext_service( 'privacy' )->set_preference( $uid, 'who_can_connect', 'followers' );
		}
		$privacy = buddynext_service( 'privacy' );
		buddynext_can( $viewer, 'buddynext-connections/follow' );

		wp_cache_flush();
		buddynext_can( $viewer, 'buddynext-connections/follow' );
		$before = $wpdb->num_queries;
		$privacy->social_state_for( $viewer, $few );
		$cost_few = $wpdb->num_queries - $before;

		wp_cache_flush();
		buddynext_can( $viewer, 'buddynext-connections/follow' );
		$before = $wpdb->num_queries;
		$privacy->social_state_for( $viewer, $many );
		$cost_many = $wpdb->num_queries - $before;

		$this->assertLessThanOrEqual( $cost_few, $cost_many, "3 members cost {$cost_few} queries, 30 cost {$cost_many}." );
	}
}
