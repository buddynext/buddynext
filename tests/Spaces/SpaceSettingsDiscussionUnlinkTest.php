<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Only the space owner is offered Unlink on the linked discussion.
 *
 * @package BuddyNext\Tests\Spaces
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use WP_UnitTestCase;

/**
 * A space moderator saw a live Unlink button that the discussion route refuses
 * with a 403. The toggles beside it were already owner-only; the button was not.
 *
 * @coversNothing
 */
class SpaceSettingsDiscussionUnlinkTest extends WP_UnitTestCase {

	/**
	 * Render the integrations panel for a space with a linked discussion.
	 *
	 * @param bool $is_owner Whether the viewer owns the space.
	 * @return string
	 */
	private function render( bool $is_owner ): string {
		ob_start();
		buddynext_get_template(
			'parts/space-settings-panel-integrations.php',
			array(
				'space'                 => (object) array(
					'id'   => 7,
					'name' => 'Remote Makers',
				),
				'integrations_settings' => array(
					'discussion_status' => array(
						'has_discussion' => true,
						'enabled'        => true,
						'forum_id'       => 3,
						'name'           => 'Remote Makers talk',
						'url'            => 'https://example.org/forum/remote-makers/',
					),
				),
				'is_space_owner'        => $is_owner,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * A moderator sees which discussion is linked but no Unlink control.
	 *
	 * @return void
	 */
	public function test_a_moderator_gets_no_unlink_button(): void {
		$html = $this->render( false );

		$this->assertStringContainsString( 'Remote Makers talk', $html, 'The linked discussion is still named.' );
		$this->assertStringNotContainsString( 'actions.unlinkDiscussion', $html );
	}

	/**
	 * The owner keeps Unlink.
	 *
	 * @return void
	 */
	public function test_the_owner_keeps_the_unlink_button(): void {
		$this->assertStringContainsString( 'actions.unlinkDiscussion', $this->render( true ) );
	}
}
