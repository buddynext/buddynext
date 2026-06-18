<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Native messaging data layer.
 *
 * BuddyNext renders its own /messages/ UI (the dm-* partials) and consumes the
 * WPMediaVerse messaging engine at the API level only — via MediaClient (the
 * in-process service) for server-render, and mvs/v1 REST for live interactions.
 * This class maps the engine's conversation/message rows into the exact shapes
 * the dm-* partials expect, and supplies the small helper callables they need.
 *
 * Mirrors the includes/Media/ pattern (Client → domain → renderer); no MVS
 * screens are embedded anywhere.
 *
 * @package BuddyNext\Messages
 */

declare( strict_types=1 );

namespace BuddyNext\Messages;

use BuddyNext\Media\MediaClient;

defined( 'ABSPATH' ) || exit;

/**
 * Maps WPMediaVerse messaging data into BuddyNext-native shapes.
 */
class MessagesData {

	/**
	 * Whether the messaging engine is available.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		$svc = MediaClient::messaging();

		return is_object( $svc ) && method_exists( $svc, 'get_conversations' );
	}

	/**
	 * Whether direct messaging is turned on for this community.
	 *
	 * The site owner can disable DMs entirely via Settings → General → Direct
	 * Messaging (buddynext_enable_dm, default true). This is the canonical
	 * on/off switch every BN-side messaging entry point consults — the rail
	 * item, header icon, user-menu link, and the /messages/ route. It is the
	 * admin intent gate; whether the WPMediaVerse engine is actually present is
	 * a separate concern handled by available().
	 *
	 * @return bool
	 */
	public static function dm_enabled(): bool {
		return (bool) get_option( 'buddynext_enable_dm', true );
	}

	/**
	 * Whether a member-facing messaging entry point (profile Message button,
	 * Messages nav item, directory/space "Message" actions, the /messages/ hub)
	 * should render at all.
	 *
	 * Combines the two independent gates so every entry point asks one question:
	 *  - dm_enabled():  the site owner's on/off intent (buddynext_enable_dm).
	 *  - available():   the WPMediaVerse engine is actually present.
	 *
	 * When this returns false the entry point must be HIDDEN — not rendered and
	 * then 404'd, and never with an "install the plugin" notice (that is an admin
	 * concern; community members should not see installation instructions).
	 *
	 * @return bool
	 */
	public static function entry_enabled(): bool {
		return self::dm_enabled() && self::available();
	}

	/**
	 * Whether group conversations are available — i.e. WPMediaVerse Pro (which
	 * REST-exposes the group lifecycle at mvs-pro/v1/groups) is active. Group
	 * chat is a Pro capability; the BN UI hides every group affordance when this
	 * is false, so the feature degrades cleanly to 1-to-1 DMs.
	 *
	 * @return bool
	 */
	public static function groups_enabled(): bool {
		return class_exists( '\WPMediaVersePro\Groups\GroupController' );
	}

	/**
	 * Is this conversation row a group (vs a 1-to-1 direct message)?
	 *
	 * @param mixed $conv Conversation row.
	 * @return bool
	 */
	private static function is_group( $conv ): bool {
		return 'group' === (string) self::val( $conv, 'type', 'direct' );
	}

