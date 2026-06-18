<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WordPress Privacy Tools integration (export + erase).
 *
 * WHY THIS EXISTS
 * ───────────────
 * BuddyNext stores all of its social-graph, content, and per-user settings in
 * custom tables ({prefix}bn_*) and in bn_* user meta. WordPress core's
 * Tools → Export/Erase Personal Data screens know nothing about these, so an
 * administrator could not fulfil a GDPR/CCPA export or erasure request for any
 * BuddyNext data. This class plugs that gap by registering one personal-data
 * exporter and one eraser through the standard core filters
 * (`wp_privacy_personal_data_exporters` / `wp_privacy_personal_data_erasers`),
 * so BuddyNext data is covered by the same admin workflow as everything else.
 *
 * WHAT IT COVERS
 * ──────────────
 * Tables keyed by the user (verified against Core\Installer schema):
 *   - bn_posts            (user_id)                     authored feed posts
 *   - bn_comments         (user_id)                     authored comments
 *   - bn_follows          (follower_id / following_id)  follow graph (both ways)
 *   - bn_connections      (requester_id / recipient_id) connection requests
 *   - bn_blocks           (blocker_id / blocked_id)     blocks/mutes (both ways)
 *   - bn_space_members    (user_id)                     space memberships
 *   - bn_notifications    (recipient_id / sender_id)    notifications (both ways)
 *   - bn_notification_prefs (user_id)                   per-type notify prefs
 *   - bn_hashtag_follows  (user_id)                     followed hashtags
 *   - bn_profile_values   (user_id)                     extended profile fields
 * Plus every bn_* row in {prefix}usermeta for the user (discovered dynamically
 * so the key list never drifts as features add meta).
 *
 * ERASURE STRATEGY (mirrors how BuddyNext already handles deleted users)
 * ─────────────────────────────────────────────────────────────────────
 *   - Posts            → hard-delete + cascade child rows, matching
 *                        Feed\PostService::delete().
 *   - Comments         → soft-delete (is_deleted=1, content blanked), matching
 *                        Comments\CommentService::delete(), so threads survive
 *                        and the author is anonymised at render.
 *   - Relational rows  → hard-delete in both directions, matching
 *                        SocialGraph\UserCleanupListener::on_deleted_user().
 *   - bn_* user meta   → deleted.
 *
 * DEFERRED (not owned by BuddyNext)
 * ─────────────────────────────────
 * Direct messages and uploaded media live in WPMediaVerse tables (wp_mvs_*),
 * consumed by BuddyNext only via WPMediaVerseBridge. Those must be exported /
 * erased by WPMediaVerse's own privacy integration and are intentionally NOT
 * touched here.
 *
 * @package BuddyNext\Privacy
 */

declare( strict_types=1 );

namespace BuddyNext\Privacy;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Registers and implements the BuddyNext personal-data exporter and eraser.
 */
class PrivacyTools implements ListenerInterface {

	/**
	 * Stable identifier used for both the exporter and eraser registration.
	 */
	private const ID = 'buddynext';

	/**
	 * Rows processed per page for the paginated export/erase contract.
	 */
	private const PER_PAGE = 100;

	/**
	 * Register the WordPress privacy hooks.
	 *
	 * Registered unconditionally: the admin Tools → Export/Erase Personal Data
	 * workflow is a compliance obligation that must work regardless of whether
	 * the optional front-end self-service toggles are enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Add the BuddyNext exporter to the core exporters list.
	 *
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'BuddyNext', 'buddynext' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the BuddyNext eraser to the core erasers list.
	 *
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'BuddyNext', 'buddynext' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Exporter
	 * ──────────────────────────────────────────────────────────────────── */

