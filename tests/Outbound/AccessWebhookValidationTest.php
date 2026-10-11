<?php
/**
 * Access webhook: input checks, signature formats, retries and the answer body.
 *
 * Each case here is something a hosted automation tool (Make, Zapier, n8n) does
 * in normal use: an epoch date, a bare-hex signature, a retried request, a
 * field mapped to the wrong key.
 *
 * @package BuddyNext\Tests\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Outbound;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PermissionService;
use BuddyNext\Core\RoleService;
use BuddyNext\REST\Router;

/**
 * Access webhook validation tests.
 *
 * @covers \BuddyNext\Outbound\AccessWebhookController
 */
class AccessWebhookValidationTest extends \WP_UnitTestCase {

	/**
	 * Site secret the requests are signed with.
	 *
	 * @var string
	 */
	private string $secret = 'validation-secret';

	/**
	 * Member the requests are about.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Counter that keeps every request body, and so every signature, different.
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		update_option( 'buddynext_webhook_secret', $this->secret );
		// Most cases here predate the role switch and exercise set_role itself.
		update_option( \BuddyNext\Outbound\AccessWebhookController::OPT_ALLOW_SET_ROLE, 1 );
		$this->user_id = self::factory()->user->create( array( 'user_email' => 'member@example.com' ) );
		( new Router() )->register();
	}

	/**
	 * Send one signed request.
	 *
	 * @param array                $body Request body.
	 * @param array<string,string> $opts 'format' (prefixed|bare|upper) and 'timestamp' (seconds|millis|stale).
	 * @return \WP_REST_Response
	 */
	private function call( array $body, array $opts = array() ): \WP_REST_Response {
		$body['_n'] = ++$this->seq;
		$payload    = (string) wp_json_encode( $body );
		$timestamp  = (string) time();
		if ( 'millis' === ( $opts['timestamp'] ?? '' ) ) {
			$timestamp = (string) ( time() * 1000 );
		} elseif ( 'stale' === ( $opts['timestamp'] ?? '' ) ) {
			$timestamp = (string) ( time() - 3600 );
		}

		$digest    = hash_hmac( 'sha256', $timestamp . '.' . $payload, $this->secret );
		$signature = 'sha256=' . $digest;
		if ( 'bare' === ( $opts['format'] ?? '' ) ) {
			$signature = $digest;
		} elseif ( 'upper' === ( $opts['format'] ?? '' ) ) {
			$signature = strtoupper( $digest );
		}

		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/webhook/access' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-BuddyNext-Timestamp', $timestamp );
		$request->set_header( 'X-BuddyNext-Signature', $signature );
		$request->set_body( $payload );

