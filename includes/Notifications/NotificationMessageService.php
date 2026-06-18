<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Notification message composer.
 *
 * Single source of truth for the human-readable copy attached to every
 * BuddyNext notification type. The notification list template, the nav
 * dropdown partial, the email-sender token resolver, and the REST list
 * endpoint all call this service so the same row renders identically
 * everywhere.
 *
 * Spec: docs/specs/NOTIFICATION-MESSAGES.md.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

use BuddyNext\Core\PageRouter;

/**
 * Compose notification message text, deep-link URL, and icon slug.
 *
 * Every notification type produced by NotificationListener and the bridge
 * listeners has an exhaustive case here. Adding a type without a case is a
 * presentation bug — the legacy fallback "sent you a notification" string
 * was removed in the production-grade pass on 2026-05-22.
 */
class NotificationMessageService {

	/**
	 * Compose the full presentation payload for a single notification row.
	 *
	 * Accepts an associative array with keys: id, type, sender_id, object_id,
	 * object_type, group_count, data, created_at, is_read. Returns an array
	 * with keys: message, url, icon, tone, label, actor_id, actor_name,
	 * group_count. Used by template, nav dropdown, REST list endpoint, and
	 * email token resolver so every surface renders the same copy.
	 *
	 * @param array $row Notification DB row (associative or stdClass coerced to array).
	 * @return array<string,mixed>
	 */
	public function compose( array $row ): array {
		$type        = isset( $row['type'] ) ? (string) $row['type'] : '';
		$actor_id    = isset( $row['sender_id'] ) ? (int) $row['sender_id'] : 0;
		$object_id   = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
		$group_count = isset( $row['group_count'] ) ? max( 1, (int) $row['group_count'] ) : 1;
		$data        = $this->normalise_data( $row['data'] ?? null );

		$actor_name = $this->resolve_actor_name( $actor_id );
		$meta       = $this->meta_for( $type );
		$url        = $this->url_for( $type, $actor_id, $object_id, $data );

		if ( $group_count > 1 && $this->supports_group_collapse( $type ) ) {
			$message = $this->compose_grouped( $type, $actor_name, $group_count, $object_id, $data );
		} else {
			$message = $this->compose_single( $type, $actor_name, $object_id, $data );
		}

		return array(
			'message'     => $message,
			'url'         => $url,
			'icon'        => $meta['icon'],
			'tone'        => $meta['tone'],
			'label'       => $meta['label'],
			'actor_id'    => $actor_id,
			'actor_name'  => $actor_name,
			'group_count' => $group_count,
		);
	}

	/**
	 * Compose presentation payloads for a batch of rows in a single pass.
	 *
	 * Prefetches user display names once so a 25-row page does not trigger
	 * 25 individual `get_userdata()` calls.
	 *
	 * @param array[] $rows Notification rows.
	 * @return array<int,array<string,mixed>> Index-aligned with $rows.
	 */
	public function compose_batch( array $rows ): array {
		$actor_ids = array();
		foreach ( $rows as $row ) {
			$actor_id = isset( $row['sender_id'] ) ? (int) $row['sender_id'] : 0;
			if ( $actor_id > 0 ) {
				$actor_ids[ $actor_id ] = true;
			}
		}
		if ( ! empty( $actor_ids ) ) {
			// Cache_users primes the user cache so subsequent get_userdata calls are O(1).
			cache_users( array_keys( $actor_ids ) );
		}

		$out = array();
		foreach ( $rows as $i => $row ) {
			$out[ $i ] = $this->compose( $row );
		}
		return $out;
	}

	/**
	 * Compose the singular message string for a type.
	 *
	 * @param string $type       Notification type slug.
	 * @param string $actor_name Actor display name (pre-escaped at render site, not here).
	 * @param int    $object_id  Object ID (post / space / etc.).
	 * @param array  $data       Decoded data JSON payload.
	 */
	private function compose_single( string $type, string $actor_name, int $object_id, array $data ): string {
		switch ( $type ) {
			case 'bn.new_follower':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s started following you.', 'buddynext' ),
					$actor_name
				);

			case 'bn.follow_requested':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s requested to follow you.', 'buddynext' ),
					$actor_name
				);

