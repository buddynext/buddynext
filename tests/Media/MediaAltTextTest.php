<?php
/**
 * Media alt text comes from WPMediaVerse.
 *
 * @package BuddyNext\Tests\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Media\MediaClient;
use WP_UnitTestCase;

/**
 * BuddyNext's tiles asked nobody and used the upload's title (card
 * 10374741988). The engine owns the alt rule; BuddyNext reads its answer and
 * falls back to the title only when the engine has none or cannot say.
 */
class MediaAltTextTest extends WP_UnitTestCase {

	/**
	 * Remove the service stub.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_media_service' );
		parent::tear_down();
	}

	/**
	 * Stub the engine's template helpers.
	 *
	 * @param object|null $helpers Stand-in for the template_helpers service.
	 * @return void
	 */
	private function engine_helpers( ?object $helpers ): void {
		add_filter(
			'buddynext_media_service',
			static fn( $resolved, string $key ) => 'template_helpers' === $key ? $helpers : $resolved,
			10,
			2
		);
	}

	/**
	 * The engine's alt wins; no engine answer means '' (the caller uses the title).
	 *
	 * @return void
	 */
	public function test_alt_text_is_the_engines_answer(): void {
		$this->engine_helpers(
			new class() {
				/**
				 * Engine alt.
				 *
				 * @param int $id Media id.
				 * @return string
				 */
				public function alt_text( int $id ): string {
					return 7 === $id ? ' A blue code editor ' : '';
				}
			}
		);
		$this->assertSame( 'A blue code editor', MediaClient::alt_text( 7 ) );
		$this->assertSame( '', MediaClient::alt_text( 8 ) );
	}

	/**
	 * An engine older than 2.6.1 (no alt_text()) or no engine: '' and no error.
	 *
	 * @return void
	 */
	public function test_older_or_missing_engine_falls_back_quietly(): void {
		$this->engine_helpers( new class() {} );
		$this->assertSame( '', MediaClient::alt_text( 7 ) );

		remove_all_filters( 'buddynext_media_service' );
		$this->engine_helpers( null );
		$this->assertSame( '', MediaClient::alt_text( 7 ) );
	}
}
