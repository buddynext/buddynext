<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * With no colour picked, email and the Appearance picker show the accent the site
 * renders, and saving the picker unchanged picks nothing (card 10463030506 follow-up).
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

use BuddyNext\Admin\AppearanceTab;
use BuddyNext\Notifications\EmailSender;
use BuddyNext\Theme\Appearance;

/**
 * @covers \BuddyNext\Theme\Appearance::display_brand
 * @covers \BuddyNext\Admin\AppearanceTab::handle_save
 */
class DisplayBrandTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'buddynext_brand_color' );
	}

	public function tear_down(): void {
		unset( $_POST['bn_brand_color'], $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );
		parent::tear_down();
	}

	private function save_appearance( ?string $posted ): void {
		if ( null !== $posted ) {
			$_POST['bn_brand_color'] = $posted;
		}
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bn_appearance_save' );

		$stop = static function (): void {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $stop );
		try {
			( new AppearanceTab() )->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $stop );
		}
	}

	public function test_email_header_uses_the_site_accent_until_a_colour_is_picked(): void {
		$html = EmailSender::brand_wrap( 'Body', 'Subject' );
		$this->assertStringContainsString( Appearance::DEFAULT_ACCENT_HEX, $html );
		$this->assertStringNotContainsString( Appearance::DEFAULT_BRAND, $html );

		update_option( 'buddynext_brand_color', '#aa3366' );
		$this->assertStringContainsString( '#aa3366', EmailSender::brand_wrap( 'Body', 'Subject' ) );

		update_option( 'buddynext_brand_color', Appearance::DEFAULT_BRAND );
		$this->assertSame( Appearance::DEFAULT_ACCENT_HEX, Appearance::display_brand(), 'The sentinel means not picked.' );
	}

	public function test_saving_the_picker_unchanged_picks_nothing(): void {
		$this->save_appearance( Appearance::DEFAULT_ACCENT_HEX );

		$this->assertFalse( get_option( 'buddynext_brand_color' ), 'No option written.' );
		$this->assertSame( '', Appearance::picked_brand() );
	}

	public function test_a_real_pick_is_stored_and_can_be_cleared(): void {
		$this->save_appearance( '#aa3366' );
		$this->assertSame( '#aa3366', Appearance::picked_brand() );

		// Unchanged on a site with a pick: still that pick.
		$this->save_appearance( '#aa3366' );
		$this->assertSame( '#aa3366', Appearance::picked_brand() );

		// The field absent, or emptied, goes back to not picked.
		$this->save_appearance( '' );
		$this->assertSame( '', Appearance::picked_brand() );
	}
}
