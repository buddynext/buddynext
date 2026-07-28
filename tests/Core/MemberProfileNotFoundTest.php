<?php
/**
 * /members/{slug}/ for a slug that resolves to nobody is a real 404.
 *
 * It used to answer "200 OK" with an empty shell — theme chrome, the nav, and no
 * content at all. A blank 200 is the worst of both outcomes: the visitor gets no
 * explanation, and search engines index the empty page as a real one.
 *
 * The same defect produced a second symptom filed separately: /members/staff/
 * (a member TYPE slug) also rendered blank. A 'bottom'-priority rewrite rule was
 * supposed to route type slugs to a filtered directory, with a comment claiming
 * the user-slug rules took precedence and that bn_member_type was only stored
 * "when no user was resolved". Rewrite rules do not work that way — they match on
 * the PATTERN, not on whether the capture resolves to anything. The 'top' rule
 * has the identical shape, always won, and the type rule never appeared in the
 * generated rewrite_rules option at all. So /members/staff/ was handled as a
 * profile URL for a member named "staff", and rendered the same blank page.
 *
 * The pretty form cannot be made to work: /members/{type}/ is indistinguishable
 * from /members/{username}/ and usernames must win. Member-type filtering is the
 * ?type= query argument — which the directory already reads and
 * PageRouter::member_type_url() already emits.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;

/**
 * Routing contract for member profile URLs.
 *
 * @covers \BuddyNext\Core\PageRouter
 */
class MemberProfileNotFoundTest extends \WP_UnitTestCase {

	/**
	 * A real member.
	 *
	 * @var int
	 */
	private $member = 0;

	/**
	 * Boot the schema, a member, and the rewrite rules.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->member = self::factory()->user->create(
			array(
				'role'          => 'subscriber',
				'user_login'    => 'qa_real_member',
				'user_nicename' => 'qa-real-member',
			)
		);

		// Pretty permalinks, so the member rules are generated at all.
		//
		// register_rewrites() is re-run by hand because it is hooked to `init`,
		// which fires once per process: without this the plugin's rules are
		// present for the first test in the class and absent for every one after
		// it, which reads as a routing failure rather than a test-harness one.
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		( new PageRouter() )->register_rewrites();
		$wp_rewrite->flush_rules( false );
	}

	/**
	 * Restore the default permalink structure.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules( false );

		parent::tear_down();
	}

	/**
	 * There is no /members/{type}/ rule, and there must never be one again: it
	 * is indistinguishable from /members/{username}/, so adding it back cannot
	 * change behaviour — it can only shadow a real member whose name happens to
	 * match a type slug.
	 *
	 * @return void
	 */
	public function test_no_member_type_rewrite_rule_is_registered(): void {
		$rules = (array) get_option( 'rewrite_rules' );

		foreach ( $rules as $pattern => $target ) {
			$this->assertStringNotContainsString(
				'bn_member_type=',
				(string) $target,
				'A /members/{type}/ rewrite rule is back: ' . $pattern
			);
		}
	}

	/**
	 * The single-segment members rule routes to a USER slug. This is the rule
	 * whose existence makes a type-slug rule unreachable, so it is asserted
	 * rather than assumed.
	 *
	 * @return void
	 */
	public function test_the_single_segment_members_rule_routes_to_a_user_slug(): void {
		$rules = (array) get_option( 'rewrite_rules' );

		$single = '';
		foreach ( $rules as $pattern => $target ) {
			if ( preg_match( '#^\^members/\(\[\^/\]\+\)/\?\$#', (string) $pattern ) ) {
				$single = (string) $target;
				break;
			}
		}

		$this->assertNotSame( '', $single, 'The /members/{slug}/ rule is missing entirely.' );
		$this->assertStringContainsString( 'bn_user_slug=', $single );
	}

	/**
	 * A real member still resolves — the gate must not 404 everybody.
	 *
	 * @return void
	 */
	public function test_a_real_member_resolves(): void {
		$this->go_to( home_url( '/members/qa-real-member/' ) );

		$this->assertSame( 'people', get_query_var( 'bn_hub' ) );
		$this->assertSame( 'qa-real-member', get_query_var( 'bn_user_slug' ) );
		$this->assertSame(
			$this->member,
			(int) get_query_var( 'bn_resolved_user_id' ),
			'A real member stopped resolving.'
		);
	}

