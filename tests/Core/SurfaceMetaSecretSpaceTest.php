<?php
/**
 * SECURITY regression — a SECRET space must not leak its name or description into
 * og:/twitter:/description head tags for a viewer who cannot see it.
 *
 * A secret space with a bogus invite token rendered the 200 "invite no longer
 * valid" page whose <head> still carried the space's name + description (scraped
 * by every link-preview crawler), turning a non-discoverable space into a named,
 * confirmable one. SurfaceMeta::describe_space now gates the descriptor on
 * visibility: a viewer who cannot see the secret space gets a generic noindex
 * stub, while a member (or a holder of a valid, unlocked invite) still gets the
 * normal card. See free-internal security shelf; Basecamp 10321576261.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HeadMeta;
use BuddyNext\Core\Installer;
use BuddyNext\Core\SurfaceMeta;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\SurfaceMeta::describe_space
 */
class SurfaceMetaSecretSpaceTest extends WP_UnitTestCase {

	private const NAME = 'Founders Lounge Regression';

	private int $owner_id;
	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		HeadMeta::reset();

		$this->owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$id             = ( new SpaceService() )->create(
			$this->owner_id,
			array(
				'name'        => self::NAME,
				'slug'        => 'founders-lounge-regression',
				'type'        => SpaceService::TYPE_SECRET,
				'description' => 'A secret space that must never be named to strangers.',
			)
		);
		$this->assertIsInt( $id );
		$this->space_id = $id;
	}

	public function tear_down(): void {
		HeadMeta::reset();
		parent::tear_down();
	}

	private function head_for( int $viewer_id ): string {
		wp_set_current_user( $viewer_id );
		HeadMeta::reset();
		SurfaceMeta::register( 'spaces', array( 'space_id' => $this->space_id ) );
		ob_start();
		do_action( 'wp_head' );
		return (string) ob_get_clean();
	}

	public function test_secret_space_name_is_not_leaked_to_anonymous(): void {
		$out = $this->head_for( 0 );

		$this->assertStringNotContainsString( self::NAME, $out, 'A secret space named itself in the head to an anonymous viewer.' );
		$this->assertStringNotContainsString( 'secret space that must never', $out, 'A secret space leaked its description.' );
		$this->assertStringContainsString( 'noindex', $out, 'The stub must be noindex.' );
	}

	public function test_secret_space_name_is_not_leaked_to_a_non_member(): void {
		$stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$out = $this->head_for( $stranger );

		$this->assertStringNotContainsString( self::NAME, $out );
	}

	public function test_a_member_still_gets_the_space_card(): void {
		// The owner is a member and may see the space — the descriptor is theirs.
		$out = $this->head_for( $this->owner_id );

		$this->assertStringContainsString( self::NAME, $out, 'A member must still get the real space card.' );
	}
}
