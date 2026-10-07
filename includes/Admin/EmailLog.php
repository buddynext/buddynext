<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext Email Log viewer.
 *
 * A read-only backend surface for the bn_email_log table, which EmailSender
 * writes to on every send but which previously had no reader outside the unit
 * tests. Gives the site owner a way to see and debug what mail BuddyNext has
 * actually sent (the third entry point — backend read — for that data store).
 *
 * Answers the owner's question "did member X get email Y, and is anything
 * failing?": one filter row (recipient search, email type grouped by area,
 * All / Sent / Failed with the failed count), newest first, paginated
 * (big-site safe: ORDER BY the PK, COUNT(*) total, LIMIT/OFFSET window). A
 * proactive admin banner warns when failures pile up in the last 24h.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Settings → Email Log admin tab.
 */
class EmailLog {

	/**
	 * Rows per page.
	 */
	private const PER_PAGE = 30;

	/**
	 * Register the admin tab.
	 *
	 * @return void
	 */
	public function register(): void {
		AdminHub::register_tab(
			'settings',
			'email-log',
			__( 'Email Log', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'subtitle' => __( 'A read-only record of the transactional emails BuddyNext has sent, newest first.', 'buddynext' ),
			)
		);

		add_action( 'admin_notices', array( $this, 'maybe_render_failure_banner' ) );
	}