	/**
	 * A human label for a group: its admin-set title, or a comma-joined list of
	 * the other members' names as a sensible fallback when untitled.
	 *
	 * @param mixed $conv   Conversation row.
	 * @param int   $viewer Viewing user ID.
	 * @return string
	 */
	private static function group_label( $conv, int $viewer ): string {
		$title = trim( (string) self::val( $conv, 'title', '' ) );
		if ( '' !== $title ) {
			return $title;
		}
		$names = array();
		foreach ( (array) self::val( $conv, 'participants', array() ) as $p ) {
			if ( (int) self::val( $p, 'id', 0 ) === $viewer ) {
				continue;
			}
			$name = trim( (string) self::val( $p, 'display_name', '' ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		if ( empty( $names ) ) {
			return __( 'Group', 'buddynext' );
		}
		$shown = array_slice( $names, 0, 3 );
		$label = implode( ', ', $shown );
		if ( count( $names ) > 3 ) {
			/* translators: 1: comma-separated member names, 2: count of remaining members. */
			$label = sprintf( __( '%1$s +%2$d', 'buddynext' ), $label, count( $names ) - 3 );
		}
		return $label;
	}

	/**
	 * Active-participant roster for a group thread header / members panel (id,
	 * name, role + labels, presence, and the per-row manage flags).
	 *
	 * @param mixed $conv            Conversation row.
	 * @param int   $viewer          Viewing user ID.
	 * @param bool  $viewer_is_admin Whether the viewer is an admin of the group.
	 * @return array<int,array<string,mixed>>
	 */
	private static function roster( $conv, int $viewer, bool $viewer_is_admin ): array {
		$out = array();
		foreach ( (array) self::val( $conv, 'participants', array() ) as $p ) {
			if ( 'active' !== (string) self::val( $p, 'status', 'active' ) ) {
				continue;
			}
			$uid     = (int) self::val( $p, 'id', 0 );
			$role    = (string) self::val( $p, 'role', 'member' );
			$is_self = ( $uid === $viewer );
			$out[]   = array(
				'id'                => $uid,
				'name'              => (string) self::val( $p, 'display_name', '' ),
				'role'              => $role,
				'role_label'        => 'admin' === $role ? __( 'Admin', 'buddynext' ) : __( 'Member', 'buddynext' ),
				'role_action_label' => 'admin' === $role ? __( 'Make member', 'buddynext' ) : __( 'Make admin', 'buddynext' ),
				'is_admin'          => 'admin' === $role,
				'is_online'         => ! empty( self::val( $p, 'is_online', false ) ) || self::is_online( $viewer, $uid ),
				'is_self'           => $is_self,
				// A group admin manages everyone but themselves (leaving is the
				// self path); non-admins manage no-one.
				'can_manage'        => ( $viewer_is_admin && ! $is_self ),
			);
		}
		return $out;
	}

	/**
	 * Count of active participants in a conversation.
	 *
	 * @param mixed $conv Conversation row.
	 * @return int
	 */
	private static function active_count( $conv ): int {
		$n = 0;
		foreach ( (array) self::val( $conv, 'participants', array() ) as $p ) {
			if ( 'active' === (string) self::val( $p, 'status', 'active' ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * The messaging service, or null.
	 *
	 * @return object|null
	 */
	private static function svc() {
		$svc = MediaClient::messaging();

		return is_object( $svc ) ? $svc : null;
	}

	/**
	 * Whether a user is online, from the canonical presence reader.
	 *
	 * Delegates to BlockService::is_user_online() (bn_last_active within the
	 * 300s window, block-aware) — the same source the member directory,
	 * profile and member cards use, so presence is consistent across surfaces.
	 *
	 * @param int $viewer  Viewing user ID.
	 * @param int $user_id User whose presence is being resolved.
	 * @return bool
	 */
	private static function is_online( int $viewer, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$blocks = buddynext_service( 'blocks' );
		return is_object( $blocks ) ? (bool) $blocks->is_user_online( $viewer, $user_id ) : false;
	}

	/**
	 * Read a key from an engine row that may be an object or an associative
	 * array (the messaging service returns objects for messages but arrays for
	 * participants — normalise access here).
	 *
	 * @param mixed  $row      Object or array.
	 * @param string $key      Key/property.
	 * @param mixed  $fallback Value to return when the key is absent.
	 * @return mixed
	 */
	private static function val( $row, string $key, $fallback = '' ) {
		if ( is_array( $row ) ) {
			return $row[ $key ] ?? $fallback;
		}
		if ( is_object( $row ) ) {
			return $row->$key ?? $fallback;
		}

		return $fallback;
	}

	/**
	 * The other participant in a conversation (from the viewer's perspective).
	 *
	 * @param mixed $conv   Conversation row (object or array).
	 * @param int   $viewer Viewing user ID.
	 * @return mixed Participant (object|array) or null.
	 */
	private static function other_participant( $conv, int $viewer ) {
		foreach ( (array) self::val( $conv, 'participants', array() ) as $p ) {
			if ( (int) self::val( $p, 'id', 0 ) !== $viewer ) {
				return $p;
			}
		}

		return null;
	}

	/**
	 * Map a conversation row to the dm-rail-item shape.
	 *
	 * @param object $conv   Conversation row.
	 * @param int    $viewer Viewing user ID.
	 * @return array<string,mixed>
	 */
	private static function map_conversation( $conv, int $viewer ): array {
		$base = array(
			'id'                   => (int) self::val( $conv, 'id', 0 ),
			'last_message_preview' => (string) self::val( $conv, 'last_message_preview', '' ),
			'last_message_at'      => (string) self::val( $conv, 'last_activity_at', '' ),
			'unread_count'         => (int) self::val( $conv, 'unread_count', 0 ),
			'other_user_typing'    => false,
			'is_pinned'            => ! empty( self::val( $conv, 'is_pinned', false ) ),
		);

		if ( self::is_group( $conv ) ) {
			return array_merge(
				$base,
				array(
					'is_group'        => true,
					'member_count'    => self::active_count( $conv ),
					'other_user_id'   => 0,
					'other_user_name' => self::group_label( $conv, $viewer ),
				)
			);
		}

		$other = self::other_participant( $conv, $viewer );

		return array_merge(
			$base,
			array(
				'is_group'        => false,
				'other_user_id'   => $other ? (int) self::val( $other, 'id', 0 ) : 0,
				'other_user_name' => $other ? (string) self::val( $other, 'display_name', '' ) : __( 'Conversation', 'buddynext' ),
			)
		);
	}

	/**
	 * Total unread direct-message count for a viewer.
	 *
	 * The single source for the rail/nav unread DM badge. Reaches the
	 * WPMediaVerse messaging tables only when that engine is present
	 * (class_exists guard), and caches the result for 60s in the
	 * buddynext_nav group so the rail and any other entry point share one
	 * query per request window instead of running parallel raw-SQL paths
	 * from the template.
	 *
	 * @param int $uid Viewing user ID.
	 * @return int Unread DM count (0 when messaging is unavailable).
	 */
	public static function unread_count( int $uid ): int {
		if ( $uid <= 0 || ! class_exists( 'WPMediaVerse\\Core\\Plugin' ) ) {
			return 0;
		}

		$cache_key = "bn_unread_msgs_{$uid}";
		$cached    = wp_cache_get( $cache_key, 'buddynext_nav' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_conversations c
				 INNER JOIN {$wpdb->prefix}mvs_conversation_participants cp
				   ON cp.conversation_id = c.id AND cp.user_id = %d AND cp.status = 'active'
				 WHERE c.last_activity_at > COALESCE(cp.last_read_at, '1970-01-01')",
				$uid
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_set( $cache_key, $count, 'buddynext_nav', 60 );

		return $count;
	}

	/**
	 * Conversation lists for the rail, split into pinned/recent with counts.
	 *
	 * @param int    $viewer Viewing user ID.
	 * @param string $tab    Tab filter (all|unread|requests).
	 * @return array{pinned:array,recent:array,unread:int,requests:int}
	 */
	public static function conversations( int $viewer, string $tab = 'all' ): array {
		$svc    = self::svc();
		$rows   = $svc ? (array) $svc->get_conversations( $viewer, $tab, 50, 1 ) : array();
		$pinned = array();
		$recent = array();
		$unread = 0;
		$reqs   = 0;

		foreach ( $rows as $conv ) {
			$mapped  = self::map_conversation( $conv, $viewer );
			$unread += $mapped['unread_count'];
			if ( 'request_pending' === self::val( $conv, 'participant_status', 'active' ) ) {
				++$reqs;
			}
			if ( $mapped['is_pinned'] ) {
				$pinned[] = $mapped;
			} else {
				$recent[] = $mapped;
			}
		}

		return array(
			'pinned'   => $pinned,
			'recent'   => $recent,
			'unread'   => $unread,
			'requests' => $reqs,
		);
	}

	/**
	 * The active thread (header data + mapped messages), or null.
	 *
	 * @param int $conv_id Conversation ID.
	 * @param int $viewer  Viewing user ID.
	 * @return array<string,mixed>|null
	 */
	public static function thread( int $conv_id, int $viewer ): ?array {
		$svc = self::svc();
		if ( ! $svc || $conv_id <= 0 ) {
			return null;
		}

		$conv = $svc->get_conversation( $conv_id, $viewer );
		if ( ! $conv ) {
			return null;
		}

		$other      = self::other_participant( $conv, $viewer );
		$other_read = $other && ! empty( $other->last_read_at ) ? strtotime( (string) $other->last_read_at ) : 0;
		$rows       = (array) $svc->get_messages( $conv_id, $viewer, 0, 50 );
		$messages   = array();

		// A conversation is a "message request" for the viewer when their own
		// participant status is still pending acceptance.
		$viewer_status = 'active';
		foreach ( (array) self::val( $conv, 'participants', array() ) as $p ) {
			if ( (int) self::val( $p, 'id', 0 ) === $viewer ) {
				$viewer_status = (string) self::val( $p, 'status', 'active' );
				break;
			}
		}
		$is_request = ( 'request_pending' === $viewer_status );

		foreach ( $rows as $m ) {
			$reactions = array();
			foreach ( (array) self::val( $m, 'reactions', array() ) as $r ) {
				$slug = (string) self::val( $r, 'emoji', '' );
				if ( '' === $slug ) {
					continue;
				}
				$user_ids    = array_map( 'intval', (array) self::val( $r, 'user_ids', array() ) );
				$reactions[] = array(
					'slug'  => $slug,
					'count' => (int) self::val( $r, 'count', 0 ),
					'mine'  => in_array( $viewer, $user_ids, true ),
				);
			}

			$created   = strtotime( (string) self::val( $m, 'created_at', '' ) );
			$parent    = self::val( $m, 'parent_preview', null );
			$sender_id = (int) self::val( $m, 'sender_id', 0 );

			// Private media shared into the DM (mvs media_share), mapped to a
			// compact shape the bubble renders. WP-attachment fallback kept for
			// any legacy messages that used attachment_id.
			$share  = self::val( $m, 'media_share', null );
			$attach = self::val( $m, 'attachment', null );
			$media  = null;
			if ( $share ) {
				$media = array(
					'type'      => (string) self::val( $share, 'type', 'image' ),
					'thumbnail' => (string) self::val( $share, 'thumbnail', '' ),
					'url'       => (string) self::val( $share, 'permalink', '' ),
					'title'     => (string) self::val( $share, 'title', '' ),
				);
			} elseif ( $attach ) {
				$media = array(
					'type'      => 0 === strpos( (string) self::val( $attach, 'mime', '' ), 'image/' ) ? 'image' : 'file',
					'thumbnail' => (string) self::val( $attach, 'thumbnail', self::val( $attach, 'url', '' ) ),
					'url'       => (string) self::val( $attach, 'url', '' ),
					'title'     => (string) self::val( $attach, 'name', '' ),
				);
			}

			$messages[] = array(
				'id'                => (int) self::val( $m, 'id', 0 ),
				'body'              => (string) self::val( $m, 'content', '' ),
				'created_at'        => (string) self::val( $m, 'created_at', '' ),
				'sender_id'         => $sender_id,
				'reactions'         => $reactions,
				'reply_to'          => $parent ? array( 'body' => (string) self::val( $parent, 'content', '' ) ) : null,
				'media'             => $media,
				'read_by_recipient' => ( $sender_id === $viewer && $other_read && $created && $other_read >= $created ),
			);
		}

		$is_group     = self::is_group( $conv );
		$viewer_admin = $is_group && self::viewer_is_admin( $conv, $viewer );

		return array(
			'conversation_id' => (int) self::val( $conv, 'id', 0 ),
			'is_group'        => $is_group,
			'member_count'    => $is_group ? self::active_count( $conv ) : 0,
			'participants'    => $is_group ? self::roster( $conv, $viewer, $viewer_admin ) : array(),
			'is_admin'        => $viewer_admin,
			'other_user_id'   => ( ! $is_group && $other ) ? (int) self::val( $other, 'id', 0 ) : 0,
			'display_name'    => $is_group
				? self::group_label( $conv, $viewer )
				: ( $other ? (string) self::val( $other, 'display_name', '' ) : __( 'Conversation', 'buddynext' ) ),
			'is_online'       => ( ! $is_group && $other ) ? ( ! empty( self::val( $other, 'is_online', false ) ) || self::is_online( $viewer, (int) self::val( $other, 'id', 0 ) ) ) : false,
			// Real avatar markup for the other user, mirroring the rail list
			// (dm-rail-item.php) so the header, bubbles, and typing indicator show
			// the SAME image the conversation list does instead of initials. Groups
			// keep '' (the header renders the group icon).
			'avatar_html'     => ( ! $is_group && $other )
				? get_avatar(
					(int) self::val( $other, 'id', 0 ),
					40,
					'',
					(string) self::val( $other, 'display_name', '' ),
					array( 'force_display' => true )
				)
				: '',
			'is_request'      => $is_request,
			'messages'        => $messages,
		);
	}

	/**
	 * Whether the viewer is an admin of a group conversation.
	 *
	 * @param mixed $conv   Conversation row.
	 * @param int   $viewer Viewing user ID.
	 * @return bool
	 */
	private static function viewer_is_admin( $conv, int $viewer ): bool {
		foreach ( (array) self::val( $conv, 'participants', array() ) as $p ) {
			if ( (int) self::val( $p, 'id', 0 ) === $viewer ) {
				return 'admin' === (string) self::val( $p, 'role', 'member' );
			}
		}
		return false;
	}

	/**
	 * Find or create a direct conversation with another user and return its ID.
	 *
	 * Backs the /messages/?to={user_id} entry points (member directory, profile
	 * connections). Returns 0 when blocked, rate-limited, or unavailable.
	 *
	 * @param int $viewer Viewing user ID.
	 * @param int $other  Target user ID.
	 * @return int Conversation ID, or 0.
	 */
	public static function open_with( int $viewer, int $other ): int {
		return self::open_with_result( $viewer, $other )['conversation_id'];
	}

	/**
	 * Find or create a direct conversation and return BOTH the ID and the reason.
	 *
	 * Same as open_with() but preserves the engine's denial reason so the caller
	 * can show a reason-aware notice ("blocked" vs "only accepts messages from
	 * connections" vs "isn't accepting messages") instead of a single generic
	 * line. The reason mirrors MessagingService::can_message() codes: 'blocked',
	 * 'dms_disabled', 'mutual_follow_required', 'account_too_new', 'rate_limited',
	 * or '' when the conversation opened. 'unavailable' covers a missing engine or
	 * a self/invalid target.
	 *
	 * @param int $viewer Viewing user ID.
	 * @param int $other  Target user ID.
	 * @return array{conversation_id: int, reason: string}
	 */
	public static function open_with_result( int $viewer, int $other ): array {
		$svc = self::svc();
		if ( ! $svc || $other <= 0 || $other === $viewer || ! method_exists( $svc, 'find_or_create_conversation' ) ) {
			return array(
				'conversation_id' => 0,
				'reason'          => 'unavailable',
			);
		}

		$result  = $svc->find_or_create_conversation( $viewer, $other );
		$conv_id = is_array( $result ) ? (int) ( $result['conversation_id'] ?? 0 ) : 0;
		// On success the engine returns the participant status (active /
		// request_pending), not a denial — surface no reason in that case.
		$status = is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '';

		return array(
			'conversation_id' => $conv_id,
			'reason'          => $conv_id > 0 ? '' : $status,
		);
	}

	/**
	 * The helper callables the dm-* partials require.
	 *
	 * @param int $viewer Viewing user ID (used to resolve recipient presence).
	 * @return array{initials_fn:callable,tone_fn:callable,relative_fn:callable,online_fn:callable}
	 */
	public static function helpers( int $viewer = 0 ): array {
		return array(
			'initials_fn' => static function ( $name ): string {
				$split = preg_split( '/\s+/', trim( (string) $name ) );
				$parts = is_array( $split ) ? $split : array();
				$ini   = strtoupper( (string) ( $parts[0][0] ?? '' ) . (string) ( isset( $parts[1] ) ? ( $parts[1][0] ?? '' ) : '' ) );

				return '' !== $ini ? $ini : 'U';
			},
			'tone_fn'     => static function ( $user_id ): int {
				return ( (int) $user_id % 8 ) + 1;
			},
			'relative_fn' => static function ( $when ): string {
				$ts = is_numeric( $when ) ? (int) $when : strtotime( (string) $when );
				if ( ! $ts ) {
					return '';
				}

				/* translators: %s: human-readable time difference, e.g. "5 mins". */
				return sprintf( __( '%s ago', 'buddynext' ), human_time_diff( $ts, time() ) );
			},
			// Presence is resolved from the canonical reader the rest of the
			// product uses (BlockService::is_user_online → bn_last_active), so
			// the conversation rail matches the member directory, profile and
			// member cards. The seam previously returned a hardcoded false, so
			// every recipient appeared permanently offline in Messages.
			'online_fn'   => static function ( $user_id ) use ( $viewer ): bool {
				return self::is_online( $viewer, (int) $user_id );
			},
		);
	}
}
