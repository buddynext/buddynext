<?php
/**
 * Space owners and moderators read their own space's moderation log over REST.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationLogService;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;

/**
 * GET /moderation/log was site-moderator only with no space filter, so the
 * space Moderation tab's Activity log and counts had no app equivalent.
 *
 * @covers \BuddyNext\Moderation\ModerationController::get_moderation_log
 */
class SpaceModerationLogRestTest extends \WP_Test_REST_TestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * GET the log with the given query.
	 *
	 * @param array<string,mixed> $query Query params.
	 * @return \WP_REST_Response
	 */
	private function get_log( array $query ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/moderation/log' );
		$request->set_query_params( $query );
		return rest_do_request( $request );
	}

	/**
	 * Own space: readable and filtered; another space or the whole log: refused.
	 *
	 * @return void
	 */
	public function test_space_moderator_reads_only_their_space(): void {
		$owner  = self::factory()->user->create();
		$other  = self::factory()->user->create();
		$spaces = new SpaceService();
		$mine   = (int) $spaces->create( $owner, array( 'name' => 'Mine', 'slug' => 'mine-' . wp_rand( 1000, 9999 ) ) );
		$theirs = (int) $spaces->create( $other, array( 'name' => 'Theirs', 'slug' => 'theirs-' . wp_rand( 1000, 9999 ) ) );

		$log = new ModerationLogService();
		$log->log( $owner, 'warn', array( 'space_id' => $mine ) );
		$log->log( $owner, 'remove_post', array( 'space_id' => $mine ) );
		$log->log( $other, 'warn', array( 'space_id' => $theirs ) );

		wp_set_current_user( $owner );
		$this->assertSame( 2, $this->get_log( array( 'space_id' => $mine ) )->get_data()['total'], 'Actions taken in my space.' );
		$this->assertSame( 1, $this->get_log( array( 'space_id' => $mine, 'action' => 'warn', 'since_days' => 7 ) )->get_data()['total'], 'Members warned this week.' );
		$this->assertSame( 403, $this->get_log( array( 'space_id' => $theirs ) )->get_status(), 'Not someone else\'s space.' );
		$this->assertSame( 403, $this->get_log( array() )->get_status(), 'Not the site-wide log.' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->get_log( array( 'space_id' => $mine ) )->get_status(), 'A plain member.' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get_log( array( 'space_id' => $mine ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 3, $this->get_log( array() )->get_data()['total'], 'Site moderators still read everything.' );
	}
}
