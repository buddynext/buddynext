<?php
/**
 * BuddyNext widget registration service.
 *
 * Registers three core WordPress sidebar widgets:
 *
 *   BuddyNext_Online_Members_Widget    — list of recently active members
 *   BuddyNext_Trending_Hashtags_Widget — trending hashtag cloud
 *   BuddyNext_Recent_Activity_Widget   — recent site-wide activity feed
 *
 * @package BuddyNext\Widgets
 */

declare( strict_types=1 );

namespace BuddyNext\Widgets;

/**
 * Handles registration of all BuddyNext sidebar widgets.
 */
class WidgetService {

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Attach registration hook.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'widgets_init', array( $this, 'register_widgets' ) );
	}

	/**
	 * Register all BuddyNext widgets with WordPress.
	 *
	 * @return void
	 */
	public function register_widgets(): void {
		register_widget( OnlineMembersWidget::class );
		register_widget( TrendingHashtagsWidget::class );
		register_widget( RecentActivityWidget::class );
	}
}
