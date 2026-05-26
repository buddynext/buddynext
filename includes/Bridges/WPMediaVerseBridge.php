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

		// Unified nav: inject "Media" link into BuddyNext top nav.
		add_filter( 'buddynext_nav_items', array( $this, 'inject_media_nav_item' ) );

		// Shell wrapping: BuddyNext hub shell + sidebar on all WPMediaVerse pages.
		add_action( 'mvs_before_content', array( $this, 'open_hub_shell' ) );
		add_action( 'mvs_after_content', array( $this, 'close_hub_shell' ) );

		// Render MVS chat components inside BuddyNext's messages hub shell.
		add_action( 'buddynext_render_messages', array( $this, 'render_messages' ) );

		// Enqueue MVS lightbox on all BuddyNext front-end pages so photo posts
		// open in the full Instagram-style lightbox with reactions, comments, favorites.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_lightbox' ) );

		// Sync MVS lightbox comments → BuddyNext activity comments.
		// When a user comments on a photo via the lightbox, create a matching
		// bn_comments entry threaded under the BuddyNext post that holds the media.
		add_action( 'mvs_comment_created', array( $this, 'sync_lightbox_comment' ), 10, 2 );
	}

	/**
	 * Enqueue WPMediaVerse messaging assets on BuddyNext's messages page.
	 *
	 * Called early from render_messages() so CSS/JS are available when
	 * wp_head() fires inside get_header(). Must bypass the mvs_buddynext_active
	 * guard that normally suppresses MVS asset loading.
	 *
	 * @return void
	 */
	public function enqueue_messaging_assets(): void {
		wp_enqueue_style(
			'mvs-messaging',
			MVS_PLUGIN_URL . 'assets/css/messaging.css',
			array(),
			MVS_VERSION
		);

		wp_register_script_module(
			'mvs-messaging',
			MVS_PLUGIN_URL . 'assets/js/messaging.js',
			array(
				array(
					'id'     => '@wordpress/interactivity',
					'import' => 'static',
				),
			),
			MVS_VERSION
		);
		wp_enqueue_script_module( 'mvs-messaging' );
	}

	/**
	 * Print the MVS messaging runtime config (REST base, nonce, current user).
	 *
	 * Rendered inline before the chat templates so the Interactivity API store
	 * can read it on init.
	 *
	 * @return void
	 */
	private function print_messaging_config(): void {
		$user   = wp_get_current_user();
		$config = array(
			'restBase'    => esc_url_raw( rest_url( 'mvs/v1' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'currentUser' => array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
			),
		);

		if ( class_exists( 'WPMediaVerse\Messaging\RestPollingTransport' ) ) {
			$transport           = apply_filters(
				'mvs_messaging_transport',
				new \WPMediaVerse\Messaging\RestPollingTransport()
			);
			$config['transport'] = $transport->get_client_config();
		}

		wp_print_inline_script_tag(
			'window.mvsMessagingConfig = ' . wp_json_encode( $config ) . ';',
			array( 'id' => 'mvs-messaging-config' )
		);
	}

	/**
	 * Render the WPMediaVerse two-pane chat UI inside BuddyNext's hub shell.
	 *
	 * Outputs the conversation list panel (280px) and the thread panel (1fr)
	 * wrapped in a flex container that becomes a single grid child inside
	 * .bn-hub-shell's 1fr column.
	 *
	 * @return void
	 */
	public function render_messages(): void {
		$this->enqueue_messaging_assets();
		$this->print_messaging_config();

		$partials = MVS_PLUGIN_DIR . 'templates/partials/';
		?>
		<div
			class="bn-msg-shell"
			data-wp-interactive="mvs/messaging"
			data-wp-init="callbacks.onInit"
			data-wp-bind--data-active-conv="state.activeConversationId"
		>
			<div class="bn-msg-sidebar">
				<?php require $partials . 'chat-list.php'; ?>
			</div>

			<div class="bn-msg-thread" data-wp-bind--hidden="!state.activeConversationId">
				<?php require $partials . 'chat-conversation.php'; ?>
			</div>

			<div class="bn-msg-empty" data-wp-bind--hidden="state.activeConversationId">
				<div class="bn-msg-empty-icon" aria-hidden="true">
					<?php echo \BuddyNext\Core\IconService::render( 'message-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<p class="bn-msg-empty-title"><?php esc_html_e( 'Your messages', 'buddynext' ); ?></p>
				<p class="bn-msg-empty-sub"><?php esc_html_e( 'Select a conversation or start a new one.', 'buddynext' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Inject a "Media" link into the BuddyNext top navigation bar.
	 *
	 * Uses the mvs_media post type archive URL. Active state is detected by
	 * checking whether the current page has the mvs-page body class (set by
	 * WPMediaVerse's TemplateLoader::maybe_add_mvs_body_class).
	 *
	 * Hooked on: buddynext_nav_items( array $items )
	 *
	 * @param array<int, array{label: string, url: string, icon?: string, active?: bool}> $items Existing nav items.
	 * @return array<int, array{label: string, url: string, icon?: string, active?: bool}>
	 */
	public function inject_media_nav_item( array $items ): array {
		$media_url  = get_post_type_archive_link( 'mvs_media' );
		$media_path = (string) ( wp_parse_url( (string) $media_url, PHP_URL_PATH ) ?? '/media/' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$is_active   = str_starts_with( $request_uri, $media_path );

		$items[] = array(
			'key'    => 'media',
			'label'  => __( 'Media', 'buddynext' ),
			'url'    => (string) $media_url,
			'icon'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
			'active' => $is_active,
		);

		return $items;
	}

	/**
	 * Render the BuddyNext subnav on WPMediaVerse pages.
	 *
	 * Hooked on mvs_before_content (fired by all MVS templates after
	 * get_header). Bails silently when BuddyNext is not fully booted.
	 */
	/**
	 * Open the BuddyNext hub shell on WPMediaVerse pages.
	 *
	 * Renders BuddyNext nav + opens the hub-shell grid container. The
	 * close_hub_shell() method on mvs_after_content renders the sidebar
	 * and closes the grid.
	 *
	 * @return void
	 */
	public function open_hub_shell(): void {
		if ( ! function_exists( 'buddynext_get_template' ) || ! did_action( 'buddynext_loaded' ) ) {
			return;
		}

		// Ensure BuddyNext base CSS is loaded (hub shell grid, sidebar, nav styles).
		wp_enqueue_style( 'bn-base' );

		buddynext_get_template( 'partials/nav.php' );
		echo '<div class="bn-hub-shell"><div class="bn-mvs-content">';
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
	 * Close the BuddyNext hub shell + render community sidebar.
	 *
	 * @return void
	 */
	public function close_hub_shell(): void {
		if ( ! function_exists( 'buddynext_get_template' ) || ! did_action( 'buddynext_loaded' ) ) {
			return;
		}

		echo '</div>'; // .bn-mvs-content
		buddynext_get_template( 'partials/sidebar.php' );
		echo '</div>'; // .bn-hub-shell
	}

	/**
	 * Return false if the sender is blocked by the recipient.
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

		global $wpdb;

		// Check whether recipient has blocked sender.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$block = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blocker_id FROM {$wpdb->prefix}bn_blocks
				 WHERE blocker_id = %d AND blocked_id = %d AND type = 'block'
				 LIMIT 1",
				$recipient_id,
				$sender_id
			)
		);

		return null === $block;
	}

	/**
	 * Notify the media owner when their content is favourited.
	 *
	 * Only fires a notification on 'add' — not on 'remove' — to avoid
	 * spamming the owner when a user toggles the favourite off.
	 *
	 * Hooked on: mvs_favorite_toggled ($media_id, $user_id, $action)
	 *
	 * @param int    $media_id Media item ID.
	 * @param int    $user_id  User who toggled the favourite.
	 * @param string $action   'add' or 'remove'.
	 */
	public function on_favorite_toggled( int $media_id, int $user_id, string $action ): void {
		if ( 'add' !== $action ) {
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

		foreach ( $clean_recipients as $recipient_id ) {
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
	 * @param int $comment_id WP comment ID created by MVS.
	 * @param int $media_id   MVS media post ID.
	 */
	public function sync_lightbox_comment( int $comment_id, int $media_id ): void {
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
			)
		);

		// Increment comment count on the bn_posts row.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_posts SET comment_count = comment_count + 1 WHERE id = %d",
				$bn_post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Fire BuddyNext hook so notifications/webhooks pick it up.
		$new_comment_id = (int) $wpdb->insert_id;
		if ( $new_comment_id > 0 ) {
			do_action( 'buddynext_comment_created', $new_comment_id, $bn_post_id, $user_id );
		}
	}
}
