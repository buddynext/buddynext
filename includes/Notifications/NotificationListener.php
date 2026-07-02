<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * Notification listener.
 *
 * Responds to social events by creating in-app notifications.
 * Each handler delegates to NotificationService::create() so that
 * cross-plugin events (follows, space joins, reactions, etc.) produce
 * the correct in-app notification rows without coupling back to callers.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Registers social-event action hooks and routes them to NotificationService.
 */
class NotificationListener implements ListenerInterface {

	/**
	 * Members processed per background fan-out batch for a new space post.
	 * Bounds each scheduled action so a space with tens of thousands of
	 * members never loads/loops the whole roster in one request.
	 */
	private const SPACE_FANOUT_BATCH = 200;

	/**
	 * Members processed per background fan-out batch, filterable so a site owner can
	 * tune it to their hosting - a beefier host can raise it; a constrained one can
	 * lower it. Clamped to at least 1 so a bad filter can never stall the keyset loop.
	 *
	 * Note: this is the per-batch MEMBER count, not the IN()-clause chunk used when
	 * bumping existing rows (that is a separate SQL placeholder bound) - the two are
	 * intentionally independent.
	 *
	 * @return int
	 */
	private function fanout_batch_size(): int {
		/**
		 * Filter the per-batch member count for space-post notification fan-out.
		 *
		 * @param int $size Default batch size (SPACE_FANOUT_BATCH).
		 */
		return max( 1, (int) apply_filters( 'buddynext_notification_fanout_batch', self::SPACE_FANOUT_BATCH ) );
	}

	/**
	 * Register all notification event hook listeners.
	 *
	 * Called once during Plugin::register_listeners() at plugins_loaded:15,
	 * before buddynext_loaded fires.
	 */
	public function register(): void {
		add_action( 'buddynext_user_followed', array( $this, 'on_user_followed' ), 10, 2 );
		add_action( 'buddynext_follow_requested', array( $this, 'on_follow_requested' ), 10, 2 );
		add_action( 'buddynext_space_member_joined', array( $this, 'on_space_member_joined' ), 10, 3 );

		// Social Graph.
		add_action( 'buddynext_connection_requested', array( $this, 'on_connection_requested' ), 10, 3 );
		add_action( 'buddynext_connection_accepted', array( $this, 'on_connection_accepted' ), 10, 3 );

		// Activity Feed.
		add_action( 'buddynext_reaction_added', array( $this, 'on_reaction_added' ), 10, 4 );
		add_action( 'buddynext_comment_created', array( $this, 'on_comment_created' ), 10, 4 );
		add_action( 'buddynext_post_shared', array( $this, 'on_post_shared' ), 10, 3 );
		add_action( 'buddynext_user_mentioned', array( $this, 'on_user_mentioned' ), 10, 3 );

		// Spaces.
		add_action( 'buddynext_space_join_requested', array( $this, 'on_space_join_requested' ), 10, 2 );
		add_action( 'buddynext_space_join_approved', array( $this, 'on_space_join_approved' ), 10, 3 );
		add_action( 'buddynext_space_member_invited', array( $this, 'on_space_member_invited' ), 10, 3 );

		// Space posts.
		add_action( 'buddynext_post_created', array( $this, 'on_post_created_in_space' ), 10, 3 );

		// Async worker — runs inline when Action Scheduler is absent.
		add_action( 'buddynext_async_space_new_post_notification', array( $this, 'async_space_new_post_notification' ), 10, 1 );
		add_action( 'buddynext_async_space_post_fanout', array( $this, 'async_space_post_fanout' ), 10, 1 );

		// Deliver stage — batched, self-paginating email send for a space post.
		// Decoupled from the record stage (the fan-out only creates in-app rows);
		// email is not real-time, so it runs here off the fan-out task.
		add_action( 'buddynext_async_space_post_emails', array( $this, 'async_space_post_emails' ), 10, 1 );
	}

	/**
	 * Notify the followed user when someone follows them.
	 *
	 * @param int $follower_id  User who initiated the follow.
	 * @param int $following_id User who was followed (notification recipient).
	 */
	public function on_user_followed( int $follower_id, int $following_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		$svc = buddynext_service( 'notifications' );

		$svc->create(
			array(
				'recipient_id' => $following_id,
				'sender_id'    => $follower_id,
				'type'         => 'bn.new_follower',
				'object_type'  => 'user',
				'object_id'    => $follower_id,
				'group_key'    => 'follower_' . $following_id,
			)
		);
	}

