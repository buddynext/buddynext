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
				'url'     => 'https://t/posts/',
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
				'url'       => 'https://t/scheduled/',
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
				'url'     => 'https://t/discussions/',
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
				'url'      => 'https://t/a/',
				'priority' => 10,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'c',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'C',
				'url'      => 'https://t/c/',
				'priority' => 30,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'b',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'B',
				'url'      => 'https://t/b/',
				'priority' => 20,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'x',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'X',
				'url'      => 'https://t/x/',
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
				'url'     => 'https://t/network/',
			)
		);
		$this->reg->register(
			array(
				'id'       => 'followers',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => 'network',
				'label'    => 'Followers',
				'url'      => 'https://t/net-followers/',
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
				'url'      => 'https://t/net-connections/',
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
	 * An integration registers sub-nav the SAME way core does — through the public
	 * `buddynext_register_nav` action — both as its OWN parent + sub-nav child and
	 * by adding a sub-nav child under an EXISTING (core) parent. This is the seam
	 * every own + 3rd-party integration uses; no template access needed.
	 */
	public function test_integration_registers_subnav_via_public_action(): void {
		// Core registers a parent tab (e.g. Network).
		$this->reg->register(
			array(
				'id'      => 'network',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Network',
				'url'     => 'https://t/network/',
			)
		);

		// An integration hooks the public action exactly like our bridges do.
		add_action(
			'buddynext_register_nav',
			static function ( $reg ): void {
				// (a) its OWN primary tab carrying its OWN sub-nav child.
				$reg->register(
					array(
						'id'       => 'courses',
						'surface'  => 'profile',
						'layer'    => 'primary',
						'label'    => 'Courses',
						'url'      => 'https://t/courses/',
						'priority' => 80,
					)
				);
				$reg->register(
					array(
						'id'      => 'certs',
						'surface' => 'profile',
						'layer'   => 'primary',
						'parent'  => 'courses',
						'label'   => 'Certificates',
						'url'     => 'https://t/certs/',
					)
				);
				// (b) a sub-nav child added under the EXISTING core parent.
				$reg->register(
					array(
						'id'      => 'mutual',
						'surface' => 'profile',
						'layer'   => 'primary',
						'parent'  => 'network',
						'label'   => 'Mutual',
						'url'     => 'https://t/mutual/',
					)
				);
			}
		);

		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$top = $out->layer( 'primary' );
		$this->assertSame( array( 'network', 'courses' ), array_map( static fn( $n ) => $n->id, $top ) );
		$this->assertSame( array( 'mutual' ), array_map( static fn( $n ) => $n->id, $top[0]->children ) );
		$this->assertSame( array( 'certs' ), array_map( static fn( $n ) => $n->id, $top[1]->children ) );

		remove_all_actions( 'buddynext_register_nav' );
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
				'url'     => 'https://t/lonely/',
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
				'url'     => 'https://t/posts/',
			)
		);
		$this->reg->register(
			array(
				'id'      => 'feed',
				'surface' => 'space',
				'layer'   => 'primary',
				'label'   => 'Feed',
				'url'     => 'https://t/feed/',
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
				'url'      => 'https://t/a/',
				'priority' => 10,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'b',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'B',
				'url'      => 'https://t/b/',
				'priority' => 20,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'c',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'C',
				'url'      => 'https://t/c/',
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

	/**
	 * A capability gate hides the item from a viewer who lacks the cap, AND the
	 * item's count callable is never invoked for a gated-out item (no wasted query
	 * behind a hidden tab). An admin (manage_options) passes and the count resolves.
	 */
	public function test_capability_gate_hides_item_and_skips_count(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$sub   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ran   = 0;
		$this->reg->register(
			array(
				'id'         => 'admin_metric',
				'surface'    => 'profile',
				'layer'      => 'metric',
				'label'      => 'Admin',
				'capability' => 'manage_options',
				'count'      => static function () use ( &$ran ) {
					$ran++;
					return 7;
				},
			)
		);

		$denied = $this->reg->resolve( new NavContext( 'profile', 5, $sub ) );
		$this->assertSame( array(), $this->ids( $denied, 'metric' ) );
		$this->assertSame( 0, $ran, 'count callable must not run for a gated-out item' );

		$granted = $this->reg->resolve( new NavContext( 'profile', 5, $admin ) );
		$this->assertSame( array( 'admin_metric' ), $this->ids( $granted, 'metric' ) );
		$this->assertSame( 1, $ran );
	}

	/**
	 * The hide_empty flag WITHOUT a count is a no-op (not a silent always-hide) —
	 * there is nothing to be "empty", so the item stays visible.
	 */
	public function test_hide_empty_ignored_when_no_count(): void {
		$this->reg->register(
			array(
				'id'         => 'rail',
				'surface'    => 'profile',
				'layer'      => 'metric',
				'label'      => 'Rail',
				'hide_empty' => true,
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array( 'rail' ), $this->ids( $out, 'metric' ) );
	}

	/**
	 * A count callable returning a negative value is clamped to 0 (never a "-1"
	 * badge), so hide_empty treats it as empty too.
	 */
	public function test_negative_count_clamped_to_zero(): void {
		$this->reg->register(
			array(
				'id'      => 'neg',
				'surface' => 'profile',
				'layer'   => 'metric',
				'label'   => 'Neg',
				'count'   => static fn() => -5,
			)
		);
		$out  = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$item = $out->layer( 'metric' )[0];
		$this->assertSame( 0, $item->count_value );
	}

	/**
	 * The `buddynext_register_nav` action fires exactly once, even across many
	 * resolve() calls and multiple surfaces (correctness + no double-registration).
	 */
	public function test_providers_fired_once_across_surfaces(): void {
		$fired = 0;
		add_action(
			'buddynext_register_nav',
			static function () use ( &$fired ): void {
				$fired++;
			}
		);
		$this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->reg->resolve( new NavContext( 'space', 3, 5, 'member' ) );
		$this->reg->resolve( new NavContext( 'profile', 9, 5 ) );
		$this->assertSame( 1, $fired );
		remove_all_actions( 'buddynext_register_nav' );
	}

	/**
	 * A url callable resolves to its exact string in url_value; a callable that
	 * returns '' resolves to null (and the item still survives via its tab).
	 */
	public function test_lazy_url_callable_resolved(): void {
		$this->reg->register(
			array(
				'id'      => 'profilelink',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Link',
				'url'     => static fn( NavContext $c ) => 'https://example.test/u/' . $c->subject_id . '/',
			)
		);
		$this->reg->register(
			array(
				'id'      => 'emptyurl',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'EmptyUrl',
				'url'     => static fn() => '',
			)
		);
		$out  = $this->reg->resolve( new NavContext( 'profile', 7, 7 ) );
		$byid = array();
		foreach ( $out->layer( 'primary' ) as $n ) {
			$byid[ $n->id ] = $n;
		}
		$this->assertSame( 'https://example.test/u/7/', $byid['profilelink']->url_value );
		$this->assertNull( $byid['emptyurl']->url_value );
	}

	/**
	 * A `before` anchor places the item immediately before its target.
	 */
	public function test_before_anchor_orders_before_target(): void {
		$this->reg->register(
			array(
				'id'       => 'a',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'A',
				'url'      => 'https://t/a/',
				'priority' => 10,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'b',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'B',
				'url'      => 'https://t/b/',
				'priority' => 20,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'c',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'C',
				'url'      => 'https://t/c/',
				'priority' => 30,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'z',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'Z',
				'url'      => 'https://t/z/',
				'priority' => 99,
				'before'   => 'b',
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array( 'a', 'z', 'b', 'c' ), $this->ids( $out, 'primary' ) );
	}

	/**
	 * When an item sets BOTH `after` and `before`, `after` wins (the disambiguation
	 * in NavItem::from_array) — so the result is the after-placement, never the
	 * before one. Here w(after:a, before:c) lands after a, not before c.
	 */
	public function test_after_wins_when_both_anchors_set(): void {
		$this->reg->register(
			array(
				'id'       => 'a',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'A',
				'url'      => 'https://t/a/',
				'priority' => 10,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'b',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'B',
				'url'      => 'https://t/b/',
				'priority' => 20,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'c',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'C',
				'url'      => 'https://t/c/',
				'priority' => 30,
			)
		);
		$this->reg->register(
			array(
				'id'       => 'w',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => 'W',
				'url'      => 'https://t/w/',
				'priority' => 99,
				'after'    => 'a',
				'before'   => 'c',
			)
		);
		$out = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$this->assertSame( array( 'a', 'w', 'b', 'c' ), $this->ids( $out, 'primary' ) );
	}

	/**
	 * A duplicate (layer, id) registration keeps the FIRST and warns (no silent
	 * clobber of a core tab by a careless integration).
	 */
	public function test_duplicate_id_keeps_first_registration(): void {
		$this->setExpectedIncorrectUsage( 'buddynext_register_nav' );
		$this->reg->register(
			array(
				'id'      => 'dup',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'First',
				'url'     => 'https://t/first/',
			)
		);
		$this->reg->register(
			array(
				'id'      => 'dup',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Second',
				'url'     => 'https://t/second/',
			)
		);
		$out     = $this->reg->resolve( new NavContext( 'profile', 5, 5 ) );
		$primary = $out->layer( 'primary' );
		$this->assertSame( array( 'dup' ), array_map( static fn( $n ) => $n->id, $primary ) );
		$this->assertSame( 'First', $primary[0]->label );
	}

	/**
	 * NavContext::is_self() and role_at_least() — the gating primitives.
	 */
	public function test_navcontext_is_self_and_role_at_least(): void {
		$this->assertTrue( ( new NavContext( 'profile', 5, 5 ) )->is_self() );
		$this->assertFalse( ( new NavContext( 'profile', 5, 9 ) )->is_self() );
		$this->assertFalse( ( new NavContext( 'profile', 5, 0 ) )->is_self(), 'logged-out viewer is never self' );

		$owner  = new NavContext( 'space', 3, 7, 'owner' );
		$member = new NavContext( 'space', 3, 8, 'member' );
		$none   = new NavContext( 'space', 3, 9, '' );
		$this->assertTrue( $owner->role_at_least( 'moderator' ) );
		$this->assertTrue( $owner->role_at_least( 'owner' ) );
		$this->assertTrue( $member->role_at_least( 'member' ) );
		$this->assertFalse( $member->role_at_least( 'moderator' ) );
		$this->assertFalse( $none->role_at_least( 'member' ), 'empty role satisfies nothing' );
	}

	/**
	 * The `full_load` flag round-trips through the contract (default false), so the
	 * shared nav renderer can emit `data-bn-full-load` for drill-in tabs and the
	 * client-nav transport reads it instead of hardcoding a route regex.
	 */
	public function test_full_load_flag_round_trips(): void {
		$this->reg->register(
			array(
				'id'        => 'settings',
				'surface'   => 'space',
				'layer'     => 'primary',
				'label'     => 'Settings',
				'url'       => 'https://example.test/spaces/x/settings/',
				'full_load' => true,
			)
		);
		$this->reg->register(
			array(
				'id'      => 'feed',
				'surface' => 'space',
				'layer'   => 'primary',
				'label'   => 'Feed',
				'url'     => 'https://example.test/spaces/x/',
			)
		);

		$out   = $this->reg->resolve( new NavContext( 'space', 9, 5, 'member' ) );
		$items = array();
		foreach ( $out->layer( 'primary' ) as $n ) {
			$items[ $n->id ] = $n;
		}

		$this->assertTrue( $items['settings']->full_load, 'full_load => true is preserved' );
		$this->assertFalse( $items['feed']->full_load, 'full_load defaults to false' );
	}

	/**
	 * Resolving the same context twice runs the count callable once (memoized),
	 * but a different context resolves afresh. This is what lets the shared space
	 * header and the space body both ask for the nav without a double count query.
	 */
	public function test_resolve_memoizes_per_context(): void {
		$count_calls = 0;
		$this->reg->register(
			array(
				'id'      => 'feed',
				'surface' => 'space',
				'layer'   => 'primary',
				'label'   => 'Feed',
				'render'  => static function (): void {},
				'count'   => static function () use ( &$count_calls ): int {
					++$count_calls;
					return 3;
				},
			)
		);

		$ctx = new NavContext( 'space', 9, 5, 'member' );
		$a   = $this->reg->resolve( $ctx );
		$b   = $this->reg->resolve( new NavContext( 'space', 9, 5, 'member' ) );

		$this->assertSame( $a, $b, 'identical context returns the cached ResolvedNav' );
		$this->assertSame( 1, $count_calls, 'count callable runs once across identical resolves' );

		// A different viewer is a different context — resolves again.
		$this->reg->resolve( new NavContext( 'space', 9, 8, 'member' ) );
		$this->assertSame( 2, $count_calls, 'a different context re-resolves' );
	}

	/**
	 * Resetting the registry drops the resolved cache, so a re-registered count
	 * callable runs again (the cache never leaks across a registry reset).
	 */
	public function test_reset_clears_resolved_cache(): void {
		$count_calls = 0;
		$register    = function () use ( &$count_calls ): void {
			$this->reg->register(
				array(
					'id'      => 'feed',
					'surface' => 'space',
					'layer'   => 'primary',
					'label'   => 'Feed',
					'render'  => static function (): void {},
					'count'   => static function () use ( &$count_calls ): int {
						++$count_calls;
						return 1;
					},
				)
			);
		};

		$register();
		$this->reg->resolve( new NavContext( 'space', 9, 5, 'member' ) );
		$this->assertSame( 1, $count_calls );

		$this->reg->reset();
		$register();
		$this->reg->resolve( new NavContext( 'space', 9, 5, 'member' ) );
		$this->assertSame( 2, $count_calls, 'reset() invalidated the memo' );
	}
}
