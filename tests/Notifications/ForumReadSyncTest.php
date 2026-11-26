<?php
/**
 * One inbox, one read state: a forum row read in BuddyNext is read in Jetonomy's
 * own notifications list, and the member sets forum email from BuddyNext.
 *
 * Jetonomy is not loaded in the BuddyNext suite, so its notification model, table
 * helper, profile model and notifier are stubbed below with the same SQL and
 * signatures as Jetonomy 2.0.7. The stubs act only while this test runs.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace Jetonomy {
	if ( ! function_exists( __NAMESPACE__ . '\\table' ) ) {
		/**
		 * Jetonomy's table-name helper.
		 *
		 * @param string $name Table name without prefix.
		 * @return string
		 */
		function table( string $name ): string {
			global $wpdb;
			return $wpdb->prefix . 'jt_' . $name;
		}
	}
}

namespace Jetonomy\Models {
	if ( ! class_exists( __NAMESPACE__ . '\\Notification' ) ) {
		/**
		 * Jetonomy's notification model: the two read methods BuddyNext calls.
		 */
		class Notification {
			/**
			 * True only while ForumReadSyncTest runs (its table exists then).
			 *
			 * @var bool
			 */
			public static bool $live = false;

			/**
			 * Calls made, as method => count.
			 *
			 * @var array<string,int>
			 */
			public static array $calls = array();

			public static function mark_all_read( int $user_id ): void {
				if ( ! self::$live ) {
					return;
				}
				self::$calls['mark_all_read'] = ( self::$calls['mark_all_read'] ?? 0 ) + 1;
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( \Jetonomy\table( 'notifications' ), array( 'is_read' => 1 ), array( 'user_id' => $user_id, 'is_read' => 0 ) );
			}

			/**
			 * @param int   $user_id Owner.
			 * @param int[] $ids     Row ids.
			 * @return int
			 */
			public static function mark_read_for_user( int $user_id, array $ids ): int {
				if ( ! self::$live ) {
					return 0;
				}
				self::$calls['mark_read_for_user'] = ( self::$calls['mark_read_for_user'] ?? 0 ) + 1;
				$ids = array_filter( array_map( 'absint', $ids ) );
				if ( ! $ids ) {
					return 0;
				}
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . \Jetonomy\table( 'notifications' ) . ' SET is_read = 1 WHERE user_id = %d AND is_read = 0 AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $user_id ), $ids ) ) );
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\\UserProfile' ) ) {
		/**
		 * Jetonomy's profile model: the settings JSON per member.
		 */
		class UserProfile {
			/**
			 * Settings JSON by user id.
			 *
			 * @var array<int,string>
			 */
			public static array $settings = array();

			public static function find_by_user( int $user_id ): ?object {
				return isset( self::$settings[ $user_id ] ) ? (object) array( 'settings' => self::$settings[ $user_id ] ) : null;
			}
		}
	}
}

namespace Jetonomy\Notifications {
	if ( ! class_exists( __NAMESPACE__ . '\\Notifier' ) ) {
		/**
		 * Jetonomy's notifier: the email default for a type.
		 */
		class Notifier {
			public static function default_email( string $type, array $global_defaults ): bool {
				return (bool) ( $global_defaults[ $type ]['email'] ?? in_array( $type, array( 'message', 'join_request_result' ), true ) );
			}
		}
	}
}

namespace BuddyNext\Tests\Notifications {

	use BuddyNext\Core\Installer;
	use Jetonomy\Models\Notification;
	use Jetonomy\Models\UserProfile;

	/**
	 * @covers \BuddyNext\Notifications\NotificationService::mark_read
	 * @covers \BuddyNext\Notifications\NotificationService::mark_all_read
	 */
	class ForumReadSyncTest extends \WP_UnitTestCase {

		private int $reader;
		private int $other;
		private int $actor;

		public function set_up(): void {
			parent::set_up();
			Installer::run();
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_notifications (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint unsigned NOT NULL,
				type varchar(50) NOT NULL,
				object_type varchar(20) NOT NULL,
				object_id bigint unsigned NOT NULL,
				is_read tinyint NOT NULL DEFAULT 0,
				PRIMARY KEY (id)
			)"
			);
			Notification::$live  = true;
			Notification::$calls = array();
			$this->reader        = self::factory()->user->create();
			$this->other         = self::factory()->user->create();
			$this->actor         = self::factory()->user->create();
			add_filter(
				'jetonomy_community_notification_types',
				static function ( array $types ): array {
					$types['reply_to_post'] = array(
						'label'       => 'Replies to your topics',
						'description' => 'Someone replied to a topic you started.',
					);
					$types['mention']       = array(
						'label'       => 'Mentions',
						'description' => 'Someone mentioned you.',
					);
					$types['vote_on_post']  = array(
						'label'       => 'Votes on your topics',
						'description' => 'Someone voted on your topic.',
					);
					return $types;
				}
			);
		}

		public function tear_down(): void {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_notifications" );
			Notification::$live      = false;
			UserProfile::$settings   = array();
			remove_all_filters( 'jetonomy_community_notification_types' );
			delete_option( 'jetonomy_settings' );
			parent::tear_down();
		}

		/**
		 * Jetonomy stores its own row for a reply, then fires its hook with the contract
		 * payload (topic as the bell object, the reply as the item).
		 *
		 * @param int $user     Recipient.
		 * @param int $reply_id Reply id.
		 * @param int $actor    Replier.
		 * @return int Jetonomy row id.
		 */
		private function reply( int $user, int $reply_id, int $actor = 0 ): int {
			global $wpdb;
			$actor = $actor > 0 ? $actor : $this->actor;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $wpdb->prefix . 'jt_notifications', array( 'user_id' => $user, 'type' => 'reply_to_post', 'object_type' => 'reply', 'object_id' => $reply_id ) );
			$jt_id   = (int) $wpdb->insert_id;
			$payload = array(
				'recipient_id'    => $user,
				'type'            => 'reply_to_post',
				'actor_id'        => $actor,
				'object_type'     => 'post',
				'object_id'       => 700,
				'item_type'       => 'reply',
				'item_id'         => $reply_id,
				'message'         => 'Someone replied to "Topic".',
				'message_single'  => '{actor} replied to "Topic".',
				'message_grouped' => '{actor} and {others} replied to "Topic".',
				'url'             => 'https://example.org/t/topic/',
				'group_key'       => 'reply_to_post_700',
				'notification_id' => $jt_id,
			);
			do_action( 'jetonomy_notification_created', $jt_id, $user, 'reply_to_post', 'reply', $reply_id, 'legacy', 'https://example.org/legacy', $payload );
			return $jt_id;
		}

		private function bn_row( int $user ): int {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'jetonomy.reply_to_post'", $user ) );
		}

		private function jt_unread( int $user ): int {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_notifications WHERE user_id = %d AND is_read = 0", $user ) );
		}

		public function test_reading_a_forum_row_reads_it_in_jetonomy_for_that_member_only(): void {
			$this->reply( $this->reader, 41 );
			$this->reply( $this->other, 41 );
			$this->assertGreaterThan( 0, $this->bn_row( $this->reader ) );

			wp_set_current_user( $this->reader );
			$response = rest_do_request( new \WP_REST_Request( 'POST', '/buddynext/v1/me/notifications/' . $this->bn_row( $this->reader ) . '/read' ) );

			$this->assertLessThan( 300, $response->get_status() );
			$this->assertSame( 0, $this->jt_unread( $this->reader ), 'The reader\'s Jetonomy row is read.' );
			$this->assertSame( 1, $this->jt_unread( $this->other ), 'Another recipient\'s Jetonomy row stays unread.' );
		}

		public function test_a_grouped_row_reads_every_reply_behind_it(): void {
			foreach ( array( 51, 52, 53 ) as $reply_id ) {
				$this->reply( $this->reader, $reply_id, self::factory()->user->create() );
			}
			$this->assertSame( 3, $this->jt_unread( $this->reader ) );

			buddynext_service( 'notifications' )->mark_read( $this->bn_row( $this->reader ), $this->reader );

			$this->assertSame( 0, $this->jt_unread( $this->reader ), 'All three replies are read in Jetonomy.' );
			$this->assertSame( 1, Notification::$calls['mark_read_for_user'] ?? 0, 'One write for the whole group.' );
		}

		public function test_read_all_reads_jetonomy_too(): void {
			$this->reply( $this->reader, 61 );
			$this->reply( $this->other, 61 );

			buddynext_service( 'notifications' )->mark_all_read( $this->reader );

			$this->assertSame( 0, $this->jt_unread( $this->reader ) );
			$this->assertSame( 1, $this->jt_unread( $this->other ) );
		}

		public function test_reads_do_not_echo_back_and_forth(): void {
			$this->reply( $this->reader, 71 );
			$before = did_action( 'jetonomy_community_notification_read' );

			buddynext_service( 'notifications' )->mark_read( $this->bn_row( $this->reader ), $this->reader );
			$this->assertSame( $before, did_action( 'jetonomy_community_notification_read' ), 'BuddyNext fires no Jetonomy read hook.' );

			// The inbound path (read in Jetonomy) marks the bell row and writes nothing back.
			$this->reply( $this->reader, 72 );
			Notification::$calls = array();
			do_action( 'jetonomy_community_notification_read', 'post', 700, $this->reader );
			$this->assertSame( array(), Notification::$calls );

			// A row already read is not synced again.
			buddynext_service( 'notifications' )->mark_read( $this->bn_row( $this->reader ), $this->reader );
			$this->assertSame( array(), Notification::$calls );
		}

		public function test_forum_email_card_renders_every_switch_with_a_label_and_hint(): void {
			wp_set_current_user( $this->reader );
			UserProfile::$settings[ $this->reader ] = (string) wp_json_encode( array( 'notifications' => array( 'mention' => array( 'email' => false ) ) ) );
			update_option( 'jetonomy_settings', array( 'notification_defaults' => array( 'reply_to_post' => array( 'email' => true ) ) ) );

			ob_start();
			buddynext_get_template(
				'parts/settings-partner-prefs.php',
				array(
					'bn_pp_user_id' => $this->reader,
					'bn_pp_fields'  => '',
				)
			);
			$html = (string) ob_get_clean();

			foreach ( array( 'pause' => 'email_opt_out', 'reply_to_post' => 'notification_preferences.reply_to_post.email', 'mention' => 'notification_preferences.mention.email', 'vote_on_post' => 'notification_preferences.vote_on_post.email' ) as $slug => $key ) {
				$this->assertStringContainsString( 'for="bn-forum-email-' . $slug . '"', $html, "$slug has a visible label." );
				$this->assertStringContainsString( 'id="bn-forum-email-' . $slug . '-hint"', $html, "$slug has a hint." );
				$this->assertStringContainsString( 'data-body-key="' . $key . '"', $html );
			}
			$this->assertStringNotContainsString( '.web', $html, 'The web channel is never offered.' );
			$this->assertStringContainsString( 'confirm your account email', $html, 'The pause hint names the verification reminder.' );
			$this->assertMatchesRegularExpression( '/id="bn-forum-email-reply_to_post"[^>]*checked/s', $html, 'Site default on.' );
			$this->assertDoesNotMatchRegularExpression( '/id="bn-forum-email-mention"[^>]*checked/s', $html, 'Member turned it off.' );
			$this->assertDoesNotMatchRegularExpression( '/id="bn-forum-email-vote_on_post"[^>]*checked/s', $html, 'Off when nothing is stored.' );
		}
	}
}
