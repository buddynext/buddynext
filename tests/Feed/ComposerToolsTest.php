<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The post composer's tool row (partials/composer.php): every tool is named and
 * has a tooltip, and the extra tools sit behind one More disclosure that a phone
 * shows (the CSS decides when; the markup must always be a correct disclosure).
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

/**
 * Composer tool row markup.
 */
class ComposerToolsTest extends \WP_UnitTestCase {

	private function render( int $user_id ): string {
		wp_set_current_user( $user_id );
		ob_start();
		buddynext_get_template(
			'partials/composer.php',
			array(
				'space_id'        => null,
				'current_user_id' => $user_id,
			)
		);
		return (string) ob_get_clean();
	}

	private function with_extra_tool(): void {
		add_filter(
			'buddynext_composer_tools',
			static function (): string {
				return '<button class="bn-composer__tool" type="button" aria-label="Extra" title="Extra" data-test-extra>x</button>';
			}
		);
	}

	public function test_extra_tools_sit_behind_one_more_disclosure(): void {
		$this->with_extra_tool();
		$html = $this->render( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertSame( 1, preg_match( '/<button class="bn-composer__tool bn-composer__more"[^>]*>/s', $html, $more ), 'One More button.' );
		$this->assertStringContainsString( 'aria-expanded="false"', $more[0] );
		$this->assertStringContainsString( 'data-wp-bind--aria-expanded="state.moreOpen"', $more[0] );
		$this->assertStringContainsString( 'data-wp-on--click="actions.toggleMore"', $more[0] );
		$this->assertStringContainsString( 'aria-label="More tools"', $more[0] );
		$this->assertStringContainsString( 'title="More tools"', $more[0] );

		$this->assertSame( 1, preg_match( '/aria-controls="([^"]+)"/', $more[0], $controls ) );
		$this->assertSame( 1, preg_match( '/<div class="bn-composer__more-tools"\s+id="' . preg_quote( $controls[1], '/' ) . '"[^>]*data-wp-class--is-open="state.moreOpen"/s', $html ), 'aria-controls names the tools row.' );

		$row = substr( $html, (int) strpos( $html, 'class="bn-composer__more-tools"' ) );
		$this->assertStringContainsString( 'data-test-extra', $row, 'Filter-added tools go in the More row.' );
		$this->assertStringContainsString( '"moreOpen":false', html_entity_decode( $html ), 'The store context starts closed.' );

		// Media and emoji stay outside the More row, before the button.
		$emoji_pos = strpos( $html, 'bn-emoji-trigger' );
		if ( false !== $emoji_pos ) {
			$this->assertLessThan( strpos( $html, 'bn-composer__more"' ), $emoji_pos, 'Emoji stays on the first row.' );
		}
	}

	public function test_every_tool_has_a_name_and_a_tooltip(): void {
		$this->with_extra_tool();
		$html = $this->render( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertGreaterThan( 0, preg_match_all( '/<button class="bn-composer__tool[ "][^>]*>/s', $html, $tools ) );
		foreach ( $tools[0] as $tool ) {
			$this->assertMatchesRegularExpression( '/aria-label="[^"]+"/', $tool, $tool );
			$this->assertMatchesRegularExpression( '/title="[^"]+"/', $tool, $tool );
		}
	}

	public function test_avatar_is_the_photo_or_the_initials(): void {
		$uid = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Ada Lovelace',
			)
		);
		update_option( 'bn_avatar_style', 'initials' );

		$this->assertSame( 1, preg_match( '/bn-composer__avatar".*?<img src="data:image\/svg\+xml;base64,([^"]+)"/s', $this->render( $uid ), $svg ), 'No photo: the initials image.' );
		$this->assertStringContainsString( '>AL<', (string) base64_decode( $svg[1] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading the test's own data URI.

		update_user_meta( $uid, 'bn_avatar', 'https://example.org/ada.jpg' );
		$this->assertMatchesRegularExpression( '/bn-composer__avatar".*?<img src="https:\/\/example\.org\/ada\.jpg/s', $this->render( $uid ), 'With a photo: the photo.' );
	}
}
