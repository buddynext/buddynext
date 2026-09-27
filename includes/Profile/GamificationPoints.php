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
	 * Recent point ledger — labelled + timestamped, newest first.
	 *
	 * @param int $member_id Member.
	 * @return void
	 */
	private function render_history( int $member_id ): void {
		$rows = function_exists( 'wb_gam_get_points_history' )
			? wb_gam_get_points_history( $member_id, 20 )
			: array();

		echo '<div class="bn-card bn-gam-points__panel">';
		echo '<div class="bn-widget-title">';
		if ( function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( 'zap' );
		}
		echo ' ' . esc_html__( 'Your recent activity', 'buddynext' );
		echo '</div>';

		if ( empty( $rows ) ) {
			echo '<p class="bn-achievements__empty">' . esc_html( sprintf( /* translators: %s: the site's name for points. */ __( 'No %s yet: start contributing to earn your first.', 'buddynext' ), \BuddyNext\Bridges\GamificationBridge::points_label() ) ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<ul class="bn-gam-ledger" role="list">';
		foreach ( $rows as $row ) {
			$action = isset( $row['action_id'] ) ? (string) $row['action_id'] : '';
			// WB Gamification names every award (registered or not) the same way its
			// toast does (card 10344428395).
			$label  = function_exists( 'wb_gam_get_action_label' ) ? wb_gam_get_action_label( $action ) : $this->humanize( $action );
			$points = (int) ( $row['points'] ?? 0 );
			$when   = ! empty( $row['created_at'] ) ? \BuddyNext\Core\Dates::utc_timestamp( (string) $row['created_at'] ) : 0;

			echo '<li class="bn-gam-ledger__row">';
			echo '<span class="bn-gam-ledger__icon" aria-hidden="true">';
			if ( function_exists( 'buddynext_icon' ) ) {
				buddynext_icon( 'sparkles' );
			}
			echo '</span>';
			echo '<span class="bn-gam-ledger__body">';
			echo '<span class="bn-gam-ledger__label">' . esc_html( $label ) . '</span>';
			if ( $when > 0 ) {
				echo '<span class="bn-gam-ledger__time">' . esc_html(
					sprintf(
						/* translators: %s: human-readable time difference, e.g. "3 hours". */
						__( '%s ago', 'buddynext' ),
						human_time_diff( $when )
					)
				) . '</span>';
			}
			echo '</span>';
			echo '<span class="bn-gam-ledger__points' . ( $points < 0 ? ' is-negative' : '' ) . '">' . esc_html(
				sprintf(
					/* translators: 1: signed amount, e.g. "+10"; 2: the site's name for points. */
					__( '%1$s %2$s', 'buddynext' ),
					( $points >= 0 ? '+' : '' ) . number_format_i18n( $points ),
					\BuddyNext\Bridges\GamificationBridge::points_label()
				)
			) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
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

	/**
	 * Title-case an action id for display when WB Gamification is too old to label it.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private function humanize( string $slug ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
