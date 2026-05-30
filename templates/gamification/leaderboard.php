<?php
/**
 * Gamification leaderboard template (v2 design system).
 *
 * Requires wb-gamification to be active. If the plugin is absent, renders a
 * friendly notice instead of an empty page.
 *
 * Data source: wb-gamification PUBLIC read API only — no BuddyNext-side SQL.
 *  - wb_gam_get_leaderboard( $period, $limit )  → ranked rows
 *  - wb_gam_get_user_points( $user_id )         → points balance
 *  - wb_gam_get_user_badges( $user_id )         → earned badges
 *  - wb_gam_get_user_streak( $user_id )         → streak data
 * BuddyNext ships zero gamification logic; it only renders these reads.
 *
 * Layout:
 *  - Hero strip: .bn-stat-grid with your-rank / points / level tiles.
 *  - Level meter: .bn-progress[data-tone="accent"] toward next milestone.
 *  - Filter strip: .bn-tabs (period) + .bn-select (rank-window).
 *  - Leaderboard list: .bn-card[data-interactive] rows with rank pill,
 *    avatar, name/handle, points, badge ribbon, follow CTA. The current
 *    user's row carries [data-self] + "You" pill.
 *  - Sidebar widgets: Your Badges, Your Streak, Next Milestone.
 *
 * All visual styling lives in assets/css/bn-gamification.css —
 * no inline <style>, no inline <script>.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard: wb-gamification must be active (public API present).
if ( ! function_exists( 'wb_gam_get_leaderboard' ) ) {
	?>
	<div class="bn-lb-shell">
		<div class="bn-lb-notice" role="status">
			<span class="bn-lb-notice__icon" aria-hidden="true"><?php buddynext_icon( 'award' ); ?></span>
			<h2><?php esc_html_e( 'Leaderboard', 'buddynext' ); ?></h2>
			<p>
				<?php esc_html_e( 'The leaderboard requires the gamification plugin to be active. Install and activate it to start earning points and see where you rank in the community.', 'buddynext' ); ?>
			</p>
		</div>
	</div>
	<?php
	return;
}

$current_user_id = get_current_user_id();

// Period tab — week | month | alltime (UI) mapped to wb-gamification periods.
$allowed_periods = array( 'week', 'month', 'alltime' );
$period          = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'month'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $period, $allowed_periods, true ) ) {
	$period = 'month';
}

// wb_gam_get_leaderboard() accepts 'all'|'week'|'month'|'day'.
$api_period = ( 'alltime' === $period ) ? 'all' : $period;

// Fetch top 10 ranked users via the plugin read API.
// Each entry: ['rank'=>int,'user_id'=>int,'display_name'=>string,'avatar_url'=>string,'points'=>int].
$leaderboard = wb_gam_get_leaderboard( $api_period, 10 );
if ( ! is_array( $leaderboard ) ) {
	$leaderboard = array();
}

// Current user stats from the read API.
$current_user_pts  = $current_user_id ? (int) wb_gam_get_user_points( $current_user_id ) : 0;
$current_user_rank = 0;

// Resolve the current user's rank from the returned leaderboard rows.
// If the user is outside the top 10, rank stays 0 (rendered as "Unranked").
if ( $current_user_id ) {
	foreach ( $leaderboard as $row ) {
		if ( (int) ( $row['user_id'] ?? 0 ) === $current_user_id ) {
			$current_user_rank = (int) ( $row['rank'] ?? 0 );
			break;
		}
	}
}

// Current user's badges via the read API.
// Each badge: ['id','name','description','image_url','category','earned_at',...].
$user_badges = $current_user_id ? wb_gam_get_user_badges( $current_user_id ) : array();
if ( ! is_array( $user_badges ) ) {
	$user_badges = array();
}
$user_badges = array_slice( $user_badges, 0, 8 );

// Current user's streak via the read API.
// Shape: ['current_streak'=>int,'longest_streak'=>int,'last_active'=>string].
$user_streak    = $current_user_id ? wb_gam_get_user_streak( $current_user_id ) : array();
$current_streak = is_array( $user_streak ) ? (int) ( $user_streak['current_streak'] ?? 0 ) : 0;
$longest_streak = is_array( $user_streak ) ? (int) ( $user_streak['longest_streak'] ?? 0 ) : 0;

// Build a per-user badge ribbon for the leaderboard rows (top earned badges).
// Sourced exclusively from the read API — no direct table access.
$ribbon_by_user = array();
foreach ( $leaderboard as $row ) {
	$uid = (int) ( $row['user_id'] ?? 0 );
	if ( $uid <= 0 ) {
		continue;
	}
	$badges = wb_gam_get_user_badges( $uid );
	if ( is_array( $badges ) && ! empty( $badges ) ) {
		$ribbon_by_user[ $uid ] = array_slice( $badges, 0, 4 );
	}
}

// Rank change — wb-gamification does not expose a rank-snapshot read API,
// so deltas default to 0 (no change) until that data source lands.
$rank_changes = array();
foreach ( $leaderboard as $row ) {
	$rank_changes[ (int) ( $row['user_id'] ?? 0 ) ] = 0;
}

// Compute next milestone for current user (next 100-pt boundary).
$next_milestone_pts  = $current_user_pts > 0 ? (int) ( ceil( ( $current_user_pts + 1 ) / 100 ) * 100 ) : 100;
$milestone_progress  = $next_milestone_pts > 0 ? min( 100, (int) ( $current_user_pts % 100 ) ) : 0;
$milestone_remaining = max( 0, $next_milestone_pts - $current_user_pts );

// Approximate current level — 1 level per 500 pts.
$current_level = max( 1, (int) floor( $current_user_pts / 500 ) + 1 );

// Rank pill tone for a given rank position.
$rank_tone = static function ( int $rank ): string {
	if ( 1 === $rank ) {
		return 'warn';
	}
	if ( 2 === $rank ) {
		return 'info';
	}
	if ( 3 === $rank ) {
		return 'paid';
	}
	return 'ink';
};

add_action(
	'buddynext_right_sidebar',
	static function () {
		buddynext_get_template(
			'partials/sidebar.php',
			array(
				'sidebar_user_id' => get_current_user_id(),
			)
		);
	}
);

/**
 * Fires before the leaderboard inner content.
 */
