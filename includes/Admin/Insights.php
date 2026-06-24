<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Community insights (Growth section + dashboard widget).
 *
 * Core BuddyNext shipped no at-a-glance reporting — admins couldn't see how
 * many members, posts, or spaces the community had, or whether it was growing.
 * This fills the reserved `growth` AdminHub section with an Insights tab and
 * adds a wp-admin dashboard widget, both reading the same cached metrics
 * (5-minute transient so a busy dashboard doesn't re-run the aggregates).
 *
 * Everything is computed from BuddyNext's own tables — no external analytics
 * dependency. (Deep event-level analytics remains a buddynext-pro concern; this
 * is the free at-a-glance overview.)
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Computes and renders community insight metrics.
 */
class Insights {

	/**
	 * Transient holding the computed metrics bundle.
	 */
	private const CACHE_KEY = 'bn_insights_metrics';

	/**
	 * Register the tab + dashboard widget.
	 *
	 * @return void
	 */
	public function register(): void {
		AdminHub::register_tab(
			'growth',
			'insights',
			__( 'Insights', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'position' => 10,
				'subtitle' => __( 'At-a-glance community metrics, computed from your own data.', 'buddynext' ),
			)
		);

		add_action(
			'wp_dashboard_setup',
			function (): void {
				if ( current_user_can( 'manage_options' ) ) {
					wp_add_dashboard_widget( 'bn_insights_widget', __( 'BuddyNext community', 'buddynext' ), array( $this, 'render_widget' ) );
				}
			}
		);
	}

	/**
	 * Render the full Insights tab: headline cards + a 14-day signup strip.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$m = $this->metrics();
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'At a glance', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<div class="bn-insights-grid">
					<?php
					$this->card( __( 'Members', 'buddynext' ), $m['members_total'], sprintf( /* translators: %d: count */ __( '+%d this week', 'buddynext' ), $m['members_new_7d'] ) );
					$this->card( __( 'Active (30 days)', 'buddynext' ), $m['members_active_30d'], $this->percent_label( $m['members_active_30d'], $m['members_total'] ) );
					$this->card( __( 'Posts', 'buddynext' ), $m['posts_total'], sprintf( /* translators: %d: count */ __( '+%d this week', 'buddynext' ), $m['posts_7d'] ) );
					$this->card( __( 'Spaces', 'buddynext' ), $m['spaces_total'], '' );
					$this->card( __( 'Comments', 'buddynext' ), $m['comments_total'], '' );
					$this->card( __( 'Reactions', 'buddynext' ), $m['reactions_total'], '' );
					$this->card( __( 'Connections', 'buddynext' ), $m['connections_total'], '' );
					$this->card( __( 'Follows', 'buddynext' ), $m['follows_total'], '' );
					?>
				</div>
			</div>
		</div>

		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'New members · last 14 days', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<?php $this->render_spark( $m['signups_14d'] ); ?>
			</div>
		</div>

		<?php
		/**
		 * Fires at the end of the Insights tab body. Pro hooks this to render its
		 * full analytics suite (Overview / Cohorts / Funnel / Profile views) below
		 * the Free at-a-glance summary, so the two live in one tab instead of a
		 * separate Analytics tab plus a ghost legacy page.
		 */
		do_action( 'buddynext_insights_after' );
	}

	/**
	 * Render the compact dashboard widget.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$m = $this->metrics();
		?>
		<ul class="bn-insights-widget">
			<li><strong><?php echo esc_html( number_format_i18n( $m['members_total'] ) ); ?></strong> <?php esc_html_e( 'members', 'buddynext' ); ?>
				<span class="bn-insights-delta">+<?php echo esc_html( number_format_i18n( $m['members_new_7d'] ) ); ?> <?php esc_html_e( 'this week', 'buddynext' ); ?></span></li>
			<li><strong><?php echo esc_html( number_format_i18n( $m['members_active_30d'] ) ); ?></strong> <?php esc_html_e( 'active in 30 days', 'buddynext' ); ?></li>
			<li><strong><?php echo esc_html( number_format_i18n( $m['posts_total'] ) ); ?></strong> <?php esc_html_e( 'posts', 'buddynext' ); ?>
				<span class="bn-insights-delta">+<?php echo esc_html( number_format_i18n( $m['posts_7d'] ) ); ?> <?php esc_html_e( 'this week', 'buddynext' ); ?></span></li>
			<li><strong><?php echo esc_html( number_format_i18n( $m['spaces_total'] ) ); ?></strong> <?php esc_html_e( 'spaces', 'buddynext' ); ?></li>
		</ul>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=buddynext-engagement&tab=insights' ) ); ?>">
				<?php esc_html_e( 'View all insights →', 'buddynext' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Compute (and cache) the metrics bundle.
	 *
	 * @return array<string,mixed>
	 */
	public function metrics(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$p     = $wpdb->prefix;
		$now   = time();
		$d7    = gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS );
		$d30ts = $now - 30 * DAY_IN_SECONDS;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$members_total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$members_new_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s", $d7 ) );
		$active_30d     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}bn_presence WHERE last_active >= %d",
				$d30ts
			)
		);

		$posts_total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bn_posts" );
		$posts_7d        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bn_posts WHERE created_at >= %s", $d7 ) );
		$spaces_total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bn_spaces" );
		$comments_total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bn_comments" );
		$reactions_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bn_reactions" );
		$connections_tot = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bn_connections WHERE status = 'accepted'" );
		$follows_total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bn_follows" );

		// 14-day signup histogram (oldest → newest), zero-filled.
		$d14  = gmdate( 'Y-m-d 00:00:00', $now - 13 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(user_registered) AS d, COUNT(*) AS c
				 FROM {$wpdb->users} WHERE user_registered >= %s GROUP BY DATE(user_registered)",
				$d14
			),
			OBJECT_K
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$signups = array();
		for ( $i = 13; $i >= 0; $i-- ) {
			$day             = gmdate( 'Y-m-d', $now - $i * DAY_IN_SECONDS );
			$signups[ $day ] = isset( $rows[ $day ] ) ? (int) $rows[ $day ]->c : 0;
		}

		$metrics = array(
			'members_total'      => $members_total,
			'members_new_7d'     => $members_new_7d,
			'members_active_30d' => $active_30d,
			'posts_total'        => $posts_total,
			'posts_7d'           => $posts_7d,
			'spaces_total'       => $spaces_total,
			'comments_total'     => $comments_total,
			'reactions_total'    => $reactions_total,
			'connections_total'  => $connections_tot,
			'follows_total'      => $follows_total,
			'signups_14d'        => $signups,
		);

		set_transient( self::CACHE_KEY, $metrics, 5 * MINUTE_IN_SECONDS );
		return $metrics;
	}

	/**
	 * Invalidate the cached metrics (call after bulk demo seed/cleanup, etc.).
	 *
	 * @return void
	 */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	// ── Render helpers ──────────────────────────────────────────────────────

	/**
	 * Render one stat card.
	 *
	 * @param string $label Card label.
	 * @param int    $value Big number.
	 * @param string $sub   Optional sub-line.
	 * @return void
	 */
	private function card( string $label, int $value, string $sub ): void {
		?>
		<div class="bn-insight-card">
			<span class="bn-insight-value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
			<span class="bn-insight-label"><?php echo esc_html( $label ); ?></span>
			<?php if ( '' !== $sub ) : ?>
				<span class="bn-insight-sub"><?php echo esc_html( $sub ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the 14-day signup bar strip (pure CSS, no chart library).
	 *
	 * @param array<string,int> $signups day => count.
	 * @return void
	 */
	private function render_spark( array $signups ): void {
		$max = max( 1, max( $signups ) );
		echo '<div class="bn-spark">';
		foreach ( $signups as $day => $count ) {
			$height = (int) round( ( $count / $max ) * 100 );
			$height = max( $count > 0 ? 6 : 2, $height ); // keep tiny/zero bars visible.
			printf(
				'<span class="bn-spark__bar" style="height:%d%%" title="%s"></span>',
				(int) $height,
				esc_attr( sprintf( /* translators: 1: count, 2: date */ _n( '%1$d signup on %2$s', '%1$d signups on %2$s', $count, 'buddynext' ), $count, $day ) )
			);
		}
		echo '</div>';
	}

	/**
	 * "x% of members" sub-label, or '' when total is zero.
	 *
	 * @param int $part  Numerator.
	 * @param int $total Denominator.
	 * @return string
	 */
	private function percent_label( int $part, int $total ): string {
		if ( $total <= 0 ) {
			return '';
		}
		/* translators: %d: percentage of members */
		return sprintf( __( '%d%% of members', 'buddynext' ), (int) round( ( $part / $total ) * 100 ) );
	}
}