	/**
	 * Export a user's BuddyNext data, paginated by the core contract.
	 *
	 * Page 1 carries the bounded, per-user sets (profile, meta, relational
	 * graph, memberships) plus the first page of authored posts. Subsequent
	 * pages stream the remaining authored posts and comments — the only
	 * potentially large collections — so a prolific member never overruns a
	 * single request.
	 *
	 * @param string $email_address Email of the user being exported.
	 * @param int    $page          1-based page number.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$page = max( 1, (int) $page );
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id = (int) $user->ID;

		// Page 1: all the bounded sets in one shot.
		if ( 1 === $page ) {
			$items = array_merge(
				$this->export_profile_meta( $user_id ),
				$this->export_profile_values( $user_id ),
				$this->export_follows( $user_id ),
				$this->export_connections( $user_id ),
				$this->export_blocks( $user_id ),
				$this->export_space_members( $user_id ),
				$this->export_hashtag_follows( $user_id ),
				$this->export_notification_prefs( $user_id ),
				$this->export_notifications( $user_id ),
				$this->export_posts( $user_id, 1 )
			);

			return array(
				'data' => $items,
				'done' => $this->is_posts_done( $user_id, 1 ) && ! $this->has_comments( $user_id ),
			);
		}

		// Pages 2+: stream posts until exhausted, then comments.
		$post_pages = $this->page_count( $this->count_posts( $user_id ) );

		if ( $page <= $post_pages ) {
			$items = $this->export_posts( $user_id, $page );
			$done  = ( $page >= $post_pages ) && ! $this->has_comments( $user_id );

			return array(
				'data' => $items,
				'done' => $done,
			);
		}

		// Comments stream on the pages after the posts run out.
		$comment_page  = $page - $post_pages;
		$comment_pages = $this->page_count( $this->count_comments( $user_id ) );
		$items         = $this->export_comments( $user_id, $comment_page );
		$done          = $comment_page >= max( 1, $comment_pages );

		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	/**
	 * Export the user's bn_* user meta as a single grouped item.
	 *
	 * Keys are discovered dynamically (LIKE 'bn\_%') so the export never falls
	 * out of sync with features that add new meta. Internal volatile keys
	 * (rate-limit counters, transient-style state) are skipped.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_profile_meta( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s ORDER BY meta_key",
				$user_id,
				$wpdb->esc_like( 'bn_' ) . '%'
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$key = (string) $row['meta_key'];

			if ( $this->is_skippable_meta_key( $key ) ) {
				continue;
			}

			// Do NOT use maybe_unserialize(): usermeta values can be
			// attacker-influenced, and unserializing a crafted object payload
			// risks PHP object injection. allowed_classes => false deserializes
			// arrays (so they still export as structured JSON) but never
			// instantiates an object gadget.
			$value = $row['meta_value'];
			if ( is_serialized( $value ) ) {
				$value = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}

			$data[] = array(
				'name'  => $key,
				'value' => (string) $value,
			);
		}

		if ( empty( $data ) ) {
			return array();
		}

		return array(
			array(
				'group_id'    => 'buddynext_profile',
				'group_label' => __( 'BuddyNext Profile & Settings', 'buddynext' ),
				'item_id'     => 'buddynext-profile-' . $user_id,
				'data'        => $data,
			),
		);
	}

	/**
	 * Export the user's extended profile field values (bn_profile_values).
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_profile_values( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.field_id, v.value, f.label
				 FROM {$p}bn_profile_values v
				 LEFT JOIN {$p}bn_profile_fields f ON f.id = v.field_id
				 WHERE v.user_id = %d
				 ORDER BY v.field_id, v.entry_index",
				$user_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$label  = '' !== (string) $row['label'] ? (string) $row['label'] : ( 'field #' . (int) $row['field_id'] );
			$data[] = array(
				'name'  => $label,
				'value' => (string) $row['value'],
			);
		}

		return array(
			array(
				'group_id'    => 'buddynext_profile_fields',
				'group_label' => __( 'BuddyNext Profile Fields', 'buddynext' ),
				'item_id'     => 'buddynext-profile-fields-' . $user_id,
				'data'        => $data,
			),
		);
	}

	/**
	 * Export the user's follow relationships (both directions).
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_follows( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$following = $wpdb->get_results( $wpdb->prepare( "SELECT following_id, status, created_at FROM {$p}bn_follows WHERE follower_id = %d", $user_id ), ARRAY_A );
		$followers = $wpdb->get_results( $wpdb->prepare( "SELECT follower_id, status, created_at FROM {$p}bn_follows WHERE following_id = %d", $user_id ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();

		foreach ( $following as $i => $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_following',
				'group_label' => __( 'BuddyNext Following', 'buddynext' ),
				'item_id'     => 'buddynext-following-' . $user_id . '-' . $i,
				'data'        => array(
					array(
						'name'  => __( 'Following user', 'buddynext' ),
						'value' => $this->user_label( (int) $row['following_id'] ),
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Since', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		foreach ( $followers as $i => $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_followers',
				'group_label' => __( 'BuddyNext Followers', 'buddynext' ),
				'item_id'     => 'buddynext-follower-' . $user_id . '-' . $i,
				'data'        => array(
					array(
						'name'  => __( 'Follower', 'buddynext' ),
						'value' => $this->user_label( (int) $row['follower_id'] ),
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Since', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export the user's connection requests (both directions).
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_connections( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT requester_id, recipient_id, status, note, created_at FROM {$p}bn_connections WHERE requester_id = %d OR recipient_id = %d",
				$user_id,
				$user_id
			),
			ARRAY_A
		);

		$items = array();
		foreach ( $rows as $i => $row ) {
			$other     = (int) $row['requester_id'] === $user_id ? (int) $row['recipient_id'] : (int) $row['requester_id'];
			$direction = (int) $row['requester_id'] === $user_id ? __( 'Sent', 'buddynext' ) : __( 'Received', 'buddynext' );

			$items[] = array(
				'group_id'    => 'buddynext_connections',
				'group_label' => __( 'BuddyNext Connections', 'buddynext' ),
				'item_id'     => 'buddynext-connection-' . $user_id . '-' . $i,
				'data'        => array(
					array(
						'name'  => __( 'Member', 'buddynext' ),
						'value' => $this->user_label( $other ),
					),
					array(
						'name'  => __( 'Direction', 'buddynext' ),
						'value' => $direction,
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Note', 'buddynext' ),
						'value' => (string) $row['note'],
					),
					array(
						'name'  => __( 'Requested', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export the user's blocks/mutes (the ones they created).
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_blocks( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blocked_id, type, created_at FROM {$p}bn_blocks WHERE blocker_id = %d",
				$user_id
			),
			ARRAY_A
		);

		$items = array();
		foreach ( $rows as $i => $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_blocks',
				'group_label' => __( 'BuddyNext Blocked Members', 'buddynext' ),
				'item_id'     => 'buddynext-block-' . $user_id . '-' . $i,
				'data'        => array(
					array(
						'name'  => __( 'Member', 'buddynext' ),
						'value' => $this->user_label( (int) $row['blocked_id'] ),
					),
					array(
						'name'  => __( 'Type', 'buddynext' ),
						'value' => (string) $row['type'],
					),
					array(
						'name'  => __( 'Since', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export the user's space memberships.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_space_members( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.space_id, m.role, m.status, m.joined_at, s.name
				 FROM {$p}bn_space_members m
				 LEFT JOIN {$p}bn_spaces s ON s.id = m.space_id
				 WHERE m.user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		$items = array();
		foreach ( $rows as $i => $row ) {
			$name    = '' !== (string) $row['name'] ? (string) $row['name'] : ( '#' . (int) $row['space_id'] );
			$items[] = array(
				'group_id'    => 'buddynext_spaces',
				'group_label' => __( 'BuddyNext Space Memberships', 'buddynext' ),
				'item_id'     => 'buddynext-space-' . $user_id . '-' . $i,
				'data'        => array(
					array(
						'name'  => __( 'Space', 'buddynext' ),
						'value' => $name,
					),
					array(
						'name'  => __( 'Role', 'buddynext' ),
						'value' => (string) $row['role'],
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Joined', 'buddynext' ),
						'value' => (string) $row['joined_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export the hashtags the user follows.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_hashtag_follows( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.name, hf.created_at
				 FROM {$p}bn_hashtag_follows hf
				 LEFT JOIN {$p}bn_hashtags h ON h.id = hf.hashtag_id
				 WHERE hf.user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'name'  => '#' . (string) $row['name'],
				'value' => (string) $row['created_at'],
			);
		}

		return array(
			array(
				'group_id'    => 'buddynext_hashtag_follows',
				'group_label' => __( 'BuddyNext Followed Hashtags', 'buddynext' ),
				'item_id'     => 'buddynext-hashtag-follows-' . $user_id,
				'data'        => $data,
			),
		);
	}

	/**
	 * Export the user's per-type notification preferences.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_notification_prefs( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, on_site, email_freq FROM {$p}bn_notification_prefs WHERE user_id = %d ORDER BY type",
				$user_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'name'  => (string) $row['type'],
				'value' => sprintf(
					/* translators: 1: on-site flag, 2: email frequency. */
					__( 'on-site: %1$s, email: %2$s', 'buddynext' ),
					$row['on_site'] ? __( 'yes', 'buddynext' ) : __( 'no', 'buddynext' ),
					(string) $row['email_freq']
				),
			);
		}

		return array(
			array(
				'group_id'    => 'buddynext_notification_prefs',
				'group_label' => __( 'BuddyNext Notification Preferences', 'buddynext' ),
				'item_id'     => 'buddynext-notification-prefs-' . $user_id,
				'data'        => $data,
			),
		);
	}

	/**
	 * Export the notifications addressed to the user (bounded summary).
	 *
	 * Only notifications the user received are exported as personal data; the
	 * outgoing (sender_id) rows are someone else's bell items and are excluded
	 * from the export to avoid leaking third-party recipients.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_notifications( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, object_type, object_id, is_read, created_at FROM {$p}bn_notifications WHERE recipient_id = %d ORDER BY created_at DESC LIMIT %d",
				$user_id,
				500
			),
			ARRAY_A
		);

		$items = array();
		foreach ( $rows as $i => $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_notifications',
				'group_label' => __( 'BuddyNext Notifications', 'buddynext' ),
				'item_id'     => 'buddynext-notification-' . $user_id . '-' . $i,
				'data'        => array(
					array(
						'name'  => __( 'Type', 'buddynext' ),
						'value' => (string) $row['type'],
					),
					array(
						'name'  => __( 'Object', 'buddynext' ),
						'value' => trim( (string) $row['object_type'] . ' ' . (string) $row['object_id'] ),
					),
					array(
						'name'  => __( 'Read', 'buddynext' ),
						'value' => $row['is_read'] ? __( 'yes', 'buddynext' ) : __( 'no', 'buddynext' ),
					),
					array(
						'name'  => __( 'Received', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export one page of the user's authored posts.
	 *
	 * @param int $user_id User id.
	 * @param int $page    1-based page number.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_posts( int $user_id, int $page ): array {
		global $wpdb;
		$p      = $wpdb->prefix;
		$offset = ( max( 1, $page ) - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, content, link_url, privacy, status, created_at FROM {$p}bn_posts WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_posts',
				'group_label' => __( 'BuddyNext Posts', 'buddynext' ),
				'item_id'     => 'buddynext-post-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Type', 'buddynext' ),
						'value' => (string) $row['type'],
					),
					array(
						'name'  => __( 'Content', 'buddynext' ),
						'value' => (string) $row['content'],
					),
					array(
						'name'  => __( 'Link', 'buddynext' ),
						'value' => (string) $row['link_url'],
					),
					array(
						'name'  => __( 'Privacy', 'buddynext' ),
						'value' => (string) $row['privacy'],
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Posted', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export one page of the user's authored comments.
	 *
	 * @param int $user_id User id.
	 * @param int $page    1-based page number.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_comments( int $user_id, int $page ): array {
		global $wpdb;
		$p      = $wpdb->prefix;
		$offset = ( max( 1, $page ) - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, object_type, object_id, content, is_deleted, created_at FROM {$p}bn_comments WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_comments',
				'group_label' => __( 'BuddyNext Comments', 'buddynext' ),
				'item_id'     => 'buddynext-comment-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'On', 'buddynext' ),
						'value' => trim( (string) $row['object_type'] . ' ' . (string) $row['object_id'] ),
					),
					array(
						'name'  => __( 'Comment', 'buddynext' ),
						'value' => (string) $row['content'],
					),
					array(
						'name'  => __( 'Deleted', 'buddynext' ),
						'value' => $row['is_deleted'] ? __( 'yes', 'buddynext' ) : __( 'no', 'buddynext' ),
					),
					array(
						'name'  => __( 'Posted', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Eraser
	 * ──────────────────────────────────────────────────────────────────── */

	/**
	 * Erase a user's BuddyNext data, paginated by the core contract.
	 *
	 * Page 1 removes all the bounded, per-user sets (meta, profile values,
	 * relational graph, memberships, notifications) and erases the first page
	 * of posts/comments. Subsequent pages continue erasing posts/comments until
	 * none remain, so a prolific member's content is removed across requests.
	 *
	 * @param string $email_address Email of the user being erased.
	 * @param int    $page          1-based page number.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$page = max( 1, (int) $page );
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;
		$removed = false;

		if ( 1 === $page ) {
			$removed = $this->erase_relational( $user_id );
		}

		// Posts + comments are the only unbounded sets — erase a page at a time.
		$post_removed    = $this->erase_posts_page( $user_id );
		$comment_removed = $this->erase_comments_page( $user_id );
		$removed         = $removed || $post_removed || $comment_removed;

		$done = ! $this->has_posts( $user_id ) && ! $this->has_erasable_comments( $user_id );

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}

	/**
	 * Erase the bounded per-user data (meta, relations, memberships).
	 *
	 * Hard-deletes in both directions for relational tables, mirroring
	 * SocialGraph\UserCleanupListener, and corrects affected space member
	 * counts so directory totals stay honest.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether anything was removed.
	 */
	private function erase_relational( int $user_id ): bool {
		global $wpdb;
		$p       = $wpdb->prefix;
		$removed = false;

		// Decrement member counts for spaces the user actively belonged to.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_space_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT space_id FROM {$p}bn_space_members WHERE user_id = %d AND status = 'active'",
				$user_id
			)
		);
		foreach ( $active_space_ids as $space_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}bn_spaces SET member_count = GREATEST(1, member_count) - 1 WHERE id = %d",
					(int) $space_id
				)
			);
			wp_cache_delete( 'space_' . (int) $space_id, 'bn_spaces' );
		}

		// Capture the posts this user reacted to BEFORE the reactions are deleted
		// below, so their denormalised reaction_count can be reconciled after
		// (deleting the rows alone would leave the counters drifted high).
		$reaction_post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT object_id FROM {$p}bn_reactions WHERE user_id = %d AND object_type = 'post'",
				$user_id
			)
		);

		$queries = array(
			$wpdb->prepare( "DELETE FROM {$p}bn_follows WHERE follower_id = %d OR following_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_connections WHERE requester_id = %d OR recipient_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_blocks WHERE blocker_id = %d OR blocked_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_space_members WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_hashtag_follows WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_notification_prefs WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_notifications WHERE recipient_id = %d OR sender_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_profile_values WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_bookmarks WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_reactions WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_poll_votes WHERE user_id = %d", $user_id ),
		);
		foreach ( $queries as $sql ) {
			if ( $wpdb->query( $sql ) > 0 ) {
				$removed = true;
			}
		}

		// Delete every bn_* user meta row.
		$deleted_meta = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( 'bn_' ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $deleted_meta > 0 ) {
			$removed = true;
		}

		// Reconcile reaction_count on the posts the user had reacted to.
		if ( ! empty( $reaction_post_ids ) ) {
			buddynext_service( 'post_service' )->recount_counters( array_map( 'intval', $reaction_post_ids ) );
		}

		clean_user_cache( $user_id );

		return $removed;
	}

	/**
	 * Hard-delete one page of the user's posts, cascading child rows.
	 *
	 * Mirrors Feed\PostService::delete() — poll votes/options, reactions,
	 * comments, shares, and bookmarks tied to the post are removed first.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether any post was removed.
	 */
	private function erase_posts_page( int $user_id ): bool {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$p}bn_posts WHERE user_id = %d ORDER BY id ASC LIMIT %d",
				$user_id,
				self::PER_PAGE
			)
		);

		if ( empty( $post_ids ) ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return false;
		}

		$ids_in = implode( ',', array_map( 'intval', $post_ids ) );

		$wpdb->query( "DELETE FROM {$p}bn_poll_votes WHERE post_id IN ({$ids_in})" );
		$wpdb->query( "DELETE FROM {$p}bn_poll_options WHERE post_id IN ({$ids_in})" );
		$wpdb->query( "DELETE FROM {$p}bn_reactions WHERE object_type = 'post' AND object_id IN ({$ids_in})" );
		$wpdb->query( "DELETE FROM {$p}bn_comments WHERE object_type = 'post' AND object_id IN ({$ids_in})" );
		$wpdb->query( "DELETE FROM {$p}bn_shares WHERE post_id IN ({$ids_in})" );
		$wpdb->query( "DELETE FROM {$p}bn_bookmarks WHERE post_id IN ({$ids_in})" );
		$wpdb->query( "DELETE FROM {$p}bn_posts WHERE id IN ({$ids_in})" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $post_ids as $post_id ) {
			wp_cache_delete( 'post_' . (int) $post_id, 'bn_posts' );
		}

		return true;
	}

	/**
	 * Soft-delete one page of the user's comments (anonymise, keep threads).
	 *
	 * Mirrors Comments\CommentService::delete(): set is_deleted=1 and blank the
	 * content so reply threads stay intact and the author is anonymised at
	 * render. Only not-yet-deleted comments are processed so the page contract
	 * eventually drains.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether any comment was anonymised.
	 */
	private function erase_comments_page( int $user_id ): bool {
		global $wpdb;
		$p = $wpdb->prefix;

		// Fetch id + parent object so we can both soft-delete and reconcile the
		// affected posts' comment_count below, without a second interpolated
		// query (the recount counts only is_deleted = 0 rows).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, object_type, object_id FROM {$p}bn_comments WHERE user_id = %d AND is_deleted = 0 ORDER BY id ASC LIMIT %d",
				$user_id,
				self::PER_PAGE
			)
		);

		if ( empty( $rows ) ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return false;
		}

		$comment_ids      = array();
		$comment_post_ids = array();
		foreach ( $rows as $row ) {
			$comment_ids[] = (int) $row->id;
			if ( 'post' === $row->object_type ) {
				$comment_post_ids[] = (int) $row->object_id;
			}
		}

		$ids_in = implode( ',', $comment_ids );
		$wpdb->query( "UPDATE {$p}bn_comments SET is_deleted = 1, content = '' WHERE id IN ({$ids_in})" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $comment_post_ids ) ) {
			buddynext_service( 'post_service' )->recount_counters( $comment_post_ids );
		}

		return true;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Counting / pagination helpers
	 * ──────────────────────────────────────────────────────────────────── */

	/**
	 * Count the user's authored posts.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	private function count_posts( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d", $user_id ) );
	}

	/**
	 * Count the user's authored comments.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	private function count_comments( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE user_id = %d", $user_id ) );
	}

	/**
	 * Whether the user still has any posts (erase-loop guard).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function has_posts( int $user_id ): bool {
		return $this->count_posts( $user_id ) > 0;
	}

	/**
	 * Whether the user has any comments at all (export-loop guard).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function has_comments( int $user_id ): bool {
		return $this->count_comments( $user_id ) > 0;
	}

	/**
	 * Whether the user still has any not-yet-anonymised comments (erase guard).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function has_erasable_comments( int $user_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE user_id = %d AND is_deleted = 0", $user_id ) ) > 0;
	}

	/**
	 * Whether the requested export page is the last page of posts.
	 *
	 * @param int $user_id User id.
	 * @param int $page    1-based page number.
	 * @return bool
	 */
	private function is_posts_done( int $user_id, int $page ): bool {
		return $page >= $this->page_count( $this->count_posts( $user_id ) );
	}

	/**
	 * Number of pages a row count spans (minimum 1).
	 *
	 * @param int $total Row count.
	 * @return int
	 */
	private function page_count( int $total ): int {
		return (int) max( 1, (int) ceil( $total / self::PER_PAGE ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Misc helpers
	 * ──────────────────────────────────────────────────────────────────── */

	/**
	 * Human-readable label for a related member (display name + id fallback).
	 *
	 * @param int $user_id Related user id.
	 * @return string
	 */
	private function user_label( int $user_id ): string {
		$u = get_userdata( $user_id );
		if ( $u instanceof \WP_User ) {
			return $u->display_name . ' (#' . $user_id . ')';
		}

		/* translators: %d: user id of an account that no longer exists. */
		return sprintf( __( 'Deleted member (#%d)', 'buddynext' ), $user_id );
	}

	/**
	 * Whether a bn_* user-meta key holds volatile/internal state we skip on
	 * export (rate-limit counters, OAuth nonces, transient-style flags).
	 *
	 * These are not meaningful personal data in an export; they are still
	 * deleted by the eraser via the broad LIKE 'bn\_%' sweep.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private function is_skippable_meta_key( string $key ): bool {
		$prefixes = array(
			'bn_oauth_rl_',
			'bn_reg_rl_',
			'bn_social_state_',
			'bn_session_',
			'bn_presence_',
			'bn_2fa_secret',
			'bn_2fa_pending_secret',
		);

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
