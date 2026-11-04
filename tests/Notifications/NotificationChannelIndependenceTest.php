<?php
/**
 * In-app off takes a notification out of the bell and nowhere else.
 *
 * The card on the settings screen promises "Turning a channel off mutes every type
 * for that delivery surface". create() used to return before the row and the hook,
 * so In-app off also silently stopped the email, the push and the digest.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\CronService;
use BuddyNext\Notifications\NotificationListener;
use BuddyNext\Notifications\NotificationPrefService;
use BuddyNext\Notifications\NotificationService;
use WP_UnitTestCase;

/**
 * Every reader of bn_notifications against a delivery-only row.
 *
 * @covers \BuddyNext\Notifications\NotificationService::create
 * @covers \BuddyNext\Notifications\NotificationService::delivery_wanted
 */
class NotificationChannelIndependenceTest extends WP_UnitTestCase {

	private const TYPE = 'bn.new_follower';

	private int $member;
	private int $sender;
	private NotificationService $notifications;
	private NotificationPrefService $prefs;

	/**
	 * Hook payloads seen by buddynext_notification_created.
	 *
	 * @var array<int,array{0:int,1:array}>
	 */
	private array $fired = array();

	public function set_up(): void {
		parent::set_up();
		$this->member        = self::factory()->user->create();
		$this->sender        = self::factory()->user->create();
		$this->notifications = buddynext_service( 'notifications' );
		$this->prefs         = buddynext_service( 'notification_prefs' );
		$this->fired         = array();
		add_action(
			'buddynext_notification_created',
			function ( int $id, int $recipient, array $data ): void {
				unset( $recipient );
				$this->fired[] = array( $id, $data );
			},
			10,
			3
		);
		wp_cache_flush();
	}

	private function create( array $extra = array() ): int {
		return $this->notifications->create(
			array_merge(
				array(
					'recipient_id' => $this->member,
					'sender_id'    => $this->sender,
					'type'         => self::TYPE,
				),
				$extra
			)
		);
	}

	private function row( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bn_notifications WHERE id = %d", $id ), ARRAY_A );
	}

	private function assert_out_of_the_bell(): void {
		$this->assertSame( 0, $this->notifications->unread_count( $this->member ) );
		$this->assertSame( 0, $this->notifications->unseen_count( $this->member ) );
		$this->assertSame( array(), $this->notifications->list_for_user( $this->member )['items'] ?? array() );
	}

	public function test_type_in_app_off_with_email_on_keeps_the_hook_and_not_the_bell(): void {
		$this->prefs->set_pref(
			$this->member,
			self::TYPE,
			array(
				'on_site'    => false,
				'email_freq' => 'immediate',
			)
		);

		$id = $this->create();

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( '0', (string) $this->row( $id )['in_app'] );
		$this->assertCount( 1, $this->fired );
		$this->assertSame( $id, $this->fired[0][0] );
		$this->assertFalse( $this->fired[0][1]['in_app'] );
		$this->assert_out_of_the_bell();
	}

