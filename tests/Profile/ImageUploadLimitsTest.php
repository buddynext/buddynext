<?php
/**
 * A member can upload the photo their phone took.
 *
 * Covers were capped at 1920x1080 and avatars at 1024x1024, both hardcoded and
 * neither filterable. A current handset shoots 4032x3024, so an ordinary photo
 * was refused on both surfaces and the member was asked to go away, crop it, and
 * come back — to finish a profile. The site owner who reported it runs a
 * collectibles community where members photograph the things they collect.
 *
 * The caps could not simply be deleted, though the report argued they could.
 * Every stored image IS downscaled (avatar 512, cover 1600) — but on save, after
 * the file has been decoded, and decoding is where the memory goes. A
 * 20000x20000 PNG is a few MB on disk and about 1.6GB decoded; the byte cap
 * cannot catch it because compression ratio is exactly what varies between a
 * photo and a bomb.
 *
 * So these tests hold both ends: the phone photo goes through, and the bomb does
 * not.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\ProfileController;

/**
 * Limits on profile image uploads.
 *
 * @covers \BuddyNext\Profile\ProfileController::validate_image_upload
 */
class ImageUploadLimitsTest extends \WP_UnitTestCase {

	/**
	 * Files created for a test, removed in tear_down.
	 *
	 * @var array<int,string>
	 */
	private array $temp_files = array();

	/**
	 * Remove any generated image.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();

		parent::tear_down();
	}

	/**
	 * Write a real JPEG of the given pixel size and return a $_FILES-shaped entry.
	 *
	 * A real file, not a fixture array: the guard reads the dimensions with
	 * getimagesize(), so a stubbed size would test the arithmetic and not the
	 * thing that runs.
	 *
	 * @param int $width  Pixel width.
	 * @param int $height Pixel height.
	 * @return array<string,mixed>
	 */
	private function image( int $width, int $height ): array {
		$path = (string) tempnam( sys_get_temp_dir(), 'bn-img' ) . '.jpg';

		$im = imagecreatetruecolor( $width, $height );
		imagejpeg( $im, $path, 60 );
		imagedestroy( $im );

		$this->temp_files[] = $path;

		return array(
			'name'     => 'photo.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => (int) filesize( $path ),
		);
	}

	/**
	 * Run the private guard.
	 *
	 * @param array<string,mixed> $file Uploaded file entry.
	 * @param string              $kind 'avatar' or 'cover'.
	 * @return true|\WP_Error
	 */
	private function validate( array $file, string $kind ) {
		$method = new \ReflectionMethod( ProfileController::class, 'validate_image_upload' );
		$method->setAccessible( true );

		return $method->invoke( new ProfileController(), $file, $kind );
	}

	// ── The complaint ────────────────────────────────────────────────────────────

	/**
	 * A photo straight off a current phone is accepted, as a cover and an avatar.
	 *
	 * 4032x3024 is the iPhone/Pixel main-camera size. It was refused by BOTH
	 * surfaces before this change — the avatar more absurdly than the cover,
	 * since its cap was 1024.
	 *
	 * @return void
	 */
	public function test_a_phone_photo_is_accepted(): void {
		$photo = $this->image( 4032, 3024 );

		$this->assertTrue( $this->validate( $photo, 'cover' ), 'A phone photo was refused as a cover image.' );
		$this->assertTrue( $this->validate( $photo, 'avatar' ), 'A phone photo was refused as an avatar.' );
	}

	/**
	 * And so is the resolution the old cover cap allowed, which must not regress.
	 *
	 * @return void
	 */
	public function test_the_previously_allowed_size_still_passes(): void {
		$this->assertTrue( $this->validate( $this->image( 1920, 1080 ), 'cover' ) );
	}

	// ── The protection that had to survive ───────────────────────────────────────

	/**
	 * A decompression bomb is still refused.
	 *
	 * Without this the whole change could be "delete the guard", which is what
	 * the report asked for and what the storage-downscales-anyway argument
	 * appears to justify.
	 *
	 * @return void
	 */
	public function test_a_pixel_bomb_is_refused(): void {
		// Declared, not generated: creating a 60MP image to prove we reject it
		// would spend the memory the guard exists to save.
		$bomb = array(
			'name'     => 'bomb.png',
			'type'     => 'image/png',
			'tmp_name' => $this->image( 9000, 7000 )['tmp_name'],
			'error'    => 0,
			'size'     => 900000,
		);

		$result = $this->validate( $bomb, 'cover' );

		$this->assertWPError( $result, 'A 63-megapixel image was accepted for decoding.' );
		$this->assertSame( 'cover_dimensions', $result->get_error_code() );
	}