	/**
	 * The reported bug: an unknown slug resolves to nobody. The router turns
	 * this into a 404 at template_redirect, which cannot run under go_to(), so
	 * what is asserted here is the CONDITION the gate fires on.
	 *
	 * @return void
	 */
	public function test_an_unknown_slug_resolves_to_nobody(): void {
		$this->go_to( home_url( '/members/definitely-not-a-real-member/' ) );

		$this->assertSame( 'people', get_query_var( 'bn_hub' ) );
		$this->assertSame( 'definitely-not-a-real-member', get_query_var( 'bn_user_slug' ) );
		$this->assertSame(
			0,
			(int) get_query_var( 'bn_resolved_user_id' ),
			'An unknown member slug resolved to a user.'
		);
	}

	/**
	 * A member TYPE slug is not special — it takes the same unknown-user path,
	 * which is why it 404s rather than rendering a filtered directory.
	 *
	 * @return void
	 */
	public function test_a_member_type_slug_takes_the_unknown_user_path(): void {
		$this->go_to( home_url( '/members/staff/' ) );

		$this->assertSame( 'staff', get_query_var( 'bn_user_slug' ) );
		$this->assertSame( 0, (int) get_query_var( 'bn_resolved_user_id' ) );
		$this->assertSame( '', (string) get_query_var( 'bn_member_type', '' ) );
	}

	/**
	 * The bare directory sets no slug, so the gate cannot fire on it. Without
	 * this, a gate keyed only on "user_id is empty" would 404 the whole
	 * directory.
	 *
	 * @return void
	 */
	public function test_the_bare_directory_sets_no_user_slug(): void {
		$this->go_to( home_url( '/members/' ) );

		$this->assertSame( 'people', get_query_var( 'bn_hub' ) );
		$this->assertSame( '', (string) get_query_var( 'bn_user_slug', '' ) );
	}

	/**
	 * Type filtering still has a working URL — the one the directory reads and
	 * the one member_type_url() emits. If this ever stops being ?type=, the
	 * removal of the pretty form becomes a real regression.
	 *
	 * @return void
	 */
	public function test_member_type_filtering_uses_the_query_argument(): void {
		$url = PageRouter::member_type_url( 'staff' );

		$this->assertStringContainsString( 'type=staff', $url );
		$this->assertStringNotContainsString(
			'/members/staff',
			$url,
			'member_type_url() emits the pretty form again, which routes to a 404.'
		);
	}

	/**
	 * A custom bn_profile_slug still resolves. The 404 gate depends entirely on
	 * resolve_user(), so a member with a custom URL must not become collateral.
	 *
	 * @return void
	 */
	public function test_a_custom_profile_slug_still_resolves(): void {
		update_user_meta( $this->member, 'bn_profile_slug', 'qa-custom-handle' );

		$this->go_to( home_url( '/members/qa-custom-handle/' ) );

		$this->assertSame(
			$this->member,
			(int) get_query_var( 'bn_resolved_user_id' ),
			'A member with a custom profile slug would now 404.'
		);
	}

	/**
	 * The reserved user-{id} form still resolves, for the same reason.
	 *
	 * @return void
	 */
	public function test_the_user_id_form_still_resolves(): void {
		$this->go_to( home_url( '/members/user-' . $this->member . '/' ) );

		$this->assertSame( $this->member, (int) get_query_var( 'bn_resolved_user_id' ) );
	}

	/**
	 * Profile sub-routes carry the same slug var, so they inherit the gate: a
	 * real member's tab resolves, an unknown member's does not.
	 *
	 * @return void
	 */
	public function test_sub_routes_resolve_for_a_real_member_only(): void {
		$this->go_to( home_url( '/members/qa-real-member/media/' ) );
		$this->assertSame( $this->member, (int) get_query_var( 'bn_resolved_user_id' ) );
		$this->assertSame( 'media', get_query_var( 'bn_profile_action' ) );

		$this->go_to( home_url( '/members/nobody-here/media/' ) );
		$this->assertSame( 0, (int) get_query_var( 'bn_resolved_user_id' ) );
	}
}
