<?php
/**
 * Gating a space has to gate more than its feed.
 *
 * `SpaceVisibility::can_view_content()` answered from the space's TYPE alone, and
 * eleven surfaces resolve through it — pinned posts, sub-spaces, the roster,
 * single posts fetched by id, media albums, the sidebar. `FeedService::space_feed()`
 * asked Pro through `buddynext_can_view_space_content` and was therefore gated;
 * this method never asked, so on an OPEN space carrying `required_ability` every
 * other surface returned true, to members without the plan AND to logged-out
 * visitors.
 *
 * Reproduced on the site before the fix: for a free-plan member the feed gate
 * answered false while can_view_content() answered true, on the same space.
 *
 * Free cannot evaluate an ability — the rule lives in Pro. So the contract Free
 * owns, and the one these tests pin, is narrower and more durable: **the question
 * is asked, and the answer is honoured, and it can only ever narrow.**
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Spaces\SpaceVisibility;

/**
 * The content gate seam on SpaceVisibility.
 *
 * @covers \BuddyNext\Spaces\SpaceVisibility::can_view_content
 */
class GatedSpaceContentGateTest extends \WP_UnitTestCase {

	/**
	 * Filters added by a test, removed in tear_down.
	 *
	 * @var array<int, callable>
	 */
	private array $added = array();

	/**
	 * Remove anything a test hooked.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->added as $callback ) {
			remove_filter( 'buddynext_can_view_space_content', $callback, 10 );
		}

		$this->added = array();

		parent::tear_down();
	}

	/**
	 * Hook the content gate for the duration of one test.
	 *
	 * @param callable $callback Filter callback.
	 * @return void
	 */
	private function gate( callable $callback ): void {
		add_filter( 'buddynext_can_view_space_content', $callback, 10, 3 );

		$this->added[] = $callback;
	}

	/**
	 * A space row of a given type.
	 *
	 * Built by hand rather than through SpaceService because the method under test
	 * takes a hydrated row, and a fixture that writes to the database would drag
	 * membership, ownership and caching into a test about one decision.
	 *
	 * @param string $type Space type.
	 * @return array<string,mixed>
	 */
	private function space( string $type = 'open' ): array {
		return array(
			'id'       => 4242,
			'type'     => $type,
			'owner_id' => 0,
		);
	}

	/**
	 * The question is asked at all. This is the whole bug.
	 *
	 * @return void
	 */
	public function test_the_content_gate_is_consulted(): void {
		$seen = array();

		$this->gate(
			static function ( bool $can, int $space_id, int $viewer_id ) use ( &$seen ): bool {
				$seen[] = array( $can, $space_id, $viewer_id );

				return $can;
			}
		);

		SpaceVisibility::can_view_content( $this->space( 'open' ), 77 );

		$this->assertCount( 1, $seen, 'can_view_content() must ask whether a plan gate applies.' );
		$this->assertSame( array( true, 4242, 77 ), $seen[0], 'The gate needs the space and the viewer to answer.' );
	}

	/**
	 * A denial is honoured, on a space whose TYPE would have allowed it.
	 *
	 * The open + gated case: the exact shape that was wide open.
	 *
	 * @return void
	 */
	public function test_a_gate_can_close_an_open_space(): void {
		$this->gate( static fn(): bool => false );

		$this->assertFalse(
			SpaceVisibility::can_view_content( $this->space( 'open' ), 77 ),
			'A plan gate must close an open space to a viewer without the plan.'
		);
	}

	/**
	 * And to a logged-out visitor, who was reading paid content for free.
	 *
	 * @return void
	 */
	public function test_a_gate_closes_the_space_to_logged_out_visitors(): void {
		$this->gate( static fn(): bool => false );

		$this->assertFalse( SpaceVisibility::can_view_content( $this->space( 'open' ), 0 ) );
	}

	/**
	 * The gate may narrow. It may NOT widen.
	 *
	 * A private space stays shut to a non-member however enthusiastically a filter
	 * answers true — otherwise the seam added to close one hole opens a larger one,
	 * and any plugin on the site could reach into private spaces through it.
	 *
	 * @return void
	 */
	public function test_the_gate_cannot_open_a_private_space(): void {
		$this->gate( static fn(): bool => true );

		$this->assertFalse(
			SpaceVisibility::can_view_content( $this->space( 'private' ), 77 ),
			'The content gate must only ever narrow access.'
		);
	}

	/**
	 * With nothing hooked — a Free-only site — an open space stays open.
	 *
	 * @return void
	 */
	public function test_an_ungated_open_space_is_unaffected(): void {
		$this->assertTrue( SpaceVisibility::can_view_content( $this->space( 'open' ), 77 ) );
		$this->assertTrue( SpaceVisibility::can_view_content( $this->space( 'open' ), 0 ) );
	}

	/**
	 * A missing space is still refused, before any filter runs.
	 *
	 * @return void
	 */
	public function test_a_null_space_is_refused_without_asking(): void {
		$asked = false;

		$this->gate(
			static function ( bool $can ) use ( &$asked ): bool {
				$asked = true;

				return true;
			}
		);

		$this->assertFalse( SpaceVisibility::can_view_content( null, 77 ) );
		$this->assertFalse( $asked, 'There is no space to ask about.' );
	}
}
