<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- concise, self-describing test methods.
/**
 * Tests for OnlineMembersWidget — shows actually-online members.
 *
 * @package BuddyNext\Tests\Widgets
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Widgets;

use BuddyNext\Realtime\PresenceService;
use BuddyNext\Widgets\OnlineMembersWidget;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Widgets\OnlineMembersWidget
 */
class OnlineMembersWidgetTest extends WP_UnitTestCase {

	private array $args = array(
		'before_widget' => '<section>',
		'after_widget'  => '</section>',
		'before_title'  => '<h2>',
		'after_title'   => '</h2>',
	);

	public function set_up(): void {
		parent::set_up();
		\BuddyNext\Core\Installer::run();
		wp_cache_flush();
	}

	private function render( int $limit = 5 ): string {
		$widget = new OnlineMembersWidget();
		ob_start();
		$widget->widget(
			$this->args,
			array(
				'title' => 'Online',
				'limit' => $limit,
			)
		);
		return (string) ob_get_clean();
	}

	public function test_lists_online_members_not_newest(): void {
		// An online member and an offline (registered but no presence) member.
		$online  = self::factory()->user->create( array( 'display_name' => 'Online Olive' ) );
		$offline = self::factory()->user->create( array( 'display_name' => 'Offline Oscar' ) );
		PresenceService::write( $online, time() - 5 );

		$html = $this->render();

		$this->assertStringContainsString( 'Online Olive', $html, 'Online member is listed.' );
		$this->assertStringNotContainsString( 'Offline Oscar', $html, 'Offline member is not listed (this is online, not newest).' );
	}

	public function test_empty_state_when_nobody_online(): void {
		// A registered user with no presence row.
		self::factory()->user->create();

		$html = $this->render();

		$this->assertStringContainsString( 'No members are online right now.', $html );
		$this->assertStringNotContainsString( '<ul', $html, 'No list is rendered when empty.' );
	}
}