	/**
	 * Number of failed sends in the last 24 hours.
	 *
	 * @return int
	 */
	private function failed_last_24h(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_log';

		if ( ! $this->table_exists() ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted {$wpdb->prefix} literal; admin-only read.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND sent_at >= %s",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/**
	 * Proactive banner on BuddyNext admin screens when email is failing.
	 *
	 * The whole point of the failed-send tracking is that the owner finds out
	 * WITHOUT having to open the Email Log on a hunch. When failures in the last
	 * 24h cross the threshold (filterable), every BuddyNext admin screen shows a
	 * warning linking to the log. Scoped to BuddyNext screens so it never nags
	 * across unrelated wp-admin pages.
	 *
	 * @return void
	 */
	public function maybe_render_failure_banner(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'buddynext' ) ) {
			return;
		}

		/**
		 * Failed-send count in the last 24h above which the admin banner shows.
		 *
		 * @since 1.2.0
		 *
		 * @param int $threshold Failure count. Default 5.
		 */
		$threshold = (int) apply_filters( 'buddynext_email_failure_alert_threshold', 5 );
		$failed    = $this->failed_last_24h();
		if ( $threshold <= 0 || $failed < $threshold ) {
			return;
		}

		$log_url = add_query_arg(
			array(
				'page'       => 'buddynext-notifications',
				'tab'        => 'email-log',
				'log_status' => 'failed',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'BuddyNext email is failing to send.', 'buddynext' ); ?></strong>
				<?php
				printf(
					/* translators: %s: number of failed emails in the last 24 hours. */
					esc_html__( '%s email(s) failed in the last 24 hours. This usually means the site has no working mailer (SMTP plugin or server MTA). Members are not receiving verification, invite, 2FA or notification email.', 'buddynext' ),
					'<strong>' . esc_html( number_format_i18n( $failed ) ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'Review the email log', 'buddynext' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether the bn_email_log table exists.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Human label for an email type key.
	 *
	 * The Type column and its filter listed raw event keys (bn.post_commented,
	 * bn.user_unsuspended) — thousands of rows the owner had to decode. Known keys
	 * get a friendly label; anything else (a partner type, a composed campaign) is
	 * humanised from the slug so the column is never a bare bn.* string.
	 *
	 * @param string $type Stored type key.
	 * @return string
	 */
	private function type_label( string $type ): string {
		$map = array(
			'transactional'           => __( 'Account email', 'buddynext' ),
			'email_verify'            => __( 'Email verification', 'buddynext' ),
			'welcome'                 => __( 'Welcome', 'buddynext' ),
			'membership'              => __( 'Membership', 'buddynext' ),
			'bn.new_follower'         => __( 'New follower', 'buddynext' ),
			'bn.connection_requested' => __( 'Connection request', 'buddynext' ),
			'bn.connection_accepted'  => __( 'Connection accepted', 'buddynext' ),
			'bn.mention'              => __( 'Mention', 'buddynext' ),
			'bn.post_reacted'         => __( 'Post reaction', 'buddynext' ),
			'bn.post_commented'       => __( 'New comment', 'buddynext' ),
			'bn.post_shared'          => __( 'Post shared', 'buddynext' ),
			'bn.new_message'          => __( 'New message', 'buddynext' ),
			'bn.space_invite'         => __( 'Space invite', 'buddynext' ),
			'bn.space_join_requested' => __( 'Space join request', 'buddynext' ),
			'bn.member_suspended'     => __( 'Member suspended', 'buddynext' ),
			'bn.user_unsuspended'     => __( 'Member unsuspended', 'buddynext' ),
			'bn.user_warned'          => __( 'Member warned', 'buddynext' ),
			'bn.strike_warning'       => __( 'Strike warning', 'buddynext' ),
			'bn.new_report'           => __( 'New report', 'buddynext' ),
			'bn.appeal_resolved'      => __( 'Appeal resolved', 'buddynext' ),
			'bn.content_removed'      => __( 'Content removed', 'buddynext' ),
			'bn.announcement'         => __( 'Announcement', 'buddynext' ),
			'bn.onboarding_nudge'     => __( 'Onboarding reminder', 'buddynext' ),
			'bn.daily_digest'         => __( 'Daily digest', 'buddynext' ),
			'bn.weekly_digest'        => __( 'Weekly digest', 'buddynext' ),
			'bn.subscription_expired' => __( 'Subscription expired', 'buddynext' ),
			'bn.subscription_granted' => __( 'Subscription granted', 'buddynext' ),
		);

		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}
		if ( '' === $type ) {
			return '—';
		}
		return ucfirst( str_replace( array( 'bn.', '_' ), array( '', ' ' ), $type ) );
	}

	/**
	 * Render the Email Log tab.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_log';

		if ( ! $this->table_exists() ) {
			echo '<div class="bn-settings-section"><div class="bn-ss-body"><p>' .
				esc_html__( 'The email log table is not present yet. It is created automatically once the plugin records its first send.', 'buddynext' ) .
				'</p></div></div>';
			return;
		}

		// Filters + page from the query string (read-only listing, nonce not
		// required for a GET-driven, capability-gated view).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter = isset( $_GET['log_type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['log_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = isset( $_GET['log_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['log_status'] ) ) : '';
		if ( ! in_array( $status_filter, array( 'sent', 'failed' ), true ) ) {
			$status_filter = '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['log_q'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET['log_q'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = self::PER_PAGE;

		// Type and recipient narrow the list; status is applied on top so the
		// Failed count can be read for the same type and recipient.
		$where = array();
		$args  = array();
		if ( '' !== $type_filter ) {
			$where[] = 'type = %s';
			$args[]  = $type_filter;
		}
		if ( '' !== $search ) {
			// Recipient search resolves matching accounts first, then filters the
			// log by user_id (indexed), so it stays cheap on a large log.
			// ponytail: first 500 matching accounts; a vaguer term should be narrowed.
			$match_ids = get_users(
				array(
					'search'         => '*' . $search . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
					'fields'         => 'ID',
					'number'         => 500,
				)
			);
			$match_ids = array_map( 'intval', (array) $match_ids );
			if ( $match_ids ) {
				$where[] = 'user_id IN (' . implode( ',', array_fill( 0, count( $match_ids ), '%d' ) ) . ')';
				$args    = array_merge( $args, $match_ids );
			} else {
				$where[] = '1 = 0';
			}
		}
		$base_where = $where;
		$base_args  = $args;
		$failed_sql = 'WHERE ' . implode( ' AND ', array_merge( $base_where, array( "status = 'failed'" ) ) );
		if ( '' !== $status_filter ) {
			$where[] = 'status = %s';
			$args[]  = $status_filter;
		}
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- admin-only debug read, intentionally uncached; WHERE built from a fixed set of prepared clauses.
		$total  = $args
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $args ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
		$failed = $base_args
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$failed_sql}", $base_args ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$failed_sql}" );
		// A page past the end (an old link, a hand-edited URL) shows the last page,
		// not an empty state that claims no email was ever sent.
		$paged  = min( $paged, max( 1, (int) ceil( $total / $per_page ) ) );
		$offset = ( $paged - 1 ) * $per_page;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, type, digest_date, status, error, sent_at FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, $offset ) )
			)
		);

		// Distinct types for the Type dropdown (small set, served by the type index).
		$types = $wpdb->get_col( "SELECT DISTINCT type FROM {$table} ORDER BY type ASC" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		// Group the types by area so the dropdown reads as a short menu.
		$grouped = array();
		foreach ( (array) $types as $t ) {
			$t                                        = (string) $t;
			$grouped[ $this->type_group( $t ) ][ $t ] = $this->type_label( $t );
		}
		$grouped = array_filter( array_merge( array_fill_keys( $this->type_groups(), array() ), $grouped ) );
		foreach ( $grouped as &$bn_group_types ) {
			asort( $bn_group_types );
		}
		unset( $bn_group_types );

		// Resolve every recipient on the page in one query (no per-row lookup).
		$user_ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) $r->user_id, (array) $rows ) ) ) );
		$user_map = array();
		if ( $user_ids ) {
			foreach ( get_users(
				array(
					'include' => $user_ids,
					'fields'  => array( 'ID', 'display_name', 'user_email' ),
				)
			) as $u ) {
				$user_map[ (int) $u->ID ] = $u;
			}
		}

		$total_pages = (int) max( 1, (int) ceil( $total / $per_page ) );
		$tab_url     = add_query_arg(
			array(
				'page' => 'buddynext-notifications',
				'tab'  => 'email-log',
			),
			admin_url( 'admin.php' )
		);
		$filters     = array(
			'log_type'   => '' !== $type_filter ? $type_filter : false,
			'log_q'      => '' !== $search ? rawurlencode( $search ) : false,
			'log_status' => '' !== $status_filter ? $status_filter : false,
		);
		$has_filters = '' !== $type_filter || '' !== $search || '' !== $status_filter;
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Sent email', 'buddynext' ); ?></span>
				<span class="bn-ss-count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<div class="bn-ss-body">

				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="bn-filter-bar bn-email-log__filters bn-admin-hub__form-bare" role="search">
					<input type="hidden" name="page" value="buddynext-notifications">
					<input type="hidden" name="tab" value="email-log">
					<?php if ( '' !== $status_filter ) : ?>
						<input type="hidden" name="log_status" value="<?php echo esc_attr( $status_filter ); ?>">
					<?php endif; ?>
					<label for="bn-email-log-q" class="screen-reader-text"><?php esc_html_e( 'Search by recipient name, username or email', 'buddynext' ); ?></label>
					<input type="search" id="bn-email-log-q" name="log_q" class="bn-input"
						value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'Search recipient name or email…', 'buddynext' ); ?>">
					<label for="bn-email-log-type" class="screen-reader-text"><?php esc_html_e( 'Filter by email', 'buddynext' ); ?></label>
					<select id="bn-email-log-type" name="log_type" class="bn-select">
						<option value=""><?php esc_html_e( 'All emails', 'buddynext' ); ?></option>
						<?php foreach ( $grouped as $group_label => $group_types ) : ?>
							<optgroup label="<?php echo esc_attr( $group_label ); ?>">
								<?php foreach ( $group_types as $t => $t_label ) : ?>
									<option value="<?php echo esc_attr( (string) $t ); ?>" <?php selected( $type_filter, (string) $t ); ?>><?php echo esc_html( $t_label ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="bn-btn" data-variant="secondary"><?php esc_html_e( 'Filter', 'buddynext' ); ?></button>
					<?php if ( '' !== $type_filter || '' !== $search ) : ?>
						<a class="bn-btn" data-variant="ghost" href="<?php echo esc_url( add_query_arg( 'log_status', $filters['log_status'], $tab_url ) ); ?>"><?php esc_html_e( 'Clear', 'buddynext' ); ?></a>
					<?php endif; ?>

					<?php
					$status_links = array(
						''       => __( 'All', 'buddynext' ),
						'sent'   => __( 'Sent', 'buddynext' ),
						'failed' => $failed > 0
							/* translators: %s: number of failed emails. */
							? sprintf( __( 'Failed (%s)', 'buddynext' ), number_format_i18n( $failed ) )
							: __( 'Failed', 'buddynext' ),
					);
					?>
					<div class="bn-segment" role="group" aria-label="<?php esc_attr_e( 'Filter by delivery status', 'buddynext' ); ?>">
						<?php foreach ( $status_links as $s_key => $s_label ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array_merge( $filters, array( 'log_status' => '' !== $s_key ? $s_key : false ) ), $tab_url ) ); ?>"
								class="bn-segment__item<?php echo $status_filter === $s_key ? ' is-active' : ''; ?><?php echo 'failed' === $s_key && $failed > 0 ? ' is-alert' : ''; ?>"
								<?php echo $status_filter === $s_key ? 'aria-current="true"' : ''; ?>>
								<?php echo esc_html( $s_label ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</form>

				<?php if ( empty( $rows ) ) : ?>
					<div class="bn-empty">
						<?php if ( $has_filters ) : ?>
							<p class="bn-empty__title"><?php esc_html_e( 'No emails match these filters', 'buddynext' ); ?></p>
							<p class="bn-empty__sub"><a href="<?php echo esc_url( $tab_url ); ?>"><?php esc_html_e( 'Clear filters', 'buddynext' ); ?></a></p>
						<?php else : ?>
							<p class="bn-empty__title"><?php esc_html_e( 'No email sent yet', 'buddynext' ); ?></p>
							<p class="bn-empty__sub"><?php esc_html_e( 'Every email BuddyNext sends is listed here, with its delivery status.', 'buddynext' ); ?></p>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<table class="widefat bn-email-log">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Recipient', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Email', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Sent', 'buddynext' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $rows as $row ) :
							$uid       = (int) $row->user_id;
							$user      = $user_map[ $uid ] ?? null;
							$email     = $this->type_label( (string) $row->type );
							$is_failed = 'failed' === (string) $row->status;
							if ( ! empty( $row->digest_date ) ) {
								$email .= ' · ' . date_i18n( (string) get_option( 'date_format' ), (int) strtotime( (string) $row->digest_date ) );
							}
							?>
							<tr>
								<td>
									<div class="bn-member-cell">
										<?php if ( $user ) : ?>
											<div class="bn-avatar bn-avatar-initials <?php echo esc_attr( Members\MemberDisplay::get_avatar_color( $uid ) ); ?>" aria-hidden="true">
												<?php echo esc_html( Members\MemberDisplay::get_initials( (string) $user->display_name ) ); ?>
											</div>
											<div class="bn-member-info">
												<div class="bn-member-name"><?php echo esc_html( (string) $user->display_name ); ?></div>
												<div class="bn-member-meta"><span class="bn-member-email"><?php echo esc_html( (string) $user->user_email ); ?></span></div>
											</div>
										<?php elseif ( $uid > 0 ) : ?>
											<span class="bn-text-muted">
												<?php
												/* translators: %d: user ID of a recipient whose account no longer exists. */
												echo esc_html( sprintf( __( 'Deleted member #%d', 'buddynext' ), $uid ) );
												?>
											</span>
										<?php else : ?>
											<span class="bn-text-muted"><?php esc_html_e( 'Guest / unknown', 'buddynext' ); ?></span>
										<?php endif; ?>
									</div>
								</td>
								<td><span title="<?php echo esc_attr( (string) $row->type ); ?>"><?php echo esc_html( $email ); ?></span></td>
								<td>
									<span class="bn-badge" data-tone="<?php echo $is_failed ? 'danger' : 'success'; ?>">
										<?php echo esc_html( $is_failed ? __( 'Failed', 'buddynext' ) : __( 'Sent', 'buddynext' ) ); ?>
									</span>
									<?php if ( $is_failed && '' !== (string) $row->error ) : ?>
										<div class="bn-email-log__error"><?php echo esc_html( (string) $row->error ); ?></div>
									<?php endif; ?>
								</td>
								<td>
									<?php
									$sent_ago   = buddynext_time_ago( (string) $row->sent_at );
									$sent_exact = buddynext_date_local( (string) $row->sent_at, 'M j, Y g:i a' );
									?>
									<?php if ( '' !== $sent_ago ) : ?>
										<time datetime="<?php echo esc_attr( (string) $row->sent_at ); ?>Z" title="<?php echo $sent_exact; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_date_local() returns esc_html()'d output, quotes encoded. ?>"><?php echo $sent_ago; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_time_ago() returns escaped output. ?></time>
									<?php else : ?>
										<?php echo esc_html( '—' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php
					AdminPageBase::render_pagination(
						$paged,
						$total_pages,
						$total,
						$per_page,
						static function ( int $p ) use ( $tab_url, $filters ): string {
							return add_query_arg( array_merge( $filters, array( 'paged' => $p > 1 ? $p : false ) ), $tab_url );
						},
						__( 'Email log pagination', 'buddynext' )
					);
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Areas the Type dropdown groups emails under, in display order.
	 *
	 * Every type in the email template catalogue must land in one of these
	 * (EmailLogTypeGroupTest walks the catalogue), so a new email cannot fall
	 * into "Other" unnoticed.
	 *
	 * @return array<string, string> Group label => pipe-separated keywords matched against the type key.
	 */
	private function type_group_map(): array {
		return array(
			// bulk_invite, not bare 'invite': Account is matched first and would
			// otherwise take bn.space_invite away from Spaces.
			__( 'Account', 'buddynext' )                   => 'verify|welcome|transactional|onboarding|password|registration|email_change|bulk_invite',
			__( 'Connections and followers', 'buddynext' ) => 'follow|connection',
			__( 'Posts and messages', 'buddynext' )        => 'mention|comment|react|share|message|favorite',
			__( 'Spaces', 'buddynext' )                    => 'space',
			__( 'Moderation', 'buddynext' )                => 'suspend|report|appeal|warn|strike|removed|post_approved|post_rejected',
			__( 'Membership', 'buddynext' )                => 'membership|subscription',
			__( 'Announcements and digests', 'buddynext' ) => 'announcement|broadcast|digest|drip',
		);
	}

	/**
	 * Group labels in display order, with "Other" last.
	 *
	 * @return string[]
	 */
	private function type_groups(): array {
		return array_merge( array_keys( $this->type_group_map() ), array( __( 'Other', 'buddynext' ) ) );
	}

	/**
	 * Area an email type belongs to. Matching on keywords also places partner
	 * and future types without a list to keep in step.
	 *
	 * @param string $type Stored type key.
	 * @return string Group label.
	 */
	private function type_group( string $type ): string {
		foreach ( $this->type_group_map() as $label => $keywords ) {
			if ( preg_match( '/' . $keywords . '/', $type ) ) {
				return $label;
			}
		}
		return __( 'Other', 'buddynext' );
	}
}
