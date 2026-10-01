<?php
/**
 * Editing a post's media: add, remove, ownership and the type that follows.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * PostService::update() takes media_ids as the post's full new media list. Media
 * id 100 belongs to $owner (fake repository via the media-boundary seam); every
 * other id resolves to no author, as the real repository answers a missing row.
 *
 * @covers \BuddyNext\Feed\PostService::update
 */
class PostEditMediaTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var PostService
	 */
	private PostService $service;

	/**
	 * Author who owns media 100.
	 *
	 * @var int
	 */
	private int $owner = 0;

	/**
	 * Another member.
	 *
	 * @var int
	 */
	private int $other = 0;

	/**
	 * Fresh schema, two members and a fake media repository.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		update_option( 'buddynext_post_edit_window', 0 );

		$this->service = new PostService();
		$this->owner   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other   = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$owner_id = $this->owner;
		add_filter(
			'buddynext_media_service',
			static function ( $service, string $key ) use ( $owner_id ) {
				if ( 'media_repository' !== $key ) {
					return $service;
				}
				return new class( $owner_id ) {
					/**
					 * Author of media id 100.
					 *
					 * @var int
					 */
					private $owner;

					/**
					 * @param int $owner Author of media id 100.
					 */
					public function __construct( int $owner ) {
						$this->owner = $owner;
					}

					/**
					 * Mirror MediaRepository::get_author(): 0 when the id resolves to nothing.
					 *
					 * @param int $media_id Media id.
					 * @return int
					 */
					public function get_author( $media_id ): int {
						return 100 === (int) $media_id ? $this->owner : 0;
					}
				};
			},
			10,
			2
		);
	}

	/**
	 * Restore the edit window.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_media_service' );
		delete_option( 'buddynext_post_edit_window' );
		parent::tear_down();
	}

	/**
	 * A text post by $user_id.
	 *
	 * @param int $user_id Author.
	 * @return int
	 */
	private function text_post( int $user_id ): int {
		wp_set_current_user( $user_id );
		return (int) $this->service->create( $user_id, array( 'type' => 'text', 'content' => 'Edit media test.' ) );
	}

	/**
	 * Raw row, bypassing the post cache.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	private function row( int $post_id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT type, media_ids, privacy FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Adding a photo to a text post makes it a photo post and indexes the media.
	 *
	 * @return void
	 */
	public function test_adding_own_media_makes_a_text_post_a_photo_post(): void {
		global $wpdb;
		$post = $this->text_post( $this->owner );

		$this->assertTrue( $this->service->update( $post, $this->owner, array( 'media_ids' => array( 100 ) ) ) );

		$row = $this->row( $post );
		$this->assertSame( 'photo', $row['type'] );
		$this->assertSame( '[100]', $row['media_ids'] );
		$this->assertSame( '100', (string) $wpdb->get_var( $wpdb->prepare( "SELECT media_id FROM {$wpdb->prefix}bn_post_media WHERE post_id = %d", $post ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Removing the last photo makes it a text post again and clears the index.
	 *
	 * @return void
	 */
	public function test_removing_all_media_returns_the_post_to_text(): void {
		global $wpdb;
		$post = $this->text_post( $this->owner );
		$this->service->update( $post, $this->owner, array( 'media_ids' => array( 100 ) ) );

		$this->assertTrue( $this->service->update( $post, $this->owner, array( 'media_ids' => array() ) ) );

		$row = $this->row( $post );
		$this->assertSame( 'text', $row['type'] );
		$this->assertNull( $row['media_ids'] );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_post_media WHERE post_id = %d", $post ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Another member's media cannot be attached on edit, exactly as on create.
	 *
	 * @return void
	 */
	public function test_another_members_media_is_refused(): void {
		$post   = $this->text_post( $this->other );
		$result = $this->service->update( $post, $this->other, array( 'media_ids' => array( 100 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'media_forbidden', $result->get_error_code() );
		$this->assertSame( 'text', $this->row( $post )['type'] );
	}

	/**
	 * A post cannot be edited down to nothing.
	 *
	 * @return void
	 */
	public function test_a_post_cannot_end_up_empty(): void {
		$post   = $this->text_post( $this->owner );
		$result = $this->service->update(
			$post,
			$this->owner,
			array(
				'content'   => '',
				'media_ids' => array(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'empty_post', $result->get_error_code() );
	}

	/**
	 * Rescheduling with a privacy change keeps the privacy (the scheduled_at
	 * column used to take no format, shifting every later one: privacy saved '').
	 *
	 * @return void
	 */
	public function test_reschedule_and_privacy_in_one_edit_keeps_the_privacy(): void {
		wp_set_current_user( $this->owner );
		$post = (int) $this->service->create(
			$this->owner,
			array(
				'type'         => 'text',
				'content'      => 'Scheduled.',
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertTrue(
			$this->service->update(
				$post,
				$this->owner,
				array(
					'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ),
					'privacy'      => 'followers',
				)
			)
		);
		$this->assertSame( 'followers', $this->row( $post )['privacy'] );
	}
}
