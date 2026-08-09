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
	 * Parent primary-tab id that houses the three gamification sub-tabs
	 * (Achievements / Points / Kudos). GamificationPoints + GamificationKudos
	 * nest under this id via their `parent` so the trio occupies one top-level
	 * slot instead of three. Kept a literal on the child side to avoid a
	 * cross-class constant dependency.
	 */
	public const PARENT_SLUG = 'gamification';

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
		add_filter( 'buddynext_rail_items', array( $this, 'inject_leaderboard_nav_item' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		// Owner control: list on the integration registry so the owner can hide the
		// Achievements tab from BuddyNext → Integrations (default on).
		add_filter(
			'buddynext_integrations',
			static function ( array $items ): array {
				$items['gamification'] = array(
					'label'    => __( 'Gamification', 'buddynext' ),
					'version'  => defined( 'WB_GAM_VERSION' ) ? WB_GAM_VERSION : null,
					'has_nav'  => true,
					'has_feed' => true,
				);
				return $items;
			}
		);
		add_filter( 'buddynext_client_nav_deny', array( $this, 'add_nav_deny' ) );
	}

	/**
	 * WB Gamification surfaces (the leaderboard hub page + the public badge-share
	 * pages) render OUTSIDE BuddyNext's buddynext/main router region, so client-nav
	 * must FULL-LOAD them — otherwise an Achievements link (a shared badge, or the
	 * "View leaderboard" CTA) gets swapped into the BN region and breaks. Bases are
	 * resolved from the hub page permalink + the plugin's canonical share-URL
	 * builder, never hardcoded.
	 *
	 * @param array<string,string|string[]> $deny Accumulated deny-list.
	 * @return array<string,string|string[]>
	 */
	public function add_nav_deny( $deny ): array {
		$deny  = (array) $deny;
		$paths = array();

		$hub = $this->hub_url();
		if ( '' !== $hub ) {
			$path = (string) wp_parse_url( $hub, PHP_URL_PATH );
			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}

		// Badge share/verify pages live under WB Gamification's own rewrite base
		// (independent of the hub page slug). Derive it from the canonical builder
		// so it tracks the plugin, then trim to the leading segment as a prefix.
		if ( is_callable( array( '\WBGam\Engine\BadgeSharePage', 'get_share_url' ) ) ) {
			$share = (string) \WBGam\Engine\BadgeSharePage::get_share_url( '_', 0 );
			$spath = (string) wp_parse_url( $share, PHP_URL_PATH );
			$seg   = explode( '/', trim( $spath, '/' ) );
			if ( ! empty( $seg[0] ) ) {
				$paths[] = '/' . $seg[0] . '/';
			}
		}

		if ( ! empty( $paths ) ) {
			$deny['gamification'] = array_values( array_unique( $paths ) );
		}
		return $deny;
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
		// bn-base is where the --bn-* tokens this stylesheet authors against are
		// defined, so it must load first. It was not declared, which left the
		// order to chance rather than to the dependency graph.
		wp_enqueue_style(
			'bn-achievements',
			BUDDYNEXT_URL . 'assets/css/achievements.css',
			array( 'bn-base' ),
			$ver
		);
	}

	/**
	 * Add the community "Leaderboard" link to the left rail when gamification is on.
	 *
	 * Hooked on: buddynext_rail_items( array $items, string $hub ). The leaderboard is
	 * a community destination (like Explore), so it sits in the primary group, not the
	 * personal "You" group. Respects the owner's gamification-nav toggle. Without this,
	 * the leaderboard page existed but was unreachable from the shell — the tab was the
	 * only gamification surface wired into the nav.
	 *
	 * @param array<int, array<string,mixed>> $items Existing rail items.
	 * @return array<int, array<string,mixed>>
	 */
	public function inject_leaderboard_nav_item( array $items ): array {
		if ( ! buddynext_integration_enabled( 'gamification', 'nav' ) ) {
			return $items;
		}

		$url  = \BuddyNext\Core\PageRouter::leaderboard_url();
		$path = rtrim( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' ), '/' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$is_active   = '' !== $path && str_starts_with( rtrim( $request_uri, '/' ), $path );

		$items[] = array(
			'key'    => 'leaderboard',
			'label'  => __( 'Leaderboard', 'buddynext' ),
			'url'    => $url,
			'icon'   => 'award',
			'show'   => true,
			'active' => $is_active,
			'group'  => 'primary',
			'order'  => 95,
		);

		return $items;
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
		// Parent container: one "Achievements" top-level tab that houses the
		// gamification sub-tabs so Achievements / Points / Kudos don't each take a
		// primary slot. Owns no panel — landing deep-links to the first available
		// child (Achievements when the member has standing, else Kudos, which is
		// always present). Shown whenever gamification nav is on (Kudos guarantees
		// at least one child, so the parent never renders empty).
		$registry->register(
			array(
				'id'        => self::PARENT_SLUG,
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Achievements', 'buddynext' ),
				'priority'  => 70,
				'condition' => static fn( \BuddyNext\Nav\NavContext $c ): bool => buddynext_integration_enabled( 'gamification', 'nav' ) && $c->subject_id > 0,
				'url'       => function ( \BuddyNext\Nav\NavContext $c ): string {
					$base = trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) );
					return $base . ( $this->has_standing( $c->subject_id ) ? self::TAB_SLUG . '/' : 'kudos/' );
				},
			)
		);

		// Achievements sub-tab — the badge grid + points/level standing strip.
		$registry->register(
			array(
				'id'        => self::TAB_SLUG,
				'surface'   => 'profile',
				'layer'     => 'primary',
				'parent'    => self::PARENT_SLUG,
				'label'     => __( 'Achievements', 'buddynext' ),
				'icon'      => 'award',
				'priority'  => 10,
				'condition' => fn( \BuddyNext\Nav\NavContext $c ): bool => buddynext_integration_enabled( 'gamification', 'nav' ) && $this->has_standing( $c->subject_id ),
				'url'       => static fn( \BuddyNext\Nav\NavContext $c ): string => trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . self::TAB_SLUG . '/',
				'count'     => fn( \BuddyNext\Nav\NavContext $c ): int => count( $this->badges( $c->subject_id ) ),
				'render'    => function ( \BuddyNext\Nav\NavContext $c ): void {
					$this->render_panel( $c->subject_id );
				},
			)
		);
	}

	/**
	 * Render the Achievements panel — the registry content seam for the tab. The
	 * standing strip + the badge grid; the panel owns its own data (read-only from
	 * wb-gamification). Replaces the old buddynext_part_profile_tab_panel_after +
	 * reactive-reveal wrapper now that the surface SSRs only the active panel.
	 *
	 * @param int $member_id Profile being viewed.
	 * @return void
	 */
	public function render_panel( int $member_id ): void {
		if ( $member_id <= 0 || ! $this->has_standing( $member_id ) ) {
			return;
		}
		echo '<div class="bn-achievements">';
		$this->render_standing( $member_id );
		$this->render_badges( $member_id );
		$this->render_points_history( $member_id );
		echo '</div>';
	}

	/**
	 * Render the member's recent points ledger below the badge grid.
	 *
	 * On a standalone BuddyPress site wb-gamification's own profile
	 * integration ships a Points sub-tab (its points-history block); on a
	 * BuddyNext stack that sub-nav is shadowed because BN owns the profile —
	 * so without this section the ledger existed nowhere on the profile.
	 * Read-only parity via the plugin's own [wb_gam_points_history]
	 * shortcode (block SSR — wb-gamification owns markup, escaping and any
	 * per-member privacy rules), scoped to the displayed member.
	 *
	 * @param int $member_id Profile being viewed.
	 * @return void
	 */
	private function render_points_history( int $member_id ): void {
		if ( ! shortcode_exists( 'wb_gam_points_history' ) ) {
			return;
		}

		// Owner (and site admins) only. Visitors get credentials and standing —
		// badges, level, rank — never the per-action churn of another member's
		// ledger; the history answers the OWNER's "where did my points come
		// from", which is who the shadowed wb-gam Points sub-tab served best.
		if ( get_current_user_id() !== $member_id && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$history = do_shortcode( sprintf( '[wb_gam_points_history user_id="%d" limit="10"]', $member_id ) );
		if ( '' === trim( wp_strip_all_tags( $history ) ) ) {
			return; // No entries yet — no empty shell.
		}

		echo '<div class="bn-card bn-achievements__panel bn-achievements__history">';
		echo '<header class="bn-achievements__head">';
		echo '<h3 class="bn-achievements__title">';
		if ( function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( 'trending-up' );
		}
		echo ' ' . esc_html__( 'Points history', 'buddynext' );
		echo '</h3>';
		echo '</header>';
		echo $history; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wb-gamification block SSR, escaped at source.
		echo '</div>';
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
	 * The full badge catalogue for a member — earned AND locked — so BN can show
	 * "what you can earn next" in-shell instead of sending members to the plugin's
	 * own /gamification/ page. Reads wb-gamification's BadgeEngine (guarded); falls
	 * back to earned-only when the catalogue method is unavailable.
	 *
	 * Ordered earned-first, then credential locked, then the rest — so the grid
	 * leads with what the member has and follows with attainable goals.
	 *
	 * @param int $member_id Member.
	 * @return array<int,array<string,mixed>> Each: id, name, description, image_url,
	 *               is_credential, category, earned (bool), earned_at.
	 */
	private function all_badges( int $member_id ): array {
		if ( ! is_callable( array( '\WBGam\Engine\BadgeEngine', 'get_all_badges_for_user' ) ) ) {
			// Engine catalogue unavailable — degrade to earned-only, flagged earned.
			return array_map(
				static function ( array $badge ): array {
					$badge['earned'] = true;
					return $badge;
				},
				$this->badges( $member_id )
			);
		}

		$all = \WBGam\Engine\BadgeEngine::get_all_badges_for_user( $member_id );
		if ( ! is_array( $all ) ) {
			return array();
		}

		usort(
			$all,
			static function ( array $a, array $b ): int {
				// Earned first, then credentials, then name — a stable, goal-first order.
				$ea = ! empty( $a['earned'] ) ? 1 : 0;
				$eb = ! empty( $b['earned'] ) ? 1 : 0;
				if ( $ea !== $eb ) {
					return $eb <=> $ea;
				}
				$ca = ! empty( $a['is_credential'] ) ? 1 : 0;
				$cb = ! empty( $b['is_credential'] ) ? 1 : 0;
				if ( $ca !== $cb ) {
					return $cb <=> $ca;
				}
				return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
			}
		);

		return $all;
	}

	/**
	 * Render the standing strip: points · level · current streak.
	 *
	 * @param int $member_id Member.
	 * @return void
	 */
	private function render_standing( int $member_id ): void {
		$tiles = $this->standing_tiles( $member_id );

		if ( empty( $tiles ) ) {
			return;
		}

		echo '<div class="bn-achievements__standing">';
		foreach ( $tiles as $tile ) {
			echo '<div class="bn-achievements__stat">';
			$icon = (string) ( $tile['icon'] ?? '' );
			if ( '' !== $icon && function_exists( 'buddynext_icon' ) && \BuddyNext\Core\IconService::has( $icon ) ) {
				echo '<span class="bn-achievements__stat-icon" aria-hidden="true">';
				buddynext_icon( $icon );
				echo '</span>';
			}
			echo '<span class="bn-achievements__stat-value">' . esc_html( (string) $tile['value'] ) . '</span>';
			echo '<span class="bn-achievements__stat-label">' . esc_html( (string) $tile['label'] ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * The member's standing tiles (points · rank · level · streak) as data.
	 *
	 * Single source for both the SSR standing strip and the REST achievements
	 * payload — read straight from wb-gamification (`wb_gam_*` + the leaderboard
	 * engine), so the tab and the app never diverge.
	 *
	 * @param int $member_id Member.
	 * @return array<int,array{icon:string,label:string,value:string}>
	 */
	private function standing_tiles( int $member_id ): array {
		$tiles = array();

		if ( function_exists( 'wb_gam_get_user_points' ) ) {
			$tiles[] = array(
				'icon'  => 'star',
				'label' => __( 'Points', 'buddynext' ),
				'value' => number_format_i18n( (int) wb_gam_get_user_points( $member_id ) ),
			);
		}
		$rank = $this->leaderboard_rank( $member_id );
		if ( $rank > 0 ) {
			$tiles[] = array(
				'icon'  => 'crown',
				'label' => __( 'Rank', 'buddynext' ),
				'value' => '#' . number_format_i18n( $rank ),
			);
		}

		if ( function_exists( 'wb_gam_get_user_level' ) ) {
			$level = wb_gam_get_user_level( $member_id );
			if ( is_array( $level ) && ! empty( $level['name'] ) ) {
				$tiles[] = array(
					'icon'  => 'trending',
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
					'icon'  => 'zap',
					'label' => __( 'Day streak', 'buddynext' ),
					'value' => number_format_i18n( $current ),
				);
			}
		}

		return $tiles;
	}

	/**
	 * Register the read-only Achievements REST route (app coverage).
	 *
	 * Only wired when wb-gamification is active (register() early-returns otherwise),
	 * so the route never exists on a site without the engine.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>\d+)/achievements',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_achievements' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Whether the current viewer may see this member's profile at all.
	 *
	 * Fails CLOSED. If the privacy service cannot be resolved there is no honest
	 * answer available, and the safe direction for a visibility check is to refuse:
	 * a boot-order regression must not silently turn a gated route back into an
	 * open one. (PortfolioController's equivalent fails open — worth aligning, but
	 * not by copying the weaker half.)
	 *
	 * @param int $member_id The profile being read.
	 * @return bool
	 */
	private function viewer_can_see( int $member_id ): bool {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return false;
		}

		$privacy = buddynext_service( 'privacy' );
		if ( ! $privacy instanceof \BuddyNext\SocialGraph\PrivacyService ) {
			return false;
		}

		return $privacy->can_view_profile( get_current_user_id(), $member_id );
	}

	/**
	 * REST: a member's badges + standing — the same data the SSR Achievements tab
	 * renders, so the native app can draw the badge grid and standing strip.
	 *
	 * @param \WP_REST_Request $request Request ({id}).
	 * @return \WP_REST_Response|\WP_Error 404 when the member does not exist.
	 */
	public function rest_achievements( \WP_REST_Request $request ) {
		$member_id = absint( $request['id'] );
		if ( $member_id <= 0 || ! get_userdata( $member_id ) ) {
			return new \WP_Error( 'not_found', __( 'Member not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// SECURITY: gate the read with the canonical profile-visibility check (block +
		// public/followers/connections) — the same gate the web Achievements tab
		// honours, and the same one PortfolioController applies to its own public
		// route. Without it this route handed a member's standing, level, rank and
		// badge history to a viewer they had blocked, and to any stranger looking at
		// a private account: the permission_callback is __return_true because the tab
		// is public for viewers who may see the profile at all, which is a different
		// question from "public to everyone".
		//
		// Return the neutral empty shape rather than a 403, so the answer for "you
		// may not see this" is identical to the answer for "there is nothing here" —
		// a 403 would confirm the member exists and has standing worth hiding.
		if ( ! $this->viewer_can_see( $member_id ) ) {
			return new \WP_REST_Response(
				array(
					'has_standing' => false,
					'standing'     => array(),
					'badges'       => array(),
				),
				200
			);
		}

		$badges = array_map(
			function ( array $badge ) use ( $member_id ): array {
				$id = isset( $badge['id'] ) ? (string) $badge['id'] : '';
				return array(
					'id'            => $id,
					'name'          => isset( $badge['name'] ) ? (string) $badge['name'] : '',
					'image_url'     => isset( $badge['image_url'] ) ? (string) $badge['image_url'] : '',
					'is_credential' => ! empty( $badge['is_credential'] ),
					'earned_at'     => isset( $badge['earned_at'] ) ? (string) $badge['earned_at'] : '',
					'share_url'     => '' !== $id ? $this->badge_share_url( $id, $member_id ) : '',
				);
			},
			$this->badges( $member_id )
		);

		return new \WP_REST_Response(
			array(
				'has_standing' => $this->has_standing( $member_id ),
				'standing'     => array_values( $this->standing_tiles( $member_id ) ),
				'badges'       => $badges,
			),
			200
		);
	}

	/**
	 * Render the badge grid + the leaderboard CTA.
	 *
	 * @param int $member_id Member.
	 * @return void
	 */
	private function render_badges( int $member_id ): void {
		$all    = $this->all_badges( $member_id );
		$total  = count( $all );
		$earned = count( array_filter( $all, static fn( array $b ): bool => ! empty( $b['earned'] ) ) );

		echo '<div class="bn-card bn-achievements__panel">';
		echo '<header class="bn-achievements__head">';
		echo '<h3 class="bn-achievements__title">';
		if ( function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( 'award' );
		}
		echo ' ' . esc_html__( 'Badges', 'buddynext' );
		if ( $total > 0 ) {
			echo ' <span class="bn-achievements__count">' . esc_html(
				sprintf(
					/* translators: 1: badges earned, 2: total badges available. */
					__( '%1$s / %2$s', 'buddynext' ),
					number_format_i18n( $earned ),
					number_format_i18n( $total )
				)
			) . '</span>';
		}
		echo '</h3>';
		echo '</header>';

		if ( 0 === $total ) {
			echo '<p class="bn-achievements__empty">' . esc_html__( 'No badges available yet.', 'buddynext' ) . '</p>';
			echo '</div>';
			return;
		}

		// Earned-vs-total progress so members see how far they are through the set.
		$pct = (int) round( $earned / $total * 100 );
		echo '<div class="bn-progress bn-achievements__progress" data-tone="accent" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $pct ) . '">';
		echo '<div class="bn-progress__fill" style="width:' . esc_attr( (string) $pct ) . '%;"></div>';
		echo '</div>';

		$date_format = (string) get_option( 'date_format', 'M j, Y' );

		echo '<ul class="bn-achievements__grid" role="list">';
		foreach ( $all as $badge ) {
			$is_earned = ! empty( $badge['earned'] );
			$id        = isset( $badge['id'] ) ? (string) $badge['id'] : '';
			$name      = isset( $badge['name'] ) ? (string) $badge['name'] : '';
			$desc      = isset( $badge['description'] ) ? (string) $badge['description'] : '';
			$image     = isset( $badge['image_url'] ) ? (string) $badge['image_url'] : '';
			$is_cr     = ! empty( $badge['is_credential'] );
			$when      = ( $is_earned && ! empty( $badge['earned_at'] ) ) ? date_i18n( $date_format, (int) strtotime( (string) $badge['earned_at'] ) ) : '';
			// Only earned badges have a public share page; locked ones are static.
			$url = ( $is_earned && '' !== $id ) ? $this->badge_share_url( $id, $member_id ) : '';

			$classes = 'bn-achievements__badge';
			if ( $is_cr ) {
				$classes .= ' is-credential';
			}
			if ( ! $is_earned ) {
				$classes .= ' is-locked';
			}

			// The description doubles as the "how to earn it" hint on locked badges.
			$title = '' !== $desc ? ' title="' . esc_attr( $desc ) . '"' : '';

			echo '<li class="' . esc_attr( $classes ) . '"' . $title . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $title pre-escaped above.
			if ( '' !== $url ) {
				echo '<a class="bn-achievements__badge-link" href="' . esc_url( $url ) . '">';
			} else {
				echo '<div class="bn-achievements__badge-link">';
			}

			echo '<span class="bn-achievements__badge-medal">';
			if ( $is_earned && '' !== $image ) {
				echo '<img src="' . esc_url( $image ) . '" alt="" loading="lazy" />';
			} elseif ( function_exists( 'buddynext_icon' ) ) {
				buddynext_icon( $is_earned ? 'award' : 'lock' );
			}
			echo '</span>';

			echo '<span class="bn-achievements__badge-name">' . esc_html( $name ) . '</span>';
			if ( '' !== $when ) {
				echo '<span class="bn-achievements__badge-date">' . esc_html( $when ) . '</span>';
			} elseif ( ! $is_earned && '' !== $desc ) {
				echo '<span class="bn-achievements__badge-date bn-achievements__badge-goal">' . esc_html( $desc ) . '</span>';
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
		// Request-scoped memo: the standing strip resolves rank once per render, but
		// a page can render several member panels — avoid re-hitting the engine (and
		// its cache lookup) for the same member within one request.
		static $ranks = array();
		if ( array_key_exists( $member_id, $ranks ) ) {
			return $ranks[ $member_id ];
		}

		if ( ! is_callable( array( '\WBGam\Engine\LeaderboardEngine', 'get_user_rank' ) ) ) {
			$ranks[ $member_id ] = 0;
			return 0;
		}
		$data                = \WBGam\Engine\LeaderboardEngine::get_user_rank( $member_id, 'all' );
		$ranks[ $member_id ] = is_array( $data ) && isset( $data['rank'] ) ? (int) $data['rank'] : 0;

		return $ranks[ $member_id ];
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
		return home_url( 'gamification/badge/' . $badge_id . '/' . $user_id . '/share/' ); // bn-route-ok: wb-gam's fixed share rewrite, fallback only.
	}
}
