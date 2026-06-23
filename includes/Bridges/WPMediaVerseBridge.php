<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WPMediaVerse bridge.
 *
 * Connects BuddyNext to the WPMediaVerse DM engine. Responsible for:
 *
 * 1. Declaring BuddyNext as active so WPMediaVerse's NotificationListener
 *    skips its own notification (avoids duplicates).
 * 2. Blocking DMs from users who are blocked via bn_blocks.
 * 3. Routing new-message events into bn_notifications (type bn.new_message)
 *    so the BuddyNext notification system handles delivery + email prefs.
 *
 * Only boots if WPMediaVerse free is active — checked at hook time via
 * class_exists, not on load, so activation order doesn't matter.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Notifications\NotificationService;
use BuddyNext\Media\MediaClient;
use BuddyNext\Feed\PostService;
use BuddyNext\Feed\IntegrationActivity;

/**
 * WPMediaVerse ↔ BuddyNext integration layer.
 */
class WPMediaVerseBridge {

	/**
	 * Re-entrancy guard for the two-way follow mirror: true while this bridge is
	 * propagating a follow/unfollow into the other store, so the reciprocal
	 * action it triggers there is ignored instead of looping back.
	 *
	 * @var bool
	 */
	private bool $mirroring_follow = false;

	/**
	 * Attach all hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		if ( ! class_exists( 'WPMediaVerse\Core\Plugin' ) ) {
			return;
		}

		// Tell WPMediaVerse that BuddyNext is active so it skips its own
		// floating chat panel, standalone messages page, and notifications.
		add_filter( 'mvs_buddynext_active', '__return_true' );

		// Gate DMs on bn_blocks + the recipient's DM-privacy preference.
		add_filter( 'mvs_can_send_message', array( $this, 'check_block' ), 10, 3 );

		// When that gate denies, report WHY (block vs privacy preference) so the
		// sender sees an accurate notice instead of a generic "blocked".
		add_filter( 'mvs_dm_denial_reason', array( $this, 'dm_denial_reason' ), 10, 3 );

		// Point WPMediaVerse user-profile links (media grid, lightbox author,
		// REST author_url) at the BuddyNext member profile. Without this, MVS
		// falls back to its own /media/@{login}/ URL, which is not a member
		// profile.
		add_filter( 'mvs_user_profile_url', array( $this, 'member_profile_url' ), 10, 2 );

		// Route new-message events into bn_notifications.
		add_action( 'mvs_message_sent', array( $this, 'on_message_sent' ), 10, 4 );

		// Notify media owner when someone favourites their content.
		add_action( 'mvs_favorite_toggled', array( $this, 'on_favorite_toggled' ), 10, 3 );

		// Keep the WPMediaVerse follow graph (mvs_follows) and BuddyNext's
		// (bn_follows) in sync both ways. MVS profiles and BN profiles otherwise
		// show divergent follow state for the same pair. A re-entrancy guard plus
		// an is_following() short-circuit prevents the mirror from looping.
		add_action( 'mvs_user_followed', array( $this, 'mirror_mvs_follow' ), 10, 2 );
		add_action( 'mvs_user_unfollowed', array( $this, 'mirror_mvs_unfollow' ), 10, 2 );
		add_action( 'buddynext_user_followed', array( $this, 'mirror_bn_follow' ), 10, 2 );
		add_action( 'buddynext_user_unfollowed', array( $this, 'mirror_bn_unfollow' ), 10, 2 );

		// The MVS profile "Message" button dispatches a mvs-open-conversation JS
		// event whose only native listener (the MVS chat panel) is suppressed
		// while BN owns /messages/. Enqueue a tiny listener that routes the click
		// to BN's native conversation instead.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_message_bridge' ) );

		// Unified nav: inject "Media" link into the BuddyNext left rail. This adds
		// a link to the media surface on BuddyNext's OWN pages — it does not alter
		// any WPMediaVerse page.
		add_filter( 'buddynext_rail_items', array( $this, 'inject_media_nav_item' ) );

		// WPMediaVerse pages (e.g. /explore-media/) render as the plugin's own
		// default — BuddyNext does not wrap them in its hub shell or inject its
		// sidebar, per the owner rule that BN must not touch MediaVerse pages.

		// NOTE: /messages/ is now a fully NATIVE BuddyNext surface (templates/
		// messages/native.php + the buddynext/messages store) consuming the engine
		// via mvs/v1 — no MVS chat screen is embedded. The former
		// buddynext_render_messages embed + its render_messages()/
		// enqueue_messaging_assets()/print_messaging_config() helpers were removed.

		// NOTE: BuddyNext consumes WPMediaVerse at the REST/API level ONLY and
		// owns 100% of its own UX — WPMediaVerse JS/CSS is never enqueued on
		// BuddyNext pages. (The former enqueue_lightbox() loaded a now-removed
		// mvs asset and 404'd; BN renders its own media + lightbox).

		// Sync MVS lightbox comments → BuddyNext activity comments.
		// When a user comments on a photo via the lightbox, create a matching
		// bn_comments entry threaded under the BuddyNext post that holds the media.
		add_action( 'mvs_comment_created', array( $this, 'sync_lightbox_comment' ), 10, 3 );

		// LinkedIn-style connect note → DM message request. When a connection
		// request carries a note (only when the owner enabled the note step), the
		// note is delivered to the recipient as a direct-message request so they
		// can read the context and decide whether to engage before accepting.
		add_action( 'buddynext_connection_requested', array( $this, 'deliver_note_as_message_request' ), 10, 4 );

		// Surface standalone WPMediaVerse uploads in the activity feed. The
		// upload itself fired no feed entry before, so media shared from the
		// "Upload Media" surface never appeared in the community feed. Deferred +
		// guarded so a photo posted through the BuddyNext composer — which uploads
		// via the same WPMediaVerse path and then creates its OWN feed post — is
		// never duplicated.
		add_action( 'mvs_media_uploaded', array( $this, 'on_media_uploaded' ), 10, 4 );
		add_action( 'buddynext_mvs_media_activity', array( $this, 'publish_media_activity' ), 10, 3 );
	}

	/**
	 * Defer a feed entry for a fresh WPMediaVerse upload.
	 *
	 * Runs on `mvs_media_uploaded`. A BuddyNext composer photo uploads the media
	 * first and creates its post a moment later (a separate request), so posting
	 * immediately would duplicate it. We re-check after a short delay and only
	 * publish if the media was NOT attached to a BuddyNext post in the meantime.
	 *
	 * @param int    $media_id   WPMediaVerse media-index id.
	 * @param array  $file_data  Upload metadata (privacy, user_id, …).
	 * @param int    $user_id    Uploader user id.
	 * @param string $media_type Resolved type: photo|video|audio|document.
	 * @return void
	 */
	public function on_media_uploaded( $media_id, $file_data, $user_id, $media_type ): void {
		$media_id = (int) $media_id;
		$user_id  = (int) $user_id;
		if ( $media_id <= 0 || $user_id <= 0 ) {
			return;
		}

		// Only public uploads belong in the public feed.
		$privacy = is_array( $file_data ) ? (string) ( $file_data['privacy'] ?? 'public' ) : 'public';
		if ( 'public' !== $privacy ) {
			return;
		}

		$args = array( $media_id, $user_id, (string) $media_type );

		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			if ( ! as_next_scheduled_action( 'buddynext_mvs_media_activity', $args, 'buddynext' ) ) {
				as_schedule_single_action( time() + 120, 'buddynext_mvs_media_activity', $args, 'buddynext' );
			}
			return;
		}

