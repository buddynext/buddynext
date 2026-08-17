<?php
/**
 * A post that was not written must never be reported as published.
 *
 * `PostService::create()` returned `(int) $wpdb->insert_id` without checking it, and
 * `PostController` only inspects `is_wp_error()` before answering 201. So an insert
 * that wrote nothing produced HTTP 201 and a "Post published" toast over a feed that
 * stayed empty — for every member, with nothing in any log.
 *
 * Verified live before fixing: with `bn_posts` absent, `POST /buddynext/v1/posts`
 * answered **201 Created** and no row existed.
 *
 * The missing table is what made this visible, but the guard is about the RESULT,
 * not that cause: a failed insert is a failed insert, and saying so is the caller's
 * only chance to tell the member the truth.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * create() must report a failed insert.
 *
 * @covers \BuddyNext\Feed\PostService::create
 */
class PostInsertFailureTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var PostService
	 */
	private PostService $service;

	/**
	 * Author.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Seed a member.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = new PostService();
		$this->user_id = (int) $this->factory->user->create();
	}

	/**
	 * Put the schema back however the test ended.
	 *
	 * DDL commits implicitly, so the surrounding transaction cannot undo it.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;

		Installer::flush_schema_check();

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$wpdb->prefix . 'bn_posts'
			)
		);

		if ( $exists < 1 ) {
			$this->with_real_ddl( static fn() => Installer::run() );
		}

		Installer::flush_schema_check();
		parent::tearDown();
	}

	/**
	 * Run a callback with WordPress's temporary-table rewriting switched off.
	 *
	 * WP_UnitTestCase rewrites CREATE/DROP TABLE to their TEMPORARY forms so tests
	 * roll back. Real DDL is impossible under that, and temporary tables are
	 * invisible to information_schema, so the condition under test cannot be built
	 * without lifting it.
	 *
	 * @param callable $fn Work to run against the real schema.
	 * @return void
	 */
	private function with_real_ddl( callable $fn ): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		try {
			$fn();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	// ── The complaint ────────────────────────────────────────────────────────────

	/**
	 * With no table to write to, create() reports failure instead of an id.
	 *
	 * @return void
	 */
	public function test_a_failed_insert_returns_an_error_not_a_zero(): void {
		global $wpdb;

		$this->with_real_ddl(
			static function () use ( $wpdb ): void {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bn_posts' );
			}
		);

		$wpdb->suppress_errors( true );
		$result = $this->service->create( $this->user_id, array( 'content' => 'Nowhere to go' ) );
		$wpdb->suppress_errors( false );

		$this->assertWPError(
			$result,
			'create() returned a falsy id rather than an error, and PostController only checks '
			. 'is_wp_error() before answering 201 — so the member was told "Post published" over a '
			. 'write that never happened.'
		);
		$this->assertSame( 'post_not_saved', $result->get_error_code() );
	}

	/**
	 * The error carries a 5xx, because this is the server's failure.
	 *
	 * @return void
	 */
	public function test_the_error_is_reported_as_a_server_failure(): void {
		global $wpdb;

		$this->with_real_ddl(
			static function () use ( $wpdb ): void {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bn_posts' );
			}
		);

		$wpdb->suppress_errors( true );
		$result = $this->service->create( $this->user_id, array( 'content' => 'Nowhere to go' ) );
		$wpdb->suppress_errors( false );

		$this->assertWPError( $result );
		$data = (array) $result->get_error_data();
		$this->assertSame(
			500,
			(int) ( $data['status'] ?? 0 ),
			'the member did nothing wrong, so this must not be reported as a 4xx'
		);
	}

	// ── What must keep working ───────────────────────────────────────────────────

	/**
	 * A normal post still returns its id.
	 *
	 * Guards against "fixing" this by returning an error on the happy path.
	 *
	 * @return void
	 */
	public function test_a_healthy_insert_still_returns_the_new_id(): void {
		$result = $this->service->create( $this->user_id, array( 'content' => 'This one should save' ) );

		$this->assertNotWPError( $result, 'a normal post must still be created' );
		$this->assertGreaterThan( 0, (int) $result );
	}
}
