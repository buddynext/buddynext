<?php
/**
 * Attached media must EXIST, including when the author is a site admin.
 *
 * Regression cover for a dangling-attachment bug reported from a live site: a
 * `photo` post published with `media_ids: [66]` and `media: []` — an id that
 * resolved to nothing anywhere. `authorize_media_ids()` returned early for any
 * user with `manage_options` ("site admins may attach any media"), and that early
 * return skipped the whole loop — so it skipped the EXISTENCE check along with
 * the ownership one it was meant to waive. A fabricated id from a direct API call
 * or an app sailed straight through; the web composer never sends one because it
 * only pushes a server-confirmed id.
 *
 * The curation exemption itself is correct and stays: an admin may still attach
 * media belonging to another member. Only the "does this id resolve at all"
 * question is now asked of everybody.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_Error;

/**
 * Media-id authorisation on post creation.
 *
 * @covers \BuddyNext\Feed\PostService::authorize_media_ids
 */
class MediaAttachmentExistenceTest extends \WP_UnitTestCase {

	/**
	 * Post service under test.
	 *
	 * @var PostService
	 */
	private $service;

	/**
	 * Site administrator — the curator the exemption exists for.
	 *
	 * @var int
	 */
	private $admin = 0;

	/**
	 * A regular member who owns media id 100.
	 *
	 * @var int
	 */
	private $owner = 0;

	/**
	 * A regular member who owns nothing.
	 *
	 * @var int
	 */
	private $other = 0;

	/**
	 * Stand in for the WPMediaVerse repository via the media-boundary seam.
	 *
	 * Media id 100 belongs to $owner; every other id resolves to author 0, which
	 * is what the real repository returns for a row that does not exist.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service = new PostService();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
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
					 * Author of the one media row this fake knows about.
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
	 * Create a photo post and return whatever PostService::create() gave back.
	 *
	 * @param int        $user_id   Author.
	 * @param array<int> $media_ids Attachments to claim.
	 * @return int|WP_Error
	 */
	private function post_with( int $user_id, array $media_ids ) {
		return $this->service->create(
			$user_id,
			array(
				'type'      => 'photo',
				'content'   => 'Attachment test.',
				'media_ids' => $media_ids,
			)
		);
	}

	/**
	 * The reported bug: an admin could publish a fabricated id.
	 *
	 * @return void
	 */
	public function test_admin_cannot_attach_a_nonexistent_media_id(): void {
		$result = $this->post_with( $this->admin, array( 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'A fabricated id was published by an admin.' );
		$this->assertSame( 'media_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * A real id mixed in with a fabricated one still fails — the check is per id,
	 * not "at least one resolves".
	 *
	 * @return void
	 */
	public function test_admin_cannot_attach_a_partly_fabricated_set(): void {
		$result = $this->post_with( $this->admin, array( 100, 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_not_found', $result->get_error_code() );
	}

	/**
	 * The curation exemption is intact: an admin may still attach media owned by
	 * someone else, as long as it exists.
	 *
	 * @return void
	 */
	public function test_admin_may_still_attach_media_owned_by_another_member(): void {
		$post_id = $this->post_with( $this->admin, array( 100 ) );

		$this->assertIsInt( $post_id, 'The curation exemption was lost.' );
		$this->assertSame( array( 100 ), $this->service->get( $post_id )['media_ids'] );
	}

	/**
	 * A member attaching a fabricated id gets the accurate 404 rather than the
	 * misleading "you can only attach media you uploaded" 403 it used to return.
	 *
	 * @return void
	 */
	public function test_member_gets_not_found_for_a_fabricated_id(): void {
		$result = $this->post_with( $this->other, array( 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_not_found', $result->get_error_code() );
	}

	/**
	 * The ownership guard is untouched: a member still cannot attach another
	 * member's real media.
	 *
	 * @return void
	 */
	public function test_member_still_cannot_attach_another_members_media(): void {
		$result = $this->post_with( $this->other, array( 100 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The owner attaching their own media is unaffected.
	 *
	 * @return void
	 */
	public function test_owner_may_attach_their_own_media(): void {
		$post_id = $this->post_with( $this->owner, array( 100 ) );

		$this->assertIsInt( $post_id );
		$this->assertSame( array( 100 ), $this->service->get( $post_id )['media_ids'] );
	}

	/**
	 * A post with no attachments never touches the repository.
	 *
	 * @return void
	 */
	public function test_post_without_media_is_unaffected(): void {
		$post_id = $this->service->create(
			$this->other,
			array(
				'type'    => 'text',
				'content' => 'No attachments here.',
			)
		);

		$this->assertIsInt( $post_id );
	}
}
