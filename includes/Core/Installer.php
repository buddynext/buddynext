<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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
	 * Filename for the mu-plugin that provides front-end plugin isolation.
	 */
	private const MU_PLUGIN_SLUG = 'buddynext-isolation.php';

	/**
	 * Database schema revision. BUMP THIS whenever the schema changes (new table,
	 * new/changed column) so existing installs run the migration on the next
	 * admin load without needing a deactivate/reactivate. Tracked separately from
	 * the (release-locked) plugin version in the buddynext_schema_version option.
	 *
	 * 2 — added bn_invites.space_id (space-linked email invitations).
	 */
	private const SCHEMA_VERSION = 2;

	/**
	 * Run the schema migration when the stored revision is behind SCHEMA_VERSION.
	 *
	 * Hooked on admin_init so a plain plugin update (no reactivation) still picks
	 * up column/table changes. Cheap no-op once the versions match.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( 'buddynext_schema_version', 0 ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::run();
		update_option( 'buddynext_schema_version', self::SCHEMA_VERSION );
	}

	/**
	 * Run the installer.
	 *
	 * Called on register_activation_hook and on manual version upgrades.
	 */
	public static function run(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Suppress echo of DB errors during schema creation so that WP-CLI
		// and browser activation do not see unexpected HTML output from dbDelta.
		$wpdb->suppress_errors( true );
		foreach ( self::schema( $wpdb->prefix, $charset ) as $sql ) {
			dbDelta( $sql );
		}
		$wpdb->suppress_errors( false );

		// FULLTEXT cannot be created via dbDelta on temporary tables (test suite).
		// Add it separately and suppress errors so tests pass without FULLTEXT.
		$wpdb->suppress_errors( true );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}bn_search_index ADD FULLTEXT KEY ft_search (title, content)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->suppress_errors( false );

		self::maybe_alter_tables( $wpdb->prefix );
		self::seed_email_templates( $wpdb->prefix );
		self::migrate_email_action_links( $wpdb->prefix );
		self::seed_default_profile_groups_and_fields( $wpdb->prefix );

		update_option( 'buddynext_db_version', BUDDYNEXT_VERSION );
		update_option( 'buddynext_schema_version', self::SCHEMA_VERSION );

		self::create_hub_pages();
		self::install_mu_plugin();

		\BuddyNext\Search\SearchService::schedule_reindex_all();
	}

	/**
	 * Insert default email templates using INSERT IGNORE so existing
	 * customised templates are never overwritten on upgrade.
	 *
	 * @param string $p Table prefix.
	 */
	private static function seed_email_templates( string $p ): void {
		global $wpdb;

		$templates = array(
			array(
				'type'         => 'email_verify',
				'subject'      => 'Verify your email address — {{site_name}}',
				'preview_text' => 'Click the link to confirm your address',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Please verify your email address by clicking the link below:</p><p><a href="{{verify_url}}">Verify my email</a></p><p>This link expires in 24 hours.</p>',
			),
			array(
				'type'         => 'welcome',
				'subject'      => 'Welcome to {{site_name}}!',
				'preview_text' => 'Your community account is ready',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Welcome to {{site_name}}! Your account is all set — <a href="{{site_url}}">start exploring</a>.</p>',
			),
			array(
				'type'         => 'bn.new_follower',
				'subject'      => 'Someone followed you on {{site_name}}',
				'preview_text' => 'You have a new follower',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You have a new follower on {{site_name}}. <a href="{{action_url}}">See who it is.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.connection_requested',
				'subject'      => 'New connection request on {{site_name}}',
				'preview_text' => 'Someone wants to connect with you',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You have a new connection request on {{site_name}}. <a href="{{action_url}}">View the request.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.connection_accepted',
				'subject'      => 'Connection accepted on {{site_name}}',
				'preview_text' => 'You are now connected',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your connection request was accepted on {{site_name}}. <a href="{{action_url}}">View your connections.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.mention',
				'subject'      => 'You were mentioned on {{site_name}}',
				'preview_text' => 'Someone mentioned you in a post',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You were mentioned in a post on {{site_name}}. <a href="{{action_url}}">See the post.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.post_reacted',
				'subject'      => 'Someone reacted to your post on {{site_name}}',
				'preview_text' => 'New reaction on your post',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your post received a reaction on {{site_name}}. <a href="{{action_url}}">See the reactions.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.post_commented',
				'subject'      => 'New comment on your post — {{site_name}}',
				'preview_text' => 'Someone commented on your post',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your post received a new comment on {{site_name}}. <a href="{{action_url}}">View the comment.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.post_shared',
				'subject'      => 'Your post was shared on {{site_name}}',
				'preview_text' => 'Someone shared your post',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your post was shared on {{site_name}}. <a href="{{action_url}}">View the share.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.space_invite',
				'subject'      => 'You have been invited to a space on {{site_name}}',
				'preview_text' => 'Join this community space',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You have been invited to join a space on {{site_name}}. <a href="{{action_url}}">View the invitation.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.space_join_requested',
				'subject'      => 'New join request for your space — {{site_name}}',
				'preview_text' => 'A member wants to join your space',
				'body_html'    => '<p>Hi {{user_name}},</p><p>A new member has requested to join your space on {{site_name}}. <a href="{{action_url}}">Review the request.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.space_request_approved',
				'subject'      => 'Your space join request was approved — {{site_name}}',
				'preview_text' => 'Welcome to the space',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your request to join a space on {{site_name}} has been approved. <a href="{{action_url}}">Visit the space.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.strike_issued',
				'subject'      => 'A moderation action has been taken on your account — {{site_name}}',
				'preview_text' => 'Important account notice',
				'body_html'    => '<p>Hi {{user_name}},</p><p>A moderation strike has been issued on your account at {{site_name}}. Please review the community guidelines to avoid further action.</p>',
			),
			array(
				'type'         => 'bn.badge_awarded',
				'subject'      => 'You earned a badge on {{site_name}}!',
				'preview_text' => 'Congratulations on your new badge',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Congratulations! You earned a new badge on {{site_name}}. <a href="{{action_url}}">View your profile.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.level_up',
				'subject'      => 'You levelled up on {{site_name}}!',
				'preview_text' => 'Your community level increased',
				'body_html'    => '<p>Hi {{user_name}},</p><p>You have reached a new level on {{site_name}}. <a href="{{action_url}}">See your new level.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.jetonomy_reply',
				'subject'      => 'New reply to your discussion — {{site_name}}',
				'preview_text' => 'Someone replied to your discussion',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your discussion received a new reply on {{site_name}}. <a href="{{action_url}}">View the reply.</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.strike_warning',
				'subject'      => 'Warning: your account has received multiple strikes — {{site_name}}',
				'preview_text' => 'You have received multiple moderation strikes',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your account on {{site_name}} has received multiple moderation strikes. Please review our community guidelines to avoid further action.</p><p>If you believe this is in error, you can contact our moderation team.</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.member_suspended',
				'subject'      => 'Your account has been suspended — {{site_name}}',
				'preview_text' => 'Your account has been suspended',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your account on {{site_name}} has been suspended. You will not be able to post or interact with the community during this period.</p><p>If you believe this was done in error, you may submit an appeal from your account page.</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.appeal_resolved',
				'subject'      => 'Your appeal has been reviewed — {{site_name}}',
				'preview_text' => 'Your moderation appeal has been resolved',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Your appeal on {{site_name}} has been reviewed and <strong>{{decision}}</strong>.</p><p>If you have questions about this decision, please contact our moderation team.</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
			),
			array(
				'type'         => 'bn.daily_digest',
				'subject'      => 'Your daily digest from {{site_name}}',
				'preview_text' => 'Here\'s what happened on {{site_name}} today',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Here\'s a summary of your notifications from today on <a href="{{action_url}}">{{site_name}}</a>:</p>{{notification_list}}<p><a href="{{unsubscribe_url}}">Unsubscribe from digest emails</a></p>',
			),
			array(
				'type'         => 'bn.weekly_digest',
				'subject'      => 'Your weekly digest from {{site_name}}',
				'preview_text' => 'Here\'s your weekly round-up from {{site_name}}',
				'body_html'    => '<p>Hi {{user_name}},</p><p>Here\'s a summary of your notifications from this week on <a href="{{action_url}}">{{site_name}}</a>:</p>{{notification_list}}<p><a href="{{unsubscribe_url}}">Unsubscribe from digest emails</a></p>',
			),
			array(
				'type'         => 'bn.bulk_invite',
				'subject'      => 'You\'ve been invited to join {{site_name}}',
				'preview_text' => 'Accept your invitation and create your account',
				'body_html'    => '<p>Hi {{first_name}},</p><p>You\'ve been invited to join <strong>{{site_name}}</strong>!</p><p><a href="{{invite_url}}">Accept invitation &rarr;</a></p><p>This invitation expires in 7 days.</p>',
			),
			array(
				'type'         => 'bn.onboarding_nudge',
				'subject'      => 'Finish setting up your {{site_name}} profile',
				'preview_text' => 'Complete your onboarding to get the most out of the community',
				'body_html'    => '<p>Hi {{recipient_name}},</p><p>You\'re almost there! Complete your profile setup on <strong>{{site_name}}</strong> to connect with the community.</p><p><a href="{{onboarding_url}}">Complete your profile &rarr;</a></p>',
			),
		);

		// Table name is a hardcoded constant — safe to interpolate. Values use prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $templates as $tpl ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$p}bn_email_templates` (type, subject, preview_text, body_html)
					 VALUES (%s, %s, %s, %s)",
					$tpl['type'],
					$tpl['subject'],
					$tpl['preview_text'],
					$tpl['body_html']
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Point existing notification email CTA links at the deep-link token.
	 *
	 * Older installs seeded every notification template's call-to-action as
	 * <a href="{{site_url}}"> — which always landed on the home page instead of
	 * the relevant profile / request / post. EmailSender now resolves
	 * {{action_url}} per type, so rewrite the link href in place. Only the href
	 * token is touched (admin copy edits are preserved) and the 'welcome'
	 * template is left alone (its {{site_url}} link is intentional). Idempotent:
	 * once rewritten there is nothing left to match.
	 *
	 * @param string $p Table prefix.
	 * @return void
	 */
	private static function migrate_email_action_links( string $p ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$p}bn_email_templates`
				 SET body_html = REPLACE( body_html, %s, %s )
				 WHERE type <> %s
				   AND body_html LIKE %s",
				'href="{{site_url}}"',
				'href="{{action_url}}"',
				'welcome',
				'%href="{{site_url}}"%'
			)
		);
	}

	/**
	 * Seed the five built-in profile groups and their fields.
	 *
	 * Uses INSERT IGNORE throughout so re-running the installer never destroys
	 * customised data. Fields are inserted with a subquery that resolves the
	 * group_id by group_key so the seed is order-independent.
	 *
	 * @param string $p Table prefix.
	 */
	private static function seed_default_profile_groups_and_fields( string $p ): void {
		global $wpdb;

		// ── 1. Groups ──────────────────────────────────────────────────────────

		// All five built-in groups are seeded on install (INSERT IGNORE is safe
		// on re-runs). The spec defines these as the canonical group set:
		// Basic Info (flat), Social Links (flat), Work Experience (repeater),
		// Education (repeater), Skills (flat).
		// Format: group_key, label, type, visibility, is_system, sort_order.
		$groups = array(
			array( 'basic_info', 'Basic Info', 'flat', 'public', 1, 1 ),
			array( 'social_links', 'Social Links', 'flat', 'public', 1, 2 ),
			array( 'work_experience', 'Work Experience', 'repeater', 'public', 1, 3 ),
			array( 'education', 'Education', 'repeater', 'public', 1, 4 ),
			array( 'skills', 'Skills', 'flat', 'public', 1, 5 ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $groups as $g ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$p}bn_profile_groups`
					    (group_key, label, type, visibility, is_system, sort_order)
					 VALUES (%s, %s, %s, %s, %d, %d)",
					$g[0],
					$g[1],
					$g[2],
					$g[3],
					$g[4],
					$g[5]
				)
			);
		}

		// ── 2. Fields ─────────────────────────────────────────────────────────
		// Each INSERT uses a subquery to resolve group_id by group_key.
		// Format: group_key, field_key, label, type, is_required, is_searchable, sort_order

		$fields = array(
			// basic_info.
			array( 'basic_info', 'headline', 'Headline', 'text', 0, 0, 1 ),
			array( 'basic_info', 'bio', 'Bio', 'textarea', 0, 0, 2 ),
			array( 'basic_info', 'location', 'Location', 'text', 0, 1, 3 ),
			array( 'basic_info', 'website', 'Website', 'url', 0, 0, 4 ),
			array( 'basic_info', 'pronouns', 'Pronouns', 'text', 0, 0, 5 ),
			array( 'basic_info', 'birth_date', 'Birth Date', 'date', 0, 0, 6 ),

			// social_links.
			array( 'social_links', 'social_twitter', 'Twitter / X', 'url', 0, 0, 1 ),
			array( 'social_links', 'social_linkedin', 'LinkedIn', 'url', 0, 0, 2 ),
			array( 'social_links', 'social_github', 'GitHub', 'url', 0, 0, 3 ),
			array( 'social_links', 'social_instagram', 'Instagram', 'url', 0, 0, 4 ),
			array( 'social_links', 'social_youtube', 'YouTube', 'url', 0, 0, 5 ),

			// work_experience (repeater).
			array( 'work_experience', 'work_company', 'Company', 'text', 0, 0, 1 ),
			array( 'work_experience', 'work_title', 'Job Title', 'text', 0, 0, 2 ),
			array( 'work_experience', 'work_location', 'Location', 'text', 0, 0, 3 ),
			array( 'work_experience', 'work_start_date', 'Start Date', 'date', 0, 0, 4 ),
			array( 'work_experience', 'work_end_date', 'End Date', 'date', 0, 0, 5 ),
			array( 'work_experience', 'work_current', 'Currently Working', 'checkbox', 0, 0, 6 ),
			array( 'work_experience', 'work_description', 'Description', 'textarea', 0, 0, 7 ),

			// education (repeater).
			array( 'education', 'edu_institution', 'Institution', 'text', 0, 0, 1 ),
			array( 'education', 'edu_degree', 'Degree', 'text', 0, 0, 2 ),
			array( 'education', 'edu_field', 'Field of Study', 'text', 0, 0, 3 ),
			array( 'education', 'edu_start_year', 'Start Year', 'number', 0, 0, 4 ),
			array( 'education', 'edu_end_year', 'End Year', 'number', 0, 0, 5 ),
			array( 'education', 'edu_current', 'Currently Attending', 'checkbox', 0, 0, 6 ),

			// skills (flat).
			array( 'skills', 'interests', 'Skills / Interests', 'text', 0, 1, 1 ),
		);

		foreach ( $fields as $f ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$p}bn_profile_fields`
					    (group_id, field_key, label, type, is_required, is_searchable, sort_order)
					 SELECT id, %s, %s, %s, %d, %d, %d
					   FROM `{$p}bn_profile_groups`
					  WHERE group_key = %s",
					$f[1],
					$f[2],
					$f[3],
					$f[4],
					$f[5],
					$f[6],
					$f[0]
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
				status       ENUM('approved','pending') NOT NULL DEFAULT 'approved',
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (follower_id, following_id),
				KEY          following (following_id, status),
				KEY          pending_inbox (following_id, status, created_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_connections (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				requester_id BIGINT(20) UNSIGNED NOT NULL,
				recipient_id BIGINT(20) UNSIGNED NOT NULL,
				status       ENUM('pending','accepted','declined','withdrawn') NOT NULL DEFAULT 'pending',
				note         VARCHAR(280) NOT NULL DEFAULT '',
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY   pair (requester_id, recipient_id),
				KEY          recipient_lookup (recipient_id),
				KEY          recipient_status (recipient_id, status),
				KEY          requester_status (requester_id, status)
			) {$cs};",

			"CREATE TABLE {$p}bn_blocks (
				blocker_id BIGINT(20) UNSIGNED NOT NULL,
				blocked_id BIGINT(20) UNSIGNED NOT NULL,
				type       ENUM('block','mute','restrict') NOT NULL DEFAULT 'block',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (blocker_id, blocked_id),
				KEY         blocked (blocked_id),
				KEY         blocker_type (blocker_id, type)
			) {$cs};",

			// ── Activity Feed ──────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_posts (
				id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id             BIGINT(20) UNSIGNED NOT NULL,
				space_id            BIGINT(20) UNSIGNED DEFAULT NULL,
				shared_post_id      BIGINT(20) UNSIGNED DEFAULT NULL,
				type                VARCHAR(32) NOT NULL DEFAULT 'text',
				content             LONGTEXT DEFAULT NULL,
				media_ids           JSON DEFAULT NULL,
				link_url            VARCHAR(2083) DEFAULT NULL,
				link_meta           JSON DEFAULT NULL,
				privacy             ENUM('public','followers','connections','space_members','private') NOT NULL DEFAULT 'public',
				status              ENUM('published','draft','pending','scheduled','deleted') NOT NULL DEFAULT 'published',
				reaction_count      INT UNSIGNED NOT NULL DEFAULT 0,
				comment_count       INT UNSIGNED NOT NULL DEFAULT 0,
				share_count         INT UNSIGNED NOT NULL DEFAULT 0,
				is_pinned            TINYINT(1) NOT NULL DEFAULT 0,
				is_announcement      TINYINT(1) NOT NULL DEFAULT 0,
				content_warning      TINYINT(1) NOT NULL DEFAULT 0,
				content_warning_type VARCHAR(32) DEFAULT NULL,
				site_pin_expires_at  DATETIME DEFAULT NULL,
				edited_at           DATETIME DEFAULT NULL,
				scheduled_at        DATETIME DEFAULT NULL,
				created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY         (id),
				KEY                 user_feed (user_id, created_at),
				KEY                 space_feed (space_id, status, created_at),
				KEY                 announcement_feed (is_announcement, status, created_at),
				KEY                 explore (privacy, created_at),
				KEY                 scheduled (scheduled_at),
				KEY                 shared_post (shared_post_id)
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

			"CREATE TABLE {$p}bn_feed_items (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				recipient_id BIGINT(20) UNSIGNED NOT NULL,
				post_id      BIGINT(20) UNSIGNED NOT NULL,
				score        FLOAT NOT NULL DEFAULT 0,
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY   recipient_post (recipient_id, post_id),
				KEY          recipient_score (recipient_id, score, created_at)
			) {$cs};",

			"CREATE TABLE {$p}bn_poll_options (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id       BIGINT(20) UNSIGNED NOT NULL,
				option_text   VARCHAR(500) NOT NULL,
				display_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
				vote_count    INT UNSIGNED NOT NULL DEFAULT 0,
				end_date      DATETIME DEFAULT NULL,
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
				id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name               VARCHAR(255) NOT NULL,
				slug               VARCHAR(200) NOT NULL,
				description        TEXT DEFAULT NULL,
				category_id        BIGINT(20) UNSIGNED DEFAULT NULL,
				parent_id          BIGINT(20) UNSIGNED DEFAULT NULL,
				type               ENUM('open','private','secret') NOT NULL DEFAULT 'open',
				owner_id           BIGINT(20) UNSIGNED NOT NULL,
				member_count       INT UNSIGNED NOT NULL DEFAULT 0,
				cover_image_url    VARCHAR(500) DEFAULT NULL,
				avatar_url         VARCHAR(500) DEFAULT NULL,
				rules              TEXT NULL DEFAULT NULL,
				required_ability   VARCHAR(64) NULL DEFAULT NULL,
				accent_color       VARCHAR(16) NULL DEFAULT NULL,
				description_layout VARCHAR(32) NULL DEFAULT 'standard',
				created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY        (id),
				UNIQUE KEY         slug (slug),
				KEY                owner (owner_id),
				KEY                category (category_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_space_members (
				space_id          BIGINT(20) UNSIGNED NOT NULL,
				user_id           BIGINT(20) UNSIGNED NOT NULL,
				role              ENUM('owner','moderator','member') NOT NULL DEFAULT 'member',
				status            ENUM('active','pending','invited','banned') NOT NULL DEFAULT 'active',
				notification_pref ENUM('all','mentions_only','none') NOT NULL DEFAULT 'all',
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
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				type         VARCHAR(64) NOT NULL,
				subject      VARCHAR(255) NOT NULL,
				preview_text VARCHAR(255) DEFAULT NULL,
				body_html    LONGTEXT NOT NULL,
				enabled      TINYINT(1) NOT NULL DEFAULT 1,
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
				PRIMARY KEY (user_id, object_type, object_id),
				KEY         object_reactions (object_type, object_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_comments (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				parent_id   BIGINT(20) UNSIGNED DEFAULT NULL,
				content     TEXT NOT NULL,
				is_edited   TINYINT(1) NOT NULL DEFAULT 0,
				is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY         thread (object_type, object_id, parent_id, created_at),
				KEY         user (user_id),
				KEY         deleted (is_deleted)
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

			"CREATE TABLE {$p}bn_profile_groups (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				group_key        VARCHAR(100) NOT NULL,
				label            VARCHAR(255) NOT NULL,
				type             ENUM('flat','repeater') NOT NULL DEFAULT 'flat',
				visibility       ENUM('public','followers','connections','private') NOT NULL DEFAULT 'public',
				is_system        TINYINT(1) NOT NULL DEFAULT 0,
				sort_order       INT NOT NULL DEFAULT 0,
				type_restriction VARCHAR(100) DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY  group_key (group_key),
				KEY         type_res (type_restriction)
			) {$cs};",

			"CREATE TABLE {$p}bn_profile_fields (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				group_id      BIGINT(20) UNSIGNED NOT NULL,
				field_key     VARCHAR(100) NOT NULL,
				label         VARCHAR(255) NOT NULL,
				type          VARCHAR(32) NOT NULL DEFAULT 'text',
				options       JSON DEFAULT NULL,
				is_required   TINYINT(1) NOT NULL DEFAULT 0,
				is_searchable TINYINT(1) NOT NULL DEFAULT 0,
				visibility    ENUM('public','followers','connections','private') NOT NULL DEFAULT 'public',
				sort_order    INT NOT NULL DEFAULT 0,
				PRIMARY KEY   (id),
				UNIQUE KEY    field_key (field_key),
				KEY           group_idx (group_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_profile_values (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id          BIGINT(20) UNSIGNED NOT NULL,
				field_id         BIGINT(20) UNSIGNED NOT NULL,
				entry_index      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				value            LONGTEXT DEFAULT NULL,
				entry_visibility ENUM('public','followers','connections','private') DEFAULT NULL,
				PRIMARY KEY      (id),
				UNIQUE KEY       user_field_entry (user_id, field_id, entry_index),
				KEY              field_idx (field_id),
				KEY              user_idx (user_id)
			) {$cs};",

			// ── Search Index ───────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_search_index (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				object_type VARCHAR(32) NOT NULL,
				object_id   BIGINT(20) UNSIGNED NOT NULL,
				title       VARCHAR(500) NOT NULL DEFAULT '',
				content     LONGTEXT DEFAULT NULL,
				author_id   BIGINT(20) UNSIGNED DEFAULT NULL,
				space_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				visibility  ENUM('public','private') NOT NULL DEFAULT 'public',
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  object (object_type, object_id),
				KEY         visibility_type (visibility, object_type),
				KEY         author (author_id),
				KEY         space (space_id),
				KEY         updated_order (updated_at)
			) {$cs};",

			// ── Webhooks ────────────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_webhook_log (
				id         BIGINT(20) NOT NULL AUTO_INCREMENT,
				source     VARCHAR(100) NOT NULL DEFAULT '',
				action     VARCHAR(100) NOT NULL DEFAULT '',
				user_id    BIGINT(20) NOT NULL DEFAULT 0,
				payload    LONGTEXT NOT NULL,
				status     VARCHAR(20) NOT NULL DEFAULT 'success',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY action (action),
				KEY user_id (user_id),
				KEY created_at (created_at)
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
				KEY         user_action (user_id, action, created_at),
				KEY         created_at (created_at)
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
				space_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY  one_per_reporter (reporter_id, object_type, object_id),
				KEY         object_status (object_type, object_id, status),
				KEY         status_date (status, created_at),
				KEY         space (space_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_mod_log (
				id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				actor_id       BIGINT(20) UNSIGNED NOT NULL,
				action         VARCHAR(64) NOT NULL,
				object_type    VARCHAR(32) DEFAULT NULL,
				object_id      BIGINT(20) UNSIGNED DEFAULT NULL,
				target_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
				note           TEXT DEFAULT NULL,
				space_id       BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY    (id),
				KEY            actor (actor_id),
				KEY            target_user (target_user_id),
				KEY            created (created_at),
				KEY            space (space_id)
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
				space_id   BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				token      VARCHAR(64) NOT NULL,
				status     ENUM('pending','registered','bounced') NOT NULL DEFAULT 'pending',
				expires_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  token (token),
				KEY         email (email),
				KEY         status_expires (status, expires_at)
			) {$cs};",

			// ── Moderation — Suspensions + Appeals + Space Bans ──────────────────

			"CREATE TABLE {$p}bn_user_suspensions (
				id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id      BIGINT(20) UNSIGNED NOT NULL,
				suspended_by BIGINT(20) UNSIGNED NOT NULL,
				reason       TEXT DEFAULT NULL,
				duration_days INT UNSIGNED DEFAULT NULL,
				hide_posts   TINYINT(1) NOT NULL DEFAULT 0,
				expires_at   DATETIME DEFAULT NULL,
				lifted_at    DATETIME DEFAULT NULL,
				lifted_by    BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY          user_active (user_id, expires_at),
				KEY          active_check (lifted_at, expires_at, user_id),
				KEY          suspended_by (suspended_by)
			) {$cs};",

			"CREATE TABLE {$p}bn_appeals (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				suspension_id BIGINT(20) UNSIGNED NOT NULL,
				strike_id     BIGINT(20) DEFAULT NULL,
				user_id       BIGINT(20) UNSIGNED NOT NULL,
				message       TEXT NOT NULL,
				status        ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
				reviewed_by   BIGINT(20) UNSIGNED DEFAULT NULL,
				reviewer_note TEXT DEFAULT NULL,
				reviewed_at   DATETIME DEFAULT NULL,
				admin_note    TEXT DEFAULT NULL,
				resolved_by   BIGINT(20) UNSIGNED DEFAULT NULL,
				resolved_at   DATETIME DEFAULT NULL,
				created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY   (id),
				KEY           user_status (user_id, status),
				KEY           suspension (suspension_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_space_bans (
				space_id   BIGINT(20) UNSIGNED NOT NULL,
				user_id    BIGINT(20) UNSIGNED NOT NULL,
				banned_by  BIGINT(20) UNSIGNED NOT NULL,
				reason     TEXT DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (space_id, user_id),
				KEY         user_bans (user_id)
			) {$cs};",

			// ── Outbound Webhooks ──────────────────────────────────────────────

			// ── Member Types ───────────────────────────────────────────────────

			"CREATE TABLE {$p}bn_member_types (
				id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
				slug        VARCHAR(100)    NOT NULL,
				name        VARCHAR(100)    NOT NULL,
				description TEXT            DEFAULT NULL,
				color       VARCHAR(7)      NOT NULL DEFAULT '#0073aa',
				text_color  VARCHAR(7)      NOT NULL DEFAULT '#ffffff',
				icon_svg    MEDIUMTEXT      DEFAULT NULL,
				sort_order  SMALLINT        NOT NULL DEFAULT 0,
				show_in_dir TINYINT(1)      NOT NULL DEFAULT 1,
				self_select TINYINT(1)      NOT NULL DEFAULT 0,
				created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  uq_slug (slug),
				KEY         idx_sort (sort_order)
			) {$cs};",

			"CREATE TABLE {$p}bn_member_type_assignments (
				id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				type_id     INT UNSIGNED     NOT NULL,
				assigned_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				assigned_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY  uq_user_type (user_id, type_id),
				KEY         idx_user_id (user_id),
				KEY         idx_type_id (type_id)
			) {$cs};",

			"CREATE TABLE {$p}bn_outbound_webhooks (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				label      VARCHAR(100) NOT NULL,
				url        VARCHAR(2083) NOT NULL,
				secret     VARCHAR(64) DEFAULT NULL,
				events     JSON DEFAULT NULL,
				is_active  TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id)
			) {$cs};",

			"CREATE TABLE {$p}bn_outbound_webhook_log (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				webhook_id    BIGINT(20) UNSIGNED NOT NULL,
				event         VARCHAR(64) NOT NULL,
				payload       JSON DEFAULT NULL,
				response_code SMALLINT UNSIGNED DEFAULT NULL,
				response_body TEXT DEFAULT NULL,
				status        ENUM('success','error') NOT NULL DEFAULT 'success',
				attempt       TINYINT UNSIGNED NOT NULL DEFAULT 1,
				created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY   (id),
				KEY           webhook_event (webhook_id, event, created_at),
				KEY           status_date (status, created_at)
			) {$cs};",

		);
	}

	/**
	 * Apply column-level ALTER TABLE migrations that dbDelta cannot handle.
	 *
	 * Checks column existence via INFORMATION_SCHEMA before running each ALTER
	 * so the method is safe to call on every activation without duplicate errors.
	 *
	 * @param string $p Table prefix.
	 */
	private static function maybe_alter_tables( string $p ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// ── bn_posts — add 'scheduled' to status ENUM if missing ──────────────

		$post_status_enum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_TYPE
				   FROM INFORMATION_SCHEMA.COLUMNS
				  WHERE TABLE_SCHEMA = DATABASE()
				    AND TABLE_NAME   = %s
				    AND COLUMN_NAME  = 'status'",
				"{$p}bn_posts"
			)
		);

		if ( is_string( $post_status_enum ) && false === strpos( $post_status_enum, "'scheduled'" ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_posts` MODIFY COLUMN `status` ENUM('published','draft','pending','scheduled','deleted') NOT NULL DEFAULT 'published'" );
		}

		// ── bn_space_members — canonicalise notification_pref to 'mentions_only' ─
		// 'mentions_only' is the single canonical value used by every surface —
		// the notifications REST API + JS store + prefs UI, AND (since the fix)
		// the space-settings path (SpaceMemberService). The column was originally
		// ENUM('all','mentions','none'); align it to ('all','mentions_only','none')
		// while preserving any existing 'mentions' rows as 'mentions_only'.
		$space_pref_enum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_TYPE
				   FROM INFORMATION_SCHEMA.COLUMNS
				  WHERE TABLE_SCHEMA = DATABASE()
				    AND TABLE_NAME   = %s
				    AND COLUMN_NAME  = 'notification_pref'",
				"{$p}bn_space_members"
			)
		);

		if ( is_string( $space_pref_enum ) && false === strpos( $space_pref_enum, "'mentions_only'" ) ) {
			// Widen to a superset first so the old 'mentions' value stays valid
			// long enough to be remapped (a direct narrow would coerce it to '').
			$wpdb->query( "ALTER TABLE `{$p}bn_space_members` MODIFY COLUMN `notification_pref` ENUM('all','mentions','mentions_only','none') NOT NULL DEFAULT 'all'" );
			$wpdb->query( "UPDATE `{$p}bn_space_members` SET `notification_pref` = 'mentions_only' WHERE `notification_pref` = 'mentions'" );
			// Narrow to the canonical set and repair any stray coerced rows.
			$wpdb->query( "ALTER TABLE `{$p}bn_space_members` MODIFY COLUMN `notification_pref` ENUM('all','mentions_only','none') NOT NULL DEFAULT 'all'" );
			$wpdb->query( "UPDATE `{$p}bn_space_members` SET `notification_pref` = 'all' WHERE `notification_pref` NOT IN ('all','mentions_only','none')" );

			// Per-space default-pref options use the same vocabulary.
			$wpdb->query( "UPDATE `{$wpdb->options}` SET option_value = 'mentions_only' WHERE option_name LIKE 'bn\\_space\\_%\\_default\\_notification\\_pref' AND option_value = 'mentions'" );
		}

		// ── bn_profile_fields — widen `type` from a restrictive ENUM to VARCHAR ──
		// The original ENUM only listed a subset of types, so any field of a
		// type outside it (most core types + every Pro/add-on type registered
		// via the FieldType engine) was silently coerced to '' by MySQL and
		// degraded to a plain text input everywhere. A VARCHAR lets the app
		// layer (FieldType::types()) own the allowed set.
		$field_type_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_TYPE
				   FROM INFORMATION_SCHEMA.COLUMNS
				  WHERE TABLE_SCHEMA = DATABASE()
				    AND TABLE_NAME   = %s
				    AND COLUMN_NAME  = 'type'",
				"{$p}bn_profile_fields"
			)
		);

		if ( is_string( $field_type_col ) && 0 === stripos( $field_type_col, 'enum' ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_profile_fields` MODIFY COLUMN `type` VARCHAR(32) NOT NULL DEFAULT 'text'" );
		}

		// ── bn_spaces — add Pro-extension columns if missing ─────────────────

		$spaces_cols = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME
				   FROM INFORMATION_SCHEMA.COLUMNS
				  WHERE TABLE_SCHEMA = DATABASE()
				    AND TABLE_NAME   = %s',
				"{$p}bn_spaces"
			)
		);

		// Suppress errors: test suite runs Installer::run() multiple times against
		// a persistent TEMPORARY table, causing "Duplicate column" notices on
		// second+ calls. These are harmless — the column already exists.
		$wpdb->suppress_errors( true );

		if ( is_array( $spaces_cols ) && ! in_array( 'required_ability', $spaces_cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_spaces` ADD COLUMN `required_ability` VARCHAR(64) NULL DEFAULT NULL" );
		}

		if ( is_array( $spaces_cols ) && ! in_array( 'accent_color', $spaces_cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_spaces` ADD COLUMN `accent_color` VARCHAR(16) NULL DEFAULT NULL" );
		}

		if ( is_array( $spaces_cols ) && ! in_array( 'description_layout', $spaces_cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_spaces` ADD COLUMN `description_layout` VARCHAR(32) NULL DEFAULT 'standard'" );
		}

		if ( is_array( $spaces_cols ) && ! in_array( 'rules', $spaces_cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_spaces` ADD COLUMN `rules` TEXT NULL DEFAULT NULL" );
		}

		// bn_connections — `note` column added for the connection-request
		// note feature (LinkedIn-style "I'd like to connect because…").
		// Existing installs upgraded without re-running CREATE TABLE need
		// the column manually appended.
		$connections_cols = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s',
				$p . 'bn_connections'
			)
		);
		if ( is_array( $connections_cols ) && ! in_array( 'note', $connections_cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_connections` ADD COLUMN `note` VARCHAR(280) NOT NULL DEFAULT '' AFTER `status`" );
		}

		// bn_appeals — `admin_note` / `resolved_by` columns added for the appeal
		// approval/denial flow (decide_appeal writes these). Existing installs
		// created before these columns existed need them appended manually.
		$appeals_cols = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s',
				$p . 'bn_appeals'
			)
		);
		if ( is_array( $appeals_cols ) && ! in_array( 'admin_note', $appeals_cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_appeals` ADD COLUMN `admin_note` TEXT NULL DEFAULT NULL AFTER `reviewed_at`" );
		}
		if ( is_array( $appeals_cols ) && ! in_array( 'resolved_by', $appeals_cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_appeals` ADD COLUMN `resolved_by` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `admin_note`" );
		}

		// bn_invites.space_id — links an email invite to a specific space so the
		// new account is dropped into that space after registration (null = a
		// plain site-onboarding invite).
		$invites_cols = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s',
				$p . 'bn_invites'
			)
		);
		if ( is_array( $invites_cols ) && ! in_array( 'space_id', $invites_cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$p}bn_invites` ADD COLUMN `space_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `first_name`" );
		}

		$wpdb->suppress_errors( false );

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Write the BuddyNext isolation mu-plugin to wp-content/mu-plugins/.
	 *
	 * Skips the write when the file already exists with identical content so
	 * that repeated activations (e.g. during automated upgrades) are cheap.
	 *
	 * @return void
	 */
	public static function install_mu_plugin(): void {
		$dest    = WP_CONTENT_DIR . '/mu-plugins/' . self::MU_PLUGIN_SLUG;
		$content = self::mu_plugin_content();

		if ( file_exists( $dest ) && file_get_contents( $dest ) === $content ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return;
		}

		wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins/' );
		file_put_contents( $dest, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Delete the BuddyNext isolation mu-plugin on deactivation.
	 *
	 * @return void
	 */
	public static function remove_mu_plugin(): void {
		$dest = WP_CONTENT_DIR . '/mu-plugins/' . self::MU_PLUGIN_SLUG;

		if ( file_exists( $dest ) ) {
			unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Return the PHP source for the buddynext-isolation mu-plugin.
	 *
	 * Kept as a private method so the content is compiled once and can be
	 * compared byte-for-byte in install_mu_plugin() to avoid unnecessary writes.
	 *
	 * @return string
	 */
	private static function mu_plugin_content(): string {
		// phpcs:disable Squiz.Strings.DoubleQuoteUsage.NotRequired
		return <<<'MUPLUGIN'
<?php
/**
 * Plugin Name: BuddyNext Isolation
 * Description: Strips non-essential plugins on BuddyNext front-end routes to save 20-40 MB per request.
 * Version:     1.0.0
 * Author:      Wbcom Designs
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

// No-op on admin pages and WP-CLI runs — isolation is for front-end only.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * Determine whether the current HTTP request targets a BuddyNext front-end route.
 *
 * Queries wp_options directly via $wpdb — WordPress option API is not yet
 * available this early in the bootstrap sequence.
 *
 * Uses a static guard so the DB query runs at most once per request.
 *
 * @return bool
 */
function buddynext_mu_is_bn_request() {
	static $result = null;

	if ( null !== $result ) {
		return $result;
	}

	// Parse the bare path from REQUEST_URI and strip the leading slash.
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw comparison only, never output.
	$path        = ltrim( strtok( $request_uri, '?' ), '/' );

	if ( '' === $path ) {
		$result = false;
		return false;
	}

	global $wpdb;

	// Fetch all six slug options in a single query.
	$option_names = array(
		'buddynext_slug_activity',
		'buddynext_slug_people',
		'buddynext_slug_spaces',
		'buddynext_slug_messages',
		'buddynext_slug_notifications',
		'buddynext_slug_auth',
	);

	$placeholders = implode( ', ', array_fill( 0, count( $option_names ), "'%s'" ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ( {$placeholders} )",
			$option_names
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

	// Build a map of option_name => value, then merge with hard-coded defaults.
	$fetched = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$fetched[ $row['option_name'] ] = $row['option_value'];
		}
	}

	$defaults = array(
		'buddynext_slug_activity'      => 'activity',
		'buddynext_slug_people'        => 'members',
		'buddynext_slug_spaces'        => 'spaces',
		'buddynext_slug_messages'      => 'messages',
		'buddynext_slug_notifications' => 'notifications',
		'buddynext_slug_auth'          => 'login',
	);

	$slugs = array_merge( $defaults, $fetched );

	foreach ( $slugs as $slug ) {
		if ( '' !== $slug && 0 === strpos( $path, $slug ) ) {
			$result = true;
			return true;
		}
	}

	$result = false;
	return false;
}

if ( buddynext_mu_is_bn_request() ) {
	/**
	 * Strip all non-whitelisted plugins before WordPress loads them.
	 *
	 * The whitelist is filterable so site owners can add cache or security
	 * plugins that must remain active on every request.
	 *
	 * @param string[]|mixed $plugins List of active plugin paths from wp_options.
	 * @return string[] Filtered list.
	 */
	add_filter(
		'option_active_plugins',
		static function ( $plugins ) {
			$whitelist = apply_filters(
				'buddynext_isolation_whitelist',
				array(
					'buddynext/buddynext.php',
					'wpmediaverse/wpmediaverse.php',
					'wpmediaverse-pro/wpmediaverse-pro.php',
					'jetonomy/jetonomy.php',
					'jetonomy-pro/jetonomy-pro.php',
					'redis-cache/redis-cache.php',
					'query-monitor/query-monitor.php',
				)
			);

			if ( ! is_array( $plugins ) ) {
				return $plugins;
			}

			return array_values( array_intersect( $plugins, $whitelist ) );
		}
	);
}
MUPLUGIN;
		// phpcs:enable Squiz.Strings.DoubleQuoteUsage.NotRequired
	}

	/**
	 * Create the six BuddyNext hub pages (activity, members, spaces, messages,
	 * notifications, auth/login) if they do not already exist.
	 *
	 * Safe to call on every activation — existing published pages are left
	 * untouched.
	 *
	 * @return void
	 */
	public static function create_hub_pages(): void {
		$hubs = array(
			'activity'      => array(
				'option_slug' => 'buddynext_slug_activity',
				'option_page' => 'buddynext_page_activity',
				'title'       => __( 'Activity', 'buddynext' ),
				'shortcode'   => '[buddynext_activity]',
				'default'     => 'activity',
			),
			'people'        => array(
				'option_slug' => 'buddynext_slug_people',
				'option_page' => 'buddynext_page_people',
				'title'       => __( 'Members', 'buddynext' ),
				'shortcode'   => '[buddynext_people]',
				'default'     => 'members',
			),
			'spaces'        => array(
				'option_slug' => 'buddynext_slug_spaces',
				'option_page' => 'buddynext_page_spaces',
				'title'       => __( 'Spaces', 'buddynext' ),
				'shortcode'   => '[buddynext_spaces]',
				'default'     => 'spaces',
			),
			'messages'      => array(
				'option_slug' => 'buddynext_slug_messages',
				'option_page' => 'buddynext_page_messages',
				'title'       => __( 'Messages', 'buddynext' ),
				'shortcode'   => '[buddynext_messages]',
				'default'     => 'messages',
			),
			'notifications' => array(
				'option_slug' => 'buddynext_slug_notifications',
				'option_page' => 'buddynext_page_notifications',
				'title'       => __( 'Notifications', 'buddynext' ),
				'shortcode'   => '[buddynext_notifications]',
				'default'     => 'notifications',
			),
			'auth'          => array(
				'option_slug' => 'buddynext_slug_auth',
				'option_page' => 'buddynext_page_auth',
				'title'       => __( 'Login', 'buddynext' ),
				'shortcode'   => '[buddynext_auth]',
				'default'     => 'login',
			),
		);

		foreach ( $hubs as $hub ) {
			$existing_id = (int) get_option( $hub['option_page'], 0 );
			if ( $existing_id > 0 && 'publish' === get_post_status( $existing_id ) ) {
				continue;
			}

			$slug = (string) get_option( $hub['option_slug'], $hub['default'] );
			if ( '' === $slug ) {
				$slug = $hub['default'];
			}

			$page_id = wp_insert_post(
				array(
					'post_title'     => $hub['title'],
					'post_name'      => $slug,
					'post_content'   => $hub['shortcode'],
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);

			if ( $page_id > 0 ) {
				update_option( $hub['option_page'], $page_id );
				update_option( $hub['option_slug'], $slug );
			}
		}

		flush_rewrite_rules();
	}
}
