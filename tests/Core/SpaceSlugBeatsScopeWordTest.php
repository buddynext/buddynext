<?php
/**
 * A space slug wins over a directory-scope word at the same URL position.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use WP_Query;

/**
 * /spaces/mine/ short-circuited to the My-Spaces view before slug resolution was
 * ever attempted, so a space actually slugged "mine" could not be opened at its
 * own URL — measured before the fix: /spaces/mine/ served the Spaces listing
 * while /spaces/open-discussion/ served its space. bn_spaces.slug is UNIQUE but
 * carries no reserved-word guard, so nothing stopped that space existing.
 *
 * The rule this locks in: a path segment in entity position is an entity first,
 * and a scope word only when no entity owns it.
 *
 * @covers \BuddyNext\Core\PageRouter
 */
class SpaceSlugBeatsScopeWordTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Drive the router's parse_query pass for /spaces/{slug}/.
	 *
	 * @param string $slug   Slug segment.
	 * @param string $action Optional second segment.
	 * @return WP_Query
	 */
	private function parse( string $slug, string $action = '' ): WP_Query {
		$query = new WP_Query();
		$query->set( 'bn_hub', 'spaces' );
		$query->set( 'bn_space_slug', $slug );
		if ( '' !== $action ) {
			$query->set( 'bn_space_action', $action );
		}

		// set_hub_vars() runs on pre_get_posts and returns early unless this IS the
		// main query, and is_main_query() compares against $wp_the_query by
		// identity — a property cannot fake it.
		$previous              = $GLOBALS['wp_the_query'];
		$GLOBALS['wp_the_query'] = $query;

		try {
			do_action_ref_array( 'pre_get_posts', array( &$query ) );
		} finally {
			$GLOBALS['wp_the_query'] = $previous;
		}

		return $query;
	}

	/**
	 * @param string $slug Space slug.
	 * @return int
	 */
	private function make_space( string $slug ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'         => 'Space ' . $slug,
				'slug'         => $slug,
				'type'         => 'open',
				'owner_id'     => 1,
				'member_count' => 1,
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function test_a_space_named_mine_resolves_to_that_space(): void {
		$space_id = $this->make_space( 'mine' );

		$query = $this->parse( 'mine' );

		$this->assertSame( $space_id, (int) $query->get( 'bn_resolved_space_id' ), 'The space must win its own URL.' );
		$this->assertSame( '', (string) $query->get( 'bn_scope' ), 'It must not be re-routed to the directory scope.' );
	}

	/**
	 * Back-compat: with no space holding the word, the legacy URL still works.
	 *
	 * @return void
	 */
	public function test_the_scope_word_still_works_when_no_space_claims_it(): void {
		$query = $this->parse( 'mine' );

		$this->assertSame( 'mine', (string) $query->get( 'bn_scope' ) );
		$this->assertSame( '', (string) $query->get( 'bn_space_slug' ) );
	}

	public function test_the_membership_filter_still_works(): void {
		$query = $this->parse( 'mine', 'managed' );

		$this->assertSame( 'mine', (string) $query->get( 'bn_scope' ) );
		$this->assertSame( 'managed', (string) $query->get( 'bn_membership' ) );
	}

	/**
	 * An ordinary space is unaffected by any of this.
	 *
	 * @return void
	 */
	public function test_an_ordinary_slug_still_resolves(): void {
		$space_id = $this->make_space( 'design-critique' );

		$query = $this->parse( 'design-critique' );

		$this->assertSame( $space_id, (int) $query->get( 'bn_resolved_space_id' ) );
	}

	/**
	 * An unknown slug is still an unknown slug, not a silent scope fallback.
	 *
	 * @return void
	 */
	public function test_an_unknown_slug_does_not_become_a_scope(): void {
		$query = $this->parse( 'no-such-space-anywhere' );

		$this->assertSame( 0, (int) $query->get( 'bn_resolved_space_id' ) );
		$this->assertSame( '', (string) $query->get( 'bn_scope' ) );
	}
}