do_action( 'buddynext_leaderboard_before' );

$period_tabs = array(
	'alltime' => __( 'All time', 'buddynext' ),
	'month'   => __( 'This month', 'buddynext' ),
	'week'    => __( 'This week', 'buddynext' ),
);

$updated_iso = gmdate( 'c' );
?>

<div class="bn-lb-shell"
	data-wp-interactive="buddynext/gamification"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'period' => $period,
			)
		)
	);
	?>
	'>

	<!-- Page header -->
	<header class="bn-lb-header">
		<div class="bn-lb-header__row">
			<h1 class="bn-lb-title"><?php esc_html_e( 'Leaderboard', 'buddynext' ); ?></h1>
			<span class="bn-badge" data-tone="success">
				<?php buddynext_icon( 'award' ); ?>
				<?php esc_html_e( 'Gamification', 'buddynext' ); ?>
			</span>
		</div>
		<p class="bn-lb-subtitle"><?php esc_html_e( 'Top contributors across this community.', 'buddynext' ); ?></p>
		<div class="bn-lb-header__meta">
			<span><?php esc_html_e( 'Last updated', 'buddynext' ); ?></span>
			<time datetime="<?php echo esc_attr( $updated_iso ); ?>">
				<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
			</time>
		</div>
	</header>

	<?php if ( $current_user_id ) : ?>
		<!-- Hero strip — your-rank / points / level -->
		<section class="bn-lb-hero" aria-label="<?php esc_attr_e( 'Your stats', 'buddynext' ); ?>">
			<div class="bn-stat-grid">
				<div class="bn-stat">
					<span class="bn-stat__label">
						<span class="bn-lb-stat__icon" aria-hidden="true"><?php buddynext_icon( 'crown' ); ?></span>
						<?php esc_html_e( 'Your rank', 'buddynext' ); ?>
					</span>
					<span class="bn-stat__value">
						<?php echo $current_user_rank > 0 ? esc_html( '#' . number_format_i18n( $current_user_rank ) ) : esc_html__( 'Unranked', 'buddynext' ); ?>
					</span>
					<span class="bn-stat__delta" data-trend="flat">
						<?php buddynext_icon( 'trending' ); ?>
						<?php esc_html_e( 'No change', 'buddynext' ); ?>
					</span>
				</div>

				<div class="bn-stat">
					<span class="bn-stat__label">
						<span class="bn-lb-stat__icon" aria-hidden="true"><?php buddynext_icon( 'zap' ); ?></span>
						<?php esc_html_e( 'Points', 'buddynext' ); ?>
					</span>
					<span class="bn-stat__value">
						<?php echo esc_html( number_format_i18n( $current_user_pts ) ); ?>
					</span>
					<span class="bn-stat__delta" data-trend="up">
						<?php buddynext_icon( 'arrow-up' ); ?>
						<?php
						// translators: %s: human-readable period label, e.g. "This month".
						echo esc_html( sprintf( __( 'Earned %s', 'buddynext' ), $period_tabs[ $period ] ?? '' ) );
						?>
					</span>
				</div>

				<div class="bn-stat">
					<span class="bn-stat__label">
						<span class="bn-lb-stat__icon" aria-hidden="true"><?php buddynext_icon( 'star' ); ?></span>
						<?php esc_html_e( 'Level', 'buddynext' ); ?>
					</span>
					<span class="bn-stat__value">
						<?php
						// translators: %d: current numeric level.
						echo esc_html( sprintf( __( 'Lv %d', 'buddynext' ), $current_level ) );
						?>
					</span>
					<span class="bn-stat__delta" data-trend="up">
						<?php
						// translators: %d: number of points remaining to next milestone.
						echo esc_html( sprintf( _n( '%d pt to next', '%d pts to next', $milestone_remaining, 'buddynext' ), $milestone_remaining ) );
						?>
					</span>
				</div>
			</div>

			<!-- Level meter -->
			<div class="bn-lb-level" aria-label="<?php esc_attr_e( 'Level progress', 'buddynext' ); ?>">
				<div class="bn-lb-level__head">
					<span class="bn-lb-level__label">
						<?php
						// translators: 1: current level, 2: current points, 3: target milestone points.
						echo esc_html( sprintf( __( 'Level %1$d — %2$s / %3$s points', 'buddynext' ), $current_level, number_format_i18n( $current_user_pts ), number_format_i18n( $next_milestone_pts ) ) );
						?>
					</span>
					<span class="bn-lb-level__remaining">
						<?php
						// translators: %d: points remaining.
						echo esc_html( sprintf( _n( '%d pt to go', '%d pts to go', $milestone_remaining, 'buddynext' ), $milestone_remaining ) );
						?>
					</span>
				</div>
				<div class="bn-progress" data-tone="accent" role="progressbar"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="<?php echo esc_attr( (string) $milestone_progress ); ?>">
					<div class="bn-progress__fill" style="width:<?php echo esc_attr( (string) $milestone_progress ); ?>%;"></div>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<!-- Filter strip — period tabs + rank window select -->
	<div class="bn-lb-filters">
		<nav class="bn-lb-filters__tabs" aria-label="<?php esc_attr_e( 'Leaderboard period', 'buddynext' ); ?>">
			<div class="bn-tabs bn-lb-period" role="tablist">
				<?php
				foreach ( $period_tabs as $pkey => $plabel ) :
					$phref     = esc_url(
						add_query_arg(
							array(
								'period' => $pkey,
							)
						)
					);
					$is_active = ( $pkey === $period );
					?>
					<a href="<?php echo $phref; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via esc_url(). ?>"
						class="bn-tab"
						role="tab"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php echo esc_html( $plabel ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</nav>

		<div class="bn-lb-filters__select">
			<label class="bn-lb-filters__select-label" for="bn-lb-window">
				<?php esc_html_e( 'Show', 'buddynext' ); ?>
			</label>
			<select id="bn-lb-window" name="window" class="bn-select" disabled>
				<option><?php esc_html_e( 'Top 10', 'buddynext' ); ?></option>
			</select>
		</div>
	</div>

	<?php if ( empty( $leaderboard ) ) : ?>
		<div class="bn-lb-empty">
			<span class="bn-lb-empty__icon" aria-hidden="true"><?php buddynext_icon( 'award' ); ?></span>
			<h2 class="bn-lb-empty__title"><?php esc_html_e( 'No leaderboard data yet', 'buddynext' ); ?></h2>
			<p class="bn-lb-empty__desc">
				<?php esc_html_e( 'Start contributing to the community to earn points and climb the ranks.', 'buddynext' ); ?>
			</p>
		</div>
	<?php else : ?>

		<!-- Leaderboard list -->
		<ol class="bn-lb-list" aria-label="<?php esc_attr_e( 'Ranked members', 'buddynext' ); ?>">
			<?php
			foreach ( $leaderboard as $idx => $row ) :
				$uid           = (int) ( $row['user_id'] ?? 0 );
				$rank          = (int) ( $row['rank'] ?? ( $idx + 1 ) );
				$is_self       = ( $current_user_id && $uid === $current_user_id );
				$user_data     = $uid ? get_userdata( $uid ) : false;
				$display       = '' !== (string) ( $row['display_name'] ?? '' )
					? (string) $row['display_name']
					: ( $user_data ? $user_data->display_name : __( 'Unknown member', 'buddynext' ) );
				$handle        = $user_data && ! empty( $user_data->user_login ) ? '@' . $user_data->user_login : '';
				$avatar_url    = (string) ( $row['avatar_url'] ?? '' );
				$pts_formatted = number_format_i18n( (int) ( $row['points'] ?? 0 ) );
				$profile_url   = $user_data ? get_author_posts_url( $uid ) : '#';
				$tone          = $rank_tone( $rank );
				$delta         = (int) ( $rank_changes[ $uid ] ?? 0 );
				$trend         = ( 0 === $delta ) ? 'flat' : ( $delta > 0 ? 'up' : 'down' );
				$ribbon        = $ribbon_by_user[ $uid ] ?? array();
				$last_space    = strrchr( $display, ' ' );
				$initials      = strtoupper( substr( $display, 0, 1 ) . substr( false === $last_space ? '' : $last_space, 1, 1 ) );
				?>
				<li>
					<article class="bn-card bn-lb-row"
						data-interactive
						<?php echo $is_self ? 'data-self' : ''; ?>>
						<span class="bn-lb-row__rank"
							data-tone="<?php echo esc_attr( $tone ); ?>"
							aria-label="
							<?php
								// translators: %d: numeric rank position.
								echo esc_attr( sprintf( __( 'Rank %d', 'buddynext' ), $rank ) );
							?>
							">
							<?php echo esc_html( (string) $rank ); ?>
						</span>

						<div class="bn-lb-row__who">
							<?php
							// Prefer the avatar URL supplied by the gamification API;
							// fall back to WordPress get_avatar() for the user.
							$avatar_html = '';
							if ( '' !== $avatar_url ) {
								$avatar_html = sprintf(
									'<img src="%1$s" alt="%2$s" class="bn-avatar" width="72" height="72" loading="lazy" decoding="async" data-size="md" />',
									esc_url( $avatar_url ),
									esc_attr( $display )
								);
							} elseif ( $user_data ) {
								$avatar_html = get_avatar(
									$uid,
									72,
									'',
									$display,
									array(
										'class'      => 'bn-avatar',
										'extra_attr' => 'data-size="md"',
									)
								);
							}
							if ( '' !== $avatar_html ) {
								echo wp_kses(
									$avatar_html,
									array(
										'img' => array(
											'src'       => true,
											'srcset'    => true,
											'sizes'     => true,
											'alt'       => true,
											'class'     => true,
											'width'     => true,
											'height'    => true,
											'loading'   => true,
											'decoding'  => true,
											'data-size' => true,
										),
									)
								);
							} else {
								?>
								<span class="bn-avatar" data-size="md" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
								<?php
							}
							?>
							<div class="bn-lb-row__id">
								<a class="bn-lb-row__name" href="<?php echo esc_url( $profile_url ); ?>">
									<?php echo esc_html( $display ); ?>
									<?php if ( $is_self ) : ?>
										<span class="bn-lb-row__self-pill"><?php esc_html_e( 'You', 'buddynext' ); ?></span>
									<?php endif; ?>
								</a>
								<?php if ( '' !== $handle ) : ?>
									<span class="bn-lb-row__handle"><?php echo esc_html( $handle ); ?></span>
								<?php endif; ?>
							</div>
						</div>

						<?php if ( ! empty( $ribbon ) ) : ?>
							<div class="bn-lb-ribbon" aria-label="<?php esc_attr_e( 'Earned badges', 'buddynext' ); ?>">
								<?php
								$ribbon_shown = array_slice( $ribbon, 0, 3 );
								$ribbon_extra = max( 0, count( $ribbon ) - count( $ribbon_shown ) );
								foreach ( $ribbon_shown as $b ) :
									$bname = isset( $b['name'] ) ? (string) $b['name'] : '';
									?>
									<span class="bn-tooltip-trigger" tabindex="0">
										<span class="bn-lb-ribbon__item" aria-hidden="true">
											<?php if ( ! empty( $b['image_url'] ) ) : ?>
												<img src="<?php echo esc_url( (string) $b['image_url'] ); ?>" alt="" />
											<?php else : ?>
												<?php buddynext_icon( 'award' ); ?>
											<?php endif; ?>
										</span>
										<span class="bn-tooltip" data-pos="top" role="tooltip">
											<?php echo esc_html( $bname ); ?>
										</span>
										<span class="screen-reader-text"><?php echo esc_html( $bname ); ?></span>
									</span>
								<?php endforeach; ?>
								<?php if ( $ribbon_extra > 0 ) : ?>
									<span class="bn-lb-ribbon__more">
										<?php
										// translators: %d: number of additional badges not shown.
										echo esc_html( sprintf( __( '+%d', 'buddynext' ), $ribbon_extra ) );
										?>
									</span>
								<?php endif; ?>
							</div>
						<?php else : ?>
							<span aria-hidden="true"></span>
						<?php endif; ?>

						<div class="bn-lb-row__points">
							<span class="bn-lb-row__points-val"><?php echo esc_html( $pts_formatted ); ?></span>
							<span class="bn-lb-row__points-unit"><?php esc_html_e( 'pts', 'buddynext' ); ?></span>
						</div>

						<?php if ( $is_self ) : ?>
							<span class="bn-lb-row__delta" data-trend="<?php echo esc_attr( $trend ); ?>">
								<?php buddynext_icon( 'trending' ); ?>
							</span>
							<?php
						else :
							$is_following = false; // Follow state — bridge to BuddyPress follow API can hydrate later.
							$btn_variant  = $is_following ? 'secondary' : 'primary';
							$btn_label    = $is_following ? __( 'Following', 'buddynext' ) : __( 'Follow', 'buddynext' );
							?>
							<span class="bn-lb-row__cta">
								<button type="button"
									class="bn-btn"
									data-variant="<?php echo esc_attr( $btn_variant ); ?>"
									data-size="sm"
									aria-pressed="<?php echo $is_following ? 'true' : 'false'; ?>"
									aria-label="
									<?php
										// translators: %s: member display name.
										echo esc_attr( sprintf( __( 'Follow %s', 'buddynext' ), $display ) );
									?>
									">
									<?php echo esc_html( $btn_label ); ?>
								</button>
							</span>
						<?php endif; ?>
					</article>
				</li>
			<?php endforeach; ?>
		</ol>

	<?php endif; // End: leaderboard data check. ?>

	<!-- Sidebar widgets -->
	<aside aria-label="<?php esc_attr_e( 'Your gamification widgets', 'buddynext' ); ?>">

		<!-- Your Badges -->
		<div class="bn-widget">
			<div class="bn-widget-title">
				<?php buddynext_icon( 'award' ); ?>
				<?php esc_html_e( 'Your Badges', 'buddynext' ); ?>
			</div>
			<?php if ( ! empty( $user_badges ) ) : ?>
				<div class="bn-lb-badges-grid">
					<?php
					foreach ( $user_badges as $badge ) :
						$badge_name = isset( $badge['name'] ) ? (string) $badge['name'] : '';
						$badge_img  = isset( $badge['image_url'] ) ? (string) $badge['image_url'] : '';
						?>
						<div class="bn-lb-badge-cell" title="<?php echo esc_attr( $badge_name ); ?>">
							<?php if ( '' !== $badge_img ) : ?>
								<img src="<?php echo esc_url( $badge_img ); ?>" alt="<?php echo esc_attr( $badge_name ); ?>" />
							<?php else : ?>
								<?php buddynext_icon( 'award' ); ?>
							<?php endif; ?>
							<span class="bn-lb-badge-cell__name"><?php echo esc_html( $badge_name ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="bn-lb-badge-hint">
					<?php
					// translators: %d: number of badges earned.
					echo esc_html( sprintf( _n( '%d badge earned', '%d badges earned', count( $user_badges ), 'buddynext' ), count( $user_badges ) ) );
					?>
				</div>
			<?php else : ?>
				<p class="bn-lb-badge-hint">
					<?php esc_html_e( 'Earn your first badge by contributing to the community.', 'buddynext' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Your Streak -->
		<?php if ( $current_user_id ) : ?>
			<div class="bn-widget">
				<div class="bn-widget-title">
					<?php buddynext_icon( 'zap' ); ?>
					<?php esc_html_e( 'Your Streak', 'buddynext' ); ?>
				</div>
				<?php if ( $current_streak > 0 || $longest_streak > 0 ) : ?>
					<div class="bn-stat-grid">
						<div class="bn-stat">
							<span class="bn-stat__label"><?php esc_html_e( 'Current', 'buddynext' ); ?></span>
							<span class="bn-stat__value">
								<?php
								// translators: %s: number of consecutive active days.
								echo esc_html( sprintf( _n( '%s day', '%s days', $current_streak, 'buddynext' ), number_format_i18n( $current_streak ) ) );
								?>
							</span>
						</div>
						<div class="bn-stat">
							<span class="bn-stat__label"><?php esc_html_e( 'Longest', 'buddynext' ); ?></span>
							<span class="bn-stat__value">
								<?php
								// translators: %s: longest streak in days.
								echo esc_html( sprintf( _n( '%s day', '%s days', $longest_streak, 'buddynext' ), number_format_i18n( $longest_streak ) ) );
								?>
							</span>
						</div>
					</div>
				<?php else : ?>
					<p class="bn-lb-badge-hint">
						<?php esc_html_e( 'Stay active each day to build your streak.', 'buddynext' ); ?>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<!-- Next Milestone -->
		<?php if ( $current_user_id ) : ?>
			<div class="bn-widget">
				<div class="bn-widget-title">
					<?php buddynext_icon( 'target' ); ?>
					<?php esc_html_e( 'Next Milestone', 'buddynext' ); ?>
				</div>
				<div class="bn-lb-milestone__name">
					<?php
					// translators: %s: target milestone in points.
					echo esc_html( sprintf( __( '%s pts milestone', 'buddynext' ), number_format_i18n( $next_milestone_pts ) ) );
					?>
				</div>
				<div class="bn-lb-milestone__desc">
					<?php
					// translators: %d: number of points remaining.
					echo esc_html( sprintf( __( 'Earn %d more points to reach the next milestone.', 'buddynext' ), $milestone_remaining ) );
					?>
				</div>
				<div class="bn-progress" data-tone="accent" role="progressbar"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="<?php echo esc_attr( (string) $milestone_progress ); ?>">
					<div class="bn-progress__fill" style="width:<?php echo esc_attr( (string) $milestone_progress ); ?>%;"></div>
				</div>
				<div class="bn-lb-milestone__row">
					<span><?php echo esc_html( number_format_i18n( $current_user_pts ) ); ?> <?php esc_html_e( 'pts', 'buddynext' ); ?></span>
					<span><?php echo esc_html( number_format_i18n( $next_milestone_pts ) ); ?> <?php esc_html_e( 'pts', 'buddynext' ); ?></span>
				</div>
				<div class="bn-lb-milestone__hint">
					<?php
					// translators: %d: number of remaining points.
					echo esc_html( sprintf( __( '%d points to go to reach the next level.', 'buddynext' ), $milestone_remaining ) );
					?>
				</div>
			</div>
		<?php endif; ?>

	</aside>

</div><!-- /.bn-lb-shell -->

<?php
/**
 * Fires after the leaderboard inner content.
 */
do_action( 'buddynext_leaderboard_after' );
?>