			case 'bn.connection_requested':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s sent you a connection request.', 'buddynext' ),
					$actor_name
				);

			case 'bn.connection_accepted':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s accepted your connection request.', 'buddynext' ),
					$actor_name
				);

			case 'bn.connection_declined':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s declined your connection request.', 'buddynext' ),
					$actor_name
				);

			case 'bn.post_reacted':
				$emoji = isset( $data['emoji'] ) ? (string) $data['emoji'] : '';
				if ( '' !== $emoji ) {
					return sprintf(
						/* translators: 1: actor display name, 2: emoji slug. */
						__( '%1$s reacted %2$s to your post.', 'buddynext' ),
						$actor_name,
						$emoji
					);
				}
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s reacted to your post.', 'buddynext' ),
					$actor_name
				);

			case 'bn.post_commented':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s commented on your post.', 'buddynext' ),
					$actor_name
				);

			case 'bn.comment_reply':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s replied to your comment.', 'buddynext' ),
					$actor_name
				);

			case 'bn.post_shared':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s shared your post.', 'buddynext' ),
					$actor_name
				);

			case 'bn.mention':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s mentioned you in a post.', 'buddynext' ),
					$actor_name
				);

			case 'bn.bookmark_milestone':
				$count = isset( $data['count'] ) ? max( 1, (int) $data['count'] ) : 1;
				return sprintf(
					/* translators: %d: number of bookmarks. */
					_n( 'Your post has been bookmarked %d time.', 'Your post has been bookmarked %d times.', $count, 'buddynext' ),
					$count
				);

			case 'bn.space_join':
				return sprintf(
					/* translators: 1: actor display name, 2: space name. */
					__( '%1$s joined %2$s.', 'buddynext' ),
					$actor_name,
					$this->resolve_space_name( $object_id )
				);

			case 'bn.space_invite':
				return sprintf(
					/* translators: 1: actor display name, 2: space name. */
					__( '%1$s invited you to %2$s.', 'buddynext' ),
					$actor_name,
					$this->resolve_space_name( $object_id )
				);

			case 'bn.space_join_requested':
				return sprintf(
					/* translators: 1: actor display name, 2: space name. */
					__( '%1$s requested to join %2$s.', 'buddynext' ),
					$actor_name,
					$this->resolve_space_name( $object_id )
				);

			case 'bn.space_request_approved':
			case 'bn.space_join_approved':
				return sprintf(
					/* translators: %s: space name. */
					__( 'Your request to join %s was approved.', 'buddynext' ),
					$this->resolve_space_name( $object_id )
				);

			case 'bn.space_join_declined':
				return sprintf(
					/* translators: %s: space name. */
					__( 'Your request to join %s was declined.', 'buddynext' ),
					$this->resolve_space_name( $object_id )
				);

			case 'bn.space_new_post':
				$space_id   = isset( $data['space_id'] ) ? (int) $data['space_id'] : 0;
				$space_name = $space_id > 0
					? $this->resolve_space_name( $space_id )
					: $this->resolve_space_for_post( $object_id );
				return sprintf(
					/* translators: 1: actor display name, 2: space name. */
					__( '%1$s posted in %2$s.', 'buddynext' ),
					$actor_name,
					$space_name
				);

			case 'bn.space_role_changed':
				$role = isset( $data['role'] ) ? (string) $data['role'] : '';
				return sprintf(
					/* translators: 1: space name, 2: new role label. */
					__( 'Your role in %1$s changed to %2$s.', 'buddynext' ),
					$this->resolve_space_name( $object_id ),
					'' !== $role ? $role : __( 'member', 'buddynext' )
				);

			case 'bn.bulk_invite':
				$count = isset( $data['count'] ) ? max( 1, (int) $data['count'] ) : 1;
				return sprintf(
					/* translators: %d: number of spaces. */
					_n( 'You were invited to %d new space.', 'You were invited to %d new spaces.', $count, 'buddynext' ),
					$count
				);

			case 'bn.new_message':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s sent you a message.', 'buddynext' ),
					$actor_name
				);

			case 'bn.user_warned':
				return __( 'A moderator issued a warning about your activity.', 'buddynext' );

			case 'bn.strike_warning':
				return __( 'You are close to an account strike. Please review the community guidelines.', 'buddynext' );

			case 'bn.strike_issued':
				return __( 'Your account received a strike for a community guideline breach.', 'buddynext' );

			case 'bn.member_suspended':
				return __( 'Your account has been suspended.', 'buddynext' );

			case 'bn.user_unsuspended':
				return __( 'Your account has been reinstated.', 'buddynext' );

			case 'bn.user_shadow_banned':
				return __( 'Your account is under review. Some actions may be limited.', 'buddynext' );

			case 'bn.appeal_submitted':
				return __( 'Your appeal was received and is under review.', 'buddynext' );

			case 'bn.appeal_resolved':
				$decision = isset( $data['decision'] ) ? (string) $data['decision'] : '';
				if ( '' !== $decision ) {
					return sprintf(
						/* translators: %s: appeal decision text. */
						__( 'Your appeal was reviewed: %s.', 'buddynext' ),
						$decision
					);
				}
				return __( 'Your appeal was reviewed.', 'buddynext' );

			case 'bn.report_resolved':
				return __( 'Your report was reviewed. Thank you for keeping the community safe.', 'buddynext' );

			case 'bn.new_report':
				return __( 'New content was reported and is awaiting review.', 'buddynext' );

			case 'bn.post_approved':
				return __( 'Your post was approved and is now live.', 'buddynext' );

			case 'bn.post_rejected':
				$reason = isset( $data['reason'] ) ? (string) $data['reason'] : '';
				if ( '' !== $reason ) {
					return sprintf(
						/* translators: %s: moderator's reason for rejecting the post. */
						__( 'Your post was not approved: %s', 'buddynext' ),
						$reason
					);
				}
				return __( 'Your post was not approved by the moderators.', 'buddynext' );

			case 'bn.badge_awarded':
				$badge = isset( $data['badge'] ) ? (string) $data['badge'] : '';
				if ( '' !== $badge ) {
					return sprintf(
						/* translators: %s: badge name. */
						__( 'You earned a new badge: %s.', 'buddynext' ),
						$badge
					);
				}
				return __( 'You earned a new badge.', 'buddynext' );

			case 'bn.level_up':
				$level = isset( $data['level'] ) ? (int) $data['level'] : 0;
				if ( $level > 0 ) {
					return sprintf(
						/* translators: %d: new level number. */
						__( 'You reached level %d.', 'buddynext' ),
						$level
					);
				}
				return __( 'You levelled up.', 'buddynext' );

			case 'bn.onboarding_nudge':
				return __( 'Finish setting up your profile to get the most out of the community.', 'buddynext' );

			case 'bn.daily_digest':
				return __( 'Your daily digest is ready.', 'buddynext' );

			case 'bn.weekly_digest':
				return __( 'Your weekly digest is ready.', 'buddynext' );

			case 'bn.media_favorited':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s favourited your media.', 'buddynext' ),
					$actor_name
				);

			case 'bn.jetonomy_reply':
				return sprintf(
					/* translators: %s: actor display name. */
					__( '%s replied to your discussion.', 'buddynext' ),
					$actor_name
				);

			case 'bn.test':
				return __( 'Test notification.', 'buddynext' );

			default:
				/**
				 * Filter the composed message for a notification type the
				 * core service does not know about. Bridge plugins use this
				 * to register copy for their own notification types without
				 * forking the core service. Returning a non-empty string
				 * suppresses the fallback warning.
				 *
				 * @since 1.0.0
				 *
				 * @param string $message    Default empty string.
				 * @param string $type       Notification type slug.
				 * @param string $actor_name Actor display name.
				 * @param int    $object_id  Object ID.
				 * @param array  $data       Decoded data payload.
				 */
				$filtered = (string) apply_filters( 'buddynext_notification_message', '', $type, $actor_name, $object_id, $data );
				if ( '' !== $filtered ) {
					return $filtered;
				}
				// Native data-driven fallback: any aggregated/mirrored notification
				// that stores a ready message (suite.*, jt.*, …) renders it even when
				// the originating bridge's render filter is not loaded in this request.
				if ( ! empty( $data['message'] ) ) {
					return (string) $data['message'];
				}
				return sprintf(
					/* translators: %s: notification type slug — only seen in development. */
					__( 'Notification (%s)', 'buddynext' ),
					$type
				);
		}
	}

	/**
	 * Compose the grouped/collapsed message for a type.
	 *
	 * @param string $type        Notification type slug.
	 * @param string $actor_name  Latest actor's display name.
	 * @param int    $group_count Number of merged events (>= 2).
	 * @param int    $object_id   Object ID.
	 * @param array  $data        Decoded data payload.
	 */
	private function compose_grouped( string $type, string $actor_name, int $group_count, int $object_id, array $data ): string {
		$others = max( 1, $group_count - 1 );

		switch ( $type ) {
			case 'bn.new_follower':
				return sprintf(
					/* translators: 1: actor display name, 2: number of other actors. */
					_n( '%1$s and %2$d other started following you.', '%1$s and %2$d others started following you.', $others, 'buddynext' ),
					$actor_name,
					$others
				);

			case 'bn.post_reacted':
				return sprintf(
					/* translators: 1: actor display name, 2: number of other actors. */
					_n( '%1$s and %2$d other reacted to your post.', '%1$s and %2$d others reacted to your post.', $others, 'buddynext' ),
					$actor_name,
					$others
				);

			case 'bn.post_commented':
				return sprintf(
					/* translators: 1: actor display name, 2: number of other actors. */
					_n( '%1$s and %2$d other commented on your post.', '%1$s and %2$d others commented on your post.', $others, 'buddynext' ),
					$actor_name,
					$others
				);

			case 'bn.post_shared':
				return sprintf(
					/* translators: 1: actor display name, 2: number of other actors. */
					_n( '%1$s and %2$d other shared your post.', '%1$s and %2$d others shared your post.', $others, 'buddynext' ),
					$actor_name,
					$others
				);

			case 'bn.space_join':
				return sprintf(
					/* translators: 1: actor display name, 2: number of other actors, 3: space name. */
					_n( '%1$s and %2$d other joined %3$s.', '%1$s and %2$d others joined %3$s.', $others, 'buddynext' ),
					$actor_name,
					$others,
					$this->resolve_space_name( $object_id )
				);

			case 'bn.space_new_post':
				$space_id   = isset( $data['space_id'] ) ? (int) $data['space_id'] : 0;
				$space_name = $space_id > 0
					? $this->resolve_space_name( $space_id )
					: $this->resolve_space_for_post( $object_id );
				return sprintf(
					/* translators: 1: actor display name, 2: number of other actors, 3: space name. */
					_n( '%1$s and %2$d other posted in %3$s.', '%1$s and %2$d others posted in %3$s.', $others, 'buddynext' ),
					$actor_name,
					$others,
					$space_name
				);

			case 'bn.new_message':
				return sprintf(
					/* translators: 1: actor display name, 2: number of other actors. */
					_n( '%1$s and %2$d other sent you a message.', '%1$s and %2$d others sent you a message.', $others, 'buddynext' ),
					$actor_name,
					$others
				);

			case 'bn.media_favorited':
				return sprintf(
					/* translators: 1: actor display name, 2: number of other actors. */
					_n( '%1$s and %2$d other favourited your media.', '%1$s and %2$d others favourited your media.', $others, 'buddynext' ),
					$actor_name,
					$others
				);

			default:
				// Group-collapse not registered for this type — fall back to the singular template.
				return $this->compose_single( $type, $actor_name, $object_id, $data );
		}
	}

	/**
	 * Return the icon slug, tone, and label for a notification type.
	 *
	 * @param string $type Notification type slug.
	 * @return array{icon:string, tone:string, label:string}
	 */
	public function meta_for( string $type ): array {
		static $map = null;
		if ( null === $map ) {
			$map = array(
				'bn.new_follower'           => array(
					'icon'  => 'user-plus',
					'tone'  => 'info',
					'label' => __( 'New follower', 'buddynext' ),
				),
				'bn.follow_requested'       => array(
					'icon'  => 'user-plus',
					'tone'  => 'info',
					'label' => __( 'Follow request', 'buddynext' ),
				),
				'bn.connection_requested'   => array(
					'icon'  => 'user-check',
					'tone'  => 'info',
					'label' => __( 'Connection request', 'buddynext' ),
				),
				'bn.connection_accepted'    => array(
					'icon'  => 'users',
					'tone'  => 'success',
					'label' => __( 'Connection accepted', 'buddynext' ),
				),
				'bn.connection_declined'    => array(
					'icon'  => 'user-x',
					'tone'  => 'info',
					'label' => __( 'Connection declined', 'buddynext' ),
				),
				'bn.post_reacted'           => array(
					'icon'  => 'heart',
					'tone'  => 'warn',
					'label' => __( 'Reaction', 'buddynext' ),
				),
				'bn.post_commented'         => array(
					'icon'  => 'message-circle',
					'tone'  => 'accent',
					'label' => __( 'Comment', 'buddynext' ),
				),
				'bn.comment_reply'          => array(
					'icon'  => 'corner-down-right',
					'tone'  => 'accent',
					'label' => __( 'Reply', 'buddynext' ),
				),
				'bn.post_shared'            => array(
					'icon'  => 'repeat-2',
					'tone'  => 'info',
					'label' => __( 'Share', 'buddynext' ),
				),
				'bn.mention'                => array(
					'icon'  => 'at-sign',
					'tone'  => 'accent',
					'label' => __( 'Mention', 'buddynext' ),
				),
				'bn.bookmark_milestone'     => array(
					'icon'  => 'bookmark',
					'tone'  => 'info',
					'label' => __( 'Bookmark milestone', 'buddynext' ),
				),
				'bn.space_join'             => array(
					'icon'  => 'users',
					'tone'  => 'info',
					'label' => __( 'New space member', 'buddynext' ),
				),
				'bn.space_invite'           => array(
					'icon'  => 'mail-plus',
					'tone'  => 'accent',
					'label' => __( 'Space invite', 'buddynext' ),
				),
				'bn.space_join_requested'   => array(
					'icon'  => 'door-open',
					'tone'  => 'accent',
					'label' => __( 'Space join request', 'buddynext' ),
				),
				'bn.space_request_approved' => array(
					'icon'  => 'check-circle',
					'tone'  => 'success',
					'label' => __( 'Space request approved', 'buddynext' ),
				),
				'bn.space_join_approved'    => array(
					'icon'  => 'check-circle',
					'tone'  => 'success',
					'label' => __( 'Space request approved', 'buddynext' ),
				),
				'bn.space_join_declined'    => array(
					'icon'  => 'x-circle',
					'tone'  => 'info',
					'label' => __( 'Space request declined', 'buddynext' ),
				),
				'bn.space_new_post'         => array(
					'icon'  => 'home',
					'tone'  => 'accent',
					'label' => __( 'New post in space', 'buddynext' ),
				),
				'bn.space_role_changed'     => array(
					'icon'  => 'shield',
					'tone'  => 'info',
					'label' => __( 'Role updated', 'buddynext' ),
				),
				'bn.bulk_invite'            => array(
					'icon'  => 'mail-plus',
					'tone'  => 'accent',
					'label' => __( 'Space invites', 'buddynext' ),
				),
				'bn.new_message'            => array(
					'icon'  => 'mail',
					'tone'  => 'info',
					'label' => __( 'Message', 'buddynext' ),
				),
				'bn.user_warned'            => array(
					'icon'  => 'alert-triangle',
					'tone'  => 'warn',
					'label' => __( 'Warning', 'buddynext' ),
				),
				'bn.strike_warning'         => array(
					'icon'  => 'alert-triangle',
					'tone'  => 'warn',
					'label' => __( 'Strike warning', 'buddynext' ),
				),
				'bn.strike_issued'          => array(
					'icon'  => 'alert-triangle',
					'tone'  => 'danger',
					'label' => __( 'Strike', 'buddynext' ),
				),
				'bn.member_suspended'       => array(
					'icon'  => 'lock',
					'tone'  => 'danger',
					'label' => __( 'Suspension', 'buddynext' ),
				),
				'bn.user_unsuspended'       => array(
					'icon'  => 'unlock',
					'tone'  => 'success',
					'label' => __( 'Reinstated', 'buddynext' ),
				),
				'bn.user_shadow_banned'     => array(
					'icon'  => 'eye-off',
					'tone'  => 'danger',
					'label' => __( 'Under review', 'buddynext' ),
				),
				'bn.appeal_submitted'       => array(
					'icon'  => 'mail',
					'tone'  => 'info',
					'label' => __( 'Appeal received', 'buddynext' ),
				),
				'bn.appeal_resolved'        => array(
					'icon'  => 'check-circle',
					'tone'  => 'success',
					'label' => __( 'Appeal resolved', 'buddynext' ),
				),
				'bn.report_resolved'        => array(
					'icon'  => 'shield-check',
					'tone'  => 'success',
					'label' => __( 'Report resolved', 'buddynext' ),
				),
				'bn.new_report'             => array(
					'icon'  => 'flag',
					'tone'  => 'warning',
					'label' => __( 'New report', 'buddynext' ),
				),
				'bn.badge_awarded'          => array(
					'icon'  => 'award',
					'tone'  => 'warn',
					'label' => __( 'Badge', 'buddynext' ),
				),
				'bn.level_up'               => array(
					'icon'  => 'trending-up',
					'tone'  => 'success',
					'label' => __( 'Level up', 'buddynext' ),
				),
				'bn.onboarding_nudge'       => array(
					'icon'  => 'sparkles',
					'tone'  => 'accent',
					'label' => __( 'Finish onboarding', 'buddynext' ),
				),
				'bn.daily_digest'           => array(
					'icon'  => 'inbox',
					'tone'  => 'info',
					'label' => __( 'Daily digest', 'buddynext' ),
				),
				'bn.weekly_digest'          => array(
					'icon'  => 'inbox',
					'tone'  => 'info',
					'label' => __( 'Weekly digest', 'buddynext' ),
				),
				'bn.media_favorited'        => array(
					'icon'  => 'heart',
					'tone'  => 'warn',
					'label' => __( 'Media favourite', 'buddynext' ),
				),
				'bn.jetonomy_reply'         => array(
					'icon'  => 'message-circle',
					'tone'  => 'accent',
					'label' => __( 'Discussion reply', 'buddynext' ),
				),
				'bn.test'                   => array(
					'icon'  => 'bell',
					'tone'  => 'info',
					'label' => __( 'Test', 'buddynext' ),
				),
			);
		}

		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}

		/**
		 * Icon/tone/label for a notification type Free does not know about.
		 *
		 * Powers BuddyNext's centralised notification center: integration
		 * notifications (type `suite.*`) supply their icon through this filter.
		 *
		 * @param array<string,string> $meta Default meta (bell icon).
		 * @param string               $type Notification type slug.
		 */
		return (array) apply_filters(
			'buddynext_notification_meta',
			array(
				'icon'  => 'bell',
				'tone'  => 'info',
				'label' => __( 'Notification', 'buddynext' ),
			),
			$type
		);
	}

	/**
	 * Resolve the deep-link URL for a notification row.
	 *
	 * @param string $type      Notification type slug.
	 * @param int    $actor_id  Actor user ID.
	 * @param int    $object_id Object ID (post / space / etc.).
	 * @param array  $data      Decoded data payload.
	 */
	/**
	 * Resolve the deep-link URL for a notification email's call-to-action.
	 *
	 * Email runs outside any request session, so the recipient is passed in
	 * explicitly to resolve "me"-relative links (e.g. a received connection
	 * request opens the recipient's own connections tab). actor_id / object_id
	 * come from the same data payload the notification was dispatched with.
	 *
	 * @param string $type         Notification type slug (e.g. 'bn.new_follower').
	 * @param int    $recipient_id Email recipient user ID.
	 * @param array  $data         Notification data payload (sender_id, object_id, ...).
	 * @return string Absolute deep-link URL, or '' when none resolves.
	 */
	public function email_action_url( string $type, int $recipient_id, array $data ): string {
		$actor_id  = isset( $data['sender_id'] ) ? (int) $data['sender_id'] : 0;
		$object_id = isset( $data['object_id'] ) ? (int) $data['object_id'] : 0;
		return $this->url_for( $type, $actor_id, $object_id, $data, $recipient_id );
	}

	/**
	 * Resolve the deep-link URL for a notification type.
	 *
	 * Shared by in-app rendering and email/cron sends. "Me"-relative links
	 * resolve against $viewer_id (the recipient), which email/cron must pass
	 * explicitly since there is no current user in those contexts.
	 *
	 * @param string               $type      Notification type slug (bn.*).
	 * @param int                  $actor_id  Actor user ID.
	 * @param int                  $object_id Primary object ID (post, space, conversation, ...).
	 * @param array<string, mixed> $data      Decoded notification data payload.
	 * @param int                  $viewer_id Recipient user ID; falls back to current user when 0.
	 * @return string Absolute URL, or empty string when no target resolves.
	 */
	private function url_for( string $type, int $actor_id, int $object_id, array $data, int $viewer_id = 0 ): string {
		// "Me"-relative deep links (a received request, your own moderation
		// record, your badge) resolve against the viewer. In-app rendering runs
		// as the recipient so get_current_user_id() is right; email/cron has no
		// current user, so the caller passes the recipient explicitly.
		$viewer_id = $viewer_id > 0 ? $viewer_id : get_current_user_id();

		switch ( $type ) {
			case 'bn.connection_requested':
				// A received request: open the RECIPIENT's own connections tab
				// (where the pending request is reviewed/accepted), not the
				// requester's profile. The recipient is the user viewing the
				// notification.
				$me = $viewer_id;
				return $me > 0 ? PageRouter::connections_url( $me ) : '';

			case 'bn.new_follower':
			case 'bn.follow_requested':
			case 'bn.connection_accepted':
			case 'bn.connection_declined':
				return $actor_id > 0 ? PageRouter::profile_url( $actor_id ) : '';

			case 'bn.post_rejected':
				// The rejected post is gone — send the author to their feed.
				return PageRouter::activity_url();

			case 'bn.post_reacted':
			case 'bn.post_commented':
			case 'bn.comment_reply':
			case 'bn.post_shared':
			case 'bn.mention':
			case 'bn.bookmark_milestone':
			case 'bn.space_new_post':
			case 'bn.post_approved':
				// object_id is a bn_posts row id — deep-link to the canonical
				// single-post permalink (/p/{id}/), not the generic feed. The
				// activity hub ignores a ?post_id= query arg, so the old form
				// silently dropped recipients on their home feed.
				return $object_id > 0
					? PageRouter::post_url( $object_id )
					: PageRouter::activity_url();

			case 'bn.space_join_requested':
				// A pending join request opens the space moderation page on the
				// "pending" tab, where the owner/mod approves or declines it —
				// not the space home page. PageRouter::space_url() falls back to
				// the spaces hub when the space is missing, so only append the
				// moderation segment when a real space URL resolved.
				if ( $object_id <= 0 ) {
					return PageRouter::spaces_url();
				}
				$space_link = PageRouter::space_url( $object_id );
				if ( PageRouter::spaces_url() === $space_link ) {
					return $space_link;
				}
				return add_query_arg( 'bn_mtab', 'pending', $space_link . 'moderation/' );

			case 'bn.space_join':
			case 'bn.space_invite':
			case 'bn.space_request_approved':
			case 'bn.space_join_approved':
			case 'bn.space_join_declined':
			case 'bn.space_role_changed':
				return $object_id > 0 ? PageRouter::space_url( $object_id ) : PageRouter::spaces_url();

			case 'bn.bulk_invite':
				return PageRouter::spaces_url();

			case 'bn.new_message':
				// The bridge stores the conversation id as the notification's
				// object_id; older rows may carry it in data instead.
				$conv_id = $object_id > 0 ? $object_id : (int) ( $data['conversation_id'] ?? 0 );
				return $conv_id > 0
					? PageRouter::conversation_url( $conv_id )
					: PageRouter::messages_url();

			case 'bn.media_favorited':
			case 'bn.jetonomy_reply':
				return $object_id > 0
					? add_query_arg( 'post_id', $object_id, PageRouter::activity_url() )
					: PageRouter::activity_url();

			case 'bn.user_warned':
			case 'bn.strike_warning':
			case 'bn.strike_issued':
			case 'bn.member_suspended':
			case 'bn.user_unsuspended':
			case 'bn.user_shadow_banned':
			case 'bn.appeal_resolved':
				// The recipient's OWN moderation record. Open the account-status
				// page, which explains the action, reason, duration, restrictions,
				// and appeal state — not the recipient's profile Posts tab, which
				// carries none of that context.
				$me = $viewer_id;
				return $me > 0 ? PageRouter::account_status_url() : '';

			case 'bn.appeal_submitted':
				// Moderator-facing (sent to every admin) — open the community
				// moderation surface where appeals are reviewed, not the admin's
				// own profile.
				return PageRouter::community_admin_url();

			case 'bn.report_resolved':
				// Reporter-facing — their report is closed and nothing on their own
				// profile is actionable, so send them to the feed.
				return PageRouter::activity_url();

			case 'bn.new_report':
				// Moderator-facing — send them to the community moderation surface.
				return PageRouter::community_admin_url();

			case 'bn.badge_awarded':
			case 'bn.level_up':
				$me = $viewer_id;
				return $me > 0 ? PageRouter::profile_url( $me ) : '';

			case 'bn.onboarding_nudge':
				return PageRouter::onboarding_url();

			case 'bn.daily_digest':
			case 'bn.weekly_digest':
				return PageRouter::activity_url();

			case 'bn.test':
				return '';

			default:
				/**
				 * Deep-link URL for a notification type Free does not know about.
				 *
				 * Powers BuddyNext's centralised notification center: integration
				 * notifications (type `suite.*`, mirrored from partner plugins via
				 * a Pro bridge) supply their own URL through this filter.
				 *
				 * @param string $url       Default empty string.
				 * @param string $type      Notification type slug.
				 * @param int    $actor_id  Actor user ID.
				 * @param int    $object_id Object ID.
				 * @param array  $data      Decoded data payload.
				 */
				$filtered_url = (string) apply_filters( 'buddynext_notification_url', '', $type, $actor_id, $object_id, $data );
				if ( '' !== $filtered_url ) {
					return $filtered_url;
				}
				// Native data-driven fallback (see resolve_message): use the stored
				// deep link for mirrored notifications.
				return (string) ( $data['url'] ?? '' );
		}
	}

	/**
	 * Whether a type supports group-collapse rendering.
	 *
	 * Mirrors the cases handled in compose_grouped().
	 *
	 * @param string $type Notification type slug.
	 */
	private function supports_group_collapse( string $type ): bool {
		return in_array(
			$type,
			array(
				'bn.new_follower',
				'bn.post_reacted',
				'bn.post_commented',
				'bn.post_shared',
				'bn.space_join',
				'bn.space_new_post',
				'bn.new_message',
				'bn.media_favorited',
			),
			true
		);
	}

	/**
	 * Resolve an actor user ID to a display name.
	 *
	 * @param int $actor_id User ID.
	 */
	private function resolve_actor_name( int $actor_id ): string {
		if ( $actor_id <= 0 ) {
			return __( 'Someone', 'buddynext' );
		}
		$user = get_userdata( $actor_id );
		if ( ! $user ) {
			return __( 'Someone', 'buddynext' );
		}
		$name = trim( (string) $user->display_name );
		return '' !== $name ? $name : __( 'Someone', 'buddynext' );
	}

	/**
	 * Resolve a space ID to its name.
	 *
	 * @param int $space_id Space ID.
	 */
	private function resolve_space_name( int $space_id ): string {
		if ( $space_id <= 0 ) {
			return __( 'a space', 'buddynext' );
		}

		$cache_key = "name_{$space_id}";
		$cached    = wp_cache_get( $cache_key, 'buddynext_space_names' );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Result is cached below.
		$name = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1",
				$space_id
			)
		);
		if ( '' === $name ) {
			$name = __( 'a space', 'buddynext' );
		}

		wp_cache_set( $cache_key, $name, 'buddynext_space_names', 300 );
		return $name;
	}

	/**
	 * Resolve the space name for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function resolve_space_for_post( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return __( 'a space', 'buddynext' );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT space_id FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$post_id
			)
		);
		return $this->resolve_space_name( $space_id );
	}

	/**
	 * Normalise a notification row's `data` column into an array.
	 *
	 * Rows may store JSON, an already-decoded array, or null.
	 *
	 * @param mixed $raw Raw value from the data column.
	 * @return array<string,mixed>
	 */
	private function normalise_data( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}
}
