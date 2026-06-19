<?php
/**
 * Tests for the unified Nav registry (Wave 0 contract + resolution).
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;

/**
 * Validation, gating, dedupe, ordering and sub-nav nesting.
 *
 * @covers \BuddyNext\Nav\NavRegistry
 * @covers \BuddyNext\Nav\NavItem
 * @covers \BuddyNext\Nav\ResolvedNav
 */
class NavRegistryTest extends \WP_UnitTestCase {

	/**
	 * Shared registry under test.
	 *
	 * @var NavRegistry
	 */
	private NavRegistry $reg;

	/**
	 * Reset the registry + drop real providers before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reg = NavRegistry::instance();
		$this->reg->reset();
		remove_all_actions( 'buddynext_register_nav' );
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
	 * A valid primary tab resolves into the primary layer.
	 */
	public function test_valid_primary_tab_resolves(): void {
		$this->reg->register(
			array(
				'id'      => 'posts',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Posts',
				'tab'     => 'posts',
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array( 'posts' ), $this->ids( $out, 'primary' ) );
	}

	/**
	 * A primary item with neither tab nor url is invalid and dropped.
	 */
	public function test_primary_without_tab_or_url_is_dropped(): void {
		$this->reg->register(
			array(
				'id'      => 'broken',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Broken',
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array(), $this->ids( $out, 'primary' ) );
	}

	/**
	 * The condition callable gates visibility (own-profile-only here).
	 */
	public function test_condition_gate_hides_item(): void {
		$this->reg->register(
			array(
				'id'        => 'scheduled',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => 'Scheduled',
				'tab'       => 'scheduled',
				'condition' => static fn( NavContext $c ) => $c->is_self(),
			)
		);
		$other = $this->reg->resolve( new NavContext( 'profile', 5, 9 ) );
		$this->assertSame( array(), $this->ids( $other, 'primary' ) );
		$self = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array( 'scheduled' ), $this->ids( $self, 'primary' ) );
	}

	/**
	 * A metric sharing an id with a primary tab is dropped (no double-nav).
	 */
	public function test_metric_duplicating_a_primary_tab_is_dropped(): void {
		$this->reg->register(
			array(
				'id'      => 'discussions',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Discussions',
				'tab'     => 'discussions',
			)
		);
		$this->reg->register(
			array(
				'id'      => 'discussions',
				'surface' => 'profile',
				'layer'   => 'metric',
				'label'   => 'Discussions',
				'count'   => 10,
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array( 'discussions' ), $this->ids( $out, 'primary' ) );
		$this->assertSame( array(), $this->ids( $out, 'metric' ) );
	}

	/**
	 * Priority orders by default; an `after` anchor overrides priority.
	 */
	public function test_ordering_priority_then_anchors(): void {
		$this->reg->register(
			array(
				'id'       => 'a',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'A',
				'tab'      => 'a',
				'priority' => 10,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'c',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'C',
				'tab'      => 'c',
				'priority' => 30,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'b',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'B',
				'tab'      => 'b',
				'priority' => 20,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'x',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'X',
				'tab'      => 'x',
				'priority' => 99,
				'after'    => 'a',
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array( 'a', 'x', 'b', 'c' ), $this->ids( $out, 'primary' ) );
	}

	/**
	 * Children nest under their parent and order among themselves.
	 */
	public function test_subnav_nesting_under_parent(): void {
		$this->reg->register(
			array(
				'id'      => 'network',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Network',
				'tab'     => 'network',
			)
		);
		$this->reg->register(
			array(
				'id'       => 'followers',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => 'network',
				'label'    => 'Followers',
				'tab'      => 'net-followers',
				'priority' => 20,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'connections',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => 'network',
				'label'    => 'Connections',
				'tab'      => 'net-connections',
				'priority' => 10,
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$top = $out->layer( 'primary' );
		$this->assertSame( array( 'network' ), array_map( static fn( $n ) => $n->id, $top ) );
		$kids = array_map( static fn( $n ) => $n->id, $top[0]->children );
		$this->assertSame( array( 'connections', 'followers' ), $kids );
	}

	/**
	 * A child whose parent does not exist is dropped.
	 */
	public function test_orphan_child_is_dropped(): void {
		$this->reg->register(
			array(
				'id'      => 'lonely',
				'surface' => 'profile',
				'layer'   => 'primary',
				'parent'  => 'nope',
				'label'   => 'Lonely',
				'tab'     => 'lonely',
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array(), $this->ids( $out, 'primary' ) );
	}

	/**
	 * The hide_empty flag omits an item whose resolved count is zero.
	 */
	public function test_hide_empty_drops_zero_count(): void {
		$this->reg->register(
			array(
				'id'         => 'badges',
				'surface'    => 'profile',
				'layer'      => 'metric',
				'label'      => 'Badges',
				'count'      => 0,
				'hide_empty' => true,
			)
		);
		$this->reg->register(
			array(
				'id'         => 'points',
				'surface'    => 'profile',
				'layer'      => 'metric',
				'label'      => 'Points',
				'count'      => 5,
				'hide_empty' => true,
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array( 'points' ), $this->ids( $out, 'metric' ) );
	}

	/**
	 * A count callable is resolved lazily and exactly once.
	 */
	public function test_lazy_count_callable_resolved(): void {
		$ran = 0;
		$this->reg->register(
			array(
				'id'      => 'pts',
				'surface' => 'profile',
				'layer'   => 'metric',
				'label'   => 'Points',
				'count'   => static function ( NavContext $c ) use ( &$ran ) {
					$ran++;
					return $c->subject_id * 2;
				},
			)
		);
		$out  = $this->reg->resolve( new NavContext( 'profile', 7, 7 ) );
		$item = $out->layer( 'metric' )[0];
		$this->assertSame( 14, $item->count_value );
		$this->assertSame( 1, $ran );
	}

	/**
	 * A context only sees items registered for its own surface.
	 */
	public function test_surface_isolation(): void {
		$this->reg->register(
			array(
				'id'      => 'posts',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Posts',
				'tab'     => 'posts',
			)
		);
		$this->reg->register(
			array(
				'id'      => 'feed',
				'surface' => 'space',
				'layer'   => 'primary',
				'label'   => 'Feed',
				'tab'     => 'feed',
			)
		);
		$space = $this->reg->resolve( new NavContext( 'space', 3, 5, 'member' ) );
		$this->assertSame( array( 'feed' ), $this->ids( $space, 'primary' ) );
	}

	/**
	 * The public filter can remove and reposition items.
	 */
	public function test_public_filter_can_remove_and_move(): void {
		$this->reg->register(
			array(
				'id'       => 'a',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'A',
				'tab'      => 'a',
				'priority' => 10,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'b',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'B',
				'tab'      => 'b',
				'priority' => 20,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'c',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'C',
				'tab'      => 'c',
				'priority' => 30,
			)
		);
		add_filter(
			'buddynext_nav_items',
			static function ( array $items ) {
				$items = buddynext_nav_remove( $items, 'b' );
				return buddynext_nav_move( $items, 'c', array( 'before' => 'a' ) );
			}
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array( 'c', 'a' ), $this->ids( $out, 'primary' ) );
		remove_all_filters( 'buddynext_nav_items' );
	}
}
