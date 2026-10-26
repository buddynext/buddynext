<?php
/**
 * The create-space form offers a parent only while sub-spaces are allowed.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

/**
 * @coversNothing
 */
class CreateSpaceModalParentTest extends \WP_UnitTestCase {

	/**
	 * Render the modal with one parent candidate.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		buddynext_get_template(
			'partials/create-space-modal.php',
			array(
				'categories'    => array(),
				'parent_spaces' => array( (object) array( 'id' => 5, 'name' => 'Parent probe' ) ),
			)
		);
		return (string) ob_get_clean();
	}

	public function test_parent_is_offered_while_sub_spaces_are_allowed(): void {
		update_option( 'buddynext_space_allow_sub', '1' );
		$this->assertStringContainsString( 'Parent probe', $this->render() );
	}

	public function test_parent_is_not_offered_when_sub_spaces_are_off(): void {
		update_option( 'buddynext_space_allow_sub', '0' );
		$this->assertStringNotContainsString( 'Parent probe', $this->render() );
	}
}