	/**
	 * An image that is small in pixels but absurd in one direction is refused.
	 *
	 * 100000x500 is 50MP and would pass a megapixel test alone, which is why the
	 * single-side ceiling exists beside it.
	 *
	 * @return void
	 */
	public function test_an_extreme_aspect_ratio_is_refused(): void {
		$long = array(
			'name'     => 'long.png',
			'type'     => 'image/png',
			'tmp_name' => $this->image( 12000, 100 )['tmp_name'],
			'error'    => 0,
			'size'     => 50000,
		);

		$result = $this->validate( $long, 'cover' );

		$this->assertWPError( $result, 'A 12000px-wide strip was accepted.' );
	}

	/**
	 * The byte cap still refuses an oversized file.
	 *
	 * @return void
	 */
	public function test_an_oversized_file_is_refused(): void {
		$file         = $this->image( 800, 600 );
		$file['size'] = 9 * 1024 * 1024;

		$result = $this->validate( $file, 'cover' );

		$this->assertWPError( $result, 'A 9MB upload passed a 5MB cap.' );
		$this->assertSame( 'cover_too_large', $result->get_error_code() );
	}

	/**
	 * Avatar and cover carry their own byte caps.
	 *
	 * 4.5MB is over the avatar's 4MB and under the cover's 5MB, so one value
	 * proves the two are not sharing a single limit.
	 *
	 * @return void
	 */
	public function test_the_two_kinds_keep_their_own_byte_caps(): void {
		$file         = $this->image( 800, 600 );
		$file['size'] = (int) ( 4.5 * 1024 * 1024 );

		$this->assertTrue( $this->validate( $file, 'cover' ), 'A 4.5MB cover was refused under a 5MB cap.' );
		$this->assertWPError( $this->validate( $file, 'avatar' ), 'A 4.5MB avatar passed a 4MB cap.' );
	}

	// ── The filters, which are what the owner asked for ──────────────────────────

	/**
	 * An owner can raise the pixel ceiling.
	 *
	 * @return void
	 */
	public function test_the_megapixel_ceiling_is_filterable(): void {
		$raise = static fn(): float => 100.0;
		add_filter( 'buddynext_upload_max_megapixels', $raise );

		$result = $this->validate(
			array(
				'name'     => 'big.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $this->image( 9000, 7000 )['tmp_name'],
				'error'    => 0,
				'size'     => 900000,
			),
			'cover'
		);

		remove_filter( 'buddynext_upload_max_megapixels', $raise );

		$this->assertTrue( $result, 'Raising the megapixel filter did not admit a larger image.' );
	}

	/**
	 * ...and lower it, which is the case a small host needs.
	 *
	 * @return void
	 */
	public function test_the_megapixel_ceiling_can_be_lowered(): void {
		$lower = static fn(): float => 1.0;
		add_filter( 'buddynext_upload_max_megapixels', $lower );

		$result = $this->validate( $this->image( 1920, 1080 ), 'cover' );

		remove_filter( 'buddynext_upload_max_megapixels', $lower );

		$this->assertWPError( $result, 'Lowering the megapixel filter did not refuse a 2MP image.' );
	}

	/**
	 * The byte cap is filterable too.
	 *
	 * @return void
	 */
	public function test_the_byte_cap_is_filterable(): void {
		$raise = static fn(): int => 20 * 1024 * 1024;
		add_filter( 'buddynext_upload_max_bytes', $raise );

		$file         = $this->image( 800, 600 );
		$file['size'] = 9 * 1024 * 1024;
		$result       = $this->validate( $file, 'cover' );

		remove_filter( 'buddynext_upload_max_bytes', $raise );

		$this->assertTrue( $result, 'Raising the byte filter did not admit a 9MB file.' );
	}

	/**
	 * Every filter is told which kind it is deciding for.
	 *
	 * Without the argument a site could only set one policy for both, and the
	 * two have different jobs — an avatar is a face, a cover is a scene.
	 *
	 * @return void
	 */
	public function test_filters_receive_the_image_kind(): void {
		$seen = array();

		$spy = static function ( $value, $kind ) use ( &$seen ) {
			$seen[] = $kind;
			return $value;
		};
		add_filter( 'buddynext_upload_max_megapixels', $spy, 10, 2 );

		$this->validate( $this->image( 800, 600 ), 'avatar' );
		$this->validate( $this->image( 800, 600 ), 'cover' );

		remove_filter( 'buddynext_upload_max_megapixels', $spy, 10 );

		$this->assertSame( array( 'avatar', 'cover' ), $seen, 'The filters cannot tell an avatar from a cover.' );
	}
}
