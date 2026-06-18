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
	 * Human labels for report reasons.
	 *
	 * @var array<string,string>
	 */
	private const REASON_LABELS = array(
		'spam'           => 'Spam',
		'harassment'     => 'Harassment',
		'misinformation' => 'Misinformation',
		'inappropriate'  => 'Inappropriate',
		'fake'           => 'Fake / scam',
		'impersonation'  => 'Impersonation',
		'other'          => 'Other',
	);

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

		// Pending approval queue first — it is proactive (clear held posts so they
		// go live) rather than reactive like reports. Hidden by AdminHub when the
		// pre-moderation feature is off, but the tab itself always registers so a
		// held post is never stranded.
		AdminHub::register_tab( 'moderation', 'pending', __( 'Pending', 'buddynext' ), array( $this, 'render_pending' ), array( 'position' => 5 ) );
		AdminHub::register_tab( 'moderation', 'reports', __( 'Reports', 'buddynext' ), array( $this, 'render_reports' ), array( 'position' => 10 ) );
		AdminHub::register_tab( 'moderation', 'suspensions', __( 'Suspensions', 'buddynext' ), array( $this, 'render_suspensions' ), array( 'position' => 20 ) );
		AdminHub::register_tab( 'moderation', 'appeals', __( 'Appeals', 'buddynext' ), array( $this, 'render_appeals' ), array( 'position' => 30 ) );
	}

	// ── Renderers ───────────────────────────────────────────────────────────

	/**
	 * Render the report queue tab.
	 *
	 * @return void
	 */
	public function render_reports(): void {
		$this->maybe_notice();
		$service = new ModerationService();
		$queue   = $service->get_queue( array( 'per_page' => 50 ) );
		$items   = $queue['items'] ?? array();
		?>
		<div class="bn-settings-section bn-mod-queue" data-mod-queue>
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Report queue', 'buddynext' ); ?></span>
				<span class="bn-ss-count"><?php echo esc_html( (string) ( $queue['total'] ?? 0 ) ); ?></span>
			</div>
			<div class="bn-ss-body">
				<?php if ( empty( $items ) ) : ?>
					<p><?php esc_html_e( 'Nothing to review. The queue is clear.', 'buddynext' ); ?></p>
					<?php
					$this->sample_preview(
						array(
							__( 'Reported content', 'buddynext' ),
							__( 'Reason', 'buddynext' ),
							__( 'Reporter', 'buddynext' ),
							__( 'When', 'buddynext' ),
							__( 'Actions', 'buddynext' ),
						),
						array(
							array( __( 'Post: "Buy followers cheap"', 'buddynext' ), __( 'Spam', 'buddynext' ), __( 'Sample Reporter', 'buddynext' ), __( '1 hour ago', 'buddynext' ), __( 'Dismiss / Resolve', 'buddynext' ) ),
							array( __( 'Comment on "Weekly check-in"', 'buddynext' ), __( 'Harassment', 'buddynext' ), __( 'Concerned Member', 'buddynext' ), __( '3 hours ago', 'buddynext' ), __( 'Dismiss / Resolve', 'buddynext' ) ),
							array( __( 'Post: "Miracle cure, no proof"', 'buddynext' ), __( 'Misinformation', 'buddynext' ), __( 'Fact Checker', 'buddynext' ), __( '6 hours ago', 'buddynext' ), __( 'Dismiss / Resolve', 'buddynext' ) ),
							array( __( 'Profile of "Admin Team"', 'buddynext' ), __( 'Impersonation', 'buddynext' ), __( 'Real Person', 'buddynext' ), __( '1 day ago', 'buddynext' ), __( 'Dismiss / Resolve', 'buddynext' ) ),
						)
					);
					?>
				<?php else : ?>
				<table class="widefat striped">
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
						<?php foreach ( $items as $report ) : ?>
							<?php $this->render_report_row( $report ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>
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
							printf(
								/* translators: %s: settings link */
								esc_html__( 'Pre-moderation is off, so posts go live instantly. Turn it on under %s if you need to approve posts before they appear.', 'buddynext' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=buddynext-moderation&tab=moderation' ) ) . '">' . esc_html__( 'Moderation > Controls', 'buddynext' ) . '</a>'
							);
						} else {
							esc_html_e( 'Nothing waiting. All held posts have been reviewed.', 'buddynext' );
						}
						?>
					</p>
					<?php
					$this->sample_preview(
						array(
							__( 'Post', 'buddynext' ),
							__( 'Author', 'buddynext' ),
							__( 'Space', 'buddynext' ),
							__( 'Held', 'buddynext' ),
							__( 'Actions', 'buddynext' ),
						),
						array(
							array( __( 'Check out my new portfolio site', 'buddynext' ), __( 'Sample Member', 'buddynext' ), __( 'Photography', 'buddynext' ), __( '2 hours ago', 'buddynext' ), __( 'Approve / Reject', 'buddynext' ) ),
							array( __( 'Has anyone tried the new feature yet?', 'buddynext' ), __( 'New Joiner', 'buddynext' ), __( 'General', 'buddynext' ), __( '5 hours ago', 'buddynext' ), __( 'Approve / Reject', 'buddynext' ) ),
							array( __( 'Big sale, visit my shop now', 'buddynext' ), __( 'Spammy Sam', 'buddynext' ), __( 'Main feed', 'buddynext' ), __( '1 day ago', 'buddynext' ), __( 'Approve / Reject', 'buddynext' ) ),
							array( __( 'Loving this community so far', 'buddynext' ), __( 'Quiet Riley', 'buddynext' ), __( 'Introductions', 'buddynext' ), __( '1 day ago', 'buddynext' ), __( 'Approve / Reject', 'buddynext' ) ),
							array( __( 'Sharing a helpful link about onboarding', 'buddynext' ), __( 'Helpful Hana', 'buddynext' ), __( 'Resources', 'buddynext' ), __( '2 days ago', 'buddynext' ), __( 'Approve / Reject', 'buddynext' ) ),
						)
					);
					?>
				<?php else : ?>
				<table class="widefat striped">
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
		?>
		<tr>
			<td><?php echo esc_html( '' !== $excerpt ? $excerpt : sprintf( '%s #%d', esc_html__( 'Post', 'buddynext' ), $post_id ) ); ?></td>
			<td><?php echo esc_html( '' !== $author ? $author : __( 'Unknown', 'buddynext' ) ); ?></td>
			<td><?php echo esc_html( '' !== $space ? $space : __( 'Main feed', 'buddynext' ) ); ?></td>
			<td><?php echo esc_html( $this->ago( (string) ( $row['created_at'] ?? '' ) ) ); ?></td>
			<td>
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
				$bn_view_url = $this->object_view_url( $object_type, $object_id );
				$bn_label    = sprintf( '%s #%d', ucfirst( $object_type ), $object_id );
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
						return self::REASON_LABELS[ $r ] ?? ucfirst( (string) $r );
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
				} else {
					echo esc_html( $reporter ? $reporter->display_name : __( '(unknown)', 'buddynext' ) );
				}
				?>
			</td>
			<td><?php echo esc_html( $this->ago( (string) ( $report['created_at'] ?? '' ) ) ); ?></td>
			<td>
				<div class="bn-mod-actions">
					<?php
					$this->report_button( $report_id, 'dismiss', __( 'Dismiss', 'buddynext' ), 'secondary' );
					$this->report_button( $report_id, 'resolve', __( 'Resolve', 'buddynext' ), 'secondary' );
					$this->report_button( $report_id, 'remove', __( 'Remove content', 'buddynext' ), 'delete', __( 'Remove the reported content? It is hidden, not hard-deleted.', 'buddynext' ) );
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
					$this->sample_preview(
						array(
							__( 'Member', 'buddynext' ),
							__( 'Reason', 'buddynext' ),
							__( 'Expires', 'buddynext' ),
							__( 'Actions', 'buddynext' ),
						),
						array(
							array( __( 'Sample Member', 'buddynext' ), __( 'Repeated spam', 'buddynext' ), __( 'in 3 days', 'buddynext' ), __( 'Lift suspension', 'buddynext' ) ),
							array( __( 'Rule Breaker', 'buddynext' ), __( 'Harassment', 'buddynext' ), __( 'in 7 days', 'buddynext' ), __( 'Lift suspension', 'buddynext' ) ),
							array( __( 'Test Account', 'buddynext' ), __( 'Ban evasion', 'buddynext' ), __( 'Permanent', 'buddynext' ), __( 'Lift suspension', 'buddynext' ) ),
							array( __( 'Cooled Off', 'buddynext' ), __( 'Off-topic flooding', 'buddynext' ), __( 'in 1 day', 'buddynext' ), __( 'Lift suspension', 'buddynext' ) ),
						)
					);
					?>
				<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Member', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'buddynext' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $suspensions as $s ) : ?>
							<?php $u = get_userdata( (int) $s['user_id'] ); ?>
							<tr>
								<td><?php echo esc_html( $u ? $u->display_name : '#' . (int) $s['user_id'] ); ?></td>
								<td><?php echo esc_html( (string) ( $s['reason'] ?: __( '(no reason given)', 'buddynext' ) ) ); ?></td>
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
					$this->sample_preview(
						array(
							__( 'Member', 'buddynext' ),
							__( 'Appeal', 'buddynext' ),
							__( 'When', 'buddynext' ),
							__( 'Decision', 'buddynext' ),
						),
						array(
							array( __( 'Sample Member', 'buddynext' ), __( 'I believe this was a misunderstanding', 'buddynext' ), __( '2 hours ago', 'buddynext' ), __( 'Approve / Deny', 'buddynext' ) ),
							array( __( 'Second Chance', 'buddynext' ), __( 'I have read the guidelines now', 'buddynext' ), __( '1 day ago', 'buddynext' ), __( 'Approve / Deny', 'buddynext' ) ),
							array( __( 'Honest Mistake', 'buddynext' ), __( 'My account was compromised at the time', 'buddynext' ), __( '2 days ago', 'buddynext' ), __( 'Approve / Deny', 'buddynext' ) ),
							array( __( 'Patient Pat', 'buddynext' ), __( 'Requesting a review of my suspension', 'buddynext' ), __( '3 days ago', 'buddynext' ), __( 'Approve / Deny', 'buddynext' ) ),
						)
					);
					?>
				<?php else : ?>
				<table class="widefat striped">
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
							<?php $u = get_userdata( (int) $a['user_id'] ); ?>
							<tr>
								<td><?php echo esc_html( $u ? $u->display_name : '#' . (int) $a['user_id'] ); ?></td>
								<td><?php echo esc_html( (string) $a['message'] ); ?></td>
								<td><?php echo esc_html( $this->ago( (string) ( $a['created_at'] ?? '' ) ) ); ?></td>
								<td>
									<div class="bn-mod-actions">
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

	// ── Action handlers ─────────────────────────────────────────────────────

	/**
	 * Handle a per-report action (dismiss / resolve / remove / escalate).
	 *
	 * @return void
	 */
	public function handle_report_action(): void {
		$this->guard( 'bn_mod_report_action' );

		$report_id = isset( $_POST['report_id'] ) ? absint( wp_unslash( $_POST['report_id'] ) ) : 0;
		$op        = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$actor     = get_current_user_id();
		$service   = new ModerationService();

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

		$this->redirect_back( 'reports', $result );
	}

	/**
	 * Handle a per-user action (strike / suspend / unsuspend).
	 *
	 * @return void
	 */
	public function handle_user_action(): void {
		$this->guard( 'bn_mod_user_action' );

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$actor   = get_current_user_id();
		$service = new ModerationService();
		$tab     = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['return_tab'] ) ) : 'reports';

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

		$this->redirect_back( $tab, $result );
	}

	/**
	 * Handle an appeal decision (approve / deny).
	 *
	 * @return void
	 */
	public function handle_appeal_action(): void {
		$this->guard( 'bn_mod_appeal_action' );

		$appeal_id = isset( $_POST['appeal_id'] ) ? absint( wp_unslash( $_POST['appeal_id'] ) ) : 0;
		$decision  = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
		$actor     = get_current_user_id();

		$result = in_array( $decision, array( 'approved', 'denied' ), true )
			? ( new ModerationService() )->resolve_appeal( $appeal_id, $actor, $decision )
			: new \WP_Error( 'bn_invalid_decision', __( 'Choose approve or deny.', 'buddynext' ) );

		$this->redirect_back( 'appeals', $result );
	}

	/**
	 * Approve or reject a held ('pending') post from the Pending queue.
	 *
	 * @return void
	 */
	public function handle_premod_action(): void {
		$this->guard( 'bn_mod_premod_action' ); // Verifies the nonce via check_admin_referer().

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() above.
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() above.
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$service = new \BuddyNext\Feed\PostService();

		$result = true;
		if ( 'approve' === $op ) {
			$result = $service->approve_pending( $post_id );
		} elseif ( 'reject' === $op ) {
			$result = $service->reject_pending( $post_id );
		}

		$this->redirect_back( 'pending', $result );
	}

	// ── Small render + flow helpers ─────────────────────────────────────────

	/**
	 * Render a dimmed "sample data" preview beneath an empty queue so an owner
	 * can see what the tab will show once there is activity. The rows are clearly
	 * labelled as illustrative, are inert (no working actions), and are hidden
	 * from assistive tech since they carry no real records.
	 *
	 * @param array<int,string>            $columns Column headers.
	 * @param array<int,array<int,string>> $rows    Sample rows; cells align to columns.
	 * @return void
	 */
	private function sample_preview( array $columns, array $rows ): void {
		?>
		<div class="bn-mod-preview" aria-hidden="true">
			<span class="bn-mod-preview__badge"><?php esc_html_e( 'Sample preview — example only, not real data. This is how the tab looks once there is activity.', 'buddynext' ); ?></span>
			<table class="widefat striped bn-mod-preview__table">
				<thead>
					<tr>
						<?php foreach ( $columns as $col ) : ?>
							<th><?php echo esc_html( (string) $col ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<?php foreach ( $row as $cell ) : ?>
								<td><?php echo esc_html( (string) $cell ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
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
		$class = 'button';
		if ( 'primary' === $variant ) {
			$class .= ' button-primary';
		} elseif ( 'delete' === $variant ) {
			$class .= ' bn-btn-danger';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-mod-action-form"
			<?php
			if ( '' !== $confirm ) :
				?>
				onsubmit="return confirm('<?php echo esc_js( $confirm ); ?>');"<?php endif; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['bn_done'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Done.', 'buddynext' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['bn_error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bn_err = sanitize_text_field( wp_unslash( (string) $_GET['bn_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $bn_err ) . '</p></div>';
		}
	}

	/**
	 * The current moderation tab slug (defaults to reports).
	 *
	 * @return string
	 */
	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'reports';
		return in_array( $tab, array( 'pending', 'reports', 'suspensions', 'appeals' ), true ) ? $tab : 'reports';
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
	 * @param string $object_type Reported object type (post|comment|user).
	 * @param int    $object_id   Reported object ID.
	 * @return string
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
		if ( 'comment' === $object_type ) {
			global $wpdb;
			// A comment has no standalone page — deep-link to its parent post.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT object_id FROM {$wpdb->prefix}bn_comments WHERE id = %d AND object_type = 'post'", $object_id )
			);
			return $post_id > 0 ? \BuddyNext\Core\PageRouter::post_url( $post_id ) : '';
		}

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
			/* translators: %s: human time difference, e.g. "2 hours" */
			? sprintf( __( '%s ago', 'buddynext' ), human_time_diff( $ts, $now ) )
			/* translators: %s: human time difference, e.g. "3 days" */
			: sprintf( __( 'in %s', 'buddynext' ), human_time_diff( $now, $ts ) );
	}
}
