<?php
/**
 * The social/canonical head contract.
 *
 * og:image must be something a third-party scraper can fetch anonymously.
 * BuddyNext's generated letter-avatars are data: URIs, which no platform
 * accepts — and the old code counted one as "has an image", so a text-only post
 * shipped an unloadable image AND claimed summary_large_image, rendering blank
 * (Basecamp 10181599620).
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HeadMeta;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\HeadMeta
 */
class HeadMetaTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		HeadMeta::reset();
	}

	public function tear_down(): void {
		HeadMeta::reset();
		remove_all_filters( 'buddynext_head_meta' );
		remove_all_filters( 'buddynext_head_meta_image' );
		parent::tear_down();
	}

	/**
	 * Render a descriptor and return the emitted head.
	 *
	 * @param array<string,mixed> $descriptor Descriptor.
	 * @return string
	 */
	private function render( array $descriptor ): string {
		HeadMeta::emit( $descriptor );
		ob_start();
		do_action( 'wp_head' );
		return (string) ob_get_clean();
	}

	/**
	 * The image contract: only absolute http(s) URLs survive.
	 */
	public function test_sanitize_image_rejects_everything_a_scraper_cannot_fetch(): void {
		$this->assertSame( '', HeadMeta::sanitize_image( 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=' ), 'A data: URI is the exact bug — no platform fetches it.' );
		$this->assertSame( '', HeadMeta::sanitize_image( '/wp-content/uploads/a.png' ), 'A relative URL is not resolvable by a scraper.' );
		$this->assertSame( '', HeadMeta::sanitize_image( '//example.test/a.png' ), 'Protocol-relative is not safe to hand out.' );
		$this->assertSame( '', HeadMeta::sanitize_image( '' ) );

		$this->assertSame( 'https://example.test/a.png', HeadMeta::sanitize_image( 'https://example.test/a.png' ) );
		$this->assertSame( 'http://example.test/a.png', HeadMeta::sanitize_image( 'http://example.test/a.png' ) );
	}

	/**
	 * The ladder skips unusable rungs and takes the first real one.
	 */
	public function test_first_usable_image_walks_past_unusable_rungs(): void {
		$this->assertSame(
			'https://example.test/real.png',
			HeadMeta::first_usable_image(
				array(
					'',
					'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
					'https://example.test/real.png',
					'https://example.test/later.png',
				)
			)
		);

		$this->assertSame( '', HeadMeta::first_usable_image( array( '', 'data:image/png;base64,AAA' ) ) );
	}

	/**
	 * A data-URI image never reaches the page, and the card degrades honestly.
	 */
	public function test_data_uri_image_is_dropped_and_card_degrades_to_summary(): void {
		$out = $this->render(
			array(
				'url'   => 'https://example.test/p/1/',
				'title' => 'A post',
				'image' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
			)
		);

		$this->assertStringNotContainsString( 'data:image', $out, 'A data URI must never be emitted as og:image.' );
		$this->assertStringContainsString( 'og:image', $out, 'The site fallback keeps the card from shipping imageless.' );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary" />', $out, 'A site logo fallback is not content and must not claim the wide card.' );
	}

	/**
	 * A real image is emitted and upgrades the card.
	 */
	public function test_real_image_emits_and_upgrades_the_card(): void {
		$out = $this->render(
			array(
				'url'   => 'https://example.test/p/1/',
				'title' => 'A post',
				'image' => 'https://example.test/photo.jpg',
			)
		);

		$this->assertStringContainsString( '<meta property="og:image" content="https://example.test/photo.jpg" />', $out );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $out );
	}

	/**
	 * Exactly one canonical and one og:title per response — duplicates are the
	 * failure mode that makes scrapers pick the wrong one.
	 */
	public function test_emits_exactly_one_canonical_and_title(): void {
		$out = $this->render(
			array(
				'url'   => 'https://example.test/spaces/design/',
				'title' => 'Design',
			)
		);

		$this->assertSame( 1, substr_count( $out, 'rel="canonical"' ) );
		$this->assertSame( 1, substr_count( $out, 'property="og:title"' ) );
	}

	/**
	 * A second surface cannot describe the same response.
	 */
	public function test_first_descriptor_wins(): void {
		HeadMeta::emit( array( 'url' => 'https://example.test/first/', 'title' => 'First' ) );
		HeadMeta::emit( array( 'url' => 'https://example.test/second/', 'title' => 'Second' ) );

		ob_start();
		do_action( 'wp_head' );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'First', $out );
		$this->assertStringNotContainsString( 'Second', $out );
		$this->assertSame( 1, substr_count( $out, 'rel="canonical"' ) );
	}

	/**
	 * noindex is emitted for restricted surfaces.
	 */
	public function test_noindex_is_emitted_when_requested(): void {
		$out = $this->render(
			array(
				'url'     => 'https://example.test/spaces/secret/',
				'title'   => 'Secret',
				'noindex' => true,
			)
		);

		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow" />', $out );
	}

	/**
	 * The whole head can be suppressed by a site that wants its SEO plugin to
	 * own every tag.
	 */
	public function test_head_can_be_suppressed_by_filter(): void {
		add_filter( 'buddynext_head_meta', '__return_empty_array' );

		$out = $this->render( array( 'url' => 'https://example.test/p/1/', 'title' => 'A post' ) );

		$this->assertStringNotContainsString( 'og:title', $out );
		$this->assertStringNotContainsString( 'rel="canonical"', $out );
	}

	/**
	 * Descriptions are collapsed and truncated to the card budget.
	 */
	public function test_description_is_plain_and_truncated(): void {
		$long = HeadMeta::prepare_description( '<p>Hello   <strong>world</strong></p>' . str_repeat( ' word', 100 ) );

		$this->assertStringNotContainsString( '<', $long );
		$this->assertLessThanOrEqual( 160, mb_strlen( $long ) );
		$this->assertStringStartsWith( 'Hello world', $long );
	}

	/**
	 * SVG fetches fine and still renders a blank card on Facebook, LinkedIn and
	 * X — the exact failure this class exists to prevent, one layer down.
	 */
	public function test_svg_is_rejected_because_platforms_do_not_render_it(): void {
		$this->assertSame( '', HeadMeta::sanitize_image( 'https://example.test/logo.svg' ) );
		$this->assertSame( '', HeadMeta::sanitize_image( 'https://example.test/logo.SVG' ) );
		$this->assertSame( '', HeadMeta::sanitize_image( 'https://example.test/logo.svgz' ) );
		$this->assertSame( 'https://example.test/logo.png', HeadMeta::sanitize_image( 'https://example.test/logo.png' ) );
	}

	/**
	 * Nothing ships blank: with no descriptor values at all, a complete card is
	 * still produced from what the site is known to have.
	 */
	public function test_bare_descriptor_still_produces_a_complete_card(): void {
		$out = $this->render( array( 'url' => 'https://example.test/anything/' ) );

		$this->assertStringContainsString( 'og:title', $out, 'Title falls back to the community name.' );
		$this->assertStringContainsString( 'og:image', $out, 'Image falls back through site icon, logo, then the bundled PWA icon.' );
		$this->assertStringContainsString( 'twitter:card', $out );
		$this->assertSame( 1, substr_count( $out, 'rel="canonical"' ) );
	}
}
