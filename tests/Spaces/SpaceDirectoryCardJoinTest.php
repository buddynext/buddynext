<?php
/**
 * Directory card join action per join method.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Invite-only spaces offer no join request (card 10370626192): a site admin sees
 * hidden spaces in the directory, and was offered "Request to join" on them.
 */
class SpaceDirectoryCardJoinTest extends WP_UnitTestCase {

	/**
	 * Render the card for a space of the given type, viewed by a non-member admin.
	 *
	 * @param string $type Space type.
	 * @return string
	 */
	private function card_for( string $type ): string {
		Installer::run();
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id    = buddynext_service( 'spaces' )->create(
			$owner,
			array(
				'name' => ucfirst( $type ) . ' card',
				'slug' => $type . '-card-' . wp_rand( 1000, 9999 ),
				'type' => $type,
			)
		);
		$this->assertIsInt( $id );
		wp_set_current_user( $admin );

		ob_start();
		buddynext_get_template(
			'parts/space-directory-card.php',
			array(
				'space'           => (array) buddynext_service( 'spaces' )->get( (int) $id ),
				'membership'      => null,
				'current_user_id' => $admin,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Each join method gets its own action; invite-only gets none.
	 *
	 * @return void
	 */
	public function test_join_action_follows_the_join_method(): void {
		$secret = $this->card_for( 'secret' );
		$this->assertStringContainsString( 'data-join-method="invite"', $secret );
		$this->assertStringNotContainsString( 'actions.requestJoin', $secret, 'Invite-only: no join request.' );
		$this->assertStringNotContainsString( 'actions.joinSpace', $secret );

		$this->assertStringContainsString( 'actions.requestJoin', $this->card_for( 'private' ) );
		$this->assertStringContainsString( 'actions.joinSpace', $this->card_for( 'open' ) );
	}
}
