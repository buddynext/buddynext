<?php
/**
 * Search reach must match read access — and the seam that widens it must be safe.
 *
 * Content in a plan-gated space is indexed `private`, which closed a real leak:
 * it used to be indexed `public`, and the guest query is literally
 * `si.visibility = 'public'`, so paid content was searchable by the whole
 * internet. The logged-in query then widens by space MEMBERSHIP.
 *
 * Membership is the wrong question for an OPEN space carrying a
 * `required_ability`: there, holding the plan IS the access mechanism and joining
 * is optional. So a member who had paid could open the space, read every post,
 * and find none of it in search. Reproduced before the fix on a `tier:pro`-gated
 * open space: `can_view_content()` true, search 0 results (card 10221739624).
 *
 * ## What these tests are really protecting
 *
 * The fix adds a filter that WIDENS the search index's reach, which is a
 * dangerous shape — the narrowing it replaced was accepted precisely because a
 * search index must fail closed. So the guarantee cannot be "Pro is careful". It
 * is enforced in Free:
 *
 *  - every id membership granted survives whatever a listener returns, so this
 *    cannot become a narrowing seam by the back door;
 *  - every id a listener ADDS is checked against `can_view_content()` before it is
 *    believed.
 *
 * The hostile-listener test below is the one that matters. Pro is not loaded in
 * this suite, so these exercise Free's guard on its own — which is exactly the
 * thing that has to hold no matter what any plugin on the site does.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * The viewer-space list behind the search visibility clause.
 *
 * @covers \BuddyNext\Search\SearchService::search
 */
class ViewerSpaceReachTest extends WP_UnitTestCase {

	/**
	 * A member of the space.
	 *
	 * @var int
	 */
	private int $joiner;

	/**
	 * Someone with no relationship to the space at all.
	 *
	 * @var int
	 */
	private int $outsider;

	/**
	 * The private space holding the canary post.
	 *
	 * @var int
	 */
	private int $space_id;

	/**
	 * A word that appears nowhere else in the index.
	 *
	 * @var string
	 */
	private const CANARY = 'Zarquonium';

	/**
	 * A word that appears only in the gated space's post.
	 *
	 * @var string
	 */
	private const GATED_CANARY = 'Bracknellium';

	/**
	 * An OPEN space carrying a required_ability.
	 *
	 * @var int
	 */
	private int $gated_id;

	/**
	 * A private space with one indexed post, one member, one outsider.
	 *
	 * TWO spaces, because the two halves need different types.
	 *
	 * The private one is unreachable by anyone but its members, and that is
	 * enforced BEFORE any listener is consulted: `content_requires_membership()`
	 * is true for private and secret, so `can_view_content()` short-circuits to
	 * false and the filter never runs. It is the subject of the hostile-listener
	 * test.
	 *
	 * The open one carries a `required_ability`. The gate makes the space's
	 * content index `private` (the ceiling), while the TYPE still allows the read,
	 * so `can_view_content()` reaches its filter - which is where Pro answers
	 * whether the viewer holds the plan. That is the only shape the widening can
	 * actually help, and it is the shape the card describes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::run();

		$this->joiner   = self::factory()->user->create();
		$this->outsider = self::factory()->user->create();

		$space_id = buddynext_service( 'spaces' )->create(
			$this->joiner,
			array(
				'name' => 'Reach QA',
				'slug' => 'reach-qa-' . wp_generate_password( 6, false ),
				'type' => 'private',
			)
		);
		$this->assertNotWPError( $space_id, 'Fixture: the space must exist.' );
		$this->space_id = (int) $space_id;

		buddynext_service( 'search' )->index(
			'post',
			987654,
			'',
			self::CANARY . ' briefing for people inside the space.',
			$this->joiner,
			'public',
			$this->space_id
		);

		$gated_id = buddynext_service( 'spaces' )->create(
			$this->joiner,
			array(
				'name' => 'Gated Reach QA',
				'slug' => 'gated-reach-qa-' . wp_generate_password( 6, false ),
				'type' => 'open',
			)
		);
		$this->assertNotWPError( $gated_id, 'Fixture: the gated space must exist.' );
		$this->gated_id = (int) $gated_id;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixture.
		$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'required_ability' => 'tier:pro' ), array( 'id' => $this->gated_id ) );
		\BuddyNext\Search\SearchService::flush_space_ceiling( $this->gated_id );

		buddynext_service( 'search' )->index(
			'post',
			987655,
			'',
			self::GATED_CANARY . ' briefing for plan holders.',
			$this->joiner,
			'public',
			$this->gated_id
		);
	}

	/**
	 * Run a search as a viewer and return how many rows came back.
	 *
	 * @param int $viewer_id Viewer.
	 * @return int
	 */
	private function hits( int $viewer_id, string $term = self::CANARY ): int {
		// The viewer-space list is memoised for the request, which is what stops a
		// single search from running the entitlement checks four times over. These
		// tests register their listeners between calls, so each call has to start
		// from a clean memo or it would be answered by a list resolved before the
		// listener existed - and the widening test would fail for a reason that
		// has nothing to do with the code under test. (It did, first time.)
		\BuddyNext\Search\SearchService::flush_viewer_space_memo();

		$result = buddynext_service( 'search' )->search( $term, 'post', 10, 1, $viewer_id );

		return (int) ( $result['total'] ?? 0 );
	}

