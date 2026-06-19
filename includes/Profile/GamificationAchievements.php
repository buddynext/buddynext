<?php
/**
 * Gamification "Achievements" profile tab.
 *
 * Gamification is a CORE integration, so it earns its own prominent profile tab
 * (like Discussions) rather than folding into the Pro Portfolio panel. The tab
 * shows the member's earned badges as a credential grid (credential badges
 * first) plus a standing strip (points / level / streak), and links each badge
 * to its public share page.
 *
 * Read-only: wb-gamification owns every value (`wb_gam_*` functions). The tab is
 * data-gated — it only appears once the member has a badge or any points, so a
 * brand-new member never sees an empty Achievements tab. Degrades cleanly to
 * nothing when wb-gamification is inactive.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

/**
 * Renders the Achievements profile tab from wb-gamification data.
 */
class GamificationAchievements {

	private const TAB_SLUG   = 'achievements';
	private const MAX_BADGES = 24;

	/**
	 * Wire the tab + panel hooks. Called from Plugin bridge-loading.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'wb_gam_get_user_badges' ) ) {
			return;
		}
		add_action( 'buddynext_register_nav', array( $this, 'register_nav' ) );
		add_action( 'buddynext_part_profile_tab_panel_after', array( $this, 'render_panel' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Load the Achievements styles on profile views only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( is_admin() || 'people' !== (string) get_query_var( 'bn_hub', '' ) ) {
			return;
		}
		$ver = defined( 'BUDDYNEXT_VERSION' ) ? BUDDYNEXT_VERSION : false;
		wp_enqueue_style(
			'bn-achievements',
			BUDDYNEXT_URL . 'assets/css/achievements.css',
			array(),
			$ver
		);
	}

	/**
	 * Register the Achievements tab on the member-profile nav surface.
	 *
	 * Hooked on `buddynext_register_nav`. Gated to members with standing (any
	 * badge or points) via a lazy condition, with a lazy badge-count badge.
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
				'label'     => __( 'Achievements', 'buddynext' ),
				'tab'       => self::TAB_SLUG,
				'icon'      => 'award',
				'priority'  => 70,
				'condition' => fn( \BuddyNext\Nav\NavContext $c ): bool => $this->has_standing( $c->subject_id ),
				'count'     => fn( \BuddyNext\Nav\NavContext $c ): int => count( $this->badges( $c->subject_id ) ),
			)
		);
	}

	/**
	 * Render the Achievements panel (hidden until the tab is active).
	 *
	 * @param array<string,mixed> $args Profile tab-panel args.
	 * @return void
	 */
	public function render_panel( array $args ): void {
		$member_id = (int) ( $args['profile_user_id'] ?? 0 );
		if ( $member_id <= 0 || ! $this->has_standing( $member_id ) ) {
			return;
		}

		// Reactive reveal: always in the DOM, shown when activeTab === slug
		// (Interactivity), matching every other profile panel — so the reactive
		// Achievements tab reveals it without a reload.
		$active   = (string) ( $args['active_tab'] ?? '' );
		$ctx_attr = esc_attr( (string) wp_json_encode( array( 'tabSlug' => self::TAB_SLUG ) ) );

		printf(
			'<div class="bn-profile-tab-panel bn-achievements" data-tab-panel="%1$s" data-wp-context=\'%2$s\' data-wp-bind--hidden="!state.isActiveTab"%3$s>',
			esc_attr( self::TAB_SLUG ),
			$ctx_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			self::TAB_SLUG === $active ? '' : ' hidden'
		);
		$this->render_standing( $member_id );
		$this->render_badges( $member_id );
		buddynext_profile_tab_panel_close();
	}

	/**
	 * Whether the member has any gamification standing (a badge or any points).
	 *
	 * @param int $member_id Member.
	 * @return bool
	 */
	private function has_standing( int $member_id ): bool {
		if ( ! empty( $this->badges( $member_id ) ) ) {
			return true;
		}
		return function_exists( 'wb_gam_get_user_points' ) && (int) wb_gam_get_user_points( $member_id ) > 0;
	}

	/**
	 * Earned badges, credential badges first.
	 *
	 * @param int $member_id Member.
	 * @return array<int,array<string,mixed>>
	 */
	private function badges( int $member_id ): array {
		$badges = (array) wb_gam_get_user_badges( $member_id );

		// Stable sort: credentials before participation badges, original order kept.
		usort(
			$badges,
			static function ( $a, $b ): int {
				return (int) ! empty( $b['is_credential'] ) <=> (int) ! empty( $a['is_credential'] );
			}
		);

		return array_slice( $badges, 0, self::MAX_BADGES );
	}

