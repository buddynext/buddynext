<?php
/**
 * Who owns the document <title> when both claimants want it.
 *
 * Two of our own filters claim `document_title_parts` at priority 10:
 * PageRouter's hub title and HeadMeta's social descriptor. At equal priority
 * registration order decides, and HeadMeta registers second (SurfaceMeta::register
 * runs after the hub title is set), so it silently won.
 *
 * That is wrong because the two strings answer to different readers. The
 * descriptor's `title` is the SOCIAL CARD title; messages and notifications
 * deliberately describe themselves with a bare community name so a personal inbox
 * cannot leak "Notifications (99+)" into a shared preview. Reusing it for the
 * browser tab printed "buddynext - buddynext" on both hubs, and cost every other
 * hub its real title too (the activity feed read "Activity", the mapped page's
 * name, instead of "Activity Feed").
 *
 * The rule pinned here: a hub render owns the document title; HeadMeta fills the
 * gap only on surfaces that run no hub render. The social card is unaffected.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HeadMeta;
use BuddyNext\Core\PageRouter;

/**
 * Document-title precedence between PageRouter and HeadMeta.
 *
 * @covers \BuddyNext\Core\HeadMeta::emit
 * @covers \BuddyNext\Core\PageRouter::title_claimed
 */
class DocumentTitleOwnershipTest extends \WP_UnitTestCase {

	/**
	 * Reset both claimants between cases.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		HeadMeta::reset();
		PageRouter::reset_title_claim();
		remove_all_filters( 'document_title_parts' );
	}

	/**
	 * Leave no filters or flags behind.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		HeadMeta::reset();
		PageRouter::reset_title_claim();
		remove_all_filters( 'document_title_parts' );
		remove_all_filters( 'buddynext_head_meta' );
		parent::tear_down();
	}

	/**
	 * Run the registered filters over a starting set of parts.
	 *
	 * @param array<string,string> $parts Starting parts.
	 * @return array<string,string> Filtered parts.
	 */
	private function resolve( array $parts = array( 'title' => 'Original', 'site' => 'Site' ) ): array {
		return (array) apply_filters( 'document_title_parts', $parts );
	}

	/**
	 * A hub that claimed the title keeps it, descriptor notwithstanding.
	 *
	 * The exact shape of the bug: notifications describes itself as the community
	 * name for the card, and that must not become the browser tab.
	 *
	 * @return void
	 */
	public function test_a_claimed_hub_title_survives_the_descriptor(): void {
		// Stand in for render_hub() having claimed "Notifications (99+)".
		PageRouter::reset_title_claim();
		add_filter(
			'document_title_parts',
			static function ( array $parts ): array {
				$parts['title'] = 'Notifications (99+)';
				return $parts;
			}
		);
		$claim = new \ReflectionProperty( PageRouter::class, 'title_claimed' );
		$claim->setAccessible( true );
		$claim->setValue( null, true );

		HeadMeta::emit(
			array(
				'url'     => home_url( '/notifications/' ),
				'title'   => 'buddynext',
				'noindex' => true,
			)
		);

		$this->assertSame(
			'Notifications (99+)',
			$this->resolve()['title'],
			'the hub title is the member-facing one and must not be replaced by the social descriptor'
		);
	}

	/**
	 * With no hub claim, the descriptor still supplies the title.
	 *
	 * A single-post permalink runs no hub render; this emitter is the only
	 * claimant there, and narrowing the rule must not switch that off.
	 *
	 * @return void
	 */
	public function test_the_descriptor_still_titles_a_surface_with_no_hub_claim(): void {
		HeadMeta::emit(
			array(
				'url'   => home_url( '/p/123/' ),
				'title' => 'A shared post',
			)
		);

		$this->assertSame(
			'A shared post',
			$this->resolve()['title'],
			'without a hub claim the descriptor must still own the document title'
		);
	}

	/**
	 * The social card keeps the descriptor's title either way.
	 *
	 * This is the half that must NOT change: the generic community name on an
	 * inbox surface is a privacy decision, not a bug.
	 *
	 * @return void
	 */
	public function test_the_social_card_still_uses_the_descriptor_title(): void {
		$claim = new \ReflectionProperty( PageRouter::class, 'title_claimed' );
		$claim->setAccessible( true );
		$claim->setValue( null, true );

		ob_start();
		HeadMeta::emit(
			array(
				'url'     => home_url( '/notifications/' ),
				'title'   => 'buddynext',
				'noindex' => true,
			)
		);
		do_action( 'wp_head' );
		$head = (string) ob_get_clean();

		$this->assertStringContainsString(
			'og:title',
			$head,
			'precondition: the card is emitted'
		);
		$this->assertMatchesRegularExpression(
			'/og:title"\s+content="buddynext"/',
			$head,
			'the shared card must still say the community name - an inbox must not leak its contents into a preview'
		);
		$this->assertStringContainsString( 'noindex', $head, 'an inbox surface stays noindex' );
	}

	/**
	 * A fresh request starts unclaimed.
	 *
	 * The flag is request state; leaking it across requests would suppress the
	 * descriptor title on permalinks that follow a hub render in the same process.
	 *
	 * @return void
	 */
	public function test_the_claim_does_not_leak_between_requests(): void {
		$claim = new \ReflectionProperty( PageRouter::class, 'title_claimed' );
		$claim->setAccessible( true );
		$claim->setValue( null, true );
		$this->assertTrue( PageRouter::title_claimed() );

		PageRouter::reset_title_claim();

		$this->assertFalse( PageRouter::title_claimed(), 'reset must clear the claim' );
	}
}
