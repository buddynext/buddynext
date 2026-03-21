<?php
/**
 * Tests that all BuddyNext abilities are registered via the WordPress Abilities API.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Abilities;

/**
 * @covers \BuddyNext\Core\Abilities
 */
class AbilitiesTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Abilities() )->register();
		// Fire the hook so wp_register_ability calls are executed.
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * @dataProvider ability_provider
	 */
	public function test_ability_is_registered( string $ability ): void {
		if ( ! function_exists( 'wp_is_registered_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API requires WP 6.9+.' );
		}

		$this->assertTrue(
			wp_is_registered_ability( $ability ),
			"Ability '{$ability}' should be registered."
		);
	}

	/**
	 * @return array<int, array{string}>
	 */
	public static function ability_provider(): array {
		return array_map(
			fn( string $a ) => array( $a ),
			array(
				'buddynext-profile/edit-own',
				'buddynext-profile/edit-any',
				'buddynext-profile/view',
				'buddynext-feed/create-post',
				'buddynext-feed/delete-own-post',
				'buddynext-feed/delete-any-post',
				'buddynext-feed/pin-post',
				'buddynext-feed/schedule-post',
				'buddynext-spaces/create',
				'buddynext-spaces/join',
				'buddynext-spaces/join-gated',
				'buddynext-spaces/post',
				'buddynext-spaces/moderate',
				'buddynext-spaces/manage-settings',
				'buddynext-spaces/delete',
				'buddynext-connections/follow',
				'buddynext-connections/connect',
				'buddynext-moderation/report',
				'buddynext-moderation/review-queue',
				'buddynext-moderation/issue-strike',
				'buddynext-moderation/suspend-user',
			)
		);
	}
}
