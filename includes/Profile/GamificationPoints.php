<?php
/**
 * Gamification "Points" profile tab.
 *
 * The member's personal points page, rendered in the BuddyNext shell so members
 * never have to visit wb-gamification's own /gamification/ hub:
 *
 *  - Your recent activity — the point ledger (PointsEngine::get_history), each row
 *    labelled from the action registry.
 *  - How to earn points — the enabled earning actions (Registry::get_actions),
 *    grouped by category with their point value + any cooldown / daily cap.
 *
 * Read-only: wb-gamification owns every value; BuddyNext only presents it. Own
 * profile only — a member's ledger is personal.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

/**
 * Renders the Points profile tab from wb-gamification data.
 */
class GamificationPoints {

	private const TAB_SLUG = 'points';

	/**
	 * Wire the tab. Called from Plugin bridge-loading (gamification only).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'wb_gam_get_user_points' ) ) {
			return;
		}
		add_action( 'buddynext_register_nav', array( $this, 'register_nav' ) );
	}

	/**
	 * Register the Points tab on the member-profile nav surface (own profile only).
	 *
	 * @param \BuddyNext\Nav\NavRegistry $registry The shared nav registry.
	 * @return void
	 */
	public function register_nav( \BuddyNext\Nav\NavRegistry $registry ): void {
		$registry->register(
			array(
				'id'        => self::TAB_SLUG,
				'surface'   => 'profile',
				'layer'     => 'primary',
				'parent'    => GamificationAchievements::PARENT_SLUG,
				'label'     => \BuddyNext\Bridges\GamificationBridge::points_label(),
				'icon'      => 'zap',
				'priority'  => 20,
				// Own profile only — a member's point ledger is personal.
				'condition' => static fn( \BuddyNext\Nav\NavContext $c ): bool =>
					buddynext_integration_enabled( 'gamification', 'nav' )
					&& $c->subject_id > 0
					&& get_current_user_id() === $c->subject_id,
				'url'       => static fn( \BuddyNext\Nav\NavContext $c ): string =>
					trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . self::TAB_SLUG . '/',
				'render'    => function ( \BuddyNext\Nav\NavContext $c ): void {
					$this->render_panel( $c->subject_id );
				},
			)
		);
	}

	/**
	 * Render the Points panel: a total, the recent ledger, and the earning guide.
	 *
	 * @param int $member_id Profile being viewed (must be the viewer).
	 * @return void
	 */
	public function render_panel( int $member_id ): void {
		if ( $member_id <= 0 || get_current_user_id() !== $member_id ) {
			return;
		}

		$total = function_exists( 'wb_gam_get_user_points' ) ? (int) wb_gam_get_user_points( $member_id ) : 0;

		echo '<div class="bn-gam-points">';

		echo '<div class="bn-card bn-gam-points__total">';
		echo '<span class="bn-gam-points__total-value">' . esc_html( number_format_i18n( $total ) ) . '</span>';
		echo '<span class="bn-gam-points__total-label">' . esc_html( sprintf( /* translators: %s: the site's name for points, e.g. "Points". */ __( 'Total %s', 'buddynext' ), \BuddyNext\Bridges\GamificationBridge::points_label() ) ) . '</span>';
		echo '</div>';

		$this->render_history( $member_id );
		$this->render_earn_guide();

		echo '</div>';
	}

	/**
	 * Recent point history: WB Gamification's own history block, inside
	 * BuddyNext's panel, so labels match its toasts (card 10344428395).
	 *
	 * @param int $member_id Member.
	 * @return void
	 */
	private function render_history( int $member_id ): void {
		$history = shortcode_exists( 'wb_gam_points_history' )
			? do_shortcode( sprintf( '[wb_gam_points_history user_id="%d" limit="20"]', $member_id ) )
			: '';

		echo '<div class="bn-card bn-gam-points__panel">';
		echo '<div class="bn-widget-title">';
		if ( function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( 'trending-up' );
		}
		echo ' ' . esc_html( sprintf( /* translators: %s: the site's name for points. */ __( '%s history', 'buddynext' ), \BuddyNext\Bridges\GamificationBridge::points_label() ) );
		echo '</div>';

		if ( '' === trim( wp_strip_all_tags( $history ) ) ) {
			echo '<p class="bn-achievements__empty">' . esc_html( sprintf( /* translators: %s: the site's name for points. */ __( 'No %s yet: start contributing to earn your first.', 'buddynext' ), \BuddyNext\Bridges\GamificationBridge::points_label() ) ) . '</p>';
		} else {
			echo $history; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wb-gamification block SSR, escaped at source.
		}
		echo '</div>';
	}

	/**
	 * "How to earn": WB Gamification's own earning guide, inside BuddyNext's panel.
	 *
	 * WB Gamification owns which actions are enabled, their points, limits and
	 * wording, so BuddyNext renders its guide rather than rebuilding it from the
	 * raw options (card 10344441205).
	 *
	 * @return void
	 */
	private function render_earn_guide(): void {
		echo '<div class="bn-card bn-gam-points__panel">';
		echo '<div class="bn-widget-title">';
		if ( function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( 'target' );
		}
		echo ' ' . esc_html( sprintf( /* translators: %s: the site's name for points. */ __( 'How to earn %s', 'buddynext' ), \BuddyNext\Bridges\GamificationBridge::points_label() ) );
		echo '</div>';
		echo do_shortcode( '[wb_gam_earning_guide]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wb-gamification block SSR, escaped at source.
		echo '</div>';
	}
}
