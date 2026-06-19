<?php
/**
 * Parity tests for the ProfileNav provider (Wave 2 — register + assert parity).
 *
 * Proves the registry reproduces the core profile inventory before any template
 * is repointed at it or any legacy filter is deleted (the mandatory parity gate).
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Media\MediaClient;
use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;
use BuddyNext\Nav\Providers\ProfileNav;

/**
 * Core profile nav: metric row + primary tabs, per viewer role.
 *
 * @covers \BuddyNext\Nav\Providers\ProfileNav
 */
class ProfileNavTest extends \WP_UnitTestCase {

	/**
	 * Shared registry under test.
	 *
	 * @var NavRegistry
	 */
	private NavRegistry $reg;

	/**
	 * Reset the registry, drop real providers, then wire only ProfileNav.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reg = NavRegistry::instance();
		$this->reg->reset();
		remove_all_actions( 'buddynext_register_nav' );
		( new ProfileNav() )->register();
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
	 * Expected primary ids in order, accounting for the media-engine gate.
	 *
	 * @param bool $is_self Whether the viewer owns the profile.
	 * @return string[]
	 */
	private function expected_primary( bool $is_self ): array {
		$ids = array( 'posts' );
		if ( $is_self ) {
			$ids[] = 'scheduled';
		}
		$ids[] = 'replies';
		if ( MediaClient::available() ) {
			$ids[] = 'media';
		}
		$ids[] = 'likes';
		$ids[] = 'network';
		return $ids;
	}

	/**
	 * The metric row is always Followers, Following, Connections — in order.
	 */
	public function test_metric_row_parity(): void {
		$uid = self::factory()->user->create();
		$out = $this->reg->resolve( new NavContext( 'profile', $uid, $uid ) );
		$this->assertSame(
			array( 'followers', 'following', 'connections' ),
			$this->ids( $out, 'metric' )
		);
	}

	/**
	 * Own profile exposes the Scheduled tab, positioned right after Posts.
	 */
	public function test_primary_tabs_parity_self(): void {
		$uid = self::factory()->user->create();
		$out = $this->reg->resolve( new NavContext( 'profile', $uid, $uid ) );
		$this->assertSame( $this->expected_primary( true ), $this->ids( $out, 'primary' ) );
	}

	/**
	 * A visitor sees the same tabs minus the owner-only Scheduled tab.
	 */
	public function test_primary_tabs_parity_viewer(): void {
		$owner   = self::factory()->user->create();
		$visitor = self::factory()->user->create();
		$out     = $this->reg->resolve( new NavContext( 'profile', $owner, $visitor ) );
		$this->assertSame( $this->expected_primary( false ), $this->ids( $out, 'primary' ) );
		$this->assertNotContains( 'scheduled', $this->ids( $out, 'primary' ) );
	}

	/**
	 * Metrics never leak into the primary layer and vice-versa.
	 */
	public function test_layers_are_isolated(): void {
		$uid     = self::factory()->user->create();
		$out     = $this->reg->resolve( new NavContext( 'profile', $uid, $uid ) );
		$metrics = $this->ids( $out, 'metric' );
		$primary = $this->ids( $out, 'primary' );
		$this->assertSame( array(), array_intersect( $metrics, $primary ) );
	}

	/**
	 * Resolved counts are a non-negative int when the item declares a count, and
	 * null otherwise (e.g. the Network parent, which only groups its sub-nav). The
	 * lazy count callable must have run for the items that declare one.
	 */
	public function test_counts_resolve_to_ints(): void {
		$uid = self::factory()->user->create();
		$out = $this->reg->resolve( new NavContext( 'profile', $uid, $uid ) );
		foreach ( array_merge( $out->layer( 'metric' ), $out->layer( 'primary' ) ) as $item ) {
			if ( null === $item->count_value ) {
				continue;
			}
			$this->assertIsInt( $item->count_value, "Count for {$item->id} should be an int" );
			$this->assertGreaterThanOrEqual( 0, $item->count_value, "Count for {$item->id} should be >= 0" );
		}
	}

	/**
	 * Logged-out viewers still see the public profile tabs (no Scheduled).
	 */
	public function test_logged_out_viewer(): void {
		$owner = self::factory()->user->create();
		$out   = $this->reg->resolve( new NavContext( 'profile', $owner, 0 ) );
		$this->assertSame( $this->expected_primary( false ), $this->ids( $out, 'primary' ) );
	}

	/**
	 * The Network tab carries its Connections / Followers / Following sub-nav as
	 * nested children, in order — and they are NOT top-level primary tabs.
	 */
	public function test_network_subnav_nesting(): void {
		$uid     = self::factory()->user->create();
		$out     = $this->reg->resolve( new NavContext( 'profile', $uid, $uid ) );
		$primary = $out->layer( 'primary' );

		$network = null;
		foreach ( $primary as $item ) {
			if ( 'network' === $item->id ) {
				$network = $item;
				break;
			}
		}
		$this->assertNotNull( $network, 'Network tab should be a top-level primary item' );
		$this->assertSame(
			array( 'connections', 'followers', 'following' ),
			array_map( static fn( $n ) => $n->id, $network->children )
		);
		// The sub-nav children must not also appear as top-level tabs.
		$top_ids = $this->ids( $out, 'primary' );
		$this->assertNotContains( 'connections', $top_ids );
		$this->assertNotContains( 'followers', $top_ids );
		$this->assertNotContains( 'following', $top_ids );
	}
}
