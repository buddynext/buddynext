<?php
/**
 * A suspended member's albums are hidden like their media, and an album title reads as typed.
 *
 * @package BuddyNext\Tests\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Media\Galleries;

/**
 * @covers \BuddyNext\Media\Galleries::can_view_album
 * @covers \BuddyNext\Media\Galleries::user_albums
 * @covers \BuddyNext\Media\Galleries::album_media_ids
 * @covers \BuddyNext\Media\Galleries::album_summary
 */
class AlbumSuspendedOwnerTest extends \WP_UnitTestCase {

	/**
	 * Fake engine services by container key.
	 *
	 * @var array<string,object>
	 */
	private array $fakes = array();

	/**
	 * Album owner.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * A public album (id) holding two public photos.
	 *
	 * @var int
	 */
	private int $album;

	/**
	 * Create the owner, a public album and a permissive engine.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! post_type_exists( 'mvs_album' ) ) {
			register_post_type( 'mvs_album', array( 'public' => true ) );
		}
		$this->owner = self::factory()->user->create();
		$this->album = (int) wp_insert_post(
			array(
				'post_type'   => 'mvs_album',
				'post_status' => 'publish',
				'post_title'  => "Trip -- 2 Ben's \"days\"",
				'post_author' => $this->owner,
			)
		);

		$this->fakes = array(
			'albums'  => new class() {
				public function get_items( int $id ): array {
					return array( array( 'media_id' => 601 ), array( 'media_id' => 602 ) );
				}
				public function get_item_count( int $id ): int {
					return 2;
				}
				public function get_cover_url( int $id, string $size ): string {
					return '';
				}
				public function get_resolved_cover_media_id( int $id ): int {
					return 0;
				}
				public function get_privacy( int $id ): string {
					return 'public';
				}
			},
			'privacy' => new class() {
				public function can_view( int $id, int $viewer, string $space = 'auto' ): bool {
					return true;
				}
			},
		);
		add_filter( 'buddynext_media_service', array( $this, 'fake' ), 10, 2 );
	}

	/**
	 * Remove the seam.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'buddynext_media_service', array( $this, 'fake' ), 10 );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Seam callback.
	 *
	 * @param object|null $resolved Resolved service.
	 * @param string      $key      Container key.
	 * @return object|null
	 */
	public function fake( $resolved, string $key ) {
		return $this->fakes[ $key ] ?? $resolved;
	}

	/**
	 * Visible while the owner is in good standing; hidden from a guest and another member once suspended, on the
	 * list, the single album and its pictures; a site administrator still sees it.
	 *
	 * @return void
	 */
	public function test_a_suspended_members_album_is_hidden_from_guests_and_members(): void {
		$member = self::factory()->user->create();
		$this->assertTrue( Galleries::can_view_album( $this->album, 0 ), 'Fixture is wrong: the album should be public.' );
		$this->assertCount( 1, Galleries::user_albums( $this->owner, 0 ) );
		$this->assertNotEmpty( Galleries::album_media_ids( $this->album, 0 ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( buddynext_service( 'moderation' )->suspend_user( $this->owner, $admin, 'Repeated spam links', array( 'duration_days' => 7 ) ) );

		foreach ( array( 0, $member ) as $viewer ) {
			wp_set_current_user( $viewer );
			$this->assertFalse( Galleries::can_view_album( $this->album, $viewer ), 'A suspended member\'s album is still public.' );
			$this->assertSame( array(), Galleries::user_albums( $this->owner, $viewer ), 'The album list still shows it.' );
			$this->assertSame( array(), Galleries::album_media_ids( $this->album, $viewer ), 'The album pictures are still listed.' );
		}

		wp_set_current_user( $admin );
		$this->assertTrue( Galleries::can_view_album( $this->album, $admin ), 'A site administrator lost sight of the album.' );
		$this->assertNotEmpty( Galleries::album_media_ids( $this->album, $admin ) );
	}

	/**
	 * The title is what the member typed: no curly quotes, no long dash.
	 *
	 * @return void
	 */
	public function test_an_album_title_reads_as_typed(): void {
		$this->assertSame( "Trip -- 2 Ben's \"days\"", Galleries::album_summary( $this->album, 0 )['title'] );
	}
}
