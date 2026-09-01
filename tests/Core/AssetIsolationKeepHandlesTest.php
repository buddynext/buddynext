<?php
/**
 * AssetIsolation honours a partner's declared keep-list.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\AssetIsolation;
use WP_UnitTestCase;

/**
 * MediaVerse stands its own UI down on BuddyNext routes and exempts the handles
 * it still needs via `mvs_frontend_presence_keep_handles`. That exemption could
 * not work, because MediaVerse sweeps at wp_enqueue_scripts@PHP_INT_MAX and this
 * pass runs at 9999 - BuddyNext dequeued the handles first, and a keep-list can
 * only decline to dequeue, never restore. On a BuddyNext + BuddyPress install
 * that left MediaVerse markup rendering with no MediaVerse CSS.
 */
class AssetIsolationKeepHandlesTest extends WP_UnitTestCase {

	/**
	 * Enqueue a style whose source is outside every allowed prefix.
	 *
	 * @param string $handle Handle.
	 * @return void
	 */
	private function enqueue_foreign_style( string $handle ): void {
		wp_register_style( $handle, 'http://example.org/wp-content/plugins/wpmediaverse/assets/css/frontend.css', array(), '1' );
		wp_enqueue_style( $handle );
	}

	/**
	 * Run the isolation pass as if on a BuddyNext hub route.
	 *
	 * @return void
	 */
	private function isolate(): void {
		$iso = new AssetIsolation();
		$run = new \ReflectionMethod( $iso, 'isolate_classic' );
		$run->setAccessible( true );

		$prefixes = new \ReflectionMethod( $iso, 'allowed_prefixes' );
		$prefixes->setAccessible( true );

		$keep = new \ReflectionMethod( $iso, 'partner_keep_handles' );
		$keep->setAccessible( true );

		$run->invoke( $iso, wp_styles(), $prefixes->invoke( $iso ), 'wp_dequeue_style', $keep->invoke( $iso ) );
	}

	/**
	 * Without a declared keep-list, a foreign asset is still stripped. This is
	 * the behaviour the whole feature exists for and must not regress.
	 *
	 * @return void
	 */
	public function test_a_foreign_asset_is_still_stripped(): void {
		$this->enqueue_foreign_style( 'mvs-frontend' );

		$this->isolate();

		$this->assertFalse( wp_style_is( 'mvs-frontend', 'enqueued' ) );
	}

	/**
	 * A handle the partner declared it needs survives.
	 *
	 * @return void
	 */
	public function test_a_declared_handle_survives(): void {
		$this->enqueue_foreign_style( 'mvs-frontend' );
		add_filter(
			'mvs_frontend_presence_keep_handles',
			static fn( $handles ): array => array_merge( (array) $handles, array( 'mvs-frontend' ) )
		);

		$this->isolate();

		$this->assertTrue( wp_style_is( 'mvs-frontend', 'enqueued' ) );
	}

	/**
	 * Declaring one handle does not spare the rest of the partner's bundle. The
	 * objection to the blanket fix was weight: allowing a whole plugin's assets
	 * on every BuddyNext route was measured to add real cost for markup that is
	 * not on the page.
	 *
	 * @return void
	 */
	public function test_only_the_declared_handle_survives(): void {
		$this->enqueue_foreign_style( 'mvs-frontend' );
		$this->enqueue_foreign_style( 'mvs-gamification' );
		add_filter(
			'mvs_frontend_presence_keep_handles',
			static fn( $handles ): array => array_merge( (array) $handles, array( 'mvs-frontend' ) )
		);

		$this->isolate();

		$this->assertTrue( wp_style_is( 'mvs-frontend', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'mvs-gamification', 'enqueued' ) );
	}

	/**
	 * With no partner registering the filter — the BuddyNext-only install, which
	 * is the overwhelmingly common case — the list is empty and nothing changes.
	 *
	 * @return void
	 */
	public function test_no_partner_filter_means_no_keep_list(): void {
		$iso  = new AssetIsolation();
		$keep = new \ReflectionMethod( $iso, 'partner_keep_handles' );
		$keep->setAccessible( true );

		$this->assertSame( array(), $keep->invoke( $iso ) );
	}

	/**
	 * A partner returning something that is not an array cannot break the pass.
	 *
	 * @return void
	 */
	public function test_a_non_array_keep_list_is_ignored(): void {
		add_filter( 'mvs_frontend_presence_keep_handles', static fn(): string => 'nonsense' );

		$iso  = new AssetIsolation();
		$keep = new \ReflectionMethod( $iso, 'partner_keep_handles' );
		$keep->setAccessible( true );

		$this->assertSame( array(), $keep->invoke( $iso ) );
	}
}
