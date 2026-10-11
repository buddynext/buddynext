<?php
/**
 * A replaced avatar or cover gets a new file name.
 *
 * The file name used to be fixed (thumb.webp) with a ?v= query string to bust
 * caches. A CDN or image optimiser that drops or rewrites the query string then
 * served the old image for as long as its cache lived. A version in the name
 * makes a changed image a changed URL for every cache in between.
 *
 * @package BuddyNext\Tests\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Media\ImageStorageService;

/**
 * Versioned image name tests.
 *
 * @covers \BuddyNext\Media\ImageStorageService
 */
class ImageStorageVersionedNamesTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ImageStorageService
	 */
	private ImageStorageService $images;

	/**
	 * Owner id used for the stored files.
	 *
	 * @var int
	 */
	private int $owner = 987654;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->images = new ImageStorageService();
	}

	/**
	 * Remove the files the test wrote.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->images->delete( 'avatar', 'user', $this->owner );
		parent::tear_down();
	}

	/**
	 * Store the sample image as the owner's avatar.
	 *
	 * @return string Stored URL.
	 */
	private function store_avatar(): string {
		$url = $this->images->store( DIR_TESTDATA . '/images/canola.jpg', 'avatar', 'user', $this->owner );
		$this->assertIsString( $url );

		return (string) $url;
	}

	/**
	 * Absolute path of a stored URL.
	 *
	 * @param string $url Stored URL.
	 * @return string
	 */
	private function path_of( string $url ): string {
		$uploads = wp_upload_dir();

		return str_replace( trailingslashit( $uploads['baseurl'] ), trailingslashit( $uploads['basedir'] ), (string) strtok( $url, '?' ) );
	}

	/**
	 * A replacement changes the path, not just a query string.
	 *
	 * @return void
	 */
	public function test_replacing_an_image_changes_its_path(): void {
		$first = $this->store_avatar();
		sleep( 1 );
		$second = $this->store_avatar();

		$this->assertStringNotContainsString( '?', $second, 'The URL does not depend on a query string.' );
		$this->assertNotSame( strtok( $first, '?' ), strtok( $second, '?' ) );
		$this->assertFileExists( $this->path_of( $second ) );
	}

	/**
	 * The previous file stays until the next change, then goes.
	 *
	 * @return void
	 */
	public function test_one_previous_version_is_kept(): void {
		$first = $this->store_avatar();
		sleep( 1 );
		$second = $this->store_avatar();
		$this->assertFileExists( $this->path_of( $first ), 'A page cached with the old URL still shows an image.' );

		sleep( 1 );
		$third = $this->store_avatar();
		$this->assertFileDoesNotExist( $this->path_of( $first ), 'Two changes back is removed.' );
		$this->assertFileExists( $this->path_of( $second ) );
		$this->assertFileExists( $this->path_of( $third ) );
	}

	/**
	 * Rendered surfaces get the newest version, and the same URL every time.
	 *
	 * @return void
	 */
	public function test_variation_url_returns_the_newest_version_and_is_stable(): void {
		$this->store_avatar();
		sleep( 1 );
		$second = $this->store_avatar();

		$full = $this->images->variation_url( 'avatar', 'user', $this->owner, 'full' );
		$this->assertSame( $second, $full );
		$this->assertSame( $full, $this->images->variation_url( 'avatar', 'user', $this->owner, 'full' ) );

		$thumb = $this->images->variation_url( 'avatar', 'user', $this->owner, 'thumb' );
		$this->assertNotSame( '', $thumb );
		$this->assertFileExists( $this->path_of( $thumb ) );
	}

	/**
	 * An image stored under the old fixed name keeps working until it is replaced.
	 *
	 * @return void
	 */
	public function test_an_image_stored_under_the_old_name_still_resolves(): void {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'bn-avatars/' . $this->owner;
		wp_mkdir_p( $dir );
		copy( DIR_TESTDATA . '/images/canola.jpg', $dir . '/full.jpg' );

		$legacy = $this->images->variation_url( 'avatar', 'user', $this->owner, 'full' );
		$this->assertStringContainsString( '/full.jpg', $legacy );

		$new = $this->store_avatar();
		$this->assertFileExists( $dir . '/full.jpg', 'Kept as the previous version.' );
		$this->assertSame( $new, $this->images->variation_url( 'avatar', 'user', $this->owner, 'full' ) );
	}
}
