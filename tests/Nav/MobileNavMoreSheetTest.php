<?php
/**
 * Every rail destination stays reachable from BuddyNext's own phone navigation.
 *
 * The rail hides at 768px and the bar has five slots, so Members, Explore, Messages,
 * Bookmarks and Settings used to vanish unless the host theme happened to link them.
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * The More sheet is built from the rail's own list.
 *
 * @coversNothing
 */
class MobileNavMoreSheetTest extends WP_UnitTestCase {

	/**
	 * Render the nav partial.
	 *
	 * @return array{0:string,1:string} The bar markup and the sheet markup.
	 */
	private function render(): array {
		ob_start();
		buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => '' ) );
		$html  = (string) ob_get_clean();
		$split = strpos( $html, 'id="bn-mobile-more"' );
		return array(
			false === $split ? $html : substr( $html, 0, $split ),
			false === $split ? '' : substr( $html, $split ),
		);
	}

	public function test_a_member_reaches_every_primary_destination(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		add_filter( 'buddynext_feature_bookmarks', '__return_true' );

		list( $bar, $sheet ) = $this->render();

		foreach ( array( PageRouter::people_url(), PageRouter::explore_url(), PageRouter::bookmarks_url(), PageRouter::settings_url() ) as $url ) {
			$this->assertStringContainsString( 'href="' . esc_url( $url ) . '"', $sheet, $url );
		}
		$this->assertStringContainsString( 'data-bn-more-toggle', $bar );
		$this->assertSame( 5, substr_count( $bar, 'bn-mobile-nav__item' ) - substr_count( $bar, 'bn-mobile-nav__item--' ), 'the bar stays five slots' );
		$this->assertStringContainsString( '/members/', $sheet, 'Profile folds into the sheet' );
	}

	public function test_a_guest_gets_explore_and_nothing_that_needs_an_account(): void {
		wp_set_current_user( 0 );

		list( $bar, $sheet ) = $this->render();

		$this->assertStringContainsString( 'href="' . esc_url( PageRouter::explore_url() ) . '"', $sheet );
		$this->assertStringContainsString( 'href="' . esc_url( PageRouter::people_url() ) . '"', $bar );
		$this->assertStringNotContainsString( 'href="' . esc_url( PageRouter::people_url() ) . '"', $sheet );
		$this->assertStringNotContainsString( esc_url( PageRouter::settings_url() ), $sheet );
		$this->assertStringNotContainsString( esc_url( PageRouter::bookmarks_url() ), $sheet );
		$this->assertStringNotContainsString( esc_url( PageRouter::messages_url() ), $sheet );
	}

	public function test_the_sheet_follows_the_rail_so_a_hidden_item_stays_hidden(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		add_filter(
			'buddynext_rail_items',
			static function ( array $items ): array {
				foreach ( $items as &$item ) {
					if ( 'explore' === $item['key'] ) {
						$item['show'] = false;
					}
				}
				$items[] = array(
					'key'   => 'qafix-bridge',
					'label' => 'QA-FIX Bridge',
					'url'   => 'https://example.com/qafix-bridge',
					'icon'  => 'link',
					'show'  => true,
				);
				return $items;
			}
		);

		list( , $sheet ) = $this->render();

		$this->assertStringNotContainsString( 'href="' . esc_url( PageRouter::explore_url() ) . '"', $sheet );
		$this->assertStringContainsString( 'https://example.com/qafix-bridge', $sheet );
	}
}
