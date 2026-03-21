<?php
/**
 * Tests for the BuddyNext widget service.
 *
 * @package BuddyNext\Tests\Widgets
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Widgets;

use BuddyNext\Widgets\WidgetService;

/**
 * Verifies widget registration.
 *
 * @covers \BuddyNext\Widgets\WidgetService
 */
class WidgetServiceTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var WidgetService
	 */
	private WidgetService $service;

	/**
	 * Create a fresh instance before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->service = new WidgetService();
	}

	/**
	 * init() adds the widgets_init hook.
	 */
	public function test_init_adds_widgets_init_hook(): void {
		$this->service->init();
		$this->assertNotFalse(
			has_action( 'widgets_init', array( $this->service, 'register_widgets' ) )
		);
	}

	/**
	 * register_widgets() registers the online members widget.
	 */
	public function test_registers_online_members_widget(): void {
		$this->service->register_widgets();
		$this->assertTrue( array_key_exists( \BuddyNext\Widgets\OnlineMembersWidget::class, $GLOBALS['wp_widget_factory']->widgets ) );
	}

	/**
	 * register_widgets() registers the trending hashtags widget.
	 */
	public function test_registers_trending_hashtags_widget(): void {
		$this->service->register_widgets();
		$this->assertTrue( array_key_exists( \BuddyNext\Widgets\TrendingHashtagsWidget::class, $GLOBALS['wp_widget_factory']->widgets ) );
	}

	/**
	 * register_widgets() registers the recent activity widget.
	 */
	public function test_registers_recent_activity_widget(): void {
		$this->service->register_widgets();
		$this->assertTrue( array_key_exists( \BuddyNext\Widgets\RecentActivityWidget::class, $GLOBALS['wp_widget_factory']->widgets ) );
	}
}
