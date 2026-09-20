<?php
/**
 * SECURITY regression — the engagement privacy gate must fail SAFE for an object
 * type it does not govern (A1, 2026-09-21 sweep).
 *
 * BaseRestController::is_post_hidden_from_viewer() runs on every reaction/comment
 * read + write. A type PostService cannot map to a post (a future reactable object
 * added without teaching the resolver) must be treated as HIDDEN, not served, so a
 * new surface cannot skip post-privacy silently. A governed type that simply
 * resolves to no post (a bogus id) carries no data and stays visible (fail-open),
 * as does an unavailable service container. See free-internal security shelf.
 *
 * @package BuddyNext\Tests\Reactions
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Reactions;

use BuddyNext\Core\Installer;
use BuddyNext\REST\BaseRestController;

/**
 * @covers \BuddyNext\REST\BaseRestController::is_post_hidden_from_viewer
 * @covers \BuddyNext\Feed\PostService::governs_engagement_type
 */
class EngagementGateUngovernedTypeTest extends \WP_UnitTestCase {

	/**
	 * A minimal concrete controller that exposes the protected engagement gate.
	 *
	 * @return object with a public probe( string, int ): bool
	 */
	private function probe_controller() {
		return new class() extends BaseRestController {
			public function register_routes(): void {}

			public function probe( string $object_type, int $object_id ): bool {
				return $this->is_post_hidden_from_viewer( $object_type, $object_id );
			}
		};
	}

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_ungoverned_type_is_hidden(): void {
		$c = $this->probe_controller();

		// A type the resolver does not know must fail safe (hidden), regardless of id.
		$this->assertTrue( $c->probe( 'gizmo', 0 ) );
		$this->assertTrue( $c->probe( 'gizmo', 4242 ) );
		$this->assertTrue( $c->probe( '', 4242 ) );
	}

	public function test_governed_type_with_no_resolvable_post_stays_visible(): void {
		$c = $this->probe_controller();

		// Governed types are NOT blanket-hidden: with no gateable post behind them
		// (a bogus id → no data) they stay visible, exactly as before this fix.
		$this->assertFalse( $c->probe( 'post', 0 ) );
		$this->assertFalse( $c->probe( 'comment', 0 ) );
	}

	public function test_governs_engagement_type_contract(): void {
		$posts = buddynext_service( 'post_service' );

		$this->assertTrue( $posts->governs_engagement_type( 'post' ) );
		$this->assertTrue( $posts->governs_engagement_type( 'comment' ) );
		$this->assertFalse( $posts->governs_engagement_type( 'gizmo' ) );
		$this->assertFalse( $posts->governs_engagement_type( '' ) );
	}
}
