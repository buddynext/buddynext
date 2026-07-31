<?php
/**
 * The member cover image has a seam, like the avatar next to it.
 *
 * Nine call sites across admin, REST, the demo seeder and the storage service
 * used to spell out the `buddynext_cover_url` user meta by hand - and so did
 * the importer, which had to reach past BuddyNext and write our storage
 * directly because there was no setter to call. That is the shape that becomes
 * a P0 the day the storage changes: a partner keeps "working" while writing a
 * key nothing reads any more.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\AvatarService;

/**
 * @covers \BuddyNext\Profile\AvatarService::save_cover_url
 * @covers \BuddyNext\Profile\AvatarService::get_cover_url
 * @covers \BuddyNext\Profile\AvatarService::delete_cover
 */
class CoverUrlSeamTest extends \WP_UnitTestCase {

	/**
	 * Member under test.
	 *
	 * @var int
	 */
	private $user = 0;

	/**
	 * Service under test.
	 *
	 * @var AvatarService
	 */
	private $avatars;

	public function set_up(): void {
		parent::set_up();

		$this->user    = self::factory()->user->create();
		$this->avatars = new AvatarService();
	}

	public function test_a_cover_round_trips(): void {
		$url = 'http://example.test/wp-content/uploads/bn-covers/1/full.webp';

		$this->avatars->save_cover_url( $this->user, $url );

		$this->assertSame( $url, $this->avatars->get_cover_url( $this->user ) );
	}

	/**
	 * The stored key must not change, or every existing cover disappears on
	 * upgrade and every renderer reading the meta goes blank.
	 *
	 * @return void
	 */
	public function test_it_writes_the_same_meta_key_renderers_already_read(): void {
		$url = 'http://example.test/cover.jpg';

		$this->avatars->save_cover_url( $this->user, $url );

		$this->assertSame(
			$url,
			(string) get_user_meta( $this->user, 'buddynext_cover_url', true ),
			'The seam changed the storage key — existing covers would vanish.'
		);
	}

	public function test_a_member_with_no_cover_returns_an_empty_string(): void {
		$this->assertSame( '', $this->avatars->get_cover_url( $this->user ) );
	}

	/**
	 * Callers should not have to branch between "set" and "clear".
	 *
	 * @return void
	 */
	public function test_an_empty_url_clears_the_cover(): void {
		$this->avatars->save_cover_url( $this->user, 'http://example.test/cover.jpg' );
		$this->avatars->save_cover_url( $this->user, '' );

		$this->assertSame( '', $this->avatars->get_cover_url( $this->user ) );
		$this->assertSame( '', (string) get_user_meta( $this->user, 'buddynext_cover_url', true ) );
	}

	public function test_delete_removes_the_cover(): void {
		$this->avatars->save_cover_url( $this->user, 'http://example.test/cover.jpg' );
		$this->avatars->delete_cover( $this->user );

		$this->assertSame( '', $this->avatars->get_cover_url( $this->user ) );
	}

	/**
	 * Escaping centralised here is the point: a call site that forgot it would
	 * otherwise have stored the URL unescaped with nothing to catch it.
	 *
	 * @return void
	 */
	public function test_an_unsafe_scheme_is_refused(): void {
		$this->avatars->save_cover_url( $this->user, 'javascript:alert(1)' );

		$this->assertSame(
			'',
			$this->avatars->get_cover_url( $this->user ),
			'A javascript: URL reached storage — the escape is not being applied.'
		);
	}

	/**
	 * The importer and the admin both pass ids that can be 0 on a bad row; a
	 * no-op beats writing meta onto user 0.
	 *
	 * @return void
	 */
	public function test_an_invalid_user_id_is_a_no_op(): void {
		$this->avatars->save_cover_url( 0, 'http://example.test/cover.jpg' );

		$this->assertSame( '', $this->avatars->get_cover_url( 0 ) );
		$this->assertSame( '', (string) get_user_meta( 0, 'buddynext_cover_url', true ) );
	}
}
