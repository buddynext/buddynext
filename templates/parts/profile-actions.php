<?php
/**
 * BuddyNext template part: profile action row.
 *
 * The single owner of "what can I do about this person" on a profile hero, for
 * ALL THREE viewer states — the owner looking at their own profile, a member
 * looking at someone else's, and a logged-out visitor. Extracted from
 * profile-hero.php, which hand-rolled the three separately and drifted.
 *
 * ── The slot model ──────────────────────────────────────────────────────────
 *
 * Every state renders the same shape, and the shape is the point:
 *
 *   [ ONE primary ] [ 0-2 secondary peers ] [ ⋯ overflow, always last ]
 *
 *   - Exactly ONE primary. Before this, a member with an incoming connection
 *     request saw Follow AND Accept both solid, so nothing said which decision
 *     was waiting on them.
 *   - `ghost` is BANNED here. It was used for two unlike things — Decline (a
 *     consequential action) and ⋯ (an overflow trigger) — and both came out
 *     borderless beside five bordered buttons, so Decline read as disabled.
 *   - ⋯ is a bordered peer at the end of the row, never a floating glyph on a
 *     line of its own, and it holds everything that is not a top-level action.
 *
 * A DECISION is not an action. An incoming connection request renders as a
 * callout ABOVE the row (the LinkedIn pattern) naming who is asking, which two
 * bare buttons in the row never did.
 *
 * ── Responsive contract ─────────────────────────────────────────────────────
 *
 * One rule at every width: this is always a ROW, never a vertical stack. The old
 * `flex-direction: column` at ≤400px turned an undifferentiated flex box into
 * four full-width blocks, which is both unreadable as a hierarchy and tall
 * enough to push ⋯ under the fixed mobile nav. Three controls fit at 390px.
 * See .bn-pf-actions in bn-profile.css.
 *
 * @package BuddyNext
 *
 * @var int    $profile_user_id Required. Whose profile this is.
 * @var string $display_name    Required. Their display name (the request callout names them).
 * @var string $username        Required. Their slug (share-to-feed mention link).
 * @var int    $viewer_id       Current viewer; 0 for a logged-out visitor.
 * @var bool   $is_owner        Whether the viewer owns this profile.
 * @var bool   $can_edit_any    Whether the viewer may edit anyone's profile.
 * @var bool   $can_follow      Whether the viewer may send a follow.
 * @var bool   $can_connect     Whether the viewer may send a connection request.
 * @var bool   $is_following    Precomputed follow state.
 * @var bool   $follow_pending  Precomputed follow-request state.
 * @var bool   $is_connected    Precomputed connection state.
 * @var bool   $conn_pending    Connection request sent by the viewer.
 * @var bool   $conn_received   Connection request received FROM this member.
 * @var int    $completion_pct  Owner only. Profile completeness percentage; a ring
 *                          renders in the row below 100.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pa_uid       = isset( $profile_user_id ) ? (int) $profile_user_id : 0;
$bn_pa_name      = isset( $display_name ) ? (string) $display_name : '';
$bn_pa_slug      = isset( $username ) ? (string) $username : '';
$bn_pa_viewer    = isset( $viewer_id ) ? (int) $viewer_id : 0;
$bn_pa_is_owner  = ! empty( $is_owner );
$bn_pa_can_edit  = ! empty( $can_edit_any );
$bn_pa_can_foll  = ! isset( $can_follow ) || (bool) $can_follow;
$bn_pa_can_conn  = ! isset( $can_connect ) || (bool) $can_connect;
$bn_pa_following = ! empty( $is_following );
$bn_pa_foll_pend = ! empty( $follow_pending );
$bn_pa_connected = ! empty( $is_connected );
$bn_pa_conn_pend = ! empty( $conn_pending );
$bn_pa_conn_recv = ! empty( $conn_received );
$bn_pa_pct       = isset( $completion_pct ) ? (int) $completion_pct : 100;

if ( $bn_pa_uid <= 0 ) {
	return;
}

$bn_pa_profile_url = \BuddyNext\Core\PageRouter::profile_url( $bn_pa_uid );
$bn_pa_mention_url = add_query_arg( 'mention', rawurlencode( $bn_pa_slug ), \BuddyNext\Core\PageRouter::activity_url() );
?>

<?php // ── Owner: their own profile ────────────────────────────────────────── ?>
<?php if ( $bn_pa_is_owner ) : ?>
	<div class="bn-pf-actions">
		<a class="bn-btn" data-variant="primary" data-size="sm"
			href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>">
			<?php buddynext_icon( 'edit' ); ?>
			<span><?php esc_html_e( 'Edit profile', 'buddynext' ); ?></span>
		</a>
		<?php
		buddynext_get_template(
			'parts/profile-actions-overflow.php',
			array(
				'profile_url' => $bn_pa_profile_url,
				'mention_url' => $bn_pa_mention_url,
				'show_safety' => false,
				'edit_url'    => '',
			)
		);
		?>

	</div>

	<?php
	// Status, not an action, so it is a SIBLING of the row rather than a cell in
	// it. Inside the row it took an equal column, wrapped "50% complete" onto two
	// lines and left the owner's row ragged — and it was never a thing you "do"
	// to the profile in the first place.
	if ( $bn_pa_pct < 100 ) :
		?>
			<a class="bn-pf-completeness"
				href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>"
				style="--bn-pf-pct: <?php echo esc_attr( (string) $bn_pa_pct ); ?>%"
				aria-label="
				<?php
				echo esc_attr(
					sprintf(
						/* translators: %d: profile completion percentage. */
						__( 'Profile %d%% complete — finish to make it discoverable', 'buddynext' ),
						$bn_pa_pct
					)
				);
				?>
				"
			>
				<span class="bn-pf-completeness__ring" aria-hidden="true">
					<span class="bn-pf-completeness__ring-fill"></span>
				</span>
				<span class="bn-pf-completeness__label">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: profile completion percentage. */
							__( '%d%% complete', 'buddynext' ),
							$bn_pa_pct
						)
					);
					?>
				</span>
		</a>
	<?php endif; ?>

	<?php // ── Logged-out visitor ──────────────────────────────────────────────── ?>
