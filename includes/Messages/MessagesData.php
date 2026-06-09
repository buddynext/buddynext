<?php
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
	 * The messaging service, or null.
	 *
	 * @return object|null
	 */
	private static function svc() {
		$svc = MediaClient::messaging();

		return is_object( $svc ) ? $svc : null;
	}

	/**
	 * Read a key from an engine row that may be an object or an associative
	 * array (the messaging service returns objects for messages but arrays for
	 * participants — normalise access here).
	 *
	 * @param mixed  $row     Object or array.
	 * @param string $key     Key/property.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	private static function val( $row, string $key, $default = '' ) {
		if ( is_array( $row ) ) {
			return $row[ $key ] ?? $default;
		}
		if ( is_object( $row ) ) {
			return $row->$key ?? $default;
		}

		return $default;
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
		$other = self::other_participant( $conv, $viewer );

		return array(
			'id'                   => (int) self::val( $conv, 'id', 0 ),
			'other_user_id'        => $other ? (int) self::val( $other, 'id', 0 ) : 0,
			'other_user_name'      => $other ? (string) self::val( $other, 'display_name', '' ) : __( 'Conversation', 'buddynext' ),
			'last_message_preview' => (string) self::val( $conv, 'last_message_preview', '' ),
			'last_message_at'      => (string) self::val( $conv, 'last_activity_at', '' ),
			'unread_count'         => (int) self::val( $conv, 'unread_count', 0 ),
			'other_user_typing'    => false,
			'is_pinned'            => ! empty( self::val( $conv, 'is_pinned', false ) ),
		);
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

		$other        = self::other_participant( $conv, $viewer );
		$other_read   = $other && ! empty( $other->last_read_at ) ? strtotime( (string) $other->last_read_at ) : 0;
		$rows         = (array) $svc->get_messages( $conv_id, $viewer, 0, 50 );
		$messages     = array();

		foreach ( $rows as $m ) {
			$reactions = array();
			foreach ( (array) self::val( $m, 'reactions', array() ) as $r ) {
				$emoji = (string) self::val( $r, 'emoji', '' );
				if ( '' !== $emoji ) {
					$reactions[ $emoji ] = (int) self::val( $r, 'count', 0 );
				}
			}

			$created    = strtotime( (string) self::val( $m, 'created_at', '' ) );
			$parent     = self::val( $m, 'parent_preview', null );
			$sender_id  = (int) self::val( $m, 'sender_id', 0 );
			$messages[] = array(
				'id'                => (int) self::val( $m, 'id', 0 ),
				'body'              => (string) self::val( $m, 'content', '' ),
				'created_at'        => (string) self::val( $m, 'created_at', '' ),
				'sender_id'         => $sender_id,
				'reactions'         => $reactions,
				'reply_to'          => $parent ? array( 'body' => (string) self::val( $parent, 'content', '' ) ) : null,
				'read_by_recipient' => ( $sender_id === $viewer && $other_read && $created && $other_read >= $created ),
			);
		}

		return array(
			'conversation_id' => (int) self::val( $conv, 'id', 0 ),
			'other_user_id'   => $other ? (int) self::val( $other, 'id', 0 ) : 0,
			'display_name'    => $other ? (string) self::val( $other, 'display_name', '' ) : __( 'Conversation', 'buddynext' ),
			'is_online'       => $other ? ! empty( self::val( $other, 'is_online', false ) ) : false,
			'avatar_html'     => '',
			'messages'        => $messages,
		);
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
		$svc = self::svc();
		if ( ! $svc || $other <= 0 || $other === $viewer || ! method_exists( $svc, 'find_or_create_conversation' ) ) {
			return 0;
		}

		$result = $svc->find_or_create_conversation( $viewer, $other );

		return is_array( $result ) ? (int) ( $result['conversation_id'] ?? 0 ) : 0;
	}

	/**
	 * The helper callables the dm-* partials require.
	 *
	 * @return array{initials_fn:callable,tone_fn:callable,relative_fn:callable,online_fn:callable}
	 */
	public static function helpers(): array {
		return array(
			'initials_fn' => static function ( $name ): string {
				$parts = preg_split( '/\s+/', trim( (string) $name ) ) ?: array();
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
			// Server-render presence is best-effort false; the poll updates
			// online state live. Kept as a seam so a presence map can wire in.
			'online_fn'   => static function ( $user_id ): bool {
				return false;
			},
		);
	}
}