		// No Action Scheduler (rare): publish inline, accepting the small
		// composer-duplicate risk over losing the activity entirely.
		$this->publish_media_activity( $media_id, $user_id, (string) $media_type );
	}

	/**
	 * Publish the deferred feed entry for an upload, unless it was already
	 * surfaced by a BuddyNext post (composer photo/media post or a prior run).
	 *
	 * Photos become a native inline photo post; other media types become a
	 * media card linking to the WPMediaVerse media page.
	 *
	 * @param int    $media_id   WPMediaVerse media-index id.
	 * @param int    $user_id    Uploader user id.
	 * @param string $media_type Resolved media type.
	 * @return void
	 */
	public function publish_media_activity( $media_id, $user_id, $media_type ): void {
		$media_id = (int) $media_id;
		$user_id  = (int) $user_id;
		if ( $media_id <= 0 || $user_id <= 0 ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attached = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE media_ids IS NOT NULL AND JSON_CONTAINS(media_ids, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) wp_json_encode( $media_id )
			)
		);
		if ( $attached > 0 ) {
			return;
		}

		if ( in_array( (string) $media_type, array( 'photo', 'image' ), true ) ) {
			( new PostService() )->create(
				$user_id,
				array(
					'type'      => 'photo',
					'content'   => '',
					'media_ids' => array( $media_id ),
				)
			);
			return;
		}

		$url  = '';
		$repo = MediaClient::repo();
		if ( is_object( $repo ) && method_exists( $repo, 'get_permalink' ) ) {
			$url = (string) $repo->get_permalink( $media_id );
		}
		if ( '' === $url ) {
			return;
		}

		IntegrationActivity::publish( $user_id, self::media_activity_verb( (string) $media_type ), $url, '', 'media', '' );
	}

	/**
	 * Human verb for a non-photo media activity card.
	 *
	 * @param string $media_type Resolved media type.
	 * @return string
	 */
	private static function media_activity_verb( string $media_type ): string {
		switch ( $media_type ) {
			case 'video':
				return __( 'shared a video', 'buddynext' );
			case 'audio':
				return __( 'shared an audio clip', 'buddynext' );
			default:
				return __( 'shared a photo', 'buddynext' );
		}
	}

	/**
	 * Deliver a connection-request note to the recipient as a DM message request.
	 *
	 * Fired on buddynext_connection_requested. Only acts when a note is present —
	 * the note step is opt-in via buddynext_connection_require_note, so a 1-click
	 * connect carries no note and this is a no-op. The note is written into a
	 * conversation between the two users; the recipient's participant lands as a
	 * pending request, so it surfaces under their Messages "Requests" tab to accept
	 * or decline — it never auto-opens an active thread with someone they have not
	 * chosen to engage.
	 *
	 * The pending-request status is requested explicitly through the engine's
	 * find_or_create_conversation( …, [ 'force_request' => true ] ) seam (WPMediaVerse
	 * 1.7.1+). The engine still enforces every denial first — a hard block, a
	 * disabled inbox, self, too-new, or the rate limit — so this can never reach a
	 * member who has shut the sender out; it only changes an otherwise-allowed send
	 * from an active thread into a request. Falls back to a plain conversation on
	 * older engine builds that ignore the third argument.
	 *
	 * Hooked on: buddynext_connection_requested( int, int, int, string ).
	 *
	 * @param int    $connection_id Connection row ID (unused).
	 * @param int    $requester_id  User who sent the connection request.
	 * @param int    $recipient_id  User receiving the request.
	 * @param string $note          Optional note attached to the request.
	 * @return void
	 */
	public function deliver_note_as_message_request( int $connection_id, int $requester_id, int $recipient_id, string $note = '' ): void {
		unset( $connection_id );

		$note = trim( $note );
		if ( '' === $note || $requester_id <= 0 || $recipient_id <= 0 ) {
			return;
		}

		$svc = MediaClient::messaging();
		if ( ! is_object( $svc )
			|| ! method_exists( $svc, 'find_or_create_conversation' )
			|| ! method_exists( $svc, 'send_message' ) ) {
			return;
		}

		try {
			$conv    = $svc->find_or_create_conversation( $requester_id, $recipient_id, array( 'force_request' => true ) );
			$conv_id = is_array( $conv ) ? (int) ( $conv['conversation_id'] ?? 0 ) : 0;

			if ( $conv_id > 0 ) {
				$svc->send_message( $conv_id, $requester_id, array( 'content' => $note ) );
			}
		} catch ( \Throwable $e ) {
			// Best-effort: the connection request itself already succeeded and its
			// in-app notification still fires. Never let a messaging-engine error
			// bubble back into the connect flow.
			unset( $e );
		}
	}

	/**
	 * Inject a person-specific "Media" link into the BuddyNext left rail.
	 *
	 * Points the viewer at their OWN profile Media tab (the media they have shared),
	 * not the global media Explore page — a "my media" shortcut. Hidden for guests.
	 * The `active` flag is computed from the current REQUEST_URI against that
	 * profile-tab path.
	 *
	 * Hooked on: buddynext_rail_items( array $items, string $hub )
	 *
	 * @param array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}> $items Existing rail items.
	 * @return array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}>
	 */
	public function inject_media_nav_item( array $items ): array {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return $items;
		}

		$media_url  = trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $uid ) ) . 'media/';
		$media_path = rtrim( (string) ( wp_parse_url( $media_url, PHP_URL_PATH ) ?? '' ), '/' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$is_active   = '' !== $media_path && str_starts_with( rtrim( $request_uri, '/' ), $media_path );

		$items[] = array(
			'key'    => 'media',
			'label'  => __( 'Media', 'buddynext' ),
			'url'    => $media_url,
			'icon'   => 'image',
			'show'   => true,
			'active' => $is_active,
			// Personal "You" group — it is the viewer's own media, so it sits with
			// Profile / Discussions / Bookmarks, not in the community group up top.
			'group'  => 'you',
			'order'  => 205,
		);

		return $items;
	}

	/**
	 * Gate a DM send against the recipient's block list AND DM-access preference.
	 *
	 * BuddyNext layers this on top of MediaVerse's own DM controls via the same
	 * mvs_can_send_message filter — either side can deny, neither overrides the
	 * other. Enforces:
	 *   - recipient has blocked the sender → deny;
	 *   - recipient's "who can DM me" preference (bn_privacy_dm, seeded on
	 *     registration from buddynext_default_dm_access, falling back to that
	 *     option when unset): everyone | members | connections | nobody.
	 * Site admins (manage_options) bypass so staff can always reach members.
	 *
	 * Hooked on: mvs_can_send_message (int $sender_id, int $recipient_id)
	 *
	 * @param bool $allowed      Current allowed state from earlier filters.
	 * @param int  $sender_id    User attempting to send.
	 * @param int  $recipient_id Intended message recipient.
	 * @return bool
	 */
	public function check_block( bool $allowed, int $sender_id, int $recipient_id ): bool {
		if ( ! $allowed ) {
			return false;
		}

		// Staff can always reach anyone.
		if ( user_can( $sender_id, 'manage_options' ) ) {
			return true;
		}

		// Recipient blocked sender → deny. Routed through the BlockService model
		// (the data-access API), never a raw query from this bridge.
		$blocks = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : null;
		if ( is_object( $blocks ) && method_exists( $blocks, 'has_blocked' )
			&& $blocks->has_blocked( $recipient_id, $sender_id ) ) {
			return false;
		}

		// Recipient's DM-access preference. Empty = inherit the site default.
		$pref = (string) get_user_meta( $recipient_id, 'bn_privacy_dm', true );
		if ( '' === $pref ) {
			$pref = (string) get_option( 'buddynext_default_dm_access', 'everyone' );
		}

		switch ( $pref ) {
			case 'nobody':
				return false;
			case 'connections':
				$conn = function_exists( 'buddynext_service' ) ? buddynext_service( 'connections' ) : null;
				return is_object( $conn )
					&& method_exists( $conn, 'are_connected' )
					&& $conn->are_connected( $sender_id, $recipient_id );
			case 'members':
				return $sender_id > 0;
			case 'everyone':
			default:
				return true;
		}
	}

	/**
	 * Translate a check_block() denial into a specific reason code.
	 *
	 * The check_block() gate is boolean, so a denial otherwise surfaces as the
	 * generic 'blocked'. This mirrors its logic to report the real cause — an
	 * actual block stays 'blocked', a "nobody" preference becomes 'dms_disabled',
	 * and a "connections-only" preference becomes 'connections_only' — so the
	 * sender's notice is accurate. Other causes keep the incoming default.
	 *
	 * Hooked on: mvs_dm_denial_reason ( string $reason, int $sender_id, int $recipient_id ).
	 *
	 * @param string $reason       Reason resolved so far (default 'blocked').
	 * @param int    $sender_id    Sender user ID.
	 * @param int    $recipient_id Recipient user ID.
	 * @return string
	 */
	public function dm_denial_reason( string $reason, int $sender_id, int $recipient_id ): string {
		// Staff are never denied by check_block, so there is nothing to translate.
		if ( user_can( $sender_id, 'manage_options' ) ) {
			return $reason;
		}

		// A real block keeps the generic 'blocked' reason (same check as check_block).
		$blocks = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : null;
		if ( is_object( $blocks ) && method_exists( $blocks, 'has_blocked' )
			&& $blocks->has_blocked( $recipient_id, $sender_id ) ) {
			return 'blocked';
		}

		// Otherwise the denial is the recipient's DM-privacy preference.
		$pref = (string) get_user_meta( $recipient_id, 'bn_privacy_dm', true );
		if ( '' === $pref ) {
			$pref = (string) get_option( 'buddynext_default_dm_access', 'everyone' );
		}

		switch ( $pref ) {
			case 'nobody':
				return 'dms_disabled';
			case 'connections':
				return 'connections_only';
			default:
				return $reason;
		}
	}

	/**
	 * Resolve a WPMediaVerse user-profile link to the BuddyNext member profile.
	 *
	 * Hooked on: mvs_user_profile_url ($url, $user_id). MVS otherwise falls back
	 * to home_url('/media/@{login}/'), which is not a member profile. Returns the
	 * MVS default untouched if the user can't be resolved.
	 *
	 * @param string $url     URL resolved so far by MVS.
	 * @param int    $user_id User whose profile is being linked.
	 * @return string
	 */
	public function member_profile_url( string $url, int $user_id ): string {
		if ( $user_id <= 0 ) {
			return $url;
		}

		$profile = \BuddyNext\Core\PageRouter::profile_url( $user_id );
		return '' !== $profile ? $profile : $url;
	}

	/**
	 * Resolve the WPMediaVerse follow service, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function mvs_follows(): ?object {
		if ( ! class_exists( '\WPMediaVerse\Core\Plugin' ) ) {
			return null;
		}
		$container = \WPMediaVerse\Core\Plugin::container();
		if ( ! is_object( $container ) || ! $container->has( 'follows' ) ) {
			return null;
		}
		$svc = $container->get( 'follows' );
		return is_object( $svc ) ? $svc : null;
	}

	/**
	 * Resolve BuddyNext's follow service, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function bn_follows(): ?object {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return null;
		}
		$svc = buddynext_service( 'follows' );
		return is_object( $svc ) ? $svc : null;
	}

	/**
	 * Mirror a follow created on a WPMediaVerse profile into bn_follows.
	 *
	 * @param int $follower_id  User who followed.
	 * @param int $following_id User being followed.
	 * @return void
	 */
	public function mirror_mvs_follow( int $follower_id, int $following_id ): void {
		if ( $this->mirroring_follow ) {
			return;
		}
		$bn = $this->bn_follows();
		if ( null === $bn || ! method_exists( $bn, 'follow' ) || ! method_exists( $bn, 'is_following' ) ) {
			return;
		}
		if ( $bn->is_following( $follower_id, $following_id ) ) {
			return;
		}
		// try/finally so a throw in follow() (or a downstream listener) can't leave
		// the re-entrancy guard stuck true, which would silently disable all
		// follow mirroring for the rest of the request.
		$this->mirroring_follow = true;
		try {
			$bn->follow( $follower_id, $following_id );
		} finally {
			$this->mirroring_follow = false;
		}
	}

	/**
	 * Mirror an unfollow on a WPMediaVerse profile into bn_follows.
	 *
	 * @param int $follower_id  User who unfollowed.
	 * @param int $following_id User being unfollowed.
	 * @return void
	 */
	public function mirror_mvs_unfollow( int $follower_id, int $following_id ): void {
		if ( $this->mirroring_follow ) {
			return;
		}
		$bn = $this->bn_follows();
		if ( null === $bn || ! method_exists( $bn, 'unfollow' ) || ! method_exists( $bn, 'is_following' ) ) {
			return;
		}
		if ( ! $bn->is_following( $follower_id, $following_id ) ) {
			return;
		}
		// try/finally so a throw can't leave the re-entrancy guard stuck true.
		$this->mirroring_follow = true;
		try {
			$bn->unfollow( $follower_id, $following_id );
		} finally {
			$this->mirroring_follow = false;
		}
	}

	/**
	 * Mirror a BuddyNext follow into the WPMediaVerse follow graph.
	 *
	 * @param int $follower_id  User who followed.
	 * @param int $following_id User being followed.
	 * @return void
	 */
	public function mirror_bn_follow( int $follower_id, int $following_id ): void {
		if ( $this->mirroring_follow ) {
			return;
		}
		$mvs = $this->mvs_follows();
		if ( null === $mvs || ! method_exists( $mvs, 'follow' ) || ! method_exists( $mvs, 'is_following' ) ) {
			return;
		}
		if ( $mvs->is_following( $follower_id, $following_id ) ) {
			return;
		}
		// try/finally so a throw can't leave the re-entrancy guard stuck true.
		$this->mirroring_follow = true;
		try {
			$mvs->follow( $follower_id, $following_id );
		} finally {
			$this->mirroring_follow = false;
		}
	}

	/**
	 * Mirror a BuddyNext unfollow into the WPMediaVerse follow graph.
	 *
	 * @param int $follower_id  User who unfollowed.
	 * @param int $following_id User being unfollowed.
	 * @return void
	 */
	public function mirror_bn_unfollow( int $follower_id, int $following_id ): void {
		if ( $this->mirroring_follow ) {
			return;
		}
		$mvs = $this->mvs_follows();
		if ( null === $mvs || ! method_exists( $mvs, 'unfollow' ) || ! method_exists( $mvs, 'is_following' ) ) {
			return;
		}
		if ( ! $mvs->is_following( $follower_id, $following_id ) ) {
			return;
		}
		// try/finally so a throw can't leave the re-entrancy guard stuck true.
		$this->mirroring_follow = true;
		try {
			$mvs->unfollow( $follower_id, $following_id );
		} finally {
			$this->mirroring_follow = false;
		}
	}

	/**
	 * Enqueue the listener that routes the MVS profile "Message" button to
	 * BuddyNext's native conversation. Loaded for logged-in visitors only (the
	 * button never renders for guests or on your own profile).
	 *
	 * @return void
	 */
	public function enqueue_message_bridge(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$handle = 'bn-mvs-message-bridge';
		wp_register_script( $handle, '', array(), '1.0.0', array( 'in_footer' => true ) );
		wp_enqueue_script( $handle );

		$messages_url = \BuddyNext\Core\PageRouter::messages_url();
		$inline       = 'document.addEventListener("mvs-open-conversation",function(e){'
			. 'var u=e&&e.detail&&parseInt(e.detail.userId,10);'
			. 'if(!u){return;}'
			. 'var b=' . wp_json_encode( $messages_url ) . ';'
			. 'window.location.href=b+(b.indexOf("?")===-1?"?":"&")+"to="+u;'
			. '});';
		wp_add_inline_script( $handle, $inline );
	}

	/**
	 * Notify the media owner when their content is favourited.
	 *
	 * Only fires a notification on 'added' — not on 'removed' — to avoid
	 * spamming the owner when a user toggles the favourite off.
	 *
	 * Hooked on: mvs_favorite_toggled ($media_id, $user_id, $action). MVS's
	 * FavoriteService emits 'added'/'removed' (not 'add'/'remove').
	 *
	 * @param int    $media_id Media item ID.
	 * @param int    $user_id  User who toggled the favourite.
	 * @param string $action   'added' or 'removed'.
	 */
	public function on_favorite_toggled( int $media_id, int $user_id, string $action ): void {
		if ( 'added' !== $action ) {
			return;
		}

		$owner_id = (int) get_post_field( 'post_author', $media_id );
		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		( new NotificationService() )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.media_favorited',
				'object_type'  => 'media',
				'object_id'    => $media_id,
				'group_key'    => "mvs_fav_{$media_id}",
				'data'         => array( 'media_id' => $media_id ),
			)
		);
	}

	/**
	 * Create a bn.new_message notification for each recipient.
	 *
	 * Skips the sender themselves (no self-notification).
	 *
	 * Hooked on: mvs_message_sent ($message_id, $conversation_id, $sender_id, $recipient_ids)
	 *
	 * @param int   $message_id      Message that was sent.
	 * @param int   $conversation_id Conversation the message belongs to.
	 * @param int   $sender_id       User who sent the message.
	 * @param int[] $recipient_ids   Users who should receive the notification.
	 */
	public function on_message_sent( int $message_id, int $conversation_id, int $sender_id, array $recipient_ids ): void {
		$service = new NotificationService();

		// Normalise recipient ids — strip sender, ints only.
		$clean_recipients = array_values(
			array_filter(
				array_map( 'intval', $recipient_ids ),
				static fn( int $rid ): bool => $rid > 0 && $rid !== $sender_id
			)
		);

		/**
		 * Fires from the sender's perspective when a DM goes out.
		 *
		 * BN-domain adapter on top of `mvs_message_sent` so gamification
		 * plugins, analytics collectors, and webhook bridges can hook
		 * the BN namespace without depending on the WPMediaVerse hook
		 * being present.
		 *
		 * @param int   $sender_id       Sender (actor).
		 * @param int   $message_id      Message that was sent.
		 * @param int   $conversation_id Conversation the message belongs to.
		 * @param int[] $recipient_ids   Recipients of the message (sender stripped).
		 */
		do_action( 'buddynext_dm_sent', $sender_id, $message_id, $conversation_id, $clean_recipients );

		$blocks = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : null;

		foreach ( $clean_recipients as $recipient_id ) {
			// Restrict gate. WPMV writes the message either way — sender
			// doesn't know they're restricted — but BN won't badge the
			// recipient's bell, fire the recipient-side adapter event, or
			// push to their notification feed. The message still sits in
			// the WPMV inbox; the recipient can find it manually if they
			// look, but no signal interrupts them.
			// Mute is a one-way "stop notifying me about this person": if the
			// recipient muted the sender, the message still lands in their WPMV
			// inbox but no bell notification interrupts them — same suppression as
			// restrict, opposite relationship direction.
			if ( $blocks
				&& ( ( method_exists( $blocks, 'is_restricted' ) && $blocks->is_restricted( $recipient_id, $sender_id ) )
					|| ( method_exists( $blocks, 'is_muted' ) && $blocks->is_muted( $recipient_id, $sender_id ) ) )
			) {
				continue;
			}

			$service->create(
				array(
					'recipient_id' => $recipient_id,
					'sender_id'    => $sender_id,
					'type'         => 'bn.new_message',
					'object_type'  => 'conversation',
					'object_id'    => $conversation_id,
					'group_key'    => "dm_{$conversation_id}_{$recipient_id}",
					'data'         => array( 'message_id' => $message_id ),
				)
			);

			/**
			 * Fires from each recipient's perspective when a DM arrives.
			 *
			 * Per-recipient mirror of `buddynext_dm_sent`. Useful for
			 * gamification rules that award the recipient (e.g. "first
			 * conversation started") or for unread-count counters that
			 * key off the recipient id.
			 *
			 * @param int $recipient_id    Recipient (per-iteration).
			 * @param int $sender_id       Sender (actor).
			 * @param int $message_id      Message id.
			 * @param int $conversation_id Conversation id.
			 */
			do_action( 'buddynext_dm_received', $recipient_id, $sender_id, $message_id, $conversation_id );
		}
	}

	/**
	 * Sync a WPMediaVerse lightbox comment to the BuddyNext activity feed.
	 *
	 * When a user comments on a photo via the MVS lightbox, find the bn_posts
	 * entry that contains that media_id and create a bn_comments row threaded
	 * under it — so the comment appears in the BuddyNext feed as a regular
	 * post comment.
	 *
	 * Signature matches the engine: mvs_comment_created fires
	 * ( $media_id, $user_id, $comment_id, $content, $source ).
	 *
	 * @param int $media_id   MVS media post ID.
	 * @param int $user_id    Commenting user ID (unused; resolved from the comment).
	 * @param int $comment_id WP comment ID created by MVS.
	 */
	public function sync_lightbox_comment( int $media_id, int $user_id, int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		$user_id = (int) $comment->user_id;
		if ( ! $user_id ) {
			return;
		}

		global $wpdb;

		// Find the BuddyNext post that has this media_id in its media_ids JSON
		// array. JSON_CONTAINS does an exact array-element match — a LIKE '%5%'
		// matched 5, 50, 51, 15… (false positives). JSON_VALID guards rows whose
		// media_ids is NULL/empty/non-JSON so the function can't error on them.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bn_post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_posts
				 WHERE media_ids IS NOT NULL AND media_ids <> ''
				   AND JSON_VALID(media_ids) AND JSON_CONTAINS(media_ids, %s)
				   AND status = 'published'
				 ORDER BY created_at DESC LIMIT 1",
				(string) $media_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $bn_post_id ) {
			return;
		}

		// Dedup: this hook can re-fire for the same lightbox comment (re-saves,
		// repeated sync passes). Without a guard each fire inserted another
		// bn_comments row, double-counting and re-notifying. Skip if an identical
		// comment (same post + author + body) already exists.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_comments
				 WHERE object_type = 'post' AND object_id = %d AND user_id = %d AND content = %s
				 LIMIT 1",
				$bn_post_id,
				$user_id,
				wp_kses_post( $comment->comment_content )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $existing > 0 ) {
			return;
		}

		// Create the bn_comments entry.
		$now = current_time( 'mysql' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'object_type' => 'post',
				'object_id'   => $bn_post_id,
				'user_id'     => $user_id,
				'content'     => wp_kses_post( $comment->comment_content ),
				'parent_id'   => 0,
				'created_at'  => $now,
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Increment comment count on the bn_posts row. Guard the service lookup
		// the same way the rest of this bridge does (e.g. the blocks check above):
		// a null container/key must skip the counter, not fatal the sync.
		$post_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'post_service' ) : null;
		if ( $post_service ) {
			$post_service->increment_counter( $bn_post_id, 'comment_count' );
		}

		// Fire BuddyNext hook so notifications/webhooks pick it up. Use the
		// canonical 4-arg signature (comment_id, object_type, object_id, user_id)
		// that CommentService fires and every listener expects — a short 3-arg
		// form would ArgumentCountError-fatal the 4-arg listeners.
		$new_comment_id = (int) $wpdb->insert_id;
		if ( $new_comment_id > 0 ) {
			do_action( 'buddynext_comment_created', $new_comment_id, 'post', $bn_post_id, $user_id );
		}
	}
}
