<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Every tone the code emits has a style (card 10435625423). Badges, alerts and
 * dialog buttons said tone "warning" while the stylesheets only know "warn", so
 * Escalated, Already suspended, Archived and Pending rendered as neutral grey.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * @coversNothing Source contract between emitted data-tone values and the CSS.
 */
class ToneVocabularyTest extends \WP_UnitTestCase {

	private function files( string $dir, string $ext ): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( BUDDYNEXT_DIR . $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->getExtension() === $ext && ! str_contains( $file->getPathname(), '/vendor/' ) ) {
				$out[] = $file->getPathname();
			}
		}
		return $out;
	}

	private function css(): string {
		$css = '';
		foreach ( $this->files( 'assets/css', 'css' ) as $file ) {
			$css .= file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
		}
		return $css;
	}

	/** Tones that have a rule for one component, e.g. '.bn-badge'. */
	private function styled_for( string $selector ): array {
		preg_match_all( '/' . preg_quote( $selector, '/' ) . '\[data-tone="([a-z-]+)"\]/', $this->css(), $m );
		return array_unique( $m[1] );
	}

	public function test_badge_tones_have_a_badge_rule(): void {
		$styled = $this->styled_for( '.bn-badge' );
		$tones  = array_column( \BuddyNext\Feed\PostService::STATUSES, 'tone' );
		foreach ( array_merge( $this->files( 'includes', 'php' ), $this->files( 'templates', 'php' ) ) as $file ) {
			preg_match_all( '/class="bn-badge"\s+data-tone="([a-z-]+)"/', (string) file_get_contents( $file ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
			$tones = array_merge( $tones, $m[1] );
		}
		$this->assertSame( array(), array_values( array_diff( array_unique( $tones ), $styled, array( 'default' ) ) ) );
	}

	public function test_notification_pill_tones_have_a_rule(): void {
		$styled = $this->styled_for( '.bn-notif-row__type' );
		$meta   = new \BuddyNext\Notifications\NotificationMessageService();
		$tones  = array();
		foreach ( array_keys( ( new \BuddyNext\Notifications\NotificationPrefCatalogue() )->all() ) as $type ) {
			$tones[ $meta->meta_for( $type )['tone'] ][] = $type;
		}
		$this->assertSame( array(), array_diff_key( $tones, array_flip( $styled ) ) );
	}

	public function test_every_literal_tone_has_a_css_rule(): void {
		$css = $this->css();
		preg_match_all( '/\[data-tone="([a-z-]+)"\]/', $css, $m );
		$styled = array_unique( $m[1] );

		$emitted = array();
		foreach ( array_merge( $this->files( 'includes', 'php' ), $this->files( 'templates', 'php' ), $this->files( 'assets/js', 'js' ) ) as $file ) {
			$src = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
			// JS dialog/toast `tone:` options are mapped by their own code, so only
			// markup tones and PHP 'tone' arrays (rendered into data-tone) count.
			preg_match_all( '/data-tone="([a-z-]+)"|\'data-tone\':\s*\'([a-z-]+)\'|\'tone\'\s*=>\s*\'([a-z-]+)\'/', $src, $hits, PREG_SET_ORDER );
			foreach ( $hits as $hit ) {
				$tone = (string) end( $hit );
				$emitted[ $tone ][] = str_replace( BUDDYNEXT_DIR, '', $file );
			}
		}

		// 'default' is the explicit base look: no tone rule by design.
		$unstyled = array_diff_key( $emitted, array_flip( array_merge( $styled, array( 'default' ) ) ) );
		$this->assertSame( array(), array_map( 'array_unique', $unstyled ), 'These tones are emitted but no stylesheet has a [data-tone] rule for them.' );
	}
}
