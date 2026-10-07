<?php
/**
 * Album privacy goes through WPMediaVerse's album seam, never its media
 * repository (card 10369186079).
 *
 * An album is a post; the media repository is keyed by MEDIA id. Writing or
 * reading an album's privacy there left the album and its photos unchanged,
 * reported a value the album never had, and on a site where a media id equals
 * the album's post id, touched or consulted an unrelated photo instead.
 *
 * WPMediaVerse is not booted in this suite; the buddynext_media_service seam
 * substitutes small fakes that record how they are called.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Media\Galleries;
use BuddyNext\Media\MediaController;
use WP_REST_Request;

/**
 * @group media
 */
class AlbumPrivacySeamTest extends \WP_UnitTestCase {

	/**
	 * Fake WPMediaVerse services, keyed by container id.
	 *
	 * @var array<string, object>
	 */
	private array $fakes = array();

	private int $owner;
	private int $album;

	public function set_up(): void {
		parent::set_up();
		$this->owner = self::factory()->user->create();
		$this->album = (int) wp_insert_post(
			array(
				'post_type'   => 'mvs_album',
				'post_status' => 'publish',
				'post_title'  => 'Holiday',
				'post_author' => $this->owner,
			)
		);
		$this->fakes = array(
			'albums'           => new class() {
				public array $stored = array();
				public function get_privacy( int $id ): string {
					return $this->stored[ $id ] ?? 'public';
				}
				public function set_privacy( int $id, string $privacy ): void {
					$this->stored[ $id ] = $privacy;
				}
			},
			'media_repository' => new class() {
				public array $writes = array();
				public array $reads  = array();
				public function set( int $id, string $key, $value ): void {
					$this->writes[] = array( $id, $key, $value );
				}
				public function get( int $id, string $key ) {
					$this->reads[] = array( $id, $key );
					return 'members';
				}
			},
		);
		add_filter( 'buddynext_media_service', array( $this, 'fake' ), 10, 2 );
	}

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

	public function test_edit_writes_through_the_album_seam_not_the_media_repository(): void {
		wp_set_current_user( $this->owner );
		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/albums/' . $this->album );
		$request->set_param( 'id', $this->album );
		$request->set_param( 'privacy', 'private' );

		$response = ( new MediaController() )->update_album( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'private', $this->fakes['albums']->get_privacy( $this->album ) );
		$this->assertSame( array(), $this->fakes['media_repository']->writes, 'Album privacy was written into the media repository.' );
		$this->assertSame( 'private', $response->get_data()['privacy'], 'The response reports what was saved.' );
	}

	public function test_summary_reads_the_album_seam(): void {
		$this->fakes['albums']->set_privacy( $this->album, 'private' );

		$this->assertSame( 'private', Galleries::album_summary( $this->album )['privacy'] );
		$this->assertNotContains( array( $this->album, 'privacy' ), $this->fakes['media_repository']->reads );
	}

	public function test_view_gate_says_this_id_is_an_album(): void {
		$privacy                 = new class() {
			public const SPACE_CPT = 'cpt';
			public array $spaces   = array();
			public function can_view( int $id, int $user, string $space = 'auto' ): bool {
				$this->spaces[] = $space;
				return true;
			}
		};
		$this->fakes['privacy'] = $privacy;

		Galleries::can_view_album( $this->album, self::factory()->user->create() );

		$this->assertSame( array( 'cpt' ), $privacy->spaces, 'A colliding photo must not decide the album.' );
	}

	public function test_view_gate_still_works_with_an_engine_without_the_mode(): void {
		$privacy                 = new class() {
			public int $calls = 0;
			public function can_view( int $id, int $user ): bool {
				++$this->calls;
				return false;
			}
		};
		$this->fakes['privacy'] = $privacy;

		$this->assertFalse( Galleries::can_view_album( $this->album, self::factory()->user->create() ) );
		$this->assertSame( 1, $privacy->calls );
	}
}
