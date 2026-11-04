<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The write APIs that used to stamp "now" take an optional source time.
 *
 * Importers write through these services, never raw bn_* SQL (card 10459592358).
 * Called without the time they behave as before; a past time is stored; a future
 * one clamps to now; an older record never moves newer activity backwards.
 *
 * @package BuddyNext\Tests\Core
 * @since 1.2.8
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use BuddyNext\Feed\IntegrationActivity;
use WP_UnitTestCase;

/**
 * Locks the optional source time on every write API.
 */
class WriteApiBackdateTest extends WP_UnitTestCase {

	private const OLD   = '2020-02-02 02:02:02';
	private const OLDER = '2019-01-01 01:01:01';

	/**
	 * Author.
	 *
	 * @var int
	 */
	private int $user;

	/**
	 * A second member.
	 *
	 * @var int
	 */
	private int $other;

	/**
	 * Create the two members.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->user  = self::factory()->user->create();
		$this->other = self::factory()->user->create();
		update_user_meta( $this->user, 'buddynext_email_verified', 1 );
		update_user_meta( $this->other, 'buddynext_email_verified', 1 );
	}

	/**
	 * Read one bn_* column.
	 *
	 * @param string $table Table without the prefix.
	 * @param string $col   Column.
	 * @param string $where Trusted WHERE clause.
	 */
	private function col( string $table, string $col, string $where ): ?string {
		global $wpdb;
		return $wpdb->get_var( "SELECT {$col} FROM {$wpdb->prefix}{$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A post dated in the past.
	 *
	 * @param string   $at    UTC time.
	 * @param int|null $space Space id.
	 */
	private function post( string $at, ?int $space = null ): int {
		$data = array(
			'type'       => 'text',
			'content'    => 'Source post ' . wp_generate_password( 6, false ),
			'created_at' => $at,
		);
		if ( null !== $space ) {
			$data['space_id'] = $space;
		}
		$id = buddynext_service( 'post_service' )->create( $this->user, $data );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * A public space owned by the first member.
	 */
	private function space(): int {
		$slug = 'backdate-' . strtolower( wp_generate_password( 8, false ) );
		$id   = buddynext_service( 'spaces' )->create(
			$this->user,
			array(
				'name' => $slug,
				'slug' => $slug,
				'type' => 'public',
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Assert a stored time is within a minute of now.
	 *
	 * @param string|null $value Stored UTC time.
	 */
	private function assert_now( ?string $value ): void {
		$this->assertGreaterThan( time() - 60, strtotime( (string) $value . ' UTC' ) );
	}

	/**
	 * A comment dates the post's activity; an older one does not move it back; a live one lands on now.
	 */
	public function test_post_last_activity_follows_the_engaging_record(): void {
		$post     = $this->post( self::OLDER );
		$comments = buddynext_service( 'comments' );

		$comments->create( $this->other, 'post', $post, 'Later source comment', null, self::OLD );
		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'last_activity_at', "id = {$post}" ) );
		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'updated_at', "id = {$post}" ) );

		$comments->create( $this->other, 'post', $post, 'Older source comment', null, '2019-06-06 06:06:06' );
		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'last_activity_at', "id = {$post}" ), 'an older record must not move activity backwards' );
		$this->assertSame( '2', $this->col( 'bn_posts', 'comment_count', "id = {$post}" ) );

		$comments->create( $this->other, 'post', $post, 'Live comment' );
		$this->assert_now( $this->col( 'bn_posts', 'last_activity_at', "id = {$post}" ) );
	}

