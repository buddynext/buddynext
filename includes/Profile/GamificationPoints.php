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
				'label'     => __( 'Points', 'buddynext' ),
				'icon'      => 'zap',
				'priority'  => 72,
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
		echo '<span class="bn-gam-points__total-label">' . esc_html__( 'Total points', 'buddynext' ) . '</span>';
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
		$rows = is_callable( array( '\WBGam\Engine\PointsEngine', 'get_history' ) )
			? \WBGam\Engine\PointsEngine::get_history( $member_id, 20 )
			: array();

		echo '<div class="bn-card bn-gam-points__panel">';
		echo '<div class="bn-widget-title">';
		if ( function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( 'zap' );
		}
		echo ' ' . esc_html__( 'Your recent activity', 'buddynext' );
		echo '</div>';

		if ( empty( $rows ) ) {
			echo '<p class="bn-achievements__empty">' . esc_html__( 'No points yet — start contributing to earn your first points.', 'buddynext' ) . '</p>';
			echo '</div>';
			return;
		}

		$labels = $this->action_labels();

		echo '<ul class="bn-gam-ledger" role="list">';
		foreach ( $rows as $row ) {
			$action = isset( $row['action_id'] ) ? (string) $row['action_id'] : '';
			$label  = $labels[ $action ] ?? $this->humanize( $action );
			$points = (int) ( $row['points'] ?? 0 );
			$when   = ! empty( $row['created_at'] ) ? (int) strtotime( (string) $row['created_at'] ) : 0;

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
					/* translators: %s: signed point amount, e.g. "+10". */
					__( '%s pts', 'buddynext' ),
					( $points >= 0 ? '+' : '' ) . number_format_i18n( $points )
				)
			) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * "How to earn" — enabled actions grouped by category with points + limits.
	 *
	 * @return void
	 */
	private function render_earn_guide(): void {
		$grouped = $this->earning_guide();

		echo '<div class="bn-card bn-gam-points__panel">';
		echo '<div class="bn-widget-title">';
		if ( function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( 'target' );
		}
		echo ' ' . esc_html__( 'How to earn points', 'buddynext' );
		echo '</div>';

		if ( empty( $grouped ) ) {
			echo '<p class="bn-achievements__empty">' . esc_html__( 'No earning opportunities are enabled yet.', 'buddynext' ) . '</p>';
			echo '</div>';
			return;
		}

		foreach ( $grouped as $category => $actions ) {
			echo '<h4 class="bn-gam-earn__cat">' . esc_html( $this->humanize( (string) $category ) ) . '</h4>';
			echo '<ul class="bn-gam-earn__grid" role="list">';
			foreach ( $actions as $action ) {
				echo '<li class="bn-gam-earn__item">';
				echo '<span class="bn-gam-earn__label">' . esc_html( (string) $action['label'] ) . '</span>';
				$meta = $this->limit_text( (int) $action['cooldown'], (int) $action['daily_cap'] );
				if ( '' !== $meta ) {
					echo '<span class="bn-gam-earn__meta">' . esc_html( $meta ) . '</span>';
				}
				echo '<span class="bn-gam-earn__pts">' . esc_html(
					sprintf(
						/* translators: %s: point amount, e.g. "+10". */
						__( '+%s pts', 'buddynext' ),
						number_format_i18n( (int) $action['points'] )
					)
				) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Action id → human label map from the registry (one build per request).
	 *
	 * @return array<string,string>
	 */
	private function action_labels(): array {
		$map     = array();
		$actions = function_exists( 'wb_gam_get_actions' ) ? (array) wb_gam_get_actions() : array();
		foreach ( $actions as $id => $action ) {
			$map[ (string) $id ] = (string) ( $action['label'] ?? $id );
		}
		return $map;
	}

	/**
	 * Enabled earning actions grouped by category, with the admin-resolved points.
	 *
	 * @return array<string,array<int,array{label:string,points:int,cooldown:int,daily_cap:int}>>
	 */
	private function earning_guide(): array {
		$actions = function_exists( 'wb_gam_get_actions' ) ? (array) wb_gam_get_actions() : array();
		$grouped = array();

		foreach ( $actions as $id => $action ) {
			if ( ! (bool) get_option( 'wb_gam_enabled_' . $id, true ) ) {
				continue;
			}
			$points = (int) get_option( 'wb_gam_points_' . $id, $action['default_points'] ?? 0 );
			if ( $points <= 0 ) {
				continue;
			}
			$category = (string) ( $action['category'] ?? 'general' );

			$grouped[ $category ][] = array(
				'label'     => (string) ( $action['label'] ?? $id ),
				'points'    => $points,
				'cooldown'  => (int) ( $action['cooldown'] ?? 0 ),
				'daily_cap' => (int) ( $action['daily_cap'] ?? 0 ),
			);
		}

		return $grouped;
	}

	/**
	 * Human-readable limit hint for an action ("max 10/day", "1m cooldown").
	 *
	 * @param int $cooldown  Cooldown seconds (0 = none).
	 * @param int $daily_cap Max awards per day (0 = unlimited).
	 * @return string
	 */
	private function limit_text( int $cooldown, int $daily_cap ): string {
		$parts = array();
		if ( $daily_cap > 0 ) {
			/* translators: %d: maximum number of times per day. */
			$parts[] = sprintf( __( 'max %d/day', 'buddynext' ), $daily_cap );
		}
		if ( $cooldown > 0 ) {
			$mins = max( 1, (int) round( $cooldown / 60 ) );
			/* translators: %d: cooldown in minutes. */
			$parts[] = sprintf( _n( '%d min cooldown', '%d min cooldown', $mins, 'buddynext' ), $mins );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Title-case a slug for display ("bn_profile_updated" → "Bn Profile Updated").
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private function humanize( string $slug ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