		return rest_do_request( $request );
	}

	/**
	 * Grant an ability with the given expires_at and return the response.
	 *
	 * @param array $extra Extra body fields.
	 * @return \WP_REST_Response
	 */
	private function grant( array $extra ): \WP_REST_Response {
		return $this->call(
			array_merge(
				array(
					'user_id' => $this->user_id,
					'action'  => 'grant_ability',
					'ability' => 'badge:supporter',
				),
				$extra
			)
		);
	}

	/**
	 * The stored expiry of the test ability.
	 *
	 * @return int|string '' when the ability was never written.
	 */
	private function stored_expiry() {
		$value = get_user_meta( $this->user_id, PermissionService::ability_meta_key( 'badge:supporter' ), true );

		return '' === $value ? '' : (int) $value;
	}

	/**
	 * A bare-hex or uppercase digest and a millisecond timestamp are accepted.
	 *
	 * @return void
	 */
	public function test_signature_formats_tools_produce_are_accepted(): void {
		$body = array(
			'user_id' => $this->user_id,
			'action'  => 'set_role',
			'role'    => 'member',
		);

		$this->assertSame( 200, $this->call( $body, array( 'format' => 'bare' ) )->get_status(), 'Bare hex.' );
		$this->assertSame( 200, $this->call( $body, array( 'format' => 'upper' ) )->get_status(), 'Uppercase hex.' );
		$this->assertSame( 200, $this->call( $body, array( 'timestamp' => 'millis' ) )->get_status(), 'Millisecond timestamp.' );

		$stale = $this->call( $body, array( 'timestamp' => 'stale' ) );
		$this->assertSame( 401, $stale->get_status() );
		$this->assertSame( 'stale_request', $stale->get_data()['code'] );
	}

	/**
	 * An ISO date, epoch seconds and epoch milliseconds all land on the same moment.
	 *
	 * @return void
	 */
	public function test_expires_at_accepts_iso_and_epoch(): void {
		$when = time() + 30 * DAY_IN_SECONDS;

		$response = $this->grant( array( 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $when ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $when, $this->stored_expiry() );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $when ), $response->get_data()['expires_at'] );

		$this->grant( array( 'expires_at' => (string) ( $when + 60 ) ) );
		$this->assertSame( $when + 60, $this->stored_expiry(), 'Epoch seconds as a string.' );

		$this->grant( array( 'expires_at' => $when + 120 ) );
		$this->assertSame( $when + 120, $this->stored_expiry(), 'Epoch seconds as a number.' );

		$this->grant( array( 'expires_at' => ( $when + 180 ) * 1000 ) );
		$this->assertSame( $when + 180, $this->stored_expiry(), 'Epoch milliseconds.' );
	}

	/**
	 * A date with no time is the end of that day, so the last day is kept.
	 *
	 * @return void
	 */
	public function test_date_only_expires_at_means_end_of_day(): void {
		$day = gmdate( 'Y-m-d', time() + 40 * DAY_IN_SECONDS );
		$this->grant( array( 'expires_at' => $day ) );

		$this->assertSame( strtotime( $day . 'T23:59:59Z' ), $this->stored_expiry() );
	}

	/**
	 * A date that cannot be read, or is in the past, is refused and writes nothing.
	 *
	 * @return void
	 */
	public function test_unreadable_or_past_expires_at_is_refused(): void {
		foreach ( array( 'in a month', '2020-01-01T00:00:00Z', '12345', 'tomorrow' ) as $bad ) {
			$response = $this->grant( array( 'expires_at' => $bad ) );
			$this->assertSame( 422, $response->get_status(), $bad );
			$this->assertSame( 'invalid_expires_at', $response->get_data()['code'], $bad );
		}

		$this->assertSame( '', $this->stored_expiry(), 'Nothing was granted.' );
	}

	/**
	 * No expires_at is a never-ending grant, unless an extension gives a default;
	 * "lifetime": true skips that default.
	 *
	 * @return void
	 */
	public function test_missing_expires_at_uses_the_default_and_lifetime_skips_it(): void {
		$this->grant( array() );
		$this->assertSame( 0, $this->stored_expiry(), 'Plain ability, no default.' );

		$default = time() + 30 * DAY_IN_SECONDS;
		add_filter( 'buddynext_access_webhook_default_expiry', static fn(): int => $default );

		$this->grant( array() );
		$this->assertSame( $default, $this->stored_expiry(), 'Default supplied by an extension.' );

		$this->grant( array( 'lifetime' => true ) );
		$this->assertSame( 0, $this->stored_expiry(), 'Lifetime said out loud.' );
	}

	/**
	 * An extension can refuse an action before anything is written.
	 *
	 * @return void
	 */
	public function test_pre_action_refusal_writes_nothing(): void {
		add_filter(
			'buddynext_access_webhook_pre_action',
			static fn() => new \WP_Error( 'unknown_plan', 'No such plan.', array( 'status' => 422 ) )
		);

		$response = $this->grant( array() );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( '', $this->stored_expiry() );
	}

	/**
	 * Credits need a whole, non-negative amount; a bad one never touches the balance.
	 *
	 * @return void
	 */
	public function test_credits_require_a_valid_amount(): void {
		$roles = new RoleService();
		$base  = array( 'user_id' => $this->user_id );

		$set = $this->call( $base + array( 'action' => 'set_credits', 'amount' => 7 ) );
		$this->assertSame( 200, $set->get_status() );
		$this->assertSame( 7, $set->get_data()['balance'] );

		$bad = array(
			array( 'action' => 'set_credits', 'credits' => 50 ),
			array( 'action' => 'set_credits' ),
			array( 'action' => 'add_credits' ),
			array( 'action' => 'add_credits', 'amount' => -50 ),
			array( 'action' => 'add_credits', 'amount' => '12.9' ),
			array( 'action' => 'deduct_credits', 'amount' => 'ten' ),
		);
		foreach ( $bad as $case ) {
			$response = $this->call( $base + $case );
			$this->assertSame( 400, $response->get_status(), (string) wp_json_encode( $case ) );
			$this->assertSame( 'invalid_amount', $response->get_data()['code'] );
		}
		$this->assertSame( 7, $roles->get_credits( $this->user_id ), 'Bad requests left the balance alone.' );

		$this->assertSame( 12, $this->call( $base + array( 'action' => 'add_credits', 'amount' => '5' ) )->get_data()['balance'] );
		$this->assertSame( 10, $this->call( $base + array( 'action' => 'deduct_credits', 'amount' => 2 ) )->get_data()['balance'] );
	}

	/**
	 * A retry with the same idempotency_key gets the first answer and changes nothing.
	 *
	 * @return void
	 */
	public function test_idempotency_key_credits_once(): void {
		$body = array(
			'user_id'         => $this->user_id,
			'action'          => 'add_credits',
			'amount'          => 25,
			'idempotency_key' => 'payment-1001',
		);

		$first  = $this->call( $body )->get_data();
		$second = $this->call( $body )->get_data();

		$this->assertSame( 25, $first['balance'] );
		$this->assertArrayNotHasKey( 'replayed', $first );
		$this->assertTrue( $second['replayed'] );
		$this->assertSame( 25, $second['balance'] );
		$this->assertSame( 25, ( new RoleService() )->get_credits( $this->user_id ) );

		$changed           = $body;
		$changed['amount'] = 99;
		$conflict          = $this->call( $changed );
		$this->assertSame( 409, $conflict->get_status(), 'Same key, different amount.' );
		$this->assertSame( 'idempotency_conflict', $conflict->get_data()['code'] );
		$this->assertSame( 25, ( new RoleService() )->get_credits( $this->user_id ) );

		$body['idempotency_key'] = 'payment-1002';
		$this->assertSame( 50, $this->call( $body )->get_data()['balance'], 'A new key is a new payment.' );
	}

	/**
	 * A user_id and user_email of different members is refused; matching ones pass.
	 *
	 * @return void
	 */
	public function test_mismatched_identity_is_refused(): void {
		$other = self::factory()->user->create( array( 'user_email' => 'other@example.com' ) );
		$body  = array(
			'action' => 'set_role',
			'role'   => 'moderator',
		);

		$conflict = $this->call(
			$body + array(
				'user_id'    => $this->user_id,
				'user_email' => 'other@example.com',
			)
		);
		$this->assertSame( 400, $conflict->get_status() );
		$this->assertSame( 'identity_conflict', $conflict->get_data()['code'] );
		$this->assertNotSame( 'moderator', get_user_meta( $this->user_id, 'bn_community_role', true ) );
		$this->assertNotSame( 'moderator', get_user_meta( $other, 'bn_community_role', true ) );

		$match = $this->call(
			$body + array(
				'user_id'    => $this->user_id,
				'user_email' => 'member@example.com',
			)
		);
		$this->assertSame( 200, $match->get_status() );
		$this->assertSame( $this->user_id, $match->get_data()['user_id'] );
		$this->assertSame( 'set_role', $match->get_data()['action'] );

		$this->assertSame( 404, $this->call( $body + array( 'user_email' => 'nobody@example.com' ) )->get_status() );
	}

	/**
	 * Two copies of one signed request that arrive together: the second is
	 * refused once it holds the member's lock, even though it passed the first
	 * replay check before the first copy had recorded itself.
	 *
	 * The first copy finishing is simulated from a filter that runs between the
	 * signature check and the lock.
	 *
	 * @return void
	 */
	public function test_a_concurrent_copy_of_a_request_is_refused_under_the_lock(): void {
		$payload   = (string) wp_json_encode(
			array(
				'user_id' => $this->user_id,
				'action'  => 'add_credits',
				'amount'  => 40,
			)
		);
		$timestamp = (string) time();
		$digest    = hash_hmac( 'sha256', $timestamp . '.' . $payload, $this->secret );

		add_filter(
			'buddynext_access_webhook_actions',
			function ( array $actions ) use ( $digest ): array {
				\BuddyNext\Outbound\WebhookLog::write( 'add_credits', $this->user_id, array(), 'success', $digest );

				return $actions;
			}
		);

		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/webhook/access' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-BuddyNext-Timestamp', $timestamp );
		$request->set_header( 'X-BuddyNext-Signature', 'sha256=' . $digest );
		$request->set_body( $payload );
		$response = rest_do_request( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'replayed_request', $response->get_data()['code'] );
		$this->assertSame( 0, ( new RoleService() )->get_credits( $this->user_id ), 'The copy changed nothing.' );
	}

	/**
	 * A request stamped ahead of the clock stays acceptable for longer than the
	 * tolerance, so a copy sent after the tolerance has passed is still refused.
	 *
	 * @return void
	 */
	public function test_replay_is_refused_for_the_whole_time_a_request_is_valid(): void {
		global $wpdb;

		$payload   = (string) wp_json_encode(
			array(
				'user_id' => $this->user_id,
				'action'  => 'add_credits',
				'amount'  => 5,
			)
		);
		$timestamp = (string) ( time() + 200 );
		$digest    = hash_hmac( 'sha256', $timestamp . '.' . $payload, $this->secret );
		$send      = function () use ( $payload, $timestamp, $digest ): \WP_REST_Response {
			$request = new \WP_REST_Request( 'POST', '/buddynext/v1/webhook/access' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'X-BuddyNext-Timestamp', $timestamp );
			$request->set_header( 'X-BuddyNext-Signature', $digest );
			$request->set_body( $payload );

			return rest_do_request( $request );
		};

		$this->assertSame( 200, $send()->get_status() );

		// Six minutes later by the log: past the tolerance, but the request, stamped
		// ahead, is still inside its window.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_webhook_log SET created_at = %s WHERE signature = %s", gmdate( 'Y-m-d H:i:s', time() - 360 ), $digest ) );

		$this->assertSame( 409, $send()->get_status() );
		$this->assertSame( 5, ( new RoleService() )->get_credits( $this->user_id ) );
	}

	/**
	 * A site can switch an action off; the stored log masks the member's address.
	 *
	 * @return void
	 */
	public function test_action_can_be_switched_off_and_log_masks_email(): void {
		global $wpdb;

		add_filter( 'buddynext_access_webhook_actions', static fn( array $actions ): array => array_diff( $actions, array( 'set_role' ) ) );
		$refused = $this->call(
			array(
				'user_id' => $this->user_id,
				'action'  => 'set_role',
				'role'    => 'admin',
			)
		);
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'action_not_allowed', $refused->get_data()['code'] );
		$this->assertNotSame( 'admin', get_user_meta( $this->user_id, 'bn_community_role', true ) );

		$this->call(
			array(
				'user_email' => 'member@example.com',
				'action'     => 'add_credits',
				'amount'     => 1,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$payload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$wpdb->prefix}bn_webhook_log WHERE user_id = %d AND action = 'add_credits' ORDER BY id DESC LIMIT 1", $this->user_id ) );
		$this->assertStringContainsString( 'm***@example.com', $payload );
		$this->assertStringNotContainsString( 'member@example.com', $payload );
	}

	/**
	 * A buyer with no account: created only when the owner switched it on, only
	 * for a signed request that gives something, and never left behind when the
	 * grant itself fails.
	 *
	 * @return void
	 */
	public function test_new_buyer_account_is_created_only_when_allowed(): void {
		$mails = array();
		add_filter(
			'pre_wp_mail',
			static function ( $short, array $atts ) use ( &$mails ) {
				if ( null !== $short ) {
					return $short; // Already held back; a real mailer sends nothing.
				}
				$mails[] = (string) $atts['subject'];
				return true;
			},
			10,
			2
		);
		$grant = array(
			'user_email' => 'buyer@example.org',
			'name'       => 'Nadia Buyer',
			'action'     => 'grant_ability',
			'ability'    => 'early_access',
		);

		// Switched off (the default): as before, and nobody is created.
		$response = $this->call( $grant );
		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( get_user_by( 'email', 'buyer@example.org' ) );

		update_option( \BuddyNext\Outbound\AccessWebhookController::OPT_CREATE_MEMBERS, 1 );

		// Things that must never create anyone, even switched on.
		$this->assertSame( 404, $this->call( array( 'user_email' => 'buyer@example.org', 'action' => 'revoke_ability', 'ability' => 'early_access' ) )->get_status(), 'A revoke gives nothing.' );
		$this->assertSame( 404, $this->call( array( 'user_email' => 'buyer@example.org', 'action' => 'set_role', 'role' => 'administrator' ) )->get_status(), 'A role change gives nothing.' );
		$this->assertSame( 404, $this->call( array( 'user_id' => 99999999, 'action' => 'grant_ability', 'ability' => 'early_access' ) )->get_status(), 'An id names nobody to create.' );
		$this->assertSame( 404, $this->call( array( 'user_email' => 'not-an-email', 'action' => 'grant_ability', 'ability' => 'early_access' ) )->get_status(), 'Not an address.' );
		$this->assertSame( 0, count( get_users( array( 'search' => '*buyer*', 'search_columns' => array( 'user_email' ) ) ) ) );

		// A grant that fails leaves no account and sends no email.
		$failed = $this->call( $grant + array( 'expires_at' => 'last tuesday-ish' ) );
		$this->assertGreaterThanOrEqual( 400, $failed->get_status() );
		$this->assertFalse( get_user_by( 'email', 'buyer@example.org' ), 'No account without the grant.' );
		$this->assertSame( array(), $mails );

		// The real thing.
		$response = $this->call( $grant );
		$data     = $response->get_data();
		$user     = get_user_by( 'email', 'buyer@example.org' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertTrue( $data['member_created'] );
		$this->assertSame( $user->ID, $data['user_id'] );
		$this->assertSame( array( get_option( 'default_role', 'subscriber' ) ), array_values( $user->roles ), 'A new member, never more.' );
		$this->assertSame( 'Nadia Buyer', $user->display_name );
		$this->assertNotEmpty( get_user_meta( $user->ID, 'buddynext_email_verified', true ) );
		$this->assertCount( 1, $mails, 'One email: the set-password one.' );

		// The same purchase again gives to the same member and creates no one.
		$again = $this->call( $grant );
		$this->assertSame( 200, $again->get_status() );
		$this->assertArrayNotHasKey( 'member_created', $again->get_data() );
		$this->assertCount( 1, get_users( array( 'search' => 'buyer@example.org', 'search_columns' => array( 'user_email' ) ) ) );
		$this->assertCount( 1, $mails );

		delete_option( \BuddyNext\Outbound\AccessWebhookController::OPT_CREATE_MEMBERS );
		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * Role changes from outside are off until the owner allows them. Everything
	 * else keeps working, a developer's filter still has the last word, and a
	 * site that used them before is told.
	 *
	 * @return void
	 */
	public function test_role_changes_are_off_until_the_owner_allows_them(): void {
		$opt  = \BuddyNext\Outbound\AccessWebhookController::OPT_ALLOW_SET_ROLE;
		$role = array(
			'user_id' => $this->user_id,
			'action'  => 'set_role',
			'role'    => 'moderator',
		);
		( new \BuddyNext\Admin\Settings() )->register();
		delete_option( $opt );
		delete_option( \BuddyNext\Outbound\AccessWebhookController::OPT_ROLE_REVIEWED );
		delete_transient( 'buddynext_webhook_set_role_used' );
		$this->assertFalse( \BuddyNext\Outbound\AccessWebhookController::role_switch_needs_attention(), 'A site that never used it is not nagged.' );

		$refused = $this->call( $role );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'action_not_allowed', $refused->get_data()['code'] );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'bn_community_role', true ), 'Nothing changed.' );
		$this->assertSame( 200, $this->call( array( 'user_id' => $this->user_id, 'action' => 'grant_ability', 'ability' => 'early_access' ) )->get_status(), 'Other actions are untouched.' );

		// A site whose log shows a role change from before the update.
		\BuddyNext\Outbound\WebhookLog::write( 'set_role', $this->user_id, array( 'role' => 'moderator' ) );
		delete_transient( 'buddynext_webhook_set_role_used' );
		$this->assertTrue( \BuddyNext\Outbound\AccessWebhookController::role_switch_needs_attention() );

		// The owner allows it.
		update_option( $opt, 1 );
		$this->assertFalse( \BuddyNext\Outbound\AccessWebhookController::role_switch_needs_attention(), 'Once chosen, no more notice.' );
		$this->assertSame( 200, $this->call( $role )->get_status() );
		$this->assertSame( 'moderator', get_user_meta( $this->user_id, 'bn_community_role', true ) );

		// The owner says no: still chosen, still refused. Saving "off" on a site
		// where it was never on stores no value, and must still count as an answer.
		delete_option( $opt );
		delete_option( \BuddyNext\Outbound\AccessWebhookController::OPT_ROLE_REVIEWED );
		$this->assertTrue( \BuddyNext\Outbound\AccessWebhookController::role_switch_needs_attention() );
		update_option( $opt, false );
		$this->assertFalse( \BuddyNext\Outbound\AccessWebhookController::role_switch_needs_attention() );
		$this->assertSame( 403, $this->call( array( 'role' => 'member' ) + $role )->get_status() );

		// A developer's filter has the last word.
		add_filter( 'buddynext_access_webhook_actions', static fn( array $actions ): array => array_merge( $actions, array( 'set_role' ) ) );
		$this->assertSame( 200, $this->call( array( 'role' => 'member' ) + $role )->get_status() );
		remove_all_filters( 'buddynext_access_webhook_actions' );
		delete_option( $opt );
	}
}
