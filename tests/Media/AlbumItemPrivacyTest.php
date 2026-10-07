<?php
/**
 * An album lists only the photos the viewer may see, and never shows a cover
 * photo they may not see.
 *
 * Since WPMediaVerse 2.6.0 a photo in an album shows with the album's privacy,
 * but an older engine let a photo keep stricter privacy inside a public album.
 * Opening the album to guests (GET /albums/{id}) must not hand them that photo
 * or its URL. Each item is asked through the engine's own privacy seam.
 *
 * @package BuddyNext\Tests\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Media\Galleries;

/**
 * Per-item album privacy.
 *
 * @covers \BuddyNext\Media\Galleries::album_media_ids
 * @covers \BuddyNext\Media\Galleries::album_summary
 */
class AlbumItemPrivacyTest extends \WP_UnitTestCase {

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
	 * Album post id.
	 *
	 * @var int
	 */
	private int $album;

	/**
	 * An album holding photo 501 (private to its owner) and 502 (public), with
	 * 501 as the cover.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->owner = self::factory()->user->create();
		$this->album = (int) wp_insert_post(
			array(
				'post_type'   => 'mvs_album',
				'post_status' => 'publish',
				'post_title'  => 'Mixed',
				'post_author' => $this->owner,
			)
		);

		$owner       = $this->owner;
		$this->fakes = array(
			'albums'  => new class() {
				public function get_items( int $id ): array {
					return array( array( 'media_id' => 501 ), array( 'media_id' => 502 ) );
				}
				public function get_item_count( int $id ): int {
					return 2;
				}
				public function get_cover_url( int $id, string $size ): string {
					return 'https://example.test/uploads/501-cover.jpg';
				}
				public function get_resolved_cover_media_id( int $id ): int {
					return 501;
				}
				public function get_privacy( int $id ): string {
					return 'public';
				}
			},
			'privacy' => new class( $owner ) {
				private int $owner;
				public function __construct( int $owner ) {
					$this->owner = $owner;
				}
				public function can_view( int $id, int $viewer, string $space = 'auto' ): bool {
					return 501 !== $id || $viewer === $this->owner;
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
	 * A guest gets only the public photo and no cover; the owner gets both.
	 *
	 * @return void
	 */
	public function test_guest_sees_only_visible_items_and_no_hidden_cover(): void {
		$this->assertSame( array( 502 ), Galleries::album_media_ids( $this->album, 0 ) );
		$this->assertSame( '', Galleries::album_summary( $this->album, 0 )['cover_url'], 'a hidden cover photo must not reach the viewer as a URL' );

		$this->assertSame( array( 501, 502 ), Galleries::album_media_ids( $this->album, $this->owner ) );
		$this->assertSame( 'https://example.test/uploads/501-cover.jpg', Galleries::album_summary( $this->album, $this->owner )['cover_url'] );
	}

	/**
	 * A cover whose photo the engine cannot identify is not shown to anyone but
	 * the owner: it cannot be checked, so it fails closed.
	 *
	 * @return void
	 */
	public function test_unidentifiable_cover_fails_closed(): void {
		$this->fakes['albums'] = new class() {
			public function get_items( int $id ): array {
				return array( array( 'media_id' => 502 ) );
			}
			public function get_cover_url( int $id, string $size ): string {
				return 'https://example.test/uploads/unknown-cover.jpg';
			}
			public function get_privacy( int $id ): string {
				return 'public';
			}
		};

		$this->assertSame( '', Galleries::album_summary( $this->album, 0 )['cover_url'] );
		$this->assertSame( 'https://example.test/uploads/unknown-cover.jpg', Galleries::album_summary( $this->album, $this->owner )['cover_url'] );
	}
}
