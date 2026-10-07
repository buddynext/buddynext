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

	/**
	 * GET /reports/queue?space_id: one space's open reports, with the offender
	 * name and strike count the space Moderation tab shows; scoped like the log.
	 *
	 * @return void
	 */
	public function test_space_report_queue_is_scoped_and_enriched(): void {
		$owner   = self::factory()->user->create();
		$other   = self::factory()->user->create();
		$author  = self::factory()->user->create( array( 'display_name' => 'Rule Breaker' ) );
		$spaces  = new SpaceService();
		$members = buddynext_service( 'space_members' );
		$mine    = (int) $spaces->create( $owner, array( 'name' => 'Mine', 'slug' => 'mq-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );
		$theirs  = (int) $spaces->create( $other, array( 'name' => 'Theirs', 'slug' => 'tq-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );
		$members->join( $mine, $author );
		$members->join( $theirs, $author );

		$posts = new \BuddyNext\Feed\PostService();
		$mod   = new \BuddyNext\Moderation\ModerationService();
		$in    = (int) $posts->create( $author, array( 'content' => 'Reported here', 'space_id' => $mine ) );
		$out   = (int) $posts->create( $author, array( 'content' => 'Reported there', 'space_id' => $theirs ) );
		$mod->report( self::factory()->user->create(), 'post', $in, 'spam' );
		$mod->report( self::factory()->user->create(), 'post', $out, 'spam' );

		$queue = function ( array $query ): \WP_REST_Response {
			$request = new WP_REST_Request( 'GET', '/buddynext/v1/reports/queue' );
			$request->set_query_params( $query );
			return rest_do_request( $request );
		};

		wp_set_current_user( $owner );
		$data = $queue( array( 'space_id' => $mine ) )->get_data();
		$this->assertSame( array( $in ), array_map( 'intval', wp_list_pluck( $data['items'], 'object_id' ) ), 'Only my space\'s report.' );
		$this->assertSame( 'Rule Breaker', $data['items'][0]['offender_name'] );
		$this->assertArrayHasKey( 'strikes_count', $data['items'][0] );
		$this->assertSame( 403, $queue( array( 'space_id' => $theirs ) )->get_status(), 'Not someone else\'s space.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertCount( 1, $queue( array( 'space_id' => $theirs ) )->get_data()['items'], 'A site moderator may narrow to any space.' );
	}

	/**
	 * Regression guard: anyone who could read the log before (any moderation
	 * ability, e.g. a role that may only issue strikes) still reads all of it.
	 *
	 * @return void
	 */
	public function test_any_moderation_ability_still_reads_the_whole_log(): void {
		$striker = self::factory()->user->create();
		( new ModerationLogService() )->log( $striker, 'warn', array( 'space_id' => 0 ) );
		$grant = static function ( $can, $user_id, $ability ) use ( $striker ) {
			return ( (int) $user_id === $striker && 'buddynext-moderation/issue-strike' === $ability ) ? true : $can;
		};
		add_filter( 'buddynext_user_can', $grant, 10, 3 );

		wp_set_current_user( $striker );
		$this->assertSame( 200, $this->get_log( array() )->get_status() );

		remove_filter( 'buddynext_user_can', $grant, 10 );
	}
}