	/**
	 * A reaction follows the same rule, and a future time clamps to now.
	 */
	public function test_reaction_dates_post_activity_and_future_clamps(): void {
		$post      = $this->post( self::OLDER );
		$reactions = buddynext_service( 'reactions' );

		$reactions->react( $this->other, 'post', $post, 'like', self::OLD );
		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'last_activity_at', "id = {$post}" ) );

		$reactions->react( $this->user, 'post', $post, 'like', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$this->assert_now( $this->col( 'bn_posts', 'last_activity_at', "id = {$post}" ) );
		$this->assertLessThanOrEqual( time() + 1, strtotime( (string) $this->col( 'bn_posts', 'last_activity_at', "id = {$post}" ) . ' UTC' ) );
	}

	/**
	 * A post with no stored activity takes the record's time rather than staying NULL.
	 */
	public function test_null_last_activity_takes_the_record_time(): void {
		global $wpdb;
		$post = $this->post( self::OLDER );
		$wpdb->update( $wpdb->prefix . 'bn_posts', array( 'last_activity_at' => null ), array( 'id' => $post ) ); // phpcs:ignore WordPress.DB

		buddynext_service( 'post_service' )->increment_counter( $post, 'comment_count', self::OLD );

		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'last_activity_at', "id = {$post}" ) );
	}

	/**
	 * A backdated post dates the space; an older one does not move it back; a live one lands on now.
	 */
	public function test_space_last_active_follows_posts(): void {
		$space = $this->space();
		buddynext_service( 'space_members' )->join( $space, $this->user );

		$this->post( self::OLD, $space );
		$this->assertSame( self::OLD, $this->col( 'bn_spaces', 'last_active_at', "id = {$space}" ) );

		$this->post( self::OLDER, $space );
		$this->assertSame( self::OLD, $this->col( 'bn_spaces', 'last_active_at', "id = {$space}" ) );

		buddynext_service( 'post_service' )->create(
			$this->user,
			array(
				'type'     => 'text',
				'content'  => 'Live space post',
				'space_id' => $space,
			)
		);
		$this->assert_now( $this->col( 'bn_spaces', 'last_active_at', "id = {$space}" ) );
	}

	/**
	 * Backdated comments are not lost to the throttle, even inside one window.
	 */
	public function test_backdated_comments_skip_the_space_throttle(): void {
		$space    = $this->space();
		$post     = $this->post( self::OLDER, $space );
		$comments = buddynext_service( 'comments' );

		$comments->create( $this->user, 'post', $post, 'First', null, '2019-06-06 06:06:06' );
		$comments->create( $this->user, 'post', $post, 'Newest', null, self::OLD );
		$comments->create( $this->user, 'post', $post, 'Oldest', null, '2019-03-03 03:03:03' );

		$this->assertSame( self::OLD, $this->col( 'bn_spaces', 'last_active_at', "id = {$space}" ) );
	}

	/**
	 * The space throttle still holds for live comments, and a live one bumps to now.
	 */
	public function test_live_comments_still_throttle(): void {
		global $wpdb;
		$space    = $this->space();
		$post     = $this->post( self::OLDER, $space );
		$comments = buddynext_service( 'comments' );

		$comments->create( $this->user, 'post', $post, 'Live' );
		$this->assert_now( $this->col( 'bn_spaces', 'last_active_at', "id = {$space}" ) );

		$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'last_active_at' => self::OLD ), array( 'id' => $space ) ); // phpcs:ignore WordPress.DB
		$comments->create( $this->user, 'post', $post, 'Live inside the window' );
		$this->assertSame( self::OLD, $this->col( 'bn_spaces', 'last_active_at', "id = {$space}" ), 'a live comment inside the window writes nothing' );
	}

	/**
	 * Join and join request store the source time; without it they stamp now.
	 */
	public function test_join_and_request_join(): void {
		$members = buddynext_service( 'space_members' );
		$space   = $this->space();
		$third   = self::factory()->user->create();
		$fourth  = self::factory()->user->create();

		$this->assertTrue( $members->join( $space, $this->other, self::OLD ) );
		$this->assertSame( self::OLD, $this->col( 'bn_space_members', 'joined_at', "space_id = {$space} AND user_id = {$this->other}" ) );

		$this->assertTrue( $members->join( $space, $third ) );
		$this->assert_now( $this->col( 'bn_space_members', 'joined_at', "space_id = {$space} AND user_id = {$third}" ) );

		$this->assertTrue( $members->request_join( $space, $fourth, self::OLDER ) );
		$this->assertSame( self::OLDER, $this->col( 'bn_space_members', 'joined_at', "space_id = {$space} AND user_id = {$fourth}" ) );

		// A pending row promoted with a time takes it; promoted without one keeps its own.
		$this->assertTrue( $members->join( $space, $fourth, self::OLD ) );
		$this->assertSame( self::OLD, $this->col( 'bn_space_members', 'joined_at', "space_id = {$space} AND user_id = {$fourth}" ) );
		$fifth = self::factory()->user->create();
		$members->request_join( $space, $fifth, self::OLDER );
		$members->join( $space, $fifth );
		$this->assertSame( self::OLDER, $this->col( 'bn_space_members', 'joined_at', "space_id = {$space} AND user_id = {$fifth}" ) );
	}

	/**
	 * A bookmark stores the source time; without it, now; a future time clamps.
	 */
	public function test_bookmark(): void {
		$post      = $this->post( self::OLDER );
		$bookmarks = buddynext_service( 'bookmarks' );

		$bookmarks->bookmark( $this->user, $post, self::OLD );
		$this->assertSame( self::OLD, $this->col( 'bn_bookmarks', 'created_at', "user_id = {$this->user} AND post_id = {$post}" ) );

		$bookmarks->bookmark( $this->other, $post );
		$this->assert_now( $this->col( 'bn_bookmarks', 'created_at', "user_id = {$this->other} AND post_id = {$post}" ) );

		$third = self::factory()->user->create();
		$bookmarks->bookmark( $third, $post, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$this->assert_now( $this->col( 'bn_bookmarks', 'created_at', "user_id = {$third} AND post_id = {$post}" ) );
	}

	/**
	 * A share dates its row, its card and the original post's activity.
	 */
	public function test_share_dates_row_card_and_original(): void {
		$post = $this->post( self::OLDER );
		$card = buddynext_service( 'shares' )->share( $this->other, $post, '', self::OLD );

		$this->assertIsInt( $card );
		$this->assertSame( self::OLD, $this->col( 'bn_shares', 'created_at', "user_id = {$this->other} AND post_id = {$post}" ) );
		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'created_at', "id = {$card}" ) );
		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'last_activity_at', "id = {$card}" ) );
		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'updated_at', "id = {$card}" ) );
		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'last_activity_at', "id = {$post}" ) );
	}

	/**
	 * A live share behaves as before: dated now, and its card keeps NULL activity.
	 */
	public function test_live_share_is_unchanged(): void {
		$post = $this->post( self::OLDER );
		$card = buddynext_service( 'shares' )->share( $this->other, $post );

		$this->assertIsInt( $card );
		$this->assert_now( $this->col( 'bn_posts', 'created_at', "id = {$card}" ) );
		$this->assertNull( $this->col( 'bn_posts', 'last_activity_at', "id = {$card}" ) );
		$this->assert_now( $this->col( 'bn_posts', 'last_activity_at', "id = {$post}" ) );
	}

	/**
	 * An integration card takes the source time, and now without one.
	 */
	public function test_integration_card(): void {
		$dated = IntegrationActivity::publish( $this->user, '', 'https://example.test/a', 'A', 'link', '', 0, array(), self::OLD );
		$this->assertIsInt( $dated );
		$this->assertSame( self::OLD, $this->col( 'bn_posts', 'created_at', "id = {$dated}" ) );

		$live = IntegrationActivity::publish( $this->user, '', 'https://example.test/b', 'B' );
		$this->assertIsInt( $live );
		$this->assert_now( $this->col( 'bn_posts', 'created_at', "id = {$live}" ) );
	}
}
