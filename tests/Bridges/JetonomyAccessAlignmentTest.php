<?php
/**
 * A space's forum is only as open as the space, and Explore only offers
 * Discussions where they exist.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Feed\ExploreService;
use BuddyNext\Spaces\SpaceService;

/**
 * Join policy follows the space type; the Discussions facet follows Jetonomy.
 */
class JetonomyAccessAlignmentTest extends \WP_UnitTestCase {

	public function test_join_policy_follows_the_space_type(): void {
		$this->assertSame( 'open', JetonomyBridge::discussion_join_policy_for( SpaceService::TYPE_OPEN ) );
		$this->assertSame( 'approval', JetonomyBridge::discussion_join_policy_for( SpaceService::TYPE_PRIVATE ) );
		$this->assertSame( 'invite', JetonomyBridge::discussion_join_policy_for( SpaceService::TYPE_SECRET ) );
		$this->assertSame( 'invite', JetonomyBridge::discussion_join_policy_for( 'anything-else' ) );
	}

	public function test_visibility_and_join_policy_agree_for_every_type(): void {
		foreach ( array( SpaceService::TYPE_OPEN, SpaceService::TYPE_PRIVATE, SpaceService::TYPE_SECRET ) as $type ) {
			$visibility = JetonomyBridge::discussion_visibility_for( $type );
			$policy     = JetonomyBridge::discussion_join_policy_for( $type );
			if ( 'public' === $visibility ) {
				$this->assertSame( 'open', $policy, $type );
			} else {
				$this->assertNotSame( 'open', $policy, "$type must not stay open to everyone." );
			}
		}
	}

	public function test_explore_offers_discussions_only_with_jetonomy_and_its_feed_integration(): void {
		$filters = ExploreService::available_filters();
		if ( class_exists( 'Jetonomy\\Jetonomy' ) && buddynext_integration_enabled( 'jetonomy', 'feed' ) ) {
			$this->assertContains( 'discussions', $filters );
		} else {
			$this->assertNotContains( 'discussions', $filters );
		}
		$this->assertContains( 'all', $filters );

		update_option( 'buddynext_integration_jetonomy_feed', '0' );
		$this->assertNotContains( 'discussions', ExploreService::available_filters(), 'Feed integration off hides the facet.' );
		delete_option( 'buddynext_integration_jetonomy_feed' );
	}
}
