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

/**
 * WPMediaVerse ↔ BuddyNext integration layer.
 */
class WPMediaVerseBridge {

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

		// Gate DMs on bn_blocks.
		add_filter( 'mvs_can_send_message', array( $this, 'check_block' ), 10, 3 );

		// Route new-message events into bn_notifications.
		add_action( 'mvs_message_sent', array( $this, 'on_message_sent' ), 10, 4 );

		// Notify media owner when someone favourites their content.
		add_action( 'mvs_favorite_toggled', array( $this, 'on_favorite_toggled' ), 10, 3 );

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
	}

	/**
	 * Inject a "Media" link into the BuddyNext left navigation rail.
	 *
	 * Uses the engine's mapped Explore page URL. The `active` flag is computed from
	 * the current REQUEST_URI and honoured wherever the rail renders (BuddyNext's own
	 * hubs); the media pages wrap their own shell, so the flag is set defensively.
	 *
	 * Hooked on: buddynext_rail_items( array $items, string $hub )
	 *
	 * @param array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}> $items Existing rail items.
	 * @return array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}>
	 */
	public function inject_media_nav_item( array $items ): array {
		// Resolve the engine's media landing page (its mapped Explore page) —
		// the mvs_media CPT/archive was dropped, so never depend on it. Falls
		// back to /media/ when no Explore page is mapped.
		$explore_id = (int) get_option( 'mvs_page_explore', 0 );
		$media_url  = $explore_id > 0 ? get_permalink( $explore_id ) : home_url( '/media/' );
		$media_path = (string) ( wp_parse_url( (string) $media_url, PHP_URL_PATH ) ?? '/media/' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$is_active   = str_starts_with( $request_uri, $media_path );

		$items[] = array(
			'key'    => 'media',
			'label'  => __( 'Media', 'buddynext' ),
			'url'    => (string) $media_url,
			'icon'   => 'image',
			'show'   => true,
			'active' => $is_active,
		);

		return $items;
	}

	/**
	 * Enqueue MVS lightbox JS + CSS on BuddyNext pages.
	 *
	 * This enables the Instagram-style lightbox (reactions, comments, favorites,
	 * gallery nav) for photo posts in the BuddyNext feed. The lightbox listens
	 * for clicks on .mvs-activity-media[data-mvs-media-id] elements.
	 */
	public function enqueue_lightbox(): void {
		// Only on front-end BuddyNext hub pages.
		if ( is_admin() || ! did_action( 'buddynext_loaded' ) ) {
			return;
		}

		// Enqueue the lightbox script (already registered by MVS on non-MVS pages).
		if ( wp_script_is( 'mvs-lightbox', 'registered' ) ) {
			wp_enqueue_script( 'mvs-lightbox' );
		} elseif ( defined( 'MVS_PLUGIN_URL' ) && defined( 'MVS_VERSION' ) ) {
			wp_enqueue_script(
				'mvs-lightbox',
				MVS_PLUGIN_URL . 'assets/js/mvs-lightbox.js',
				array(),
				MVS_VERSION,
				true
			);
			wp_localize_script(
				'mvs-lightbox',
				'mvsLightboxData',
				array(
					'restUrl'    => esc_url_raw( rest_url( 'mvs/v1/' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'isLoggedIn' => is_user_logged_in(),
				)
			);
		}

		// Also enqueue MVS frontend CSS for lightbox styling.
		if ( wp_style_is( 'mvs-frontend', 'registered' ) ) {
			wp_enqueue_style( 'mvs-frontend' );
		} elseif ( defined( 'MVS_PLUGIN_URL' ) && defined( 'MVS_VERSION' ) ) {
			wp_enqueue_style(
				'mvs-frontend',
				MVS_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				MVS_VERSION
			);
		}
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
				&& ( $blocks->is_restricted( $recipient_id, $sender_id )
					|| $blocks->is_muted( $recipient_id, $sender_id ) )
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

		// Find the BuddyNext post that has this media_id in its media_ids JSON array.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bn_post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_posts
				 WHERE media_ids LIKE %s AND status = 'published'
				 ORDER BY created_at DESC LIMIT 1",
				'%' . $wpdb->esc_like( (string) $media_id ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $bn_post_id ) {
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