	public function test_email_really_goes_out(): void {
		$this->prefs->set_pref(
			$this->member,
			self::TYPE,
			array(
				'on_site'    => false,
				'email_freq' => 'immediate',
			)
		);
		$queued = 0;
		add_filter(
			'pre_as_enqueue_async_action',
			static function ( $pre, $hook ) use ( &$queued ) {
				if ( 'buddynext_send_notification_email' === $hook ) {
					++$queued;
					return 1;
				}
				return $pre;
			},
			10,
			2
		);
		$mailed = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mailed ) {
				++$mailed;
				return true;
			}
		);

		$this->create();

		$this->assertSame( 1, $queued + $mailed );
	}

	public function test_master_in_app_off_behaves_the_same(): void {
		$this->prefs->set_channel_prefs( $this->member, array( 'in_app' => false ) );

		$id = $this->create();

		$this->assertGreaterThan( 0, $id );
		$this->assertCount( 1, $this->fired );
		$this->assert_out_of_the_bell();
	}

	public function test_every_channel_off_writes_nothing_and_fires_nothing(): void {
		global $wpdb;
		$this->prefs->set_pref(
			$this->member,
			self::TYPE,
			array(
				'on_site'    => false,
				'email_freq' => 'off',
			)
		);

		$this->assertSame( 0, $this->create() );
		$this->assertSame( array(), $this->fired );
		$this->assertSame(
			'0',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d", $this->member ) )
		);

		// Master email off does the same as the type's Off.
		$this->prefs->set_pref(
			$this->member,
			self::TYPE,
			array(
				'on_site'    => false,
				'email_freq' => 'immediate',
			)
		);
		$this->prefs->set_channel_prefs( $this->member, array( 'email' => false ) );
		$this->assertSame( 0, $this->create() );
	}

	public function test_another_channel_can_ask_for_the_row(): void {
		$this->prefs->set_pref(
			$this->member,
			self::TYPE,
			array(
				'on_site'    => false,
				'email_freq' => 'off',
			)
		);
		add_filter( 'buddynext_notification_delivery_wanted', '__return_true' );

		$this->assertGreaterThan( 0, $this->create() );
		$this->assertCount( 1, $this->fired );
		$this->assert_out_of_the_bell();

		remove_filter( 'buddynext_notification_delivery_wanted', '__return_true' );
	}

	public function test_bell_on_is_unchanged(): void {
		$id = $this->create();

		$this->assertSame( '1', (string) $this->row( $id )['in_app'] );
		$this->assertArrayNotHasKey( 'in_app', $this->fired[0][1] );
		$this->assertSame( 1, $this->notifications->unread_count( $this->member ) );
	}

	public function test_mark_all_read_leaves_the_digest_its_rows(): void {
		$this->prefs->set_pref(
			$this->member,
			self::TYPE,
			array(
				'on_site'    => false,
				'email_freq' => 'daily',
			)
		);
		$hidden = $this->create();

		$this->notifications->mark_all_read( $this->member );

		$this->assertSame( '0', (string) $this->row( $hidden )['is_read'] );
		$this->assertContains( $hidden, array_map( 'intval', array_column( $this->digest_rows(), 'id' ) ) );
	}

	/**
	 * Rows the daily digest would build from, read through the cron's own queries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function digest_rows(): array {
		$instance = ( new \ReflectionClass( CronService::class ) )->newInstanceWithoutConstructor();

		$ids = new \ReflectionMethod( CronService::class, 'get_digest_user_ids' );
		$this->assertContains( $this->member, $ids->invoke( $instance, 1, 0 ) );

		$rows = new \ReflectionMethod( CronService::class, 'fetch_unread_notifications' );
		return (array) $rows->invoke( $instance, $this->member, 1, 'daily' );
	}

	public function test_group_rows_do_not_merge_across_the_bell(): void {
		$visible = $this->create( array( 'group_key' => 'qafix_group' ) );
		$this->prefs->set_channel_prefs( $this->member, array( 'in_app' => false ) );
		wp_cache_flush();

		$hidden = $this->create( array( 'group_key' => 'qafix_group' ) );

		$this->assertNotSame( $visible, $hidden );
		$this->assertSame( '1', (string) $this->row( $visible )['group_count'] );
		$this->assertSame( '0', (string) $this->row( $hidden )['in_app'] );
	}

	public function test_space_post_fan_out_keeps_email_recipients_with_the_bell_off(): void {
		global $wpdb;

		$space_id = 987654;
		$both_off = self::factory()->user->create();
		foreach ( array( $this->member, $both_off ) as $user_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_space_members',
				array(
					'space_id' => $space_id,
					'user_id'  => $user_id,
					'role'     => 'member',
					'status'   => 'active',
				)
			);
		}

		$this->prefs->set_channel_prefs( $this->member, array( 'in_app' => false ) );
		$this->prefs->set_pref(
			$this->member,
			'bn.space_new_post',
			array( 'email_freq' => 'daily' )
		);
		$this->prefs->set_pref(
			$both_off,
			'bn.space_new_post',
			array(
				'on_site'    => false,
				'email_freq' => 'off',
			)
		);

		$listener = ( new \ReflectionClass( NotificationListener::class ) )->newInstanceWithoutConstructor();
		$batch    = new \ReflectionMethod( NotificationListener::class, 'fan_out_space_post_batch' );
		$batch->invoke( $listener, 777, $space_id, $this->sender, 0, 50 );

		$hidden = $wpdb->get_row(
			$wpdb->prepare( "SELECT in_app FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.space_new_post'", $this->member ),
			ARRAY_A
		);
		$this->assertSame( '0', (string) ( $hidden['in_app'] ?? '' ) );
		$this->assertSame( 0, $this->notifications->unread_count( $this->member ) );

		$this->assertSame(
			'0',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.space_new_post'", $both_off ) )
		);
	}

	public function test_announcement_bell_respects_in_app_off(): void {
		global $wpdb;

		$space_id = 987655;
		$bell_on  = self::factory()->user->create();
		foreach ( array( $this->member, $bell_on ) as $user_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_space_members',
				array(
					'space_id' => $space_id,
					'user_id'  => $user_id,
					'role'     => 'member',
					'status'   => 'active',
				)
			);
		}
		$this->prefs->set_channel_prefs( $this->member, array( 'in_app' => false ) );

		$listener = ( new \ReflectionClass( NotificationListener::class ) )->newInstanceWithoutConstructor();
		$batch    = new \ReflectionMethod( NotificationListener::class, 'fan_out_announcement_batch' );
		$batch->invoke( $listener, 778, $this->sender, $space_id, 0, 50 );

		$this->assertSame( 0, $this->notifications->unread_count( $this->member ) );
		$this->assertSame( 1, $this->notifications->unread_count( $bell_on ) );
		$this->assertCount( 2, $this->fired );
	}
}
