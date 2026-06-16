<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * Jetonomy bridge listener.
 *
 * Mirrors EVERY Jetonomy notification (replies, mentions, accepted answers,
 * join requests, votes, …) into BuddyNext's central notification center so a
 * member sees them all in `/notifications/`.
 *
 * Jetonomy 1.5.0 fires one central hook for every notification it creates:
 *   do_action( 'jetonomy_notification_created', int $notification_id,
 *       int $user_id, string $type, string $object_type, int $object_id,
 *       string $message, string $url );
 * carrying a ready message + deep link, so BN mirrors data-driven — no per-type
 * copy to maintain. The `jt.*` types render straight from the stored data via the
 * three Free notification seams. Jetonomy keeps its own notification UI; BN
 * mirrors alongside it (the partner owns its emails — BN never double-emails).
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Mirrors Jetonomy notifications into BuddyNext's central center.
 */
class JetonomyBridgeListener implements ListenerInterface {

	/**
	 * Single BN type all Jetonomy notifications mirror into — one prefs toggle for
	 * the source, like SuiteNotifications' per-source type.
	 */
	private const TYPE = 'jt.notification';

	/**
	 * Register the Jetonomy notification mirror + the data-driven render seams +
	 * the prefs-catalogue entry (which marks it collect-only, never email).
	 *
	 * Bails when Jetonomy is not active so nothing is registered on sites that
	 * do not use the forum plugin.
	 */
	public function register(): void {
		// Guard on the real Jetonomy bootstrap class (matches JetonomyBridge); the
		// old 'Jetonomy\Core\Plugin' guard never existed, so the listener silently
		// never registered on a live site.
		if ( ! class_exists( 'Jetonomy\Jetonomy' ) ) {
			return;
		}

		add_action( 'jetonomy_notification_created', array( $this, 'on_notification' ), 10, 7 );

		// Render the mirrored type straight from its stored message/url.
		add_filter( 'buddynext_notification_message', array( $this, 'filter_message' ), 10, 5 );
		add_filter( 'buddynext_notification_url', array( $this, 'filter_url' ), 10, 5 );
		add_filter( 'buddynext_notification_meta', array( $this, 'filter_meta' ), 10, 2 );

		// Collect-only: Jetonomy owns its own emails, so BN never emails this type.
		add_filter( 'buddynext_notification_prefs_catalogue', array( $this, 'filter_catalogue' ) );
	}

	/**
	 * Register the mirrored-Discussions notification in the prefs catalogue with
	 * `can_email = false` so BuddyNext only displays it — Jetonomy sends its emails.
	 *
	 * @param array<string,array<string,mixed>> $catalogue Incoming catalogue.
	 * @return array<string,array<string,mixed>>
	 */
	public function filter_catalogue( array $catalogue ): array {
		$catalogue[ self::TYPE ] = array(
			'label'              => __( 'Discussions', 'buddynext' ),
			'description'        => __( 'Replies, mentions, and answers from the forums.', 'buddynext' ),
			'group'              => 'social',
			'default_on_site'    => true,
			'default_email_freq' => 'off',
			'can_email'          => false,
		);
		return $catalogue;
	}

