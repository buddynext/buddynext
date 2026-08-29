<?php
/**
 * Who wins when several plugins can answer for the same member's face.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\AvatarService;

/**
 * Three plugins in this suite hook `pre_get_avatar_data`, and the answer has to be
 * the same on every surface WordPress renders an avatar - the rail, the member
 * directory, a comment, the admin user list, a REST payload. Two rules decide it:
 *
 *   1. A member's BuddyNext upload outranks a sibling plugin's (owner directive,
 *      2026-08-29). BuddyNext is the account's home in this suite.
 *   2. A placeholder from us - the configured default image, or generated initials
 *      - never outranks anyone's real photograph.
 *
 * Both were true only by accident before. Rule 1 held because BuddyNext boots at
 * plugins_loaded:15 and so happened to be the last of three callbacks registered at
 * priority 10; a sibling that moved its boot would have flipped it silently. Rule 2
 * held for initials (moved to priority 99 in 49552a60) but NOT for the configured
 * default image, which was still overwriting a real photo from a sibling.
 *
 * These tests assert the ORDER, not the implementation, so the next person to touch
 * a priority finds out from a named failure.
 *
 * @covers \BuddyNext\Profile\AvatarService::filter_avatar_data
 * @covers \BuddyNext\Profile\AvatarService::filter_avatar_fallback
 */
class AvatarPrecedenceTest extends \WP_UnitTestCase {

	private int $member;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		( new AvatarService() )->init();
		$this->member = self::factory()->user->create();
	}

	/**
	 * Stand in for a sibling plugin answering at the priority they all use.
	 *
	 * @param string $url URL the sibling would supply.
	 * @return void
	 */
	private function sibling_plugin_supplies( string $url ): void {
		add_filter(
			'pre_get_avatar_data',
			static function ( array $args ) use ( $url ): array {
				$args['url']          = $url;
				$args['found_avatar'] = true;
				return $args;
			},
			10,
			2
		);
	}

	public function test_a_buddynext_upload_outranks_a_sibling_plugins_avatar(): void {
		$this->sibling_plugin_supplies( 'https://example.test/sibling.jpg' );
		update_user_meta( $this->member, 'bn_avatar', 'https://example.test/buddynext.webp' );

		$this->assertStringContainsString(
			'buddynext.webp',
			(string) get_avatar_url( $this->member ),
			'BuddyNext is the account home in this suite - its upload wins.'
		);
	}

	public function test_a_configured_default_image_does_not_outrank_a_real_photo(): void {
		update_option( 'bn_avatar_style', 'default_image' );
		update_option( 'bn_default_avatar_url', 'https://example.test/site-placeholder.png' );
		$this->sibling_plugin_supplies( 'https://example.test/real-photo.jpg' );

		$this->assertStringContainsString(
			'real-photo.jpg',
			(string) get_avatar_url( $this->member ),
			'A site-wide placeholder must never replace a member\'s actual photograph.'
		);
	}

	public function test_generated_initials_do_not_outrank_a_real_photo(): void {
		update_option( 'bn_avatar_style', 'initials' );
		$this->sibling_plugin_supplies( 'https://example.test/real-photo.jpg' );

		$this->assertStringContainsString( 'real-photo.jpg', (string) get_avatar_url( $this->member ) );
	}

	public function test_the_default_image_still_shows_when_nobody_has_a_photo(): void {
		update_option( 'bn_avatar_style', 'default_image' );
		update_option( 'bn_default_avatar_url', 'https://example.test/site-placeholder.png' );

		$this->assertStringContainsString( 'site-placeholder.png', (string) get_avatar_url( $this->member ) );
	}

	public function test_initials_still_show_when_nobody_has_a_photo(): void {
		update_option( 'bn_avatar_style', 'initials' );

		$this->assertStringContainsString( 'data:image/svg', (string) get_avatar_url( $this->member ) );
	}

	public function test_the_upload_runs_before_our_placeholders(): void {
		global $wp_filter;

		$priorities = array();
		foreach ( ( $wp_filter['pre_get_avatar_data']->callbacks ?? array() ) as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$object = $callback['function'][0] ?? null;
				if ( $object instanceof AvatarService ) {
					$priorities[ (string) ( $callback['function'][1] ?? '' ) ] = (int) $priority;
				}
			}
		}

		$this->assertArrayHasKey( 'filter_avatar_data', $priorities );
		$this->assertArrayHasKey( 'filter_avatar_fallback', $priorities );
		$this->assertGreaterThan(
			10,
			$priorities['filter_avatar_data'],
			'The upload must run AFTER the priority siblings use, or precedence is decided by plugin load order.'
		);
		$this->assertGreaterThan(
			$priorities['filter_avatar_data'],
			$priorities['filter_avatar_fallback'],
			'Our placeholders must run after our real avatar, and after everyone else.'
		);
	}
}
