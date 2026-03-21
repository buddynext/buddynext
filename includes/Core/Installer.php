<?php
/**
 * Database installer.
 *
 * Creates all bn_* tables on activation via dbDelta(). Safe to run on every
 * activation — dbDelta skips tables that already exist with the correct schema.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Handles database table creation and upgrades.
 */
class Installer {

	/**
	 * Run the installer.
	 *
	 * Called on register_activation_hook and on manual version upgrades.
	 */
	public static function run(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( static::schema( $wpdb->prefix, $charset ) as $sql ) {
			dbDelta( $sql );
		}

		// FULLTEXT cannot be created via dbDelta on temporary tables (test suite).
		// Add it separately and suppress errors so tests pass without FULLTEXT.
		$wpdb->suppress_errors( true );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}bn_search_index ADD FULLTEXT KEY ft_search (title, content)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->suppress_errors( false );

		update_option( 'buddynext_db_version', BUDDYNEXT_VERSION );
	}

	/**
	 * Return the full set of CREATE TABLE statements.
	 *
	 * Each statement is passed individually to dbDelta() so a failure in one
	 * table does not abort the rest.
	 *
	 * @param string $p  Table prefix (e.g. 'wp_').
	 * @param string $cs Charset collation string.
	 * @return string[]
	 */
	private static function schema( string $p, string $cs ): array {
		return array(

			// ── Social Graph ───────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_follows (
				follower_id  BIGINT(20) UNSIGNED NOT NULL,
				following_id BIGINT(20) UNSIGNED NOT NULL,
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (follower_id, following_id),
				KEY          following (following_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_connections (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				requester_id BIGINT(20) UNSIGNED NOT NULL,
				recipient_id BIGINT(20) UNSIGNED NOT NULL,
				status       ENUM('pending','accepted','declined','withdrawn') NOT NULL DEFAULT 'pending',
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY   pair (requester_id, recipient_id),
				KEY          recipient_status (recipient_id, status),
				KEY          requester_status (requester_id, status)
			) {$cs};",

			"CREATE TABLE {$p}bn_blocks (
				blocker_id BIGINT(20) UNSIGNED NOT NULL,
				blocked_id BIGINT(20) UNSIGNED NOT NULL,
				type       ENUM('block','mute') NOT NULL DEFAULT 'block',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (blocker_id, blocked_id),
				KEY         blocked (blocked_id)
			) {$cs};",

			// ── Activity Feed ──────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_posts (
				id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id             BIGINT(20) UNSIGNED NOT NULL,
				space_id            BIGINT(20) UNSIGNED DEFAULT NULL,
				type                VARCHAR(32) NOT NULL DEFAULT 'text',
				content             LONGTEXT DEFAULT NULL,
				media_ids           JSON DEFAULT NULL,
				link_url            VARCHAR(2083) DEFAULT NULL,
				link_meta           JSON DEFAULT NULL,
				privacy             ENUM('public','followers','connections','space_members','private') NOT NULL DEFAULT 'public',
				reaction_count      INT UNSIGNED NOT NULL DEFAULT 0,
				comment_count       INT UNSIGNED NOT NULL DEFAULT 0,
				share_count         INT UNSIGNED NOT NULL DEFAULT 0,
				is_pinned           TINYINT(1) NOT NULL DEFAULT 0,
				is_announcement     TINYINT(1) NOT NULL DEFAULT 0,
				site_pin_expires_at DATETIME DEFAULT NULL,
				edited_at           DATETIME DEFAULT NULL,
				scheduled_at        DATETIME DEFAULT NULL,
				created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY         (id),
				KEY                 user_feed (user_id, created_at),
				KEY                 space_feed (space_id, created_at),
				KEY                 explore (privacy, created_at),
				KEY                 scheduled (scheduled_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_bookmarks (
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				post_id    BIGINT(20) UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (user_id, post_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_shares (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				post_id    BIGINT(20) UNSIGNED NOT NULL,
				content    TEXT DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         user_shares (user_id),
				KEY         post_shares (post_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_poll_options (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id       BIGINT(20) UNSIGNED NOT NULL,
				option_text   VARCHAR(500) NOT NULL,
				display_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
				vote_count    INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY   (id),
				KEY           post_options (post_id, display_order)
			) {$cs};",

			"CREATE TABLE {$p}bn_poll_votes (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id    BIGINT(20) UNSIGNED NOT NULL,
				option_id  BIGINT(20) UNSIGNED NOT NULL,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				voted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  one_vote_per_user (post_id, user_id),
				KEY         option_votes (option_id)
			) {$cs};",

			// ── Spaces ─────────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_spaces (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name         VARCHAR(255) NOT NULL,
				slug         VARCHAR(200) NOT NULL,
				description  TEXT DEFAULT NULL,
				category_id  BIGINT(20) UNSIGNED DEFAULT NULL,
				parent_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				type         ENUM('open','private','secret') NOT NULL DEFAULT 'open',
				owner_id     BIGINT(20) UNSIGNED NOT NULL,
				member_count INT UNSIGNED NOT NULL DEFAULT 0,
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY   slug (slug),
				KEY          owner (owner_id),
				KEY          category (category_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_space_members (
				space_id          BIGINT(20) UNSIGNED NOT NULL,
				user_id           BIGINT(20) UNSIGNED NOT NULL,
				role              ENUM('owner','moderator','member') NOT NULL DEFAULT 'member',
				status            ENUM('active','pending','invited','banned') NOT NULL DEFAULT 'active',
				notification_pref ENUM('all','mentions','none') NOT NULL DEFAULT 'all',
				joined_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY       (space_id, user_id),
				KEY               user_role (user_id, role),
				KEY               user_status (user_id, status)
			) {$cs};",

			"CREATE TABLE {$p}bn_space_categories (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name        VARCHAR(100) NOT NULL,
				slug        VARCHAR(100) NOT NULL,
				description TEXT DEFAULT NULL,
				sort_order  INT NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY  slug (slug)
			) {$cs};",

			// ── Notifications + Email ──────────────────────────────────────────

			"CREATE TABLE {$p}bn_notifications (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				recipient_id BIGINT(20) UNSIGNED NOT NULL,
				sender_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				type         VARCHAR(64) NOT NULL,
				object_type  VARCHAR(32) DEFAULT NULL,
				object_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				group_key    VARCHAR(128) DEFAULT NULL,
				group_count  INT UNSIGNED NOT NULL DEFAULT 1,
				data         JSON DEFAULT NULL,
				is_read      TINYINT(1) NOT NULL DEFAULT 0,
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY          bell (recipient_id, is_read, created_at),
				KEY          recipient_group (recipient_id, group_key)
			) {$cs};",

			"CREATE TABLE {$p}bn_notification_prefs (
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				type       VARCHAR(64) NOT NULL,
				on_site    TINYINT(1) NOT NULL DEFAULT 1,
				email_freq ENUM('immediate','daily','weekly','off') NOT NULL DEFAULT 'immediate',
				PRIMARY KEY (user_id, type)
			) {$cs};",

			"CREATE TABLE {$p}bn_email_templates (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				type       VARCHAR(64) NOT NULL,
				subject    VARCHAR(255) NOT NULL,
				preheader  VARCHAR(255) DEFAULT NULL,
				body       LONGTEXT NOT NULL,
				is_active  TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  type (type)
			) {$cs};",

			"CREATE TABLE {$p}bn_email_log (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				type        VARCHAR(64) NOT NULL,
				digest_date DATE DEFAULT NULL,
				sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         user_type (user_id, type, digest_date)
			) {$cs};",

			"CREATE TABLE {$p}bn_verify_tokens (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				token      VARCHAR(64) NOT NULL,
				type       VARCHAR(32) NOT NULL DEFAULT 'email_verify',
				expires_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  token (token),
				KEY         user_type (user_id, type)
			) {$cs};",

			// ── Reactions + Comments ───────────────────────────────────────────

			"CREATE TABLE {$p}bn_reactions (
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				emoji       VARCHAR(32) NOT NULL DEFAULT 'like',
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				UNIQUE KEY  one_per_user (user_id, object_type, object_id),
				KEY         object_reactions (object_type, object_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_comments (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				parent_id   BIGINT(20) UNSIGNED DEFAULT NULL,
				content     TEXT NOT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         thread (object_type, object_id, parent_id, created_at),
				KEY         user (user_id)
			) {$cs};",

			// ── Hashtags ───────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_hashtags (
				id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name           VARCHAR(100) NOT NULL,
				slug           VARCHAR(100) NOT NULL,
				post_count     INT UNSIGNED NOT NULL DEFAULT 0,
				follower_count INT UNSIGNED NOT NULL DEFAULT 0,
				created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY    (id),
				UNIQUE KEY     slug (slug)
			) {$cs};",

			"CREATE TABLE {$p}bn_post_hashtags (
				post_id     BIGINT(20) UNSIGNED NOT NULL,
				object_type VARCHAR(32) NOT NULL DEFAULT 'post',
				hashtag_id  BIGINT(20) UNSIGNED NOT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (post_id, object_type, hashtag_id),
				KEY         hashtag_feed (hashtag_id, created_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_hashtag_follows (
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				hashtag_id BIGINT(20) UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (user_id, hashtag_id),
				KEY         hashtag (hashtag_id)
			) {$cs};",

			// ── Profiles ───────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_profile_fields (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				field_key   VARCHAR(100) NOT NULL,
				label       VARCHAR(255) NOT NULL,
				type        ENUM('text','textarea','url','date','select','checkbox','repeater') NOT NULL DEFAULT 'text',
				options     JSON DEFAULT NULL,
				is_required TINYINT(1) NOT NULL DEFAULT 0,
				visibility  ENUM('public','connections','private') NOT NULL DEFAULT 'public',
				group_name  VARCHAR(100) NOT NULL DEFAULT 'general',
				sort_order  INT NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY  field_key (field_key)
			) {$cs};",

			"CREATE TABLE {$p}bn_profile_values (
				id       BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id  BIGINT(20) UNSIGNED NOT NULL,
				field_id BIGINT(20) UNSIGNED NOT NULL,
				value    LONGTEXT DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY  user_field (user_id, field_id),
				KEY         field (field_id)
			) {$cs};",

			// ── Search Index ───────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_search_index (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				title       VARCHAR(500) NOT NULL DEFAULT '',
				content     LONGTEXT DEFAULT NULL,
				author_id   BIGINT(20) UNSIGNED DEFAULT NULL,
				visibility  ENUM('public','private') NOT NULL DEFAULT 'public',
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  object (object_type, object_id),
				KEY         visibility_type (visibility, object_type),
				KEY         author (author_id)
			) {$cs};",

			// ── Roles + Permissions ────────────────────────────────────────────

			"CREATE TABLE {$p}bn_user_abilities (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				ability    VARCHAR(128) NOT NULL,
				source     VARCHAR(64) DEFAULT NULL,
				expires_at DATETIME DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         user_ability (user_id, ability, expires_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_user_credits (
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				balance    INT UNSIGNED NOT NULL DEFAULT 0,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (user_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_webhook_log (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				source     VARCHAR(64) DEFAULT NULL,
				action     VARCHAR(64) NOT NULL,
				user_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				payload    JSON DEFAULT NULL,
				status     ENUM('success','error') NOT NULL DEFAULT 'success',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         user_action (user_id, action),
				KEY         created (created_at)
			) {$cs};",

			// ── Direct Messaging ───────────────────────────────────────────────

			"CREATE TABLE {$p}bn_conversations (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				type             ENUM('direct','group') NOT NULL DEFAULT 'direct',
				title            VARCHAR(255) DEFAULT NULL,
				created_by       BIGINT(20) UNSIGNED NOT NULL,
				last_message_id  BIGINT(20) UNSIGNED DEFAULT NULL,
				last_activity_at DATETIME DEFAULT NULL,
				created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY      (id),
				KEY              created_by (created_by),
				KEY              last_activity (last_activity_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_conversation_participants (
				conversation_id BIGINT(20) UNSIGNED NOT NULL,
				user_id         BIGINT(20) UNSIGNED NOT NULL,
				role            ENUM('admin','member') NOT NULL DEFAULT 'member',
				last_read_at    DATETIME DEFAULT NULL,
				is_muted        TINYINT(1) NOT NULL DEFAULT 0,
				muted_until     DATETIME DEFAULT NULL,
				is_pinned       TINYINT(1) NOT NULL DEFAULT 0,
				is_archived     TINYINT(1) NOT NULL DEFAULT 0,
				status          ENUM('active','left','removed') NOT NULL DEFAULT 'active',
				joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY     (conversation_id, user_id),
				KEY             user_conversations (user_id, status, last_read_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_messages (
				id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT(20) UNSIGNED NOT NULL,
				sender_id       BIGINT(20) UNSIGNED NOT NULL,
				content         TEXT DEFAULT NULL,
				message_type    ENUM('text','media','system') NOT NULL DEFAULT 'text',
				attachment_id   BIGINT(20) UNSIGNED DEFAULT NULL,
				media_id        BIGINT(20) UNSIGNED DEFAULT NULL,
				parent_id       BIGINT(20) UNSIGNED DEFAULT NULL,
				metadata        JSON DEFAULT NULL,
				is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
				deleted_for_all TINYINT(1) NOT NULL DEFAULT 0,
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY     (id),
				KEY             conversation (conversation_id, created_at),
				KEY             sender (sender_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_message_reactions (
				message_id BIGINT(20) UNSIGNED NOT NULL,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				emoji      VARCHAR(32) NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (message_id, user_id)
			) {$cs};",

			// ── Activity Log ───────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_activity_log (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				action      VARCHAR(64) NOT NULL,
				object_type VARCHAR(32) DEFAULT NULL,
				object_id   BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         user_action (user_id, action, created_at)
			) {$cs};",

			// ── Moderation ─────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_reports (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				reporter_id BIGINT(20) UNSIGNED NOT NULL,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				reason      ENUM('spam','harassment','misinformation','inappropriate','fake','impersonation','other') NOT NULL DEFAULT 'other',
				notes       TEXT DEFAULT NULL,
				status      ENUM('pending','dismissed','escalated','resolved') NOT NULL DEFAULT 'pending',
				resolved_by BIGINT(20) UNSIGNED DEFAULT NULL,
				resolved_at DATETIME DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  one_per_reporter (reporter_id, object_type, object_id),
				KEY         object_status (object_type, object_id, status),
				KEY         status_date (status, created_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_mod_log (
				id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				actor_id       BIGINT(20) UNSIGNED NOT NULL,
				action         VARCHAR(64) NOT NULL,
				object_type    VARCHAR(32) DEFAULT NULL,
				object_id      BIGINT(20) UNSIGNED DEFAULT NULL,
				target_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
				note           TEXT DEFAULT NULL,
				created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY    (id),
				KEY            actor (actor_id),
				KEY            target_user (target_user_id),
				KEY            created (created_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_user_strikes (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				issued_by   BIGINT(20) UNSIGNED NOT NULL,
				reason      TEXT DEFAULT NULL,
				is_reversed TINYINT(1) NOT NULL DEFAULT 0,
				reversed_by BIGINT(20) UNSIGNED DEFAULT NULL,
				reversed_at DATETIME DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         user_active (user_id, is_reversed),
				KEY         issued_by (issued_by)
			) {$cs};",

			// ── Onboarding + Invites ───────────────────────────────────────────

			"CREATE TABLE {$p}bn_invites (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				email      VARCHAR(200) NOT NULL,
				first_name VARCHAR(100) DEFAULT NULL,
				token      VARCHAR(64) NOT NULL,
				status     ENUM('pending','registered','bounced') NOT NULL DEFAULT 'pending',
				expires_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  token (token),
				KEY         email (email),
				KEY         status_expires (status, expires_at)
			) {$cs};",

		);
	}
}
