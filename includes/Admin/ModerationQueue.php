<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin Moderation queue (Moderation section).
 *
 * BuddyNext already has a full moderation engine (reports, strikes,
 * suspensions, appeals) exposed over REST and on the per-space front-end
 * manage screens — but there was no central wp-admin surface, so site admins
 * could not triage everything in one place. This class fills the reserved
 * `moderation` AdminHub section with three tabs:
 *
 *   Reports     — the pending/escalated report queue, with per-report actions
 *                 (dismiss, resolve, resolve + remove content, escalate) plus
 *                 author actions (strike, suspend).
 *   Suspensions — every active suspension, with one-click lift.
 *   Appeals     — pending appeals awaiting a decision (approve / deny).
 *
 * Everything routes through ModerationService (the same engine the REST
 * controller uses); this is purely an admin UI over it. Actions post to
 * admin-post.php, run synchronously, and redirect back with a notice —
 * matching the Members admin convention.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use BuddyNext\Moderation\ModerationService;

/**
 * Renders the admin moderation queue and handles its actions.
 */
class ModerationQueue {

	/**
	 * Register hooks + the three moderation tabs.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_mod_report_action', array( $this, 'handle_report_action' ) );
		add_action( 'admin_post_bn_mod_user_action', array( $this, 'handle_user_action' ) );
		add_action( 'admin_post_bn_mod_appeal_action', array( $this, 'handle_appeal_action' ) );
		add_action( 'admin_post_bn_mod_premod_action', array( $this, 'handle_premod_action' ) );
		add_action( 'admin_post_bn_mod_log_export', array( $this, 'handle_export_log' ) );
		add_action( 'admin_post_bn_mod_run_now', array( $this, 'handle_run_now' ) );

		// Pending approval queue first — it is proactive (clear held posts so they
		// go live) rather than reactive like reports. Hidden by AdminHub when the
		// pre-moderation feature is off, but the tab itself always registers so a
		// held post is never stranded.
		AdminHub::register_tab(
			'moderation',
			'pending',
			__( 'Pending', 'buddynext' ),
			array( $this, 'render_pending' ),
			array(
				'position' => 5,
				// Counter pill on the tab so the owner sees at a glance how many
				// posts are held for approval without opening the tab.
				'badge'    => static fn(): int => ( new \BuddyNext\Feed\PostService() )->count_pending(),
			)
		);
		AdminHub::register_tab( 'moderation', 'reports', __( 'Reports', 'buddynext' ), array( $this, 'render_reports' ), array( 'position' => 10 ) );
		AdminHub::register_tab( 'moderation', 'suspensions', __( 'Suspensions', 'buddynext' ), array( $this, 'render_suspensions' ), array( 'position' => 20 ) );
		AdminHub::register_tab( 'moderation', 'appeals', __( 'Appeals', 'buddynext' ), array( $this, 'render_appeals' ), array( 'position' => 30 ) );
		AdminHub::register_tab( 'moderation', 'log', __( 'Moderation Log', 'buddynext' ), array( $this, 'render_log' ), array( 'position' => 40 ) );
	}

	// ── Renderers ───────────────────────────────────────────────────────────

	/**
	 * Render the report queue tab.
	 *
	 * @return void
	 */
	public function render_reports(): void {
		$this->maybe_notice();

		// Filters + pagination. The queue can hold thousands of reports; the old
		// hardcoded per_page=50 with no paging capped the admin at 50 groups and
		// gave no way to narrow by type/reason or reorder (big-site checklist).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters on an admin screen.
		$page   = isset( $_GET['mod_page'] ) ? max( 1, absint( wp_unslash( $_GET['mod_page'] ) ) ) : 1;
		$type   = isset( $_GET['mod_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['mod_type'] ) ) : '';
		$reason = isset( $_GET['mod_reason'] ) ? sanitize_key( wp_unslash( (string) $_GET['mod_reason'] ) ) : '';
		$sort   = ( isset( $_GET['mod_sort'] ) && 'reported' === $_GET['mod_sort'] ) ? 'reported' : 'recent';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$service  = new ModerationService();
		$queue    = $service->get_queue(
			array(
				'per_page'    => $per_page,
				'page'        => $page,
				'object_type' => $type,
				'reason'      => $reason,
				'sort'        => $sort,
			)
		);
		$items    = $queue['items'] ?? array();
		$total    = (int) ( $queue['total'] ?? 0 );
		$pages    = (int) ceil( $total / max( 1, $per_page ) );
		$filtered = ( '' !== $type || '' !== $reason );
		?>
		<div class="bn-settings-section bn-mod-queue" data-mod-queue>
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Report queue', 'buddynext' ); ?></span>
				<span class="bn-ss-count"><?php echo esc_html( (string) $total ); ?></span>
			</div>
			<div class="bn-ss-body">
				<?php $this->render_queue_filters( $type, $reason, $sort ); ?>
				<?php if ( empty( $items ) && $filtered ) : ?>
					<p><?php esc_html_e( 'No reports match these filters.', 'buddynext' ); ?></p>
				<?php elseif ( empty( $items ) ) : ?>
					<p><?php esc_html_e( 'Nothing to review. The queue is clear.', 'buddynext' ); ?></p>
					<?php
					$this->empty_queue_shape(
						array(
							__( 'Reported content', 'buddynext' ),
							__( 'Reason', 'buddynext' ),
							__( 'Reporter', 'buddynext' ),
							__( 'When', 'buddynext' ),
							__( 'Actions', 'buddynext' ),
						)
					);
					?>
				<?php else : ?>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Reported content', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Reporter', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'When', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Resolve every reported object's label in one pass per type
						// before printing any row. Resolving them row by row is the
						// N+1 a queue this size exists to avoid.
						buddynext_prime_object_labels(
							array_map(
								static function ( array $bn_report ): array {
									return array(
										(string) ( $bn_report['object_type'] ?? '' ),
										(int) ( $bn_report['object_id'] ?? 0 ),
									);
								},
								$items
							)
						);
						?>
						<?php foreach ( $items as $report ) : ?>
							<?php $this->render_report_row( $report ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
					<?php
					// Shared paginator, preserving the active filter/sort across pages.
					AdminPageBase::render_pagination(
						$page,
						$pages,
						$total,
						$per_page,
						static function ( int $p ) use ( $type, $reason, $sort ): string {
							$query = array( 'mod_page' => $p );
							if ( '' !== $type ) {
								$query['mod_type'] = $type;
							}
							if ( '' !== $reason ) {
								$query['mod_reason'] = $reason;
							}
							if ( 'recent' !== $sort ) {
								$query['mod_sort'] = $sort;
							}
							return add_query_arg( $query, remove_query_arg( array( 'mod_page', 'mod_type', 'mod_reason', 'mod_sort' ) ) );
						},
						__( 'Report queue pagination', 'buddynext' )
					);
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the report-queue filter/sort bar (GET form). Resets to page 1 on apply.
	 *
	 * @param string $type   Active object-type filter ('' = all).
	 * @param string $reason Active reason filter ('' = all).
	 * @param string $sort   Active sort ('recent' | 'reported').
	 * @return void
	 */
	private function render_queue_filters( string $type, string $reason, string $sort ): void {
		$types = array(
			'post'    => __( 'Posts', 'buddynext' ),
			'comment' => __( 'Comments', 'buddynext' ),
			'user'    => __( 'Members', 'buddynext' ),
			'space'   => __( 'Spaces', 'buddynext' ),
		);
		?>
		<form method="get" class="bn-mod-queue-filters">
			<?php
			// Preserve the admin page/tab routing params in the GET form.
			foreach ( array( 'page', 'tab' ) as $bn_keep ) {
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filter/notice params on an admin screen; every value is sanitized here and escaped at output.
				$bn_val = isset( $_GET[ $bn_keep ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $bn_keep ] ) ) : '';
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				if ( '' !== $bn_val ) {
					printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $bn_keep ), esc_attr( $bn_val ) );
				}
			}
			?>
			<label class="screen-reader-text" for="bn-mod-type"><?php esc_html_e( 'Filter by content type', 'buddynext' ); ?></label>
			<select name="mod_type" id="bn-mod-type">
				<option value=""><?php esc_html_e( 'All content types', 'buddynext' ); ?></option>
				<?php foreach ( $types as $bn_key => $bn_label ) : ?>
					<option value="<?php echo esc_attr( $bn_key ); ?>" <?php selected( $type, $bn_key ); ?>><?php echo esc_html( $bn_label ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="bn-mod-reason"><?php esc_html_e( 'Filter by reason', 'buddynext' ); ?></label>
			<select name="mod_reason" id="bn-mod-reason">
				<option value=""><?php esc_html_e( 'All reasons', 'buddynext' ); ?></option>
				<?php foreach ( \BuddyNext\Moderation\ModerationService::reason_labels() as $bn_key => $bn_label ) : ?>
					<option value="<?php echo esc_attr( $bn_key ); ?>" <?php selected( $reason, $bn_key ); ?>><?php echo esc_html( $bn_label ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="bn-mod-sort"><?php esc_html_e( 'Sort', 'buddynext' ); ?></label>
			<select name="mod_sort" id="bn-mod-sort">
				<option value="recent" <?php selected( $sort, 'recent' ); ?>><?php esc_html_e( 'Newest first', 'buddynext' ); ?></option>
				<option value="reported" <?php selected( $sort, 'reported' ); ?>><?php esc_html_e( 'Most reported', 'buddynext' ); ?></option>
			</select>

			<button type="submit" class="bn-btn" data-variant="secondary"><?php esc_html_e( 'Apply', 'buddynext' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render the pre-moderation Pending tab: posts held for approval, oldest
	 * first, each with Approve / Reject. When the feature is off and the queue is
	 * empty, it tells the owner where to switch it on.
	 *
	 * @return void
	 */
	public function render_pending(): void {
		$this->maybe_notice();
		$service = new \BuddyNext\Feed\PostService();
		$total   = $service->count_pending();
		$items   = $total > 0 ? $service->get_pending_for_review( 50 ) : array();
		$mode    = \BuddyNext\Moderation\PreModerationService::mode();
		?>
		<div class="bn-settings-section bn-mod-queue" data-mod-queue>
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Posts awaiting approval', 'buddynext' ); ?></span>
				<span class="bn-ss-count"><?php echo esc_html( (string) $total ); ?></span>
			</div>
			<div class="bn-ss-body">
				<?php if ( empty( $items ) ) : ?>
					<p>
						<?php
						if ( 'off' === $mode ) {
							// No "turn it on under Settings" any more — there is no
							// setting to point at. Pre-moderation is developer-only
							// (see PreModerationService), so telling an owner to go
							// looking for a switch would send them somewhere it is
							// not.
							esc_html_e( 'Posts go live as soon as they are written, so nothing waits here. Reported content is handled under Reports.', 'buddynext' );
						} else {
							esc_html_e( 'Nothing waiting. All held posts have been reviewed.', 'buddynext' );
						}
						?>
					</p>
					<?php
					$this->empty_queue_shape(
						array(
							__( 'Post', 'buddynext' ),
							__( 'Author', 'buddynext' ),
							__( 'Space', 'buddynext' ),
							__( 'Held', 'buddynext' ),
							__( 'Actions', 'buddynext' ),
						)
					);
					?>
				<?php else : ?>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Author', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Space', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Held', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $row ) : ?>
							<?php $this->render_pending_row( $row ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one held-post row with Approve / Reject controls.
	 *
	 * @param array<string,mixed> $row Hydrated pending post.
	 * @return void
	 */
	private function render_pending_row( array $row ): void {
		$post_id = (int) ( $row['id'] ?? 0 );
		$author  = (string) ( $row['author_name'] ?? '' );
		$space   = (string) ( $row['space_name'] ?? '' );
		$excerpt = wp_trim_words( wp_strip_all_tags( (string) ( $row['content'] ?? '' ) ), 24, '…' );
		if ( '' === $excerpt && ! empty( $row['link_url'] ) ) {
			$excerpt = (string) $row['link_url'];
		}
		$bn_full_text = trim( wp_strip_all_tags( (string) ( $row['content'] ?? '' ) ) );
		$bn_link      = (string) ( $row['link_url'] ?? '' );
		// A held post is hidden from the front end, so there is no public URL to
		// "view" it — the moderator must read the full text here to decide. The
		// cell shows the excerpt; when the content was trimmed (or carries a link),
		// it expands inline to the full content so they are not approving blind.
		$bn_has_more = ( '' !== $bn_full_text && str_word_count( $bn_full_text ) > 24 ) || '' !== $bn_link;
		$bn_label    = '' !== $excerpt ? $excerpt : sprintf( '%s #%d', esc_html__( 'Post', 'buddynext' ), $post_id );
		?>
		<tr>
			<td>
				<?php if ( $bn_has_more ) : ?>
					<details class="bn-mod-pending-content">
						<summary><?php echo esc_html( $bn_label ); ?></summary>
						<?php if ( '' !== $bn_full_text ) : ?>
							<div class="bn-mod-pending-content__full"><?php echo esc_html( $bn_full_text ); ?></div>
						<?php endif; ?>
						<?php if ( '' !== $bn_link ) : ?>
							<p class="bn-mod-pending-content__link">
								<a href="<?php echo esc_url( $bn_link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $bn_link ); ?></a>
							</p>
						<?php endif; ?>
					</details>
				<?php else : ?>
					<?php echo esc_html( $bn_label ); ?>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( '' !== $author ? $author : __( 'Unknown', 'buddynext' ) ); ?></td>
			<td><?php echo esc_html( '' !== $space ? $space : __( 'Main feed', 'buddynext' ) ); ?></td>
			<td><?php echo esc_html( $this->ago( (string) ( $row['created_at'] ?? '' ) ) ); ?></td>
			<td>
				<div class="bn-row-actions">
				<?php
				$this->action_form(
					'bn_mod_premod_action',
					array(
						'post_id' => $post_id,
						'op'      => 'approve',
					),
					__( 'Approve', 'buddynext' ),
					'primary',
					''
				);
				$this->action_form(
					'bn_mod_premod_action',
					array(
						'post_id' => $post_id,
						'op'      => 'reject',
					),
					__( 'Reject', 'buddynext' ),
					'delete',
					__( 'Reject and delete this post?', 'buddynext' )
				);
				?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render one report row with its action controls.
	 *
	 * @param array<string,mixed> $report Hydrated report.
	 * @return void
	 */
	private function render_report_row( array $report ): void {
		$report_id   = (int) ( $report['id'] ?? 0 );
		$object_type = (string) ( $report['object_type'] ?? '' );
		$object_id   = (int) ( $report['object_id'] ?? 0 );
		$reason      = (string) ( $report['reason'] ?? 'other' );
		$reporter    = get_userdata( (int) ( $report['reporter_id'] ?? 0 ) );
		$author_id   = $this->object_author( $object_type, $object_id );
		$escalated   = 'escalated' === ( $report['status'] ?? '' );
		?>
		<tr>
			<td>
				<?php
				// A moderator deciding whether to strike or suspend cannot act on
				// "User #519". Resolved through the same helper the log uses, so a
				// deleted target is named as deleted on both surfaces.
				$bn_label = buddynext_object_label( $object_type, $object_id );

				// false = we checked and it is gone; null = not ours to answer (a
				// message, or a type an add-on claimed). Only a definite false
				// suppresses anything, so an unknowable type keeps every control.
				$bn_missing = ( false === buddynext_object_exists( $object_type, $object_id ) );

				// A "View content" link to something that no longer exists is a
				// promise the page cannot keep — it lands on a 404 or, worse, on
				// whatever now occupies that id.
				$bn_view_url = $bn_missing ? '' : $this->object_view_url( $object_type, $object_id );
				?>
				<strong><?php echo esc_html( $bn_label ); ?></strong>
				<?php if ( '' !== $bn_view_url ) : ?>
					<a class="bn-mod-view-link" href="<?php echo esc_url( $bn_view_url ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'View content', 'buddynext' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $escalated ) : ?>
					<span class="bn-badge" data-tone="warning"><?php esc_html_e( 'Escalated', 'buddynext' ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $report['notes'] ) ) : ?>
					<p class="description"><?php echo esc_html( (string) $report['notes'] ); ?></p>
				<?php endif; ?>
			</td>
			<td>
				<?php
				// Consolidated rows carry every distinct reason; fall back to the
				// single reason for legacy/single-row reads.
				$bn_reasons = ( ! empty( $report['reasons'] ) && is_array( $report['reasons'] ) ) ? $report['reasons'] : array( $reason );
				$bn_labels  = array_map(
					static function ( $r ) {
						return \BuddyNext\Moderation\ModerationService::reason_label( (string) $r );
					},
					$bn_reasons
				);
				echo esc_html( implode( ', ', $bn_labels ) );
				?>
			</td>
			<td>
				<?php
				$bn_report_count = (int) ( $report['report_count'] ?? 1 );
				if ( $bn_report_count > 1 ) {
					printf(
						/* translators: %d: number of users who reported this content */
						esc_html( _n( 'Reported by %d user', 'Reported by %d users', $bn_report_count, 'buddynext' ) ),
						(int) $bn_report_count
					);
				} elseif ( $reporter ) {
					echo esc_html( $reporter->display_name );
				} elseif ( 0 === (int) ( $report['reporter_id'] ?? 0 ) ) {
					// reporter_id 0 is the system: an auto-moderation rule flagged this,
					// nobody reported it. Rendering that as "(unknown)" told the moderator
					// the reporter had been deleted, which is a different and worrying
					// story. Auto-flags now reach the queue from five surfaces, not one,
					// so this is the common case rather than a curiosity.
					echo esc_html__( 'System (auto-flagged)', 'buddynext' );
				} else {
					echo esc_html__( '(deleted user)', 'buddynext' );
				}
				?>
			</td>
			<td><?php echo esc_html( $this->ago( (string) ( $report['created_at'] ?? '' ) ) ); ?></td>
			<td>
				<div class="bn-row-actions">
					<?php
					$this->report_button( $report_id, 'dismiss', __( 'Dismiss', 'buddynext' ), 'secondary' );
					$this->report_button( $report_id, 'resolve', __( 'Resolve', 'buddynext' ), 'secondary' );
					// "Remove content" only applies to removable objects — remove_object()
					// returns a 422 (bn_removal_unsupported) for user/space reports. Gate
					// the button on the same object types the frontend queue does
					// (templates/moderation/queue.php), so a moderator is never shown an
					// action that always fails.
					//
					// Existence is the second half of the same rule. A report whose
					// target has since been deleted fails the same way and with the
					// same 422 — remove_object() finds nothing to take down — and a
					// queue accumulates those: this surfaced with six of them sitting
					// in the seeded queue, each still offering the button.
					if ( in_array( $object_type, array( 'post', 'comment', 'message' ), true ) && ! $bn_missing ) {
						$this->report_button( $report_id, 'remove', __( 'Remove content', 'buddynext' ), 'delete', __( 'Remove the reported content? It is hidden, not hard-deleted.', 'buddynext' ) );
					}
					if ( ! $escalated ) {
						$this->report_button( $report_id, 'escalate', __( 'Escalate', 'buddynext' ), 'secondary' );
					}
					if ( $author_id > 0 && 'user' !== $object_type ) {
						$this->user_inline_actions( $author_id );
					} elseif ( 'user' === $object_type && $object_id > 0 ) {
						$this->user_inline_actions( $object_id );
					}
					?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the active-suspensions tab.
	 *
	 * @return void
	 */
	public function render_suspensions(): void {
		$this->maybe_notice();
		$service     = new ModerationService();
		$suspensions = $service->get_active_suspensions();

		// Prime both members per row in one pass - the suspended member and whoever
		// suspended them - so a long suspension list stays two queries rather than
		// two per row.
		$bn_sus_pairs = array();
		foreach ( $suspensions as $bn_sus_row ) {
			$bn_sus_pairs[] = array( 'user', (int) $bn_sus_row['user_id'] );
			if ( (int) ( $bn_sus_row['suspended_by'] ?? 0 ) > 0 ) {
				$bn_sus_pairs[] = array( 'user', (int) $bn_sus_row['suspended_by'] );
			}
		}
		buddynext_prime_object_labels( $bn_sus_pairs );
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Active suspensions', 'buddynext' ); ?></span>
				<span class="bn-ss-count"><?php echo esc_html( (string) count( $suspensions ) ); ?></span>
			</div>
			<div class="bn-ss-body">
				<?php if ( empty( $suspensions ) ) : ?>
					<p><?php esc_html_e( 'No members are currently suspended.', 'buddynext' ); ?></p>
					<?php
					$this->empty_queue_shape(
						array(
							__( 'Member', 'buddynext' ),
							__( 'Reason', 'buddynext' ),
							__( 'Suspended by', 'buddynext' ),
							__( 'Expires', 'buddynext' ),
							__( 'Actions', 'buddynext' ),
						)
					);
					?>
				<?php else : ?>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Member', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Suspended by', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $suspensions as $s ) : ?>
							<tr>
								<td><?php echo esc_html( buddynext_member_label( (int) $s['user_id'] ) ); ?></td>
								<td><?php echo esc_html( (string) ( ! empty( $s['reason'] ) ? $s['reason'] : __( '(no reason given)', 'buddynext' ) ) ); ?></td>
								<?php
								// suspended_by is 0 for an automatic strike-threshold sanction and a
								// moderator id otherwise. Until 1.1.6 automated suspensions never
								// applied, so every row here was necessarily a person; now that they
								// do, "who decided this" is the first question an appeal raises and
								// the table could not answer it. The value was already stored - this
								// is a column, not new plumbing. Matches the moderation log's own
								// treatment, including "Deleted member (#id)" for a removed account.
								?>
								<td><?php echo esc_html( buddynext_member_label( (int) ( $s['suspended_by'] ?? 0 ), __( 'System', 'buddynext' ) ) ); ?></td>
								<td><?php echo esc_html( $s['expires_at'] ? $this->ago( (string) $s['expires_at'] ) : __( 'Permanent', 'buddynext' ) ); ?></td>
								<td><?php $this->user_button( (int) $s['user_id'], 'unsuspend', __( 'Lift suspension', 'buddynext' ), 'secondary' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the pending-appeals tab.
	 *
	 * @return void
	 */
	public function render_appeals(): void {
		$this->maybe_notice();
		$service = new ModerationService();
		$appeals = $service->get_pending_appeals();
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Pending appeals', 'buddynext' ); ?></span>
				<span class="bn-ss-count"><?php echo esc_html( (string) count( $appeals ) ); ?></span>
			</div>
			<div class="bn-ss-body">
				<?php if ( empty( $appeals ) ) : ?>
					<p><?php esc_html_e( 'No appeals are waiting for review.', 'buddynext' ); ?></p>
					<?php
					$this->empty_queue_shape(
						array(
							__( 'Member', 'buddynext' ),
							__( 'Appeal', 'buddynext' ),
							__( 'When', 'buddynext' ),
							__( 'Decision', 'buddynext' ),
						)
					);
					?>
				<?php else : ?>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Member', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Appeal', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'When', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Decision', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $appeals as $a ) : ?>
							<tr>
								<td><?php echo esc_html( buddynext_member_label( (int) $a['user_id'] ) ); ?></td>
								<td><?php echo esc_html( (string) $a['message'] ); ?></td>
								<td><?php echo esc_html( $this->ago( (string) ( $a['created_at'] ?? '' ) ) ); ?></td>
								<td>
									<div class="bn-row-actions">
										<?php
										$this->appeal_button( (int) $a['id'], 'approved', __( 'Approve', 'buddynext' ), 'primary' );
										$this->appeal_button( (int) $a['id'], 'denied', __( 'Deny', 'buddynext' ), 'secondary' );
										?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Moderation Log (audit trail) tab — a paginated, read-only view
	 * of bn_mod_log so site owners can review every moderator action.
	 *
	 * @return void
	 */
	public function render_log(): void {
		$filters  = self::log_filters();
		$page     = $filters['page'];
		$per_page = 30;
		$result   = ( new \BuddyNext\Moderation\ModerationLogService() )->get_log(
			array_merge(
				$filters['query'],
				array(
					'page'     => $page,
					'per_page' => $per_page,
				)
			)
		);
		$items    = (array) ( $result['items'] ?? array() );
		$total    = (int) ( $result['total'] ?? 0 );
		$pages    = (int) ceil( $total / $per_page );

		// Notices for the Run-now / export flow.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag; the actions themselves are nonce-checked.
		$bn_flag = isset( $_GET['bn_modlog'] ) ? sanitize_key( wp_unslash( $_GET['bn_modlog'] ) ) : '';
		if ( 'ran' === $bn_flag ) {
			AdminPageBase::render_notice( __( 'Moderation jobs queued to run now.', 'buddynext' ), 'success' );
		}
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Moderation log', 'buddynext' ); ?></span>
				<span class="bn-ss-count"><?php echo esc_html( (string) $total ); ?></span>
			</div>
			<div class="bn-ss-body">
				<?php $this->render_log_toolbar( $filters ); ?>
				<?php if ( empty( $items ) ) : ?>
					<div class="bn-empty">
						<p class="bn-empty__title"><?php esc_html_e( 'No moderator actions have been recorded yet', 'buddynext' ); ?></p>
					</div>
				<?php else : ?>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Moderator', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Action', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Target member', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Object', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Note', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Same one-pass resolve as the report queue above.
						buddynext_prime_object_labels(
							array_map(
								static function ( array $bn_row ): array {
									return array(
										(string) ( $bn_row['object_type'] ?? '' ),
										(int) ( $bn_row['object_id'] ?? 0 ),
									);
								},
								$items
							)
						);

						foreach ( $items as $row ) :
							// The object column used to print "post #4046" whether or not
							// that post still existed, while the member columns already
							// said "Deleted member (#2204)". Since bn_mod_log rows now
							// deliberately outlive their targets, that asymmetry applies
							// to every deleted post and space: a moderator reading the
							// audit trail could not tell a live target from a destroyed
							// one, which matters most in exactly the audit case.
							//
							// The two get_userdata() calls that stood here were dead —
							// both cells render through buddynext_member_label() — so they
							// were two wasted lookups per row.
							$object = buddynext_object_label(
								(string) ( $row['object_type'] ?? '' ),
								(int) ( $row['object_id'] ?? 0 )
							);
							?>
							<tr>
								<td><?php echo esc_html( $this->ago( (string) ( $row['created_at'] ?? '' ) ) ); ?></td>
								<td><?php echo esc_html( buddynext_member_label( (int) ( $row['actor_id'] ?? 0 ), __( 'System', 'buddynext' ) ) ); ?></td>
								<td>
								<?php $bn_action_slug = (string) ( $row['action'] ?? '' ); ?>
								<span class="bn-badge" data-tone="neutral" title="<?php echo esc_attr( $bn_action_slug ); ?>"><?php echo esc_html( $this->action_label( $bn_action_slug ) ); ?></span>
							</td>
								<td><?php echo esc_html( buddynext_member_label( (int) ( $row['target_user_id'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( $object ); ?></td>
								<td><?php echo esc_html( (string) ( $row['note'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
					<?php
					// Shared paginator — numbered links PLUS Prev/Next and the nav/aria
					// a11y wrapper, matching the rest of the admin. Self-guards at one page.
					AdminPageBase::render_pagination(
						$page,
						(int) $pages,
						(int) $total,
						$per_page,
						static function ( int $p ): string {
							return add_query_arg( 'log_page', $p, remove_query_arg( 'log_page' ) );
						},
						__( 'Moderation log pagination', 'buddynext' )
					);
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Parse the moderation-log filter GET params into get_log() args + a page.
	 *
	 * Read-only screen params; the destructive actions (export, run-now) are
	 * nonce-checked in their own handlers.
	 *
	 * @return array{page:int, query:array<string,mixed>}
	 */
	private static function log_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters on an admin screen, sanitized here and escaped at output.
		$page   = isset( $_GET['log_page'] ) ? max( 1, absint( wp_unslash( $_GET['log_page'] ) ) ) : 1;
		$action = isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( $_GET['log_action'] ) ) : '';
		// Three distinct member/space filters. The old single 'log_actor' input was
		// LABELLED as a member filter but silently filtered by TARGET, so "what has
		// moderator X done?" returned rows where X was the victim. Now: log_target
		// filters the target member, log_actor filters the moderator (actor), and
		// log_space filters the space — each with its own visible label below.
		$target = isset( $_GET['log_target'] ) ? absint( wp_unslash( $_GET['log_target'] ) ) : 0;
		$actor  = isset( $_GET['log_actor'] ) ? absint( wp_unslash( $_GET['log_actor'] ) ) : 0;
		$space  = isset( $_GET['log_space'] ) ? absint( wp_unslash( $_GET['log_space'] ) ) : 0;
		$since  = isset( $_GET['log_since'] ) ? sanitize_text_field( wp_unslash( $_GET['log_since'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query = array();
		if ( '' !== $action ) {
			$query['action'] = $action;
		}
		if ( $target > 0 ) {
			$query['user_id'] = $target; // get_log() filters on target_user_id.
		}
		if ( $actor > 0 ) {
			$query['actor_id'] = $actor;
		}
		if ( $space > 0 ) {
			$query['space_id'] = $space;
		}
		if ( '' !== $since ) {
			$query['since'] = $since;
		}

		return array(
			'page'  => $page,
			'query' => $query,
		);
	}

	/**
	 * The moderation-log toolbar: action/member/date filters, CSV export of the
	 * filtered set, and a Run-now button for the background moderation jobs.
	 *
	 * @param array{page:int, query:array<string,mixed>} $filters Active filters.
	 * @return void
	 */
	/**
	 * Human labels for moderation-log action slugs.
	 *
	 * ONE map, so the filter dropdown, the Action column and the CSV export all
	 * read the same way. The column used to print the raw slug (dismiss_report)
	 * while the filter above it showed the label ("Report dismissed"), so an owner
	 * could not tell they were the same thing.
	 *
	 * @return array<string,string>
	 */
	private function action_labels(): array {
		return array(
			// Member sanctions. 'suspend' (automatic, from the strike threshold) and
			// 'suspend_user' (a moderator/admin acting directly) are BOTH written and
			// are distinct slugs — labelled distinctly so filtering by one does not
			// silently drop the other (card 10284912236). The dead 'unsuspend' slug
			// nothing ever wrote is gone; the real one is 'unsuspend_user'.
			// 'warn' is a high-volume, filterable sanction written by
			// ModerationService::warn(); without a label the dropdown and the column
			// badge disagreed for it (card 10284912236 item 1). 'warned' is the legacy
			// slug some older rows carry.
			'warn'              => __( 'Warning issued', 'buddynext' ),
			'warned'            => __( 'Warning issued', 'buddynext' ),
			'issue_strike'      => __( 'Strike issued', 'buddynext' ),
			'reverse_strike'    => __( 'Strike reversed', 'buddynext' ),
			'suspend_user'      => __( 'Suspended', 'buddynext' ),
			'suspend'           => __( 'Suspended (automatic)', 'buddynext' ),
			'unsuspend_user'    => __( 'Unsuspended', 'buddynext' ),
			'perma_ban'         => __( 'Permanent ban', 'buddynext' ),
			'shadow_ban'        => __( 'Shadow ban', 'buddynext' ),
			'remove_shadow_ban' => __( 'Shadow ban removed', 'buddynext' ),
			// Space sanctions.
			'space_ban'         => __( 'Banned from space', 'buddynext' ),
			'space_unban'       => __( 'Space ban lifted', 'buddynext' ),
			// Reports + content.
			'dismiss_report'    => __( 'Report dismissed', 'buddynext' ),
			'escalate_report'   => __( 'Report escalated', 'buddynext' ),
			'resolve_report'    => __( 'Report resolved', 'buddynext' ),
			'remove_content'    => __( 'Content removed', 'buddynext' ),
			'approve_pending'   => __( 'Post approved', 'buddynext' ),
			'reject_pending'    => __( 'Post rejected', 'buddynext' ),
			// Appeals.
			'approve_appeal'    => __( 'Appeal approved', 'buddynext' ),
			'deny_appeal'       => __( 'Appeal denied', 'buddynext' ),
			'resolve_appeal'    => __( 'Appeal resolved', 'buddynext' ),
			// AI sweep (pro), written with the ai_ prefix + the system actor.
			'ai_remove_content' => __( 'AI: content removed', 'buddynext' ),
			'ai_escalate'       => __( 'AI: escalated', 'buddynext' ),
			'ai_dismiss'        => __( 'AI: dismissed', 'buddynext' ),
		);
	}

	/**
	 * Label for one action slug, with a humanised fallback for anything not in the
	 * map (a partner-registered action, say) so the column never shows a bare slug.
	 *
	 * @param string $slug Action slug.
	 * @return string
	 */
	private function action_label( string $slug ): string {
		$labels = $this->action_labels();
		if ( isset( $labels[ $slug ] ) ) {
			return $labels[ $slug ];
		}
		return '' === $slug ? '—' : ucfirst( str_replace( '_', ' ', $slug ) );
	}

	/**
	 * Render the moderation-log filter toolbar: action dropdown, moderator / target
	 * / space id inputs, a since-date, and the CSV export + run-now controls.
	 *
	 * @param array $filters Resolved filter state (the 'query' key holds the
	 *                       current action / user_id / actor_id / space_id / since).
	 * @return void
	 */
	private function render_log_toolbar( array $filters ): void {
		$q          = $filters['query'];
		$actions    = $this->action_labels();
		$cur_action = (string) ( $q['action'] ?? '' );
		$cur_target = (int) ( $q['user_id'] ?? 0 );
		$cur_actor  = (int) ( $q['actor_id'] ?? 0 );
		$cur_space  = (int) ( $q['space_id'] ?? 0 );
		$cur_since  = (string) ( $q['since'] ?? '' );
		$export_url = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'     => 'bn_mod_log_export',
						'log_action' => $cur_action,
						'log_target' => $cur_target ?: '',
						'log_actor'  => $cur_actor ?: '',
						'log_space'  => $cur_space ?: '',
						'log_since'  => $cur_since,
					)
				),
				admin_url( 'admin-post.php' )
			),
			'bn_mod_log_export'
		);
		?>
		<div class="bn-toolbar bn-modlog-toolbar">
			<form method="get" class="bn-modlog-filters">
				<?php // Preserve the admin page/tab routing so the filtered view stays on this tab. ?>
				<?php foreach ( array( 'page', 'tab' ) as $bn_keep ) : ?>
					<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<?php if ( isset( $_GET[ $bn_keep ] ) ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $bn_keep ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET[ $bn_keep ] ) ) ); ?>">
					<?php endif; ?>
				<?php endforeach; ?>
				<select name="log_action" class="bn-select" aria-label="<?php esc_attr_e( 'Filter by action', 'buddynext' ); ?>">
					<option value=""><?php esc_html_e( 'All actions', 'buddynext' ); ?></option>
					<?php foreach ( $actions as $bn_slug => $bn_label ) : ?>
						<option value="<?php echo esc_attr( $bn_slug ); ?>" <?php selected( $cur_action, $bn_slug ); ?>><?php echo esc_html( $bn_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="number" name="log_actor" class="bn-input" min="0" value="<?php echo esc_attr( $cur_actor ?: '' ); ?>" placeholder="<?php esc_attr_e( 'Moderator ID', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'Filter by moderator (actor) ID', 'buddynext' ); ?>">
				<input type="number" name="log_target" class="bn-input" min="0" value="<?php echo esc_attr( $cur_target ?: '' ); ?>" placeholder="<?php esc_attr_e( 'Target member ID', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'Filter by target member ID', 'buddynext' ); ?>">
				<input type="number" name="log_space" class="bn-input" min="0" value="<?php echo esc_attr( $cur_space ?: '' ); ?>" placeholder="<?php esc_attr_e( 'Space ID', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'Filter by space ID', 'buddynext' ); ?>">
				<input type="date" name="log_since" class="bn-input" value="<?php echo esc_attr( $cur_since ); ?>" aria-label="<?php esc_attr_e( 'Show entries since', 'buddynext' ); ?>">
				<button type="submit" class="bn-btn" data-variant="secondary"><?php esc_html_e( 'Filter', 'buddynext' ); ?></button>
			</form>
			<div class="bn-modlog-actions">
				<a class="bn-btn" data-variant="ghost" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'buddynext' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-modlog-runnow">
					<input type="hidden" name="action" value="bn_mod_run_now">
					<?php wp_nonce_field( 'bn_mod_run_now' ); ?>
					<button type="submit" class="bn-btn" data-variant="secondary"><?php esc_html_e( 'Run now', 'buddynext' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Stream the filtered moderation log as a CSV download (admin only).
	 *
	 * @return void
	 */
	public function handle_export_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_mod_log_export' );

		$filters = self::log_filters();
		$log     = new \BuddyNext\Moderation\ModerationLogService();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=moderation-log-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'When (UTC)', 'Moderator', 'Action', 'Target member', 'Object type', 'Object ID', 'Space ID', 'Note' ) );

		// Page through so a large log never loads whole into memory.
		$page = 1;
		do {
			$result = $log->get_log(
				array_merge(
					$filters['query'],
					array(
						'page'     => $page,
						'per_page' => 100,
					)
				)
			);
			$items  = (array) ( $result['items'] ?? array() );
			foreach ( $items as $row ) {
				fputcsv(
					$out,
					array(
						(string) ( $row['created_at'] ?? '' ),
						buddynext_member_label( (int) ( $row['actor_id'] ?? 0 ), __( 'System', 'buddynext' ) ),
						$this->action_label( (string) ( $row['action'] ?? '' ) ),
						buddynext_member_label( (int) ( $row['target_user_id'] ?? 0 ) ),
						(string) ( $row['object_type'] ?? '' ),
						(int) ( $row['object_id'] ?? 0 ),
						(int) ( $row['space_id'] ?? 0 ),
						(string) ( $row['note'] ?? '' ),
					)
				);
			}
			++$page;
		} while ( ! empty( $items ) && (int) ( $result['total'] ?? 0 ) > ( $page - 1 ) * 100 );

		fclose( $out );
		exit;
	}

	/**
	 * Run the background moderation jobs now (admin only): fires
	 * buddynext_run_moderation_jobs, which the AI sweep (Pro) and retention
	 * cleanup hook into, then returns to the log with a notice.
	 *
	 * @return void
	 */
	public function handle_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_mod_run_now' );

		/**
		 * Fires when an admin clicks "Run now" on the moderation log — an on-demand
		 * trigger for the moderation background jobs (the Pro AI sweep, retention
		 * cleanup). Nothing runs on a free-only install with no listeners, which is
		 * correct: there is no AI sweep to run.
		 */
		do_action( 'buddynext_run_moderation_jobs' );

		wp_safe_redirect( add_query_arg( 'bn_modlog', 'ran', AdminHub::tab_url( 'moderation', 'log' ) ) );
		exit;
	}

	// ── Action handlers ─────────────────────────────────────────────────────

	/**
	 * Handle a per-report action (dismiss / resolve / remove / escalate).
	 *
	 * @return void
	 */
	public function handle_report_action(): void {
		$this->guard( 'bn_mod_report_action' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in guard() via check_admin_referer().
		$report_id = isset( $_POST['report_id'] ) ? absint( wp_unslash( $_POST['report_id'] ) ) : 0;
		$op        = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$actor   = get_current_user_id();
		$service = new ModerationService();

		// Read the report's space_id BEFORE running the action. An action can remove
		// the reported content and, in future, the report row itself; reading after
		// the switch then returns null and the log row defaults space_id to 0, so the
		// action vanishes from that space's Moderation tab (which filters on
		// space_id). The premod path already reads before acting; do the same here
		// (card 10264294456). Captured for every op — the log below only uses it for
		// the report_actions ops, but capturing unconditionally keeps it correct if
		// the switch grows.
		$report_row      = $service->get_report( $report_id );
		$report_space_id = (int) ( ( is_array( $report_row ) ? $report_row : array() )['space_id'] ?? 0 );

		$result = true;
		switch ( $op ) {
			case 'dismiss':
				$result = $service->dismiss( $report_id, $actor );
				break;
			case 'resolve':
				$result = $service->resolve( $report_id, $actor );
				break;
			case 'remove':
				$result = $service->remove_content( $report_id, $actor );
				break;
			case 'escalate':
				$result = $service->escalate( $report_id, $actor );
				break;
		}

		// Audit trail: log the successful action (mirrors the REST controller's
		// action names) so admin-queue actions appear in bn_mod_log too.
		$report_actions = array(
			'dismiss'  => 'dismiss_report',
			'resolve'  => 'resolve_report',
			'remove'   => 'remove_content',
			'escalate' => 'escalate_report',
		);
		if ( ! is_wp_error( $result ) && isset( $report_actions[ $op ] ) ) {
			// space_id read pre-action above, so a space report actioned from wp-admin
			// shows on THAT space's Moderation tab, not only the site log. Mirrors
			// Pro's BulkModService.
			( new \BuddyNext\Moderation\ModerationLogService() )->log(
				$actor,
				$report_actions[ $op ],
				array(
					'report_id' => $report_id,
					'space_id'  => $report_space_id,
				)
			);
		}

		$this->redirect_back( 'reports', $result );
	}

	/**
	 * Handle a per-user action (strike / suspend / unsuspend).
	 *
	 * @return void
	 */
	public function handle_user_action(): void {
		$this->guard( 'bn_mod_user_action' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in guard() via check_admin_referer().
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$actor   = get_current_user_id();
		$service = new ModerationService();
		$tab     = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['return_tab'] ) ) : 'reports';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = true;
		switch ( $op ) {
			case 'strike':
				$result = $service->issue_strike( $user_id, $actor, __( 'Issued from the moderation queue.', 'buddynext' ) );
				break;
			case 'suspend':
				$result = $service->suspend_user( $user_id, $actor, __( 'Suspended from the moderation queue.', 'buddynext' ) );
				break;
			case 'unsuspend':
				$result = $service->unsuspend_user( $user_id, $actor );
				break;
		}

		$user_actions = array(
			'strike'    => 'issue_strike',
			'suspend'   => 'suspend_user',
			'unsuspend' => 'unsuspend_user',
		);
		if ( ! is_wp_error( $result ) && isset( $user_actions[ $op ] ) ) {
			( new \BuddyNext\Moderation\ModerationLogService() )->log( $actor, $user_actions[ $op ], array( 'target_user_id' => $user_id ) );
		}

		$this->redirect_back( $tab, $result );
	}

	/**
	 * Handle an appeal decision (approve / deny).
	 *
	 * @return void
	 */
	public function handle_appeal_action(): void {
		$this->guard( 'bn_mod_appeal_action' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in guard() via check_admin_referer().
		$appeal_id = isset( $_POST['appeal_id'] ) ? absint( wp_unslash( $_POST['appeal_id'] ) ) : 0;
		$decision  = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$actor = get_current_user_id();

		$result = in_array( $decision, array( 'approved', 'denied' ), true )
			? ( new ModerationService() )->resolve_appeal( $appeal_id, $actor, $decision )
			: new \WP_Error( 'bn_invalid_decision', __( 'Choose approve or deny.', 'buddynext' ) );

		if ( ! is_wp_error( $result ) ) {
			( new \BuddyNext\Moderation\ModerationLogService() )->log(
				$actor,
				'resolve_appeal',
				array(
					'appeal_id' => $appeal_id,
					'decision'  => $decision,
				)
			);
		}

		$this->redirect_back( 'appeals', $result );
	}

	/**
	 * Approve or reject a held ('pending') post from the Pending queue.
	 *
	 * @return void
	 */
	public function handle_premod_action(): void {
		$this->guard( 'bn_mod_premod_action' ); // Verifies the nonce via check_admin_referer().

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in guard() via check_admin_referer().
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$service = new \BuddyNext\Feed\PostService();

		// Capture the pending post's space BEFORE the action (reject removes the row),
		// so a space's premod action is logged against THAT space's Moderation tab,
		// not only the site log — the tab filters on space_id and this writer
		// defaulted it to 0 (card 10264294456 RFT round 4). Status-agnostic PK read.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bn_pending_space_id = $post_id > 0
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id ) )
			: 0;

		$result    = true;
		$op_action = '';
		if ( 'approve' === $op ) {
			$result    = $service->approve_pending( $post_id );
			$op_action = 'approve_pending';
		} elseif ( 'reject' === $op ) {
			$result    = $service->reject_pending( $post_id );
			$op_action = 'reject_pending';
		}

		// approve_pending()/reject_pending() return a bool, not a WP_Error — so a
		// false (post already handled / not found) must surface as a failure
		// instead of being logged and redirected as a phantom success.
		if ( false === $result ) {
			$result = new \WP_Error( 'premod_action_failed', __( 'Could not update that post. It may already have been handled.', 'buddynext' ) );
		}

		if ( ! is_wp_error( $result ) && '' !== $op_action ) {
			( new \BuddyNext\Moderation\ModerationLogService() )->log(
				get_current_user_id(),
				$op_action,
				array(
					'post_id'  => $post_id,
					'space_id' => $bn_pending_space_id,
				)
			);
		}

		$this->redirect_back( 'pending', $result );
	}

	// ── Small render + flow helpers ─────────────────────────────────────────

	/**
	 * Render the column shape of an empty queue - headers only, no invented rows.
	 *
	 * This used to render four rows of fabricated reports: Post: "Buy followers
	 * cheap" / Spam / Sample Reporter / 1 hour ago, a fake impersonation report
	 * against "Admin Team", and two more. They were dimmed and badged "example
	 * only", but they still read as content: an owner glancing at Moderation saw
	 * four rows that looked like work waiting for them.
	 *
	 * Invented records do not belong on a screen whose entire job is telling the
	 * owner what is really happening in their community - least of all one where
	 * the fake rows name plausible members and accuse them of spam and
	 * impersonation.
	 *
	 * Headers only, and no message: each caller already prints its own accurate
	 * empty line immediately above ("Nothing to review. The queue is clear.",
	 * "No members are currently suspended.", "No appeals are waiting for
	 * review."). A sentence in here would be redundant on one tab and wrong on
	 * the other three.
	 *
	 * @param array<int,string> $columns Column headers.
	 * @return void
	 */
	private function empty_queue_shape( array $columns ): void {
		?>
		<div class="bn-mod-preview">
			<table class="widefat bn-mod-preview__table">
				<thead>
					<tr>
						<?php foreach ( $columns as $col ) : ?>
							<th><?php echo esc_html( (string) $col ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>

			</table>
		</div>
		<?php
	}

	/**
	 * Render a single-button report-action form.
	 *
	 * @param int    $report_id Report ID.
	 * @param string $op        Operation key.
	 * @param string $label     Button label.
	 * @param string $variant   WP button class hint (secondary|delete|primary).
	 * @param string $confirm   Optional confirm() prompt.
	 * @return void
	 */
	private function report_button( int $report_id, string $op, string $label, string $variant, string $confirm = '' ): void {
		$this->action_form(
			'bn_mod_report_action',
			array(
				'report_id' => $report_id,
				'op'        => $op,
			),
			$label,
			$variant,
			$confirm
		);
	}

	/**
	 * Render strike + suspend buttons for a content author.
	 *
	 * @param int $user_id Author user ID.
	 * @return void
	 */
	private function user_inline_actions( int $user_id ): void {
		$this->user_button( $user_id, 'strike', __( 'Strike author', 'buddynext' ), 'secondary' );

		// Already suspended: show the state, not a Suspend button. Re-suspending is
		// a server-side no-op (suspend_user() returns the existing active
		// suspension), so offering it told the moderator nothing and hid the one
		// fact they needed — that this account is already out. Mirrors the frontend
		// queue's "Already suspended" state.
		if ( buddynext_service( 'moderation' )->is_suspended( $user_id ) ) {
			echo '<span class="bn-badge" data-tone="warning">' . esc_html__( 'Already suspended', 'buddynext' ) . '</span>';
			return;
		}

		$this->user_button( $user_id, 'suspend', __( 'Suspend author', 'buddynext' ), 'delete', __( 'Suspend this member?', 'buddynext' ) );
	}

	/**
	 * Render a single-button user-action form.
	 *
	 * @param int    $user_id User ID.
	 * @param string $op      Operation key.
	 * @param string $label   Button label.
	 * @param string $variant Button class hint.
	 * @param string $confirm Optional confirm() prompt.
	 * @return void
	 */
	private function user_button( int $user_id, string $op, string $label, string $variant, string $confirm = '' ): void {
		$this->action_form(
			'bn_mod_user_action',
			array(
				'user_id'    => $user_id,
				'op'         => $op,
				'return_tab' => $this->current_tab(),
			),
			$label,
			$variant,
			$confirm
		);
	}

	/**
	 * Render an appeal-decision button form.
	 *
	 * @param int    $appeal_id Appeal ID.
	 * @param string $decision  approved|denied.
	 * @param string $label     Button label.
	 * @param string $variant   Button class hint.
	 * @return void
	 */
	private function appeal_button( int $appeal_id, string $decision, string $label, string $variant ): void {
		$this->action_form(
			'bn_mod_appeal_action',
			array(
				'appeal_id' => $appeal_id,
				'decision'  => $decision,
			),
			$label,
			$variant,
			''
		);
	}

	/**
	 * Render a tiny inline admin-post form carrying one action.
	 *
	 * @param string              $action  admin-post action (also the nonce).
	 * @param array<string,mixed> $fields  Hidden field name => value.
	 * @param string              $label   Button label.
	 * @param string              $variant Button class hint.
	 * @param string              $confirm Optional confirm() prompt.
	 * @return void
	 */
	private function action_form( string $action, array $fields, string $label, string $variant, string $confirm ): void {
		$data_variant = 'secondary';
		if ( 'primary' === $variant ) {
			$data_variant = 'primary';
		} elseif ( 'delete' === $variant ) {
			$data_variant = 'danger';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-row-actions__form"
			<?php
			// Declarative confirm via the shared bn-admin-dialogs modal (enqueued on
			// every buddynext-* admin page); replaces the native browser confirm().
			if ( '' !== $confirm ) :
				?>
				data-bn-confirm="<?php echo esc_attr( $confirm ); ?>" data-bn-confirm-tone="<?php echo esc_attr( 'delete' === $variant ? 'danger' : 'neutral' ); ?>"<?php endif; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="bn-btn" data-variant="<?php echo esc_attr( $data_variant ); ?>" data-size="sm"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Shared handler guard: capability + nonce.
	 *
	 * @param string $action Nonce/action name.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to a moderation tab, reflecting the action's outcome.
	 *
	 * A WP_Error result (e.g. unsuspending a user who is not suspended, or acting
	 * on a missing appeal/report) redirects with the error message so the
	 * moderator sees a real failure notice instead of a false "Done." — every
	 * handler must pass the service return value here.
	 *
	 * @param string          $tab    Tab slug.
	 * @param mixed|\WP_Error $result Service return value; WP_Error means failure.
	 * @return void
	 */
	private function redirect_back( string $tab, $result = true ): void {
		$args = array(
			'page' => 'buddynext-moderation',
			'tab'  => $tab,
		);

		if ( is_wp_error( $result ) ) {
			$args['bn_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['bn_done'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Print the post-action success or error notice when present.
	 *
	 * @return void
	 */
	private function maybe_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filter/notice params on an admin screen; every value is sanitized here and escaped at output.
		if ( ! empty( $_GET['bn_done'] ) ) {
			AdminPageBase::render_notice( __( 'Done.', 'buddynext' ), 'success', false, array( 'data-bn-clear-param' => 'bn_done bn_error' ) );
		}

		if ( ! empty( $_GET['bn_error'] ) ) {
			$bn_err = sanitize_text_field( wp_unslash( (string) $_GET['bn_error'] ) );
			AdminPageBase::render_notice( (string) $bn_err, 'error', false, array( 'data-bn-clear-param' => 'bn_done bn_error' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The current moderation tab slug (defaults to reports).
	 *
	 * @return string
	 */
	private function current_tab(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filter/notice params on an admin screen; every value is sanitized here and escaped at output.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'reports';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, array( 'pending', 'reports', 'suspensions', 'appeals', 'log' ), true ) ? $tab : 'reports';
	}

	/**
	 * Best-effort author of a reported object (post/comment). 0 when unknown.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return int
	 */
	private function object_author( string $object_type, int $object_id ): int {
		if ( $object_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 'post' === $object_type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d", $object_id ) );
		}
		if ( 'comment' === $object_type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bn_comments WHERE id = %d", $object_id ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return 0;
	}

	/**
	 * Resolve a front-end URL for the reported object so a moderator can open
	 * and review the actual content (post permalink, the comment's parent post,
	 * or the reported member's profile). Returns '' when no URL applies.
	 *
	 * Reports carry five object types (post, comment, user, space, message - see
	 * ModerationService). This handled three, and the other two fell through to the
	 * empty case, so the "View content" link was suppressed with no indication that
	 * anything was missing. A moderator asked to judge a reported SPACE had no way
	 * to open it short of guessing the URL or searching by name.
	 *
	 * @param string $object_type Reported object type (post|comment|user|space|message).
	 * @param int    $object_id   Reported object ID.
	 * @return string Front-end URL, or '' when the type has no viewable page.
	 */
	private function object_view_url( string $object_type, int $object_id ): string {
		if ( $object_id <= 0 ) {
			return '';
		}

		if ( 'post' === $object_type ) {
			return \BuddyNext\Core\PageRouter::post_url( $object_id );
		}
		if ( 'user' === $object_type ) {
			return \BuddyNext\Core\PageRouter::profile_url( $object_id );
		}
		if ( 'space' === $object_type ) {
			return \BuddyNext\Core\PageRouter::space_url( $object_id );
		}
		if ( 'comment' === $object_type ) {
			global $wpdb;
			// A comment has no standalone page — deep-link to its parent post.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin deep-link lookup; prepared; not a hot path.
			$post_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT object_id FROM {$wpdb->prefix}bn_comments WHERE id = %d AND object_type = 'post'", $object_id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $post_id > 0 ? \BuddyNext\Core\PageRouter::post_url( $post_id ) : '';
		}

		// 'message' returns '' deliberately, and this is the one type where that is
		// the right answer rather than an oversight. A reported DM has no page a
		// moderator can open, and manufacturing one would expose a private
		// conversation - including the half the reporter did not report - to anyone
		// with the moderation screen. The queue already shows the reported message's
		// excerpt inline, which is the part that was actually reported.
		return '';
	}

	/**
	 * Human "x ago" / "in x" for a MySQL datetime, or '' when empty.
	 *
	 * @param string $datetime MySQL datetime.
	 * @return string
	 */
	private function ago( string $datetime ): string {
		if ( '' === $datetime ) {
			return '';
		}
		$ts = strtotime( $datetime );
		if ( ! $ts ) {
			return '';
		}
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		return $ts <= $now
			/* translators: %s: human-readable time difference, e.g. "3 hours". */
			? sprintf( __( '%s ago', 'buddynext' ), human_time_diff( $ts, $now ) )
			/* translators: %s: human time difference, e.g. "3 days" */
			: sprintf( __( 'in %s', 'buddynext' ), human_time_diff( $now, $ts ) );
	}
}