	/**
	 * Render the standing strip: points · level · current streak.
	 *
	 * @param int $member_id Member.
	 * @return void
	 */
	private function render_standing( int $member_id ): void {
		$tiles = array();

		if ( function_exists( 'wb_gam_get_user_points' ) ) {
			$tiles[] = array(
				'label' => __( 'Points', 'buddynext' ),
				'value' => number_format_i18n( (int) wb_gam_get_user_points( $member_id ) ),
			);
		}
		$rank = $this->leaderboard_rank( $member_id );
		if ( $rank > 0 ) {
			$tiles[] = array(
				'label' => __( 'Rank', 'buddynext' ),
				'value' => '#' . number_format_i18n( $rank ),
			);
		}

		if ( function_exists( 'wb_gam_get_user_level' ) ) {
			$level = wb_gam_get_user_level( $member_id );
			if ( is_array( $level ) && ! empty( $level['name'] ) ) {
				$tiles[] = array(
					'label' => __( 'Level', 'buddynext' ),
					'value' => (string) $level['name'],
				);
			}
		}
		if ( function_exists( 'wb_gam_get_user_streak' ) ) {
			$streak  = wb_gam_get_user_streak( $member_id );
			$current = (int) ( $streak['current_streak'] ?? 0 );
			if ( $current > 0 ) {
				$tiles[] = array(
					'label' => __( 'Day streak', 'buddynext' ),
					'value' => number_format_i18n( $current ),
				);
			}
		}

		if ( empty( $tiles ) ) {
			return;
		}

		echo '<div class="bn-achievements__standing">';
		foreach ( $tiles as $tile ) {
			echo '<div class="bn-achievements__stat">';
			echo '<span class="bn-achievements__stat-value">' . esc_html( (string) $tile['value'] ) . '</span>';
			echo '<span class="bn-achievements__stat-label">' . esc_html( (string) $tile['label'] ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the badge grid + the leaderboard CTA.
	 *
	 * @param int $member_id Member.
	 * @return void
	 */
	private function render_badges( int $member_id ): void {
		$badges = $this->badges( $member_id );

		echo '<div class="bn-card bn-achievements__panel">';
		echo '<header class="bn-achievements__head">';
		echo '<h3 class="bn-achievements__title">';
		if ( function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( 'award' );
		}
		echo ' ' . esc_html__( 'Badges', 'buddynext' );
		if ( ! empty( $badges ) ) {
			echo ' <span class="bn-achievements__count">' . esc_html( number_format_i18n( count( $badges ) ) ) . '</span>';
		}
		echo '</h3>';

		$hub = $this->hub_url();
		if ( '' !== $hub ) {
			echo '<a class="bn-link bn-achievements__cta" href="' . esc_url( $hub ) . '">' . esc_html__( 'View leaderboard', 'buddynext' ) . '</a>';
		}
		echo '</header>';

		if ( empty( $badges ) ) {
			echo '<p class="bn-achievements__empty">' . esc_html__( 'No badges earned yet — keep contributing to unlock them.', 'buddynext' ) . '</p>';
			echo '</div>';
			return;
		}

		$date_format = (string) get_option( 'date_format', 'M j, Y' );

		echo '<ul class="bn-achievements__grid" role="list">';
		foreach ( $badges as $badge ) {
			$id    = isset( $badge['id'] ) ? (string) $badge['id'] : '';
			$name  = isset( $badge['name'] ) ? (string) $badge['name'] : '';
			$image = isset( $badge['image_url'] ) ? (string) $badge['image_url'] : '';
			$is_cr = ! empty( $badge['is_credential'] );
			$when  = ! empty( $badge['earned_at'] ) ? date_i18n( $date_format, (int) strtotime( (string) $badge['earned_at'] ) ) : '';
			$url   = '' !== $id ? $this->badge_share_url( $id, $member_id ) : '';

			echo '<li class="bn-achievements__badge' . ( $is_cr ? ' is-credential' : '' ) . '">';
			if ( '' !== $url ) {
				echo '<a class="bn-achievements__badge-link" href="' . esc_url( $url ) . '">';
			} else {
				echo '<div class="bn-achievements__badge-link">';
			}

			echo '<span class="bn-achievements__badge-medal">';
			if ( '' !== $image ) {
				echo '<img src="' . esc_url( $image ) . '" alt="" loading="lazy" />';
			} elseif ( function_exists( 'buddynext_icon' ) ) {
				buddynext_icon( 'award' );
			}
			echo '</span>';

			echo '<span class="bn-achievements__badge-name">' . esc_html( $name ) . '</span>';
			if ( '' !== $when ) {
				echo '<span class="bn-achievements__badge-date">' . esc_html( $when ) . '</span>';
			}

			echo '' !== $url ? '</a>' : '</div>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * The member's leaderboard rank (all-time), or 0 when unavailable.
	 *
	 * Rank has no `wb_gam_*` wrapper, so it reads the engine directly — guarded so
	 * it no-ops when WB Gamification is absent. Returns the `rank` from
	 * LeaderboardEngine::get_user_rank()'s `{rank, points, points_to_next}` result.
	 *
	 * @param int $member_id Member.
	 * @return int
	 */
	private function leaderboard_rank( int $member_id ): int {
		if ( ! is_callable( array( '\WBGam\Engine\LeaderboardEngine', 'get_user_rank' ) ) ) {
			return 0;
		}
		$data = \WBGam\Engine\LeaderboardEngine::get_user_rank( $member_id, 'all' );
		return is_array( $data ) && isset( $data['rank'] ) ? (int) $data['rank'] : 0;
	}

	/**
	 * The wb-gamification hub page URL (leaderboard), or '' when unset.
	 *
	 * @return string
	 */
	private function hub_url(): string {
		$page_id = (int) get_option( 'wb_gam_hub_page_id', 0 );
		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		return (string) get_permalink( $page_id );
	}

	/**
	 * Public share URL for a badge.
	 *
	 * Defers to WB Gamification's canonical `\WBGam\Engine\BadgeSharePage::get_share_url()`
	 * so the link can never drift from the plugin's own share-page rewrite. The
	 * hand-built fallback (`gamification/badge/{id}/{uid}/share/`) only runs on an
	 * older WB Gamification that predates the helper.
	 *
	 * @param string $badge_id Badge slug.
	 * @param int    $user_id  Member.
	 * @return string
	 */
	private function badge_share_url( string $badge_id, int $user_id ): string {
		if ( is_callable( array( '\WBGam\Engine\BadgeSharePage', 'get_share_url' ) ) ) {
			return (string) \WBGam\Engine\BadgeSharePage::get_share_url( $badge_id, $user_id );
		}
		return home_url( 'gamification/badge/' . $badge_id . '/' . $user_id . '/share/' );
	}
}