	/**
	 * Notify the owner of a private account when a follow request lands.
	 *
	 * @param int $follower_id  Requester.
	 * @param int $following_id Private-account owner (recipient).
	 */
	public function on_follow_requested( int $follower_id, int $following_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $following_id,
				'sender_id'    => $follower_id,
				'type'         => 'bn.follow_requested',
				'object_type'  => 'user',
				'object_id'    => $follower_id,
				'group_key'    => 'follow_request_' . $following_id,
			)
		);
	}

	/**
	 * Notify the space owner when a new member joins their space.
	 *
	 * No notification is sent if the joining user is the space owner
	 * (e.g. the owner re-joining after removal).
	 *
	 * @param int    $space_id Space that was joined.
	 * @param int    $user_id  User who joined the space.
	 * @param string $_role    Member role assigned (unused — required by hook contract).
	 */
	public function on_space_member_joined( int $space_id, int $user_id, string $_role ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_role required by hook contract.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT owner_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1",
				$space_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		$svc = buddynext_service( 'notifications' );

		$svc->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.space_join',
				'object_type'  => 'space',
				'object_id'    => $space_id,
				'group_key'    => 'space_join_' . $space_id,
			)
		);
	}

	/**
	 * Notify the addressee when someone requests a connection.
	 *
	 * @param int $connection_id Connection row ID (unused — provided for hook contract completeness).
	 * @param int $requester_id  User who sent the connection request.
	 * @param int $addressee_id  User who received the request (notification recipient).
	 */
	public function on_connection_requested( int $connection_id, int $requester_id, int $addressee_id ): void {
		if ( $requester_id === $addressee_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $addressee_id, $requester_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $addressee_id,
				'sender_id'    => $requester_id,
				'type'         => 'bn.connection_requested',
				'object_type'  => 'connection',
				'object_id'    => $requester_id,
				'group_key'    => 'conn_req_' . $addressee_id . '_' . $requester_id,
			)
		);
	}

	/**
	 * Notify the original requester when their connection request is accepted.
	 *
	 * @param int $connection_id Connection row ID (unused — provided for hook contract completeness).
	 * @param int $requester_id  User who originally sent the request (notification recipient).
	 * @param int $addressee_id  User who accepted the request.
	 */
	public function on_connection_accepted( int $connection_id, int $requester_id, int $addressee_id ): void {
		if ( $requester_id === $addressee_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $requester_id, $addressee_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $requester_id,
				'sender_id'    => $addressee_id,
				'type'         => 'bn.connection_accepted',
				'object_type'  => 'connection',
				'object_id'    => $addressee_id,
				'group_key'    => 'conn_accepted_' . $requester_id . '_' . $addressee_id,
			)
		);
	}

	/**
	 * Notify the post owner when someone reacts to their content.
	 *
	 * Only fires a notification for 'post' object type.
	 *
	 * @param string $object_type Object type (e.g. 'post', 'comment').
	 * @param int    $object_id   Object ID.
	 * @param int    $user_id     User who reacted.
	 * @param string $emoji       Emoji slug used for the reaction.
	 */
	public function on_reaction_added( string $object_type, int $object_id, int $user_id, string $emoji ): void {
		if ( 'post' !== $object_type ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$object_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $owner_id, $user_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.post_reacted',
				'object_type'  => 'post',
				'object_id'    => $object_id,
				'group_key'    => 'post_reactions_' . $object_id,
				'data'         => array( 'emoji' => $emoji ),
			)
		);
	}

	/**
	 * Notify the post owner when someone comments on their content.
	 *
	 * Only handles 'post' object types; comments on other surfaces (e.g. media)
	 * route through their own listeners.
	 *
	 * @param int    $comment_id  ID of the new comment.
	 * @param string $object_type Object type the comment is attached to.
	 * @param int    $object_id   Object that was commented on.
	 * @param int    $user_id     User who commented.
	 */
	public function on_comment_created( int $comment_id, string $object_type, int $object_id, int $user_id ): void {
		if ( 'post' !== $object_type ) {
			return;
		}

		$post_id = $object_id;

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$post_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $owner_id, $user_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.post_commented',
				'object_type'  => 'post',
				'object_id'    => $post_id,
				'group_key'    => 'post_comments_' . $post_id,
				'data'         => array( 'comment_id' => $comment_id ),
			)
		);
	}

	/**
	 * Notify the post owner when someone shares their post.
	 *
	 * Hook signature matches the canonical
	 * `buddynext_post_shared($share_id, $original_post_id, $user_id)` contract
	 * documented in `docs/specs/HOOKS.md` and fired by Feed\ShareService.
	 *
	 * @param int $share_id         Row ID in bn_shares (unused — kept to honour the contract).
	 * @param int $original_post_id Post that was shared.
	 * @param int $user_id          User who shared the post.
	 */
	public function on_post_shared( int $share_id, int $original_post_id, int $user_id ): void {
		unset( $share_id ); // Reserved for future use; the notification only needs post_id + user_id.
		$post_id = $original_post_id;
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$post_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $owner_id, $user_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.post_shared',
				'object_type'  => 'post',
				'object_id'    => $post_id,
				'group_key'    => 'post_shares_' . $post_id,
			)
		);
	}

	/**
	 * Notify a user when they are mentioned in a post or comment.
	 *
	 * @param int $mentioned_user_id User who was mentioned (notification recipient).
	 * @param int $mentioner_id      User who wrote the mention.
	 * @param int $context_id        ID of the post or comment containing the mention.
	 */
	public function on_user_mentioned( int $mentioned_user_id, int $mentioner_id, int $context_id ): void {
		if ( $mentioned_user_id === $mentioner_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $mentioned_user_id, $mentioner_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $mentioned_user_id,
				'sender_id'    => $mentioner_id,
				'type'         => 'bn.mention',
				'object_type'  => 'post',
				'object_id'    => $context_id,
				'group_key'    => 'mention_' . $mentioned_user_id . '_' . $context_id,
			)
		);
	}

	/**
	 * Notify the space owner when a user requests to join a private space.
	 *
	 * @param int $space_id Space that received the join request.
	 * @param int $user_id  User requesting to join.
	 */
	public function on_space_join_requested( int $space_id, int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT owner_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1",
				$space_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $owner_id, $user_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.space_join_requested',
				'object_type'  => 'space',
				'object_id'    => $space_id,
				'group_key'    => 'space_join_req_' . $space_id,
			)
		);
	}

	/**
	 * Notify the user when their space join request is approved.
	 *
	 * @param int $space_id    Space they have been approved to join.
	 * @param int $user_id     User whose request was approved (notification recipient).
	 * @param int $_by_user_id User who approved the request (unused — required by hook contract).
	 */
	public function on_space_join_approved( int $space_id, int $user_id, int $_by_user_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_by_user_id required by hook contract.
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => null,
				'type'         => 'bn.space_request_approved',
				'object_type'  => 'space',
				'object_id'    => $space_id,
				'group_key'    => 'space_approved_' . $space_id . '_' . $user_id,
			)
		);
	}

	/**
	 * Notify a user when they are invited to join a space.
	 *
	 * @param int $invited_user_id User who was invited (notification recipient).
	 * @param int $space_id        Space they were invited to.
	 * @param int $inviter_id      User who sent the invitation.
	 */
	public function on_space_member_invited( int $invited_user_id, int $space_id, int $inviter_id ): void {
		if ( $invited_user_id === $inviter_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $invited_user_id, $inviter_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $invited_user_id,
				'sender_id'    => $inviter_id,
				'type'         => 'bn.space_invite',
				'object_type'  => 'space',
				'object_id'    => $space_id,
				'group_key'    => 'space_invite_' . $space_id . '_' . $invited_user_id,
			)
		);
	}

	/**
	 * Notify active space members when a new post is created in their space.
	 *
	 * Skips the post author, users who have opted out via space notification
	 * preferences, and users in a block relationship with the author.
	 * Uses Action Scheduler for bulk dispatch to avoid synchronous N+1 sends.
	 *
	 * @param int    $post_id Post that was created.
	 * @param int    $user_id Author of the post.
	 * @param string $_type   Post type (unused — required by hook contract).
	 */
	public function on_post_created_in_space( int $post_id, int $user_id = 0, string $_type = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_type required by hook contract.
		global $wpdb;

		// Resolve the post's space, status, and scheduled time in one read so
		// scheduled posts (Pro feature) can be skipped until they go live. Pro
		// re-fires `buddynext_post_created` once it promotes status='scheduled'
		// to 'published', so the notification fires on the right edge.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT space_id, status, scheduled_at FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$post_id
			),
			ARRAY_A
		);

		if ( ! is_array( $post_row ) ) {
			return;
		}

		$space_id     = (int) ( $post_row['space_id'] ?? 0 );
		$post_status  = (string) ( $post_row['status'] ?? '' );
		$scheduled_at = (string) ( $post_row['scheduled_at'] ?? '' );

		if ( 0 === $space_id ) {
			return;
		}

		// Suppress space-new-post notifications for scheduled posts that have
		// not yet been promoted. Pro's ScheduledPostsIntegration re-fires this
		// hook once the post is published; an immediate notification would
		// otherwise alert recipients before the post is publicly visible.
		if ( 'scheduled' === $post_status && '' !== $scheduled_at ) {
			$scheduled_ts = strtotime( $scheduled_at );
			if ( false !== $scheduled_ts && $scheduled_ts > time() ) {
				return;
			}
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		// Always notify the first bounded batch INLINE, then hand any remainder
		// to the background fan-out. This guarantees delivery for normal-sized
		// spaces (<= SPACE_FANOUT_BATCH members) without depending on an Action
		// Scheduler worker actually running: AS is frequently present (bundled
		// with WooCommerce) yet its queue can sit undrained when wp-cron is
		// disabled or stalled, which previously meant members got no
		// notification at all. The first batch is the same bounded keyset query
		// the async worker uses, so the posting request cost is capped.
		$batch_size    = $this->fanout_batch_size();
		$batch         = $this->fan_out_space_post_batch( $post_id, $space_id, $user_id, 0, $batch_size );
		$after_user_id = (int) $batch['last_user_id'];
		$has_more      = ( $batch_size === $batch['count'] && $after_user_id > 0 );

		if ( ! $has_more ) {
			return;
		}

		// More members remain. Page the rest through a background action
		// (keyset-resumed from the last user_id) so a large roster never loops
		// inside the posting request. Fall back to inline draining only when
		// Action Scheduler is absent.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				'buddynext_async_space_post_fanout',
				array(
					'post_id'       => $post_id,
					'space_id'      => $space_id,
					'author_id'     => $user_id,
					'after_user_id' => $after_user_id,
				),
				'buddynext'
			);
			return;
		}

		// AS-absent (local/test): drain the remaining batches inline so all
		// members are notified, still bounded to SPACE_FANOUT_BATCH rows per query.
		do {
			$batch         = $this->fan_out_space_post_batch( $post_id, $space_id, $user_id, $after_user_id, $batch_size );
			$after_user_id = (int) $batch['last_user_id'];
		} while ( $batch_size === $batch['count'] && $after_user_id > 0 );
	}

	/**
	 * Background fan-out for a new space post, processed in bounded batches.
	 *
	 * Pages through active members SPACE_FANOUT_BATCH at a time and re-enqueues
	 * itself for the next page, so neither the posting request nor any single
	 * scheduled action ever loads or loops the whole space roster.
	 *
	 * @param array<string, mixed> $args post_id, space_id, author_id, after_user_id.
	 * @return void
	 */
	public function async_space_post_fanout( array $args ): void {
		$post_id       = (int) ( $args['post_id'] ?? 0 );
		$space_id      = (int) ( $args['space_id'] ?? 0 );
		$author_id     = (int) ( $args['author_id'] ?? 0 );
		$after_user_id = max( 0, (int) ( $args['after_user_id'] ?? 0 ) );

		if ( 0 === $post_id || 0 === $space_id ) {
			return;
		}

		$batch_size = $this->fanout_batch_size();
		$batch      = $this->fan_out_space_post_batch( $post_id, $space_id, $author_id, $after_user_id, $batch_size );

		// A full batch means more members may remain — schedule the next page,
		// resuming from the last user_id (keyset, never OFFSET).
		if ( $batch_size === $batch['count'] && $batch['last_user_id'] > 0 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				'buddynext_async_space_post_fanout',
				array(
					'post_id'       => $post_id,
					'space_id'      => $space_id,
					'author_id'     => $author_id,
					'after_user_id' => $batch['last_user_id'],
				),
				'buddynext'
			);
		}
	}

	/**
	 * Process one bounded batch of space members for a new-post notification.
	 *
	 * @param int $post_id       Post that was created.
	 * @param int $space_id      Space the post belongs to.
	 * @param int $author_id     Post author (excluded from recipients).
	 * @param int $after_user_id Keyset anchor — only members with a greater
	 *                           user_id are fetched. Backed by the
	 *                           bn_space_members PRIMARY KEY (space_id, user_id),
	 *                           so each batch is O(limit) regardless of depth.
	 * @param int $limit         Maximum members to process this batch.
	 * @return array{count: int, last_user_id: int} Rows fetched and the last
	 *               user_id seen (the next batch's anchor).
	 */
	private function fan_out_space_post_batch( int $post_id, int $space_id, int $author_id, int $after_user_id, int $limit ): array {
		global $wpdb;

		// ── Read stage (batched) ──────────────────────────────────────────────
		// One keyset query fetches the next page of active members AND their
		// per-space notification_pref, so the space-pref check costs no extra
		// query. Backed by the bn_space_members PRIMARY KEY (space_id, user_id).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, notification_pref FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND status = 'active' AND user_id != %d AND user_id > %d
				 ORDER BY user_id ASC
				 LIMIT %d",
				$space_id,
				$author_id,
				$after_user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			return array(
				'count'        => 0,
				'last_user_id' => $after_user_id,
			);
		}

		// 'count' is the number of members FETCHED (not notified), so the keyset
		// pager keeps advancing even when an entire page is filtered out.
		$fetched_count = count( $rows );
		$last_row      = end( $rows );
		$last_user_id  = (int) $last_row['user_id'];

		// Space-pref gate: only 'none' suppresses a space new-post (null/'' = 'all').
		$candidates = array();
		foreach ( $rows as $row ) {
			$pref = ( null === $row['notification_pref'] || '' === $row['notification_pref'] )
				? 'all'
				: (string) $row['notification_pref'];
			if ( 'none' !== $pref ) {
				$candidates[] = (int) $row['user_id'];
			}
		}

		if ( empty( $candidates ) ) {
			return array(
				'count'        => $fetched_count,
				'last_user_id' => $last_user_id,
			);
		}

		// Block/mute/restrict suppression — one batched, type-aware query.
		$blocked = $this->blocked_member_ids( $candidates, $author_id );
		if ( ! empty( $blocked ) ) {
			$candidates = array_values( array_diff( $candidates, $blocked ) );
		}

		if ( empty( $candidates ) ) {
			return array(
				'count'        => $fetched_count,
				'last_user_id' => $last_user_id,
			);
		}

		$pref_service = buddynext_service( 'notification_prefs' );

		// In-app gates mirroring NotificationService::create(): per-type on_site
		// (batched, one query) + the in_app channel toggle (usermeta, primed once).
		$on_site = ( is_object( $pref_service ) && method_exists( $pref_service, 'get_on_site_map' ) )
			? $pref_service->get_on_site_map( $candidates, 'bn.space_new_post' )
			: array();
		update_meta_cache( 'user', $candidates );

		// Build the recipient set, applying the SAME gate filters create() applies
		// per recipient so the batched path notifies exactly who the per-row path
		// would have. defer_email keeps email off the per-row hook below (the
		// batched email stage owns it).
		$recipients = array();
		foreach ( $candidates as $member_id ) {
			$data = array(
				'recipient_id' => $member_id,
				'sender_id'    => $author_id,
				'type'         => 'bn.space_new_post',
				'object_type'  => 'post',
				'object_id'    => $post_id,
				'group_key'    => 'space_new_post_' . $space_id . '_' . $member_id,
				'defer_email'  => true,
			);

			$forced = (bool) apply_filters( 'buddynext_notification_force_on_site', false, $member_id, 'bn.space_new_post', $data );
			if ( ! $forced ) {
				if ( array_key_exists( $member_id, $on_site ) && ! $on_site[ $member_id ] ) {
					continue;
				}
				$channels = (array) $pref_service->get_channel_prefs( $member_id );
				if ( array_key_exists( 'in_app', $channels ) && empty( $channels['in_app'] ) ) {
					continue;
				}
			}

			/** This filter is documented in includes/Notifications/NotificationService.php */
			if ( ! (bool) apply_filters( 'buddynext_notification_should_send', true, $data ) ) {
				continue;
			}

			/** This filter is documented in includes/Notifications/NotificationService.php */
			$send_at = apply_filters( 'buddynext_notification_send_at', null, $data );
			if ( null !== $send_at ) {
				$data['send_at'] = (string) $send_at;
			}

			$recipients[ $member_id ] = $data;
		}

		if ( empty( $recipients ) ) {
			return array(
				'count'        => $fetched_count,
				'last_user_id' => $last_user_id,
			);
		}

		// ── Write stage (bulk) ────────────────────────────────────────────────
		$recipient_ids = array_keys( $recipients );
		$group_keys    = array();
		foreach ( $recipient_ids as $rid ) {
			$group_keys[ $rid ] = 'space_new_post_' . $space_id . '_' . $rid;
		}

		// One query splits recipients into "merge" (an unread group row already
		// exists in the 24h window) vs. "insert" (fresh row needed).
		$existing = array();
		$key_ph   = implode( ', ', array_fill( 0, count( $group_keys ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$existing_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT recipient_id, id FROM {$wpdb->prefix}bn_notifications
				 WHERE group_key IN ( {$key_ph} ) AND is_read = 0
				   AND created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR",
				array_values( $group_keys )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		foreach ( (array) $existing_rows as $row ) {
			$existing[ (int) $row['recipient_id'] ] = (int) $row['id'];
		}

		$now     = current_time( 'mysql', true );
		$new_ids = array();

		// Merge: bump group_count on existing unread rows, in chunks.
		if ( ! empty( $existing ) ) {
			foreach ( array_chunk( array_values( $existing ), 100 ) as $chunk ) {
				$id_ph = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}bn_notifications
						 SET sender_id = %d, group_count = group_count + 1, created_at = %s
						 WHERE id IN ( {$id_ph} )",
						array_merge( array( $author_id, $now ), $chunk )
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			}
		}

		// Insert: bulk-create rows for new recipients, in chunks.
		$new_recipient_ids = array_values( array_diff( $recipient_ids, array_keys( $existing ) ) );
		if ( ! empty( $new_recipient_ids ) ) {
			foreach ( array_chunk( $new_recipient_ids, 100 ) as $chunk ) {
				$row_ph = array();
				$values = array();
				foreach ( $chunk as $rid ) {
					// data column is a literal NULL (space posts carry no JSON payload).
					$row_ph[] = '(%d, %d, %s, %s, %d, %s, %d, NULL, %d, %s)';
					array_push( $values, $rid, $author_id, 'bn.space_new_post', 'post', $post_id, $group_keys[ $rid ], 1, 0, $now );
				}
				$rows_sql = implode( ', ', $row_ph );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}bn_notifications
						 (recipient_id, sender_id, type, object_type, object_id, group_key, group_count, data, is_read, created_at)
						 VALUES {$rows_sql}",
						$values
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			}

			// Resolve the new rows' ids by group_key, pinned to this batch's exact
			// created_at. group_key has NO unique constraint and is reused across
			// posts in the space, so a recipient could still hold a STALE unread row
			// (older than the 24h merge window, hence treated as "new" here). The
			// created_at pin guarantees each recipient maps to the row we just
			// inserted, never that stale row. One query.
			$nk_ph    = implode( ', ', array_fill( 0, count( $new_recipient_ids ), '%s' ) );
			$new_keys = array();
			foreach ( $new_recipient_ids as $rid ) {
				$new_keys[] = $group_keys[ $rid ];
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$inserted_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT recipient_id, id FROM {$wpdb->prefix}bn_notifications
					 WHERE group_key IN ( {$nk_ph} ) AND is_read = 0 AND created_at = %s",
					array_merge( $new_keys, array( $now ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			foreach ( (array) $inserted_rows as $row ) {
				$new_ids[ (int) $row['recipient_id'] ] = (int) $row['id'];
			}
		}

		// ── Deliver stage (in-app: cache + per-row hook) ──────────────────────
		// Fire the per-row contract hook for every recipient so the real-time
		// consumers (Pro realtime + push, analytics) run exactly as they do for a
		// single create(). defer_email (set above) keeps email off this path.
		// Cache key mirrors NotificationService::CACHE_GROUP.
		foreach ( $recipients as $member_id => $data ) {
			$notif_id = $existing[ $member_id ] ?? ( $new_ids[ $member_id ] ?? 0 );
			if ( $notif_id <= 0 ) {
				continue;
			}
			wp_cache_delete( "unread_{$member_id}", 'buddynext_notifications' );
			/** This action is documented in includes/Notifications/NotificationService.php */
			do_action( 'buddynext_notification_created', $notif_id, $member_id, $data );
		}

		// Hand email delivery to the batched AS stage (off the fan-out task).
		$this->enqueue_space_post_emails( $post_id, $space_id, $author_id, array_keys( $recipients ) );

		return array(
			'count'        => $fetched_count,
			'last_user_id' => $last_user_id,
		);
	}

	/**
	 * Batch-resolve which members are in a notification-suppressing relationship
	 * with the post author, in one type-aware query.
	 *
	 * Mirrors {@see self::is_blocked()} for a whole batch: 'block' is
	 * bidirectional (author<->member, either direction); 'mute'/'restrict' are
	 * one-way (the member silenced the author: blocker = member, blocked = author).
	 *
	 * @param int[] $member_ids Candidate recipient IDs.
	 * @param int   $author_id  Post author.
	 * @return int[] Member IDs whose notification must be suppressed.
	 */
	private function blocked_member_ids( array $member_ids, int $author_id ): array {
		$member_ids = array_values( array_unique( array_filter( array_map( 'intval', $member_ids ) ) ) );
		if ( empty( $member_ids ) ) {
			return array();
		}

		global $wpdb;

		$ph = implode( ', ', array_fill( 0, count( $member_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blocker_id, blocked_id, type FROM {$wpdb->prefix}bn_blocks
				 WHERE ( type = 'block' AND (
				            ( blocker_id = %d AND blocked_id IN ( {$ph} ) )
				         OR ( blocked_id = %d AND blocker_id IN ( {$ph} ) )
				        ) )
				    OR ( type IN ( 'mute', 'restrict' ) AND blocked_id = %d AND blocker_id IN ( {$ph} ) )",
				array_merge( array( $author_id ), $member_ids, array( $author_id ), $member_ids, array( $author_id ), $member_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$blocked = array();
		foreach ( (array) $rows as $row ) {
			$blocker    = (int) $row['blocker_id'];
			$blocked_id = (int) $row['blocked_id'];
			if ( 'block' === $row['type'] ) {
				// The member is whichever side is not the author.
				$blocked[ $blocker === $author_id ? $blocked_id : $blocker ] = true;
			} else {
				// mute/restrict: the muter (recipient/member) is the blocker.
				$blocked[ $blocker ] = true;
			}
		}

		return array_keys( $blocked );
	}

	/**
	 * Enqueue (or run inline) the batched email-delivery stage for a space post.
	 *
	 * @param int   $post_id    Post ID.
	 * @param int   $space_id   Space ID.
	 * @param int   $author_id  Author ID.
	 * @param int[] $recipients Recipients that received an in-app notification.
	 * @return void
	 */
	private function enqueue_space_post_emails( int $post_id, int $space_id, int $author_id, array $recipients ): void {
		if ( empty( $recipients ) ) {
			return;
		}

		$args = array(
			'post_id'    => $post_id,
			'space_id'   => $space_id,
			'author_id'  => $author_id,
			'recipients' => array_values( $recipients ),
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'buddynext_async_space_post_emails', $args, 'buddynext' );
			return;
		}

		// No Action Scheduler (small sites / CLI): deliver inline.
		$this->async_space_post_emails( $args );
	}

	/**
	 * Deliver stage: send space new-post emails for a recipient batch.
	 *
	 * Runs as its OWN Action Scheduler action so email never blocks the record
	 * stage (the fan-out). Self-paginates a bounded chunk at a time and delivers
	 * inline through EmailSender ($defer = false, since this is already an async
	 * worker): immediate frequencies send now, daily/weekly queue a digest, 'off'
	 * is dropped. The in-app rows already exist; this only handles email.
	 *
	 * @param array<string,mixed> $args post_id, space_id, author_id, recipients[].
	 * @return void
	 */
	public function async_space_post_emails( array $args ): void {
		$post_id    = (int) ( $args['post_id'] ?? 0 );
		$author_id  = (int) ( $args['author_id'] ?? 0 );
		$space_id   = (int) ( $args['space_id'] ?? 0 );
		$recipients = array_values( array_filter( array_map( 'intval', (array) ( $args['recipients'] ?? array() ) ) ) );

		if ( 0 === $post_id || empty( $recipients ) ) {
			return;
		}

		$sender = buddynext_service( 'email_sender' );
		if ( ! is_object( $sender ) || ! method_exists( $sender, 'send' ) ) {
			return;
		}

		$chunk_size = 50;
		$chunk      = array_slice( $recipients, 0, $chunk_size );
		$remaining  = array_slice( $recipients, $chunk_size );

		$data = array(
			'type'        => 'bn.space_new_post',
			'sender_id'   => $author_id,
			'object_type' => 'post',
			'object_id'   => $post_id,
		);

		foreach ( $chunk as $member_id ) {
			// $defer = false: already an async AS worker, so immediate emails send
			// inline here rather than spawning one sub-action per recipient.
			$sender->send( $member_id, 'bn.space_new_post', $data, false );
		}

		if ( ! empty( $remaining ) ) {
			$this->enqueue_space_post_emails( $post_id, $space_id, $author_id, $remaining );
		}
	}

	/**
	 * Action Scheduler worker: create a single space_new_post notification.
	 *
	 * Action Scheduler passes all arguments as a single associative array when
	 * the action was enqueued with an array as the sole argument.
	 *
	 * @param array $args Keys: post_id, space_id, author_id, recipient_id.
	 */
	public function async_space_new_post_notification( array $args ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		$post_id      = (int) ( $args['post_id'] ?? 0 );
		$space_id     = (int) ( $args['space_id'] ?? 0 );
		$author_id    = (int) ( $args['author_id'] ?? 0 );
		$recipient_id = (int) ( $args['recipient_id'] ?? 0 );

		if ( 0 === $post_id || 0 === $space_id || 0 === $author_id || 0 === $recipient_id ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $recipient_id,
				'sender_id'    => $author_id,
				'type'         => 'bn.space_new_post',
				'object_type'  => 'post',
				'object_id'    => $post_id,
				'group_key'    => 'space_new_post_' . $space_id . '_' . $recipient_id,
			)
		);
	}

	/**
	 * Check whether either user has blocked the other.
	 *
	 * Returns true when a block record exists in either direction, meaning
	 * the notification should be suppressed.
	 *
	 * @param int $recipient_id Notification recipient.
	 * @param int $sender_id    User triggering the event.
	 * @return bool
	 */
	private function is_blocked( int $recipient_id, int $sender_id ): bool {
		global $wpdb;

		// Type-aware suppression so notifications are filtered the way each
		// relationship semantically intends:
		//
		// - block:    bidirectional. Suppress in either direction so the
		// two users never see each other's events.
		// - mute:     unidirectional. The muter (= recipient) silenced
		// the muted user. The muted user's notifications
		// from the muter still fire — mute is one-way.
		// - restrict: unidirectional. Same rule as mute, with the added
		// point that the restricted user MUST keep getting
		// notifs from the restrictor, otherwise they'd
		// detect they've been restricted.
		//
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}bn_blocks
				 WHERE (
					    ( type = 'block' AND (
					        ( blocker_id = %d AND blocked_id = %d )
					     OR ( blocker_id = %d AND blocked_id = %d )
					    ) )
					 OR ( type IN ( 'mute', 'restrict' ) AND blocker_id = %d AND blocked_id = %d )
				 )
				 LIMIT 1",
				$recipient_id,
				$sender_id,
				$sender_id,
				$recipient_id,
				$recipient_id,
				$sender_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null !== $row;
	}
}
