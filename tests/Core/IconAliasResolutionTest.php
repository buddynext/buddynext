<?php
/**
 * A stored `tab-*` icon keeps rendering after its duplicate SVG is deleted.
 *
 * BuddyNext maintained TWO icon sets: the Lucide library in assets/icons/ that
 * buddynext_icon() reads, and a second copy under assets/svg/admin/tab-*.svg that
 * the admin nav-icon picker globbed. Widening the picker meant copying 22 Lucide
 * glyphs into the second folder, so the same artwork lived in two places and could
 * drift apart.
 *
 * The two sets are bridged by a fallback in render(): a `tab-` slug missing from
 * assets/icons/ is looked up in assets/svg/admin/. That is why nothing is visibly
 * broken today — and also why the duplicates cannot simply be deleted. Sites store
 * the picker's value (`tab-star`) on the nav item, so removing tab-star.svg would
 * blank that icon on every site using it.
 *
 * The missing step is an alias: `tab-star` should resolve to the library's `star`
 * BEFORE the admin-folder fallback is tried. With that, the 25 exact duplicates are
 * safe to delete and the 13 bespoke glyphs (tab-about, tab-auth, tab-custom and
 * friends, which have no Lucide equivalent) keep working through the fallback.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\IconService;

/**
 * tab-* icon slugs resolve to the shared library where one exists.
 *
 * @covers \BuddyNext\Core\IconService::render
 */
class IconAliasResolutionTest extends \WP_UnitTestCase {

	/**
	 * A `tab-` slug with a library twin renders from the library.
	 *
	 * This is what makes deleting the duplicate safe: the alias resolves before the
	 * assets/svg/admin/ fallback is consulted, so the file can go.
	 *
	 * @return void
	 */
	public function test_a_tab_slug_resolves_to_its_library_icon(): void {
		$aliased = IconService::render( 'tab-star' );
		$direct  = IconService::render( 'star' );

		$this->assertNotSame( '', $direct, 'precondition: the library must have a star icon' );

		// The ARTWORK must match, not the whole string: the wrapper keeps the class
		// it was asked for (bn-icon--tab-star), which is right — a site may target
		// that class in CSS, and normalising it would be an unannounced change to
		// markup nobody asked us to touch. What matters for deleting the duplicate
		// file is that the drawing comes from the library.
		$strip = static fn( string $svg ): string => (string) preg_replace( '/ class="[^"]*"/', '', $svg );

		$this->assertSame(
			$strip( $direct ),
			$strip( $aliased ),
			'tab-star must draw the SAME artwork as star, or the duplicate file cannot be removed '
			. 'without blanking the icon on every site that stored the picker value.'
		);
	}

	/**
	 * A bespoke `tab-` glyph with no library twin still renders.
	 *
	 * tab-about, tab-auth, tab-custom and the other admin-only glyphs have no Lucide
	 * equivalent and stay in assets/svg/admin/. The alias must not break them.
	 *
	 * @return void
	 */
	public function test_a_bespoke_tab_glyph_still_renders(): void {
		$this->assertNotSame(
			'',
			IconService::render( 'tab-custom' ),
			'a tab-* glyph with no library equivalent must still resolve through the admin folder'
		);
	}

	/**
	 * A plain library slug is unaffected.
	 *
	 * Guards against the alias leaking into the main path.
	 *
	 * @return void
	 */
	public function test_a_plain_library_slug_is_unaffected(): void {
		$this->assertNotSame( '', IconService::render( 'home' ) );
		$this->assertStringContainsString( '<svg', IconService::render( 'home' ) );
	}

	/**
	 * An unknown slug still renders nothing rather than erroring.
	 *
	 * It also NOTICES now (1.1.6). Returning '' in silence is how a blank icon
	 * shipped and sat there looking like a styling bug, so render() reports the
	 * missing slug through _doing_it_wrong() under WP_DEBUG. The contract this
	 * test guards - never fatal, never partial markup, always '' - is unchanged;
	 * the expectation below is what makes the new notice explicit rather than
	 * something a future reader has to rediscover from a red suite.
	 *
	 * @return void
	 */
	public function test_an_unknown_slug_renders_nothing(): void {
		$this->setExpectedIncorrectUsage( 'BuddyNext\\Core\\IconService::render' );

		$this->assertSame( '', IconService::render( 'tab-definitely-not-an-icon' ) );
		$this->assertSame( '', IconService::render( 'definitely-not-an-icon' ) );
	}

	/**
	 * The missing slug is NAMED, so the log says which icon to add.
	 *
	 * A notice that only says "an icon is missing" would have saved nobody on the
	 * bug this was built for - the whole difficulty was identifying which of the
	 * five social chips had no file behind it.
	 *
	 * @return void
	 */
	public function test_the_notice_names_the_missing_slug(): void {
		$this->setExpectedIncorrectUsage( 'BuddyNext\\Core\\IconService::render' );

		$captured = '';
		add_action(
			'doing_it_wrong_run',
			static function ( $function_name, $message ) use ( &$captured ): void {
				unset( $function_name );
				$captured = (string) $message;
			},
			10,
			2
		);

		IconService::render( 'no-such-glyph-here' );

		$this->assertStringContainsString( 'no-such-glyph-here', $captured );
	}

	/**
	 * An EMPTY slug is silent - it means "no icon here", not a missing file.
	 *
	 * Nav items and filtered links legitimately supply no glyph. Warning on those
	 * reports every one of them as a defect and buries the real ones; caught the
	 * first time only because HeaderUserSectionTest happened to render one.
	 *
	 * @return void
	 */
	public function test_an_empty_slug_does_not_warn(): void {
		$fired = false;
		add_action(
			'doing_it_wrong_run',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$this->assertSame( '', IconService::render( '' ) );
		$this->assertFalse( $fired, 'An empty icon name must not report incorrect usage.' );
	}
}
