<?php
/**
 * A per-request memo never remembers "not found".
 *
 * The production half of card 10369216993. A memo that stores a negative answer
 * keeps it for the rest of the request, so anything created later in that same
 * request (activation then a seeder, a space then its forum link) is invisible to
 * it. SearchService's space ceiling had this shape (tests/Search/SpaceCeilingMemoTest.php);
 * these are the two others the audit found.
 *
 * @package BuddyNext\Tests\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Support;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\BlockService;
use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\SocialGraph\PrivacyService;
use WP_UnitTestCase;

/**
 * Negative answers are re-checked, positive ones memoised.
 *
 * @covers \BuddyNext\Bridges\JetonomyBridge
 * @covers \BuddyNext\SocialGraph\PrivacyService::block_exclude_sql
 */
class NegativeMemoTest extends WP_UnitTestCase {

	/**
	 * Install the BuddyNext tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * A forum looked up before its space link is written still resolves after it.
	 *
	 * Space creation fires Jetonomy's hooks before the jetonomy_forum_id meta is
	 * written. A memoised 0 would leave every later discussion in that request with
	 * no space, past the space's own "share to the main feed" toggle.
	 *
	 * @return void
	 */
	public function test_forum_linked_later_in_the_request_resolves_to_its_space(): void {
		$space_id = ( new \BuddyNext\Spaces\SpaceService() )->create(
			self::factory()->user->create(),
			array(
				'name' => 'QA-RFT memo space',
				'slug' => 'qa-rft-memo-space',
			)
		);
		$this->assertIsInt( $space_id );

		$resolve = new \ReflectionMethod( JetonomyBridge::class, 'space_id_for_forum' );
		$bridge  = new JetonomyBridge();
		$forum   = 987654;

		$this->assertSame( 0, $resolve->invoke( $bridge, $forum ), 'Not linked yet.' );

		update_space_meta( $space_id, 'jetonomy_forum_id', $forum );

		$this->assertSame( $space_id, $resolve->invoke( $bridge, $forum ), 'The earlier 0 was memoised.' );
	}

	/**
	 * A blocks table missing on the first feed query is found on the next.
	 *
	 * A memoised "missing" switches block filtering off for everything the request
	 * renders afterwards, so blocked members show up in the feed.
	 *
	 * @return void
	 */
	public function test_blocks_table_found_later_in_the_request_filters_again(): void {
		$service = new PrivacyService( new FollowService(), new ConnectionService(), new BlockService() );
		$viewer  = self::factory()->user->create();

		// The first probe sees no table, as on a request that runs before the installer.
		$hide_table = static function ( string $sql ): string {
			return false !== strpos( $sql, 'SHOW TABLES LIKE' ) ? 'SELECT NULL FROM DUAL WHERE 0' : $sql;
		};
		add_filter( 'query', $hide_table );
		list( $missing ) = $service->block_exclude_sql( $viewer, 'p.user_id', null, null );
		remove_filter( 'query', $hide_table );

		$this->assertSame( '', $missing, 'No table, no fragment.' );

		list( $found ) = $service->block_exclude_sql( $viewer, 'p.user_id', null, null );

		$this->assertStringContainsString( 'bn_blocks', $found, 'The earlier "missing" was memoised.' );
	}
}