<?php elseif ( $bn_pa_viewer <= 0 ) : ?>
	<?php
	// A single Follow CTA routed through login that returns here, so the hero is
	// never action-less. Share still works logged-out — it is a link, not a
	// relationship — so the overflow stays, minus the safety controls, which need
	// an account to act on.
	?>
	<div class="bn-pf-actions">
		<a class="bn-btn" data-variant="primary" data-size="sm"
			href="<?php echo esc_url( add_query_arg( 'redirect_to', $bn_pa_profile_url, \BuddyNext\Core\PageRouter::auth_url() ) ); ?>">
			<?php esc_html_e( 'Follow', 'buddynext' ); ?>
		</a>
		<?php
		buddynext_get_template(
			'parts/profile-actions-overflow.php',
			array(
				'profile_url' => $bn_pa_profile_url,
				'mention_url' => $bn_pa_mention_url,
				'show_safety' => false,
				'edit_url'    => '',
			)
		);
		?>
	</div>

	<?php // ── Member viewing another member ───────────────────────────────────── ?>
<?php else : ?>

	<?php
	/*
	 * The incoming request, lifted out of the row. It is the only thing on this
	 * screen that is waiting on the member, so it gets a line of its own that
	 * says who is asking — and it leaves the row with a single primary.
	 */
	?>
	<div class="bn-pf-request"
		data-wp-bind--hidden="!context.connectionReceived"
		<?php echo $bn_pa_conn_recv ? '' : 'hidden'; ?>>
		<p class="bn-pf-request__text">
			<?php
			printf(
				/* translators: %s: member display name. */
				esc_html__( '%s wants to connect', 'buddynext' ),
				'<strong>' . esc_html( $bn_pa_name ) . '</strong>'
			);
			?>
		</p>
		<div class="bn-pf-request__actions">
			<button class="bn-btn" data-variant="primary" data-size="sm"
				data-wp-on--click="actions.acceptRequest">
				<?php esc_html_e( 'Accept', 'buddynext' ); ?>
			</button>
			<button class="bn-btn" data-variant="secondary" data-size="sm"
				data-wp-on--click="actions.declineRequest">
				<?php esc_html_e( 'Decline', 'buddynext' ); ?>
			</button>
		</div>
	</div>

	<div class="bn-pf-actions">
		<?php
		/*
		 * Follow is delegated to the shared control every other surface uses
		 * (member showcase, leaderboard, space members, sidebar row, post byline,
		 * post options), so the product has ONE follow button rather than two
		 * implementations free to disagree. It models all three states —
		 * Follow / Requested / Following — reactively.
		 *
		 * The hero's own copy existed for no reason that survives inspection: it
		 * also maintained ctx.followerCount, but nothing renders that (the metric
		 * row, parts/nav-metrics.php, is server-rendered and carries no
		 * Interactivity binding), so the bookkeeping was dead.
		 */
		if ( $bn_pa_can_foll || $bn_pa_following || $bn_pa_foll_pend ) {
			buddynext_get_template(
				'partials/follow-button.php',
				array(
					'user_id'         => $bn_pa_uid,
					'known_following' => $bn_pa_following,
					'known_pending'   => $bn_pa_foll_pend,
					'known_blocked'   => false,
				)
			);
		}
		?>

		<?php // Connection state. No shared partial models this one, so it stays on the profile store. ?>
		<?php if ( $bn_pa_can_conn || $bn_pa_connected || $bn_pa_conn_pend || $bn_pa_conn_recv ) : ?>
			<button class="bn-btn" data-variant="secondary" data-size="sm"
				data-wp-on--click="actions.connect"
				data-wp-bind--hidden="!context.showConnect"
				<?php echo ( $bn_pa_connected || $bn_pa_conn_pend || $bn_pa_conn_recv ) ? 'hidden' : ''; ?>>
				<?php esc_html_e( 'Connect', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
		<button class="bn-btn" data-variant="secondary" data-size="sm"
			data-wp-on--click="actions.withdrawRequest"
			data-wp-bind--hidden="!context.connectionPending"
			<?php echo $bn_pa_conn_pend ? '' : 'hidden'; ?>>
			<?php esc_html_e( 'Pending', 'buddynext' ); ?>
		</button>
		<button class="bn-btn bn-pf-connected" data-variant="secondary" data-state="connected" data-size="sm"
			data-wp-on--click="actions.disconnectUser"
			data-wp-bind--hidden="!context.isConnected"
			<?php echo $bn_pa_connected ? '' : 'hidden'; ?>>
			<?php buddynext_icon( 'check' ); ?>
			<span><?php esc_html_e( 'Connected', 'buddynext' ); ?></span>
		</button>

		<?php
		// Message shows as its own control from 641px up; below that it moves into
		// the overflow so the remaining three controls have room for their labels.
		// See profile-actions-overflow.php for the measurement.
		$bn_pa_message_url = \BuddyNext\Messages\MessagesData::entry_enabled()
			? add_query_arg( 'with', $bn_pa_uid, \BuddyNext\Core\PageRouter::messages_url() )
			: '';
		?>
		<?php if ( '' !== $bn_pa_message_url ) : ?>
			<a href="<?php echo esc_url( $bn_pa_message_url ); ?>"
				class="bn-btn bn-pf-actions__wide-only" data-variant="secondary" data-size="sm">
				<?php buddynext_icon( 'message-circle' ); ?>
				<span><?php esc_html_e( 'Message', 'buddynext' ); ?></span>
			</a>
		<?php endif; ?>

		<?php
		buddynext_get_template(
			'parts/profile-actions-overflow.php',
			array(
				'profile_url' => $bn_pa_profile_url,
				'mention_url' => $bn_pa_mention_url,
				'show_safety' => true,
				'edit_url'    => $bn_pa_can_edit ? \BuddyNext\Core\PageRouter::edit_profile_url( $bn_pa_uid ) : '',
				'message_url' => $bn_pa_message_url,
			)
		);
		?>
	</div>
<?php endif; ?>