	/**
	 * Mirror a Jetonomy notification into BN's center.
	 *
	 * @param int    $notification_id Jetonomy notification row id (unused).
	 * @param int    $user_id         Recipient.
	 * @param string $type            Jetonomy type (reply, mention, accepted_answer, …).
	 * @param string $object_type     'post' | 'reply' | 'user' | 'space' | ''.
	 * @param int    $object_id       Related object id.
	 * @param string $message         Ready-to-display message.
	 * @param string $url             Deep link to the content.
	 */
	public function on_notification( int $notification_id, int $user_id, string $type, string $object_type, int $object_id, string $message = '', string $url = '' ): void {
		// $message and $url are optional: current Jetonomy fires this hook with 7
		// args (message + deep link appended), but older/other firing sites pass
		// only 5. Defaulting them avoids the ArgumentCountError that 500'd reply
		// creation, while still honouring the real values when they are supplied.
		if ( $user_id <= 0 || '' === $message || ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		// Honour BN blocks when the actor is resolvable from the object — so a
		// blocked member's forum activity never notifies you in BN.
		$actor_id = $this->resolve_actor( $object_type, $object_id );
		if ( $actor_id > 0 && $this->is_blocked( $user_id, $actor_id ) ) {
			return;
		}

		$slug = sanitize_key( $type );

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $actor_id > 0 ? $actor_id : null,
				'type'         => self::TYPE,
				'object_type'  => '' !== $object_type ? $object_type : 'jetonomy',
				'object_id'    => $object_id,
				// Dedupe re-fired events (keep the Jetonomy subtype so distinct
				// events on the same object never collapse).
				'group_key'    => 'jt_' . $slug . '_' . $object_id,
				'data'         => array(
					'message' => $message,
					'url'     => $url,
				),
			)
		);
	}

	/**
	 * Message text for any jt.* type — straight from the stored data.
	 *
	 * @param string $message    Incoming message.
	 * @param string $type       Notification type.
	 * @param string $actor_name Actor name (unused for mirrored types).
	 * @param int    $object_id  Object id (unused).
	 * @param array  $data       Decoded row data.
	 * @return string
	 */
	public function filter_message( $message, string $type, string $actor_name, int $object_id, array $data ) {
		return $this->is_jetonomy( $type ) ? (string) ( $data['message'] ?? $message ) : $message;
	}

	/**
	 * Deep-link URL for any jt.* type.
	 *
	 * @param string $url       Incoming url.
	 * @param string $type      Notification type.
	 * @param int    $actor_id  Actor id (unused).
	 * @param int    $object_id Object id (unused).
	 * @param array  $data      Decoded row data.
	 * @return string
	 */
	public function filter_url( $url, string $type, int $actor_id, int $object_id, array $data ) {
		return $this->is_jetonomy( $type ) ? (string) ( $data['url'] ?? $url ) : $url;
	}

	/**
	 * Icon/tone/label for any jt.* type.
	 *
	 * @param array<string,string> $meta Incoming meta.
	 * @param string               $type Notification type.
	 * @return array<string,string>
	 */
	public function filter_meta( array $meta, string $type ): array {
		if ( ! $this->is_jetonomy( $type ) ) {
			return $meta;
		}
		return array(
			'icon'  => 'messages-square',
			'tone'  => 'info',
			'label' => __( 'Discussions', 'buddynext' ),
		);
	}

	/**
	 * Resolve the acting user for a Jetonomy object, or 0 when not applicable.
	 *
	 * @param string $object_type Object type from the hook.
	 * @param int    $object_id   Object id.
	 * @return int
	 */
	private function resolve_actor( string $object_type, int $object_id ): int {
		if ( $object_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table = '';
		if ( 'reply' === $object_type ) {
			$table = $wpdb->prefix . 'jt_replies';
		} elseif ( 'post' === $object_type ) {
			$table = $wpdb->prefix . 'jt_posts';
		}
		if ( '' === $table ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT author_id FROM {$table} WHERE id = %d LIMIT 1", $object_id ) );
	}

	/**
	 * Whether either user has blocked the other.
	 *
	 * @param int $recipient_id Notification recipient.
	 * @param int $sender_id    Acting user.
	 * @return bool
	 */
	private function is_blocked( int $recipient_id, int $sender_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$blocked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blocker_id FROM {$wpdb->prefix}bn_blocks
				 WHERE ( blocker_id = %d AND blocked_id = %d )
				    OR ( blocker_id = %d AND blocked_id = %d )
				 LIMIT 1",
				$recipient_id,
				$sender_id,
				$sender_id,
				$recipient_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null !== $blocked;
	}

	/**
	 * Whether a notification type is the mirrored Jetonomy type.
	 *
	 * @param string $type Notification type.
	 * @return bool
	 */
	private function is_jetonomy( string $type ): bool {
		return self::TYPE === $type;
	}
}