	/**
	 * The premise: the canary is indexed non-public, and only members find it.
	 *
	 * If this ever fails the rest prove nothing — either the ceiling stopped
	 * downgrading the visibility, or the canary is not in the index at all.
	 *
	 * @return void
	 */
	public function test_the_premise_holds(): void {
		global $wpdb;

		$visibility = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT visibility FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				987654
			)
		);

		$this->assertSame( 'private', $visibility, 'The canary should have been indexed private - the space ceiling is not being applied.' );
		$this->assertSame( 0, $this->hits( 0 ), 'A logged-out visitor found content in a private space.' );
		$this->assertSame( 1, $this->hits( $this->joiner ), 'A member of the space cannot find its content.' );
		$this->assertSame( 0, $this->hits( $this->outsider ), 'An outsider found content in a space they cannot read.' );
	}

	/**
	 * A listener may add reach for someone who can genuinely read the space.
	 *
	 * Stands in for Pro adding a gated space the viewer holds the plan for. The
	 * outsider cannot read this private space, so the listener's addition is only
	 * honoured once `can_view_content()` agrees — which it is made to, here, the
	 * same way Pro makes it agree for an entitlement.
	 *
	 * @return void
	 */
	public function test_a_listener_can_widen_reach_for_a_reader(): void {
		$this->assertSame(
			0,
			$this->hits( $this->outsider, self::GATED_CANARY ),
			'Fixture: the outsider is not a member, so membership alone must give no reach.'
		);
		$this->assertTrue(
			\BuddyNext\Spaces\SpaceVisibility::can_view_content( buddynext_service( 'spaces' )->get( $this->gated_id ), $this->outsider ),
			'Fixture: the whole point is a space this viewer MAY read without having joined.'
		);

		$gated_id = $this->gated_id;
		$outsider = $this->outsider;

		// Stands in for Pro proposing a gated space the viewer holds the plan for.
		add_filter(
			'buddynext_search_viewer_spaces',
			static fn( $ids, $viewer ) => (int) $viewer === $outsider ? array_merge( (array) $ids, array( $gated_id ) ) : $ids,
			10,
			2
		);

		$this->assertSame(
			1,
			$this->hits( $this->outsider, self::GATED_CANARY ),
			'A viewer who may read the space still cannot find its content, so the widening seam does nothing.'
		);
	}

	/**
	 * The widening cannot reach a private or secret space, whatever anyone claims.
	 *
	 * A stronger guarantee than "additions are verified", and worth its own test
	 * because it does not depend on the verification at all:
	 * `content_requires_membership()` is true for these types, so
	 * `can_view_content()` returns false BEFORE its filter runs. Neither this seam
	 * nor Pro nor any other plugin can grant a non-member reach into them.
	 *
	 * @return void
	 */
	public function test_private_and_secret_spaces_are_beyond_the_seam(): void {
		$space_id = $this->space_id;
		$outsider = $this->outsider;

		// Both seams pushed as hard as they can be: propose the space AND try to
		// grant the read.
		add_filter(
			'buddynext_search_viewer_spaces',
			static fn( $ids, $viewer ) => array_merge( (array) $ids, array( $space_id ) ),
			99,
			2
		);
		add_filter( 'buddynext_can_view_space_content', '__return_true', 99 );

		$this->assertSame(
			0,
			$this->hits( $this->outsider ),
			'A non-member reached into a PRIVATE space. The type-based denial in can_view_content() is no longer short-circuiting ahead of its filter.'
		);
	}

	/**
	 * A listener CANNOT widen reach into a space the viewer may not read.
	 *
	 * The one that matters. A seam into the search index that trusted its
	 * listeners would be a bigger hole than the leak the narrowing was added to
	 * close, so the check lives in Free and does not depend on anyone's good
	 * manners.
	 *
	 * @return void
	 */
	public function test_a_listener_cannot_widen_reach_for_someone_who_cannot_read(): void {
		global $wpdb;

		$every_space = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bn_spaces" ) );
		$this->assertContains( $this->space_id, $every_space, 'Fixture: the listener must actually be offering the space.' );

		// Deliberately hostile: hand back every space on the site, for everyone.
		add_filter( 'buddynext_search_viewer_spaces', static fn() => $every_space, 99 );

		$this->assertSame(
			0,
			$this->hits( $this->outsider ),
			'A plugin granted itself reach into a private space by answering a filter. can_view_content() is not being consulted on additions.'
		);
		$this->assertSame(
			0,
			$this->hits( 0 ),
			'The widening seam ran for a logged-out visitor.'
		);
	}

	/**
	 * A listener cannot REMOVE reach the viewer earned by joining.
	 *
	 * The seam widens; it must not be usable to narrow. Otherwise any plugin could
	 * quietly blank a member's search results, which is the same class of bug in
	 * the opposite direction and far harder to notice.
	 *
	 * @return void
	 */
	public function test_a_listener_cannot_narrow_membership(): void {
		add_filter( 'buddynext_search_viewer_spaces', static fn() => array(), 99 );

		$this->assertSame(
			1,
			$this->hits( $this->joiner ),
			'A listener stripped a member of reach into their own space.'
		);
	}

	/**
	 * Additions are bounded, so a huge gated estate cannot cost per keystroke.
	 *
	 * Each addition costs a space fetch and an ability check. The limit is the
	 * thing that keeps this from being a denial-of-service on a community with
	 * thousands of gated spaces.
	 *
	 * @return void
	 */
	public function test_the_number_of_additions_is_bounded(): void {
		$seen = 0;

		add_filter( 'buddynext_search_entitled_space_limit', static fn() => 3 );
		add_filter( 'buddynext_search_viewer_spaces', static fn( $ids ) => array_merge( (array) $ids, range( 900000, 900099 ) ), 99 );
		add_filter(
			'buddynext_can_view_space_content',
			static function ( $can ) use ( &$seen ) {
				++$seen;
				return $can;
			},
			10,
			3
		);

		$this->hits( $this->outsider );

		$this->assertLessThanOrEqual(
			3,
			$seen,
			'More additions were verified than the limit allows, so a large gated estate would be checked on every search.'
		);
	}
}
