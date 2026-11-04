<?php
/**
 * Parity tests for the SpaceNav provider (Wave 3 — register + assert parity).
 *
 * Proves the registry reproduces the core space inventory per viewer role before
 * the space template is repointed at it (the mandatory parity gate).
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;
use BuddyNext\Nav\Providers\SpaceNav;

/**
 * Core space nav: primary tabs, role-gated, clean-URL.
 *
 * @covers \BuddyNext\Nav\Providers\SpaceNav
 */
class SpaceNavTest extends \WP_UnitTestCase {

	/**
	 * Shared registry under test.
	 *
	 * @var NavRegistry
	 */
	private NavRegistry $reg;

	/**
	 * Reset the registry, drop real providers, then wire only SpaceNav.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reg = NavRegistry::instance();
		$this->reg->reset();
		remove_all_actions( 'buddynext_register_nav' );
		( new SpaceNav() )->register();
	}

	/**
	 * Ordered item ids for a layer.
	 *
	 * @param \BuddyNext\Nav\ResolvedNav $resolved Resolved nav.
	 * @param string                     $layer    Layer name.
	 * @return string[]
	 */
	private function ids( $resolved, string $layer ): array {
		return array_map( static fn( $n ) => $n->id, $resolved->layer( $layer ) );
	}

	/**
	 * BuddyNext's own primary tabs for a viewer role, in order.
	 *
	 * Partner tabs (Media, Leaderboard, ...) are on by default in every space
	 * (2e69f75c) and show whenever their plugin's stub is loaded, which depends on
	 * which tests ran first. These tests are about who sees Moderation, so they
	 * compare BuddyNext's own tabs only; see core_ids().
	 *
	 * @param bool $can_moderate Whether the viewer is owner/moderator.
	 * @return string[]
	 */
	private function expected_primary( bool $can_moderate ): array {
		return $can_moderate ? array( 'feed', 'members', 'about', 'moderation' ) : array( 'feed', 'members', 'about' );
	}

	/**
	 * The resolved primary ids, without partner tabs.
	 *
	 * @param mixed $out Resolved nav.
	 * @return string[]
	 */
	private function core_ids( $out ): array {
		return array_values( array_intersect( $this->ids( $out, 'primary' ), array( 'feed', 'members', 'about', 'moderation' ) ) );
	}

	/**
	 * A regular member (no moderation role) sees Feed, Members, About — no Moderation.
	 */
	public function test_member_sees_no_moderation(): void {
		$out = $this->reg->resolve( new NavContext( 'space', 7, 3, 'member' ) );
		$this->assertSame( $this->expected_primary( false ), $this->core_ids( $out ) );
	}

	/**
	 * A moderator sees the Moderation tab, positioned last.
	 */
	public function test_moderator_sees_moderation(): void {
		$out = $this->reg->resolve( new NavContext( 'space', 7, 3, 'moderator' ) );
		$this->assertSame( $this->expected_primary( true ), $this->core_ids( $out ) );
		$ids = $this->ids( $out, 'primary' );
		$this->assertSame( 'moderation', end( $ids ), 'Moderation stays last, after partner tabs' );
	}

	/**
	 * An owner also sees the Moderation tab (role_at_least covers owner).
	 */
	public function test_owner_sees_moderation(): void {
		$out = $this->reg->resolve( new NavContext( 'space', 7, 3, 'owner' ) );
		$this->assertContains( 'moderation', $this->ids( $out, 'primary' ) );
	}

	/**
	 * A logged-out / non-member viewer never sees Moderation.
	 */
	public function test_non_member_sees_no_moderation(): void {
		$out = $this->reg->resolve( new NavContext( 'space', 7, 0, '' ) );
		$this->assertSame( $this->expected_primary( false ), $this->core_ids( $out ) );
		$this->assertNotContains( 'moderation', $this->ids( $out, 'primary' ) );
	}

	/**
	 * Every tab resolves a non-empty clean URL (the lazy url callable ran).
	 */
	public function test_tabs_carry_resolved_urls(): void {
		$out = $this->reg->resolve( new NavContext( 'space', 7, 3, 'owner' ) );
		foreach ( $out->layer( 'primary' ) as $item ) {
			$this->assertNotNull( $item->url_value, "Tab {$item->id} should resolve a URL" );
			$this->assertNotSame( '', (string) $item->url_value, "Tab {$item->id} URL should be non-empty" );
		}
	}
}
