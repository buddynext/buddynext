<?php
/**
 * BuddyNext's Hub screens may silence other plugins, never themselves.
 *
 * `AdminHub::suppress_foreign_admin_notices()` calls `remove_all_actions()` on the
 * three notice hooks for every Hub screen. The intent is right and its comment
 * says so — the host theme's TGMPA nag and other plugins' "finish setup" prompts
 * have no business crowding the settings UI. `remove_all_actions()` simply cannot
 * tell foreign from our own.
 *
 * So nine owner-facing warnings were removed on the exact screens they are about.
 * Measured in the browser: wp-admin/index.php renders the BuddyNext warning with
 * its action button; `admin.php?page=buddynext-monetization&tab=tiers` renders
 * ZERO `.notice` elements of any kind.
 *
 * The ones that matter are all misconfiguration warnings:
 *
 *   render_no_default_plan_notice   "no plan applies to your members"
 *   render_no_gateway_notice        "your plans cannot be bought"
 *   render_missing_block_notice     "your membership page cannot show plans"
 *   render_paypal_webhook_notice    "buyers will be charged and not receive their plan"
 *
 * `free-internal/docs/standards/public-surface-integrity.md` requires that a
 * member-facing dead end caused by owner misconfiguration is reported to the
 * OWNER — the member is never the error channel — and it names
 * `render_no_default_plan_notice()` as the pattern to copy. Muting that pattern on
 * the Hub satisfied the standard in code and not in the product.
 *
 * ## Why ownership is decided by FILE, not namespace
 *
 * A callback can be a closure, a static string, a plain function or an object
 * method, and only some of those carry a namespace worth reading. Every one of
 * them has a defining file. Asking where the code LIVES answers correctly for all
 * four shapes, and keeps working for a Pro notice, a bridge notice, or one added
 * next year by someone who never reads this file.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminHub;
use WP_UnitTestCase;

/**
 * Notice suppression on Hub screens.
 *
 * @covers \BuddyNext\Admin\AdminHub::suppress_foreign_admin_notices
 */
class HubKeepsOurOwnNoticesTest extends WP_UnitTestCase {

	/**
	 * Pretend we are on a BuddyNext Hub screen and run the suppressor.
	 *
	 * @return void
	 */
	private function suppress_on_hub_screen(): void {
		set_current_screen( 'buddynext_page_buddynext-monetization' );

		( new AdminHub() )->suppress_foreign_admin_notices();
	}

	/**
	 * A notice defined inside BuddyNext survives.
	 *
	 * @return void
	 */
	public function test_our_own_notice_survives_on_a_hub_screen(): void {
		// Registered as a method on a real BuddyNext class, which is how all nine
		// of the live ones are registered.
		$ours = array( new \BuddyNext\Auth\CoreRegistration(), 'render_terms_notice' );
		add_action( 'admin_notices', $ours );

		$this->assertNotFalse( has_action( 'admin_notices', $ours ), 'Fixture: the notice must be registered before we suppress anything.' );

		$this->suppress_on_hub_screen();

		$this->assertNotFalse(
			has_action( 'admin_notices', $ours ),
			'A BuddyNext notice was removed on a Hub screen - the screens its warnings are about.'
		);
	}

	/**
	 * A notice defined outside BuddyNext is still removed.
	 *
	 * Guards the guard: keeping everything would pass the test above and hand the
	 * settings UI back to every plugin's nag, which is what the suppression exists
	 * to prevent.
	 *
	 * @return void
	 */
	public function test_a_foreign_notice_is_still_removed_on_a_hub_screen(): void {
		// A WordPress CORE function, so its defining file is genuinely outside the
		// plugin. A closure written here would not do: this file lives under the
		// plugin directory, so the file-path rule correctly reads it as ours - the
		// first draft of this test used one and watched the "foreign" notice
		// survive, which looked like a bug in the code and was a bug in the fixture.
		$theirs = '__return_false';
		add_action( 'admin_notices', $theirs );

		$this->assertNotFalse( has_action( 'admin_notices', $theirs ), 'Fixture: the foreign notice must be registered first.' );

		$this->suppress_on_hub_screen();

		// Asserted on THIS callback, not on the hook being empty: BuddyNext
		// registers its own nine notices at boot and they are supposed to survive,
		// so an empty-hook assertion could never have passed.
		$this->assertFalse(
			has_action( 'admin_notices', $theirs ),
			'A foreign notice survived on a Hub screen; the settings UI is back to being crowded.'
		);
	}

	/**
	 * Off a Hub screen, nothing is touched at all.
	 *
	 * @return void
	 */
	public function test_nothing_is_suppressed_outside_a_hub_screen(): void {
		add_action( 'admin_notices', '__return_false' );

		set_current_screen( 'dashboard' );
		( new AdminHub() )->suppress_foreign_admin_notices();

		$this->assertNotFalse(
			has_action( 'admin_notices', '__return_false' ),
			'The suppressor reached beyond Hub screens and stripped notices site-wide.'
		);
	}
}
