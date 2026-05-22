<?php
/**
 * Tests for NotificationMessageService.
 *
 * Verifies that every notification type slug enumerated in
 * docs/specs/NOTIFICATION-MESSAGES.md composes a real, human-readable
 * message — never the fallback "sent you a notification." string that
 * F5 of docs/qa/PRODUCTION-READINESS.md flagged as broken.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationMessageService;

/**
 * Notification message composer test.
 *
 * @covers \BuddyNext\Notifications\NotificationMessageService
 */
class NotificationMessageServiceTest extends \WP_UnitTestCase {

	/**
	 * Message composer service under test.
	 *
	 * @var NotificationMessageService
	 */
	private NotificationMessageService $service;

	/**
	 * Test actor user ID.
	 *
	 * @var int
	 */
	private int $actor_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service  = new NotificationMessageService();
		$this->actor_id = self::factory()->user->create(
			array( 'display_name' => 'Aiko Tanaka' )
		);
	}

	/**
	 * Build a notification row stub for a type.
	 *
	 * @param string $type        Notification type slug.
	 * @param array  $overrides   Field overrides.
	 * @return array<string,mixed>
	 */
	private function row( string $type, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 1,
				'type'        => $type,
				'sender_id'   => $this->actor_id,
				'object_id'   => 0,
				'object_type' => null,
				'group_count' => 1,
				'data'        => null,
				'is_read'     => 0,
				'created_at'  => '2026-05-22 12:00:00',
			),
			$overrides
		);
	}

	/**
	 * Provider: every type slug + the substring the rendered message must contain.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_types(): array {
		return array(
			'new_follower'           => array( 'bn.new_follower', 'started following you' ),
			'connection_requested'   => array( 'bn.connection_requested', 'connection request' ),
			'connection_accepted'    => array( 'bn.connection_accepted', 'accepted your connection' ),
			'connection_declined'    => array( 'bn.connection_declined', 'declined your connection' ),
			'post_reacted'           => array( 'bn.post_reacted', 'reacted' ),
			'post_commented'         => array( 'bn.post_commented', 'commented on your post' ),
			'comment_reply'          => array( 'bn.comment_reply', 'replied to your comment' ),
			'post_shared'            => array( 'bn.post_shared', 'shared your post' ),
			'mention'                => array( 'bn.mention', 'mentioned you' ),
			'bookmark_milestone'     => array( 'bn.bookmark_milestone', 'bookmarked' ),
			'space_join'             => array( 'bn.space_join', 'joined' ),
			'space_invite'           => array( 'bn.space_invite', 'invited you to' ),
			'space_join_requested'   => array( 'bn.space_join_requested', 'requested to join' ),
			'space_request_approved' => array( 'bn.space_request_approved', 'approved' ),
			'space_join_approved'    => array( 'bn.space_join_approved', 'approved' ),
			'space_join_declined'    => array( 'bn.space_join_declined', 'declined' ),
			'space_new_post'         => array( 'bn.space_new_post', 'posted in' ),
			'space_role_changed'     => array( 'bn.space_role_changed', 'role in' ),
			'bulk_invite'            => array( 'bn.bulk_invite', 'invited to' ),
			'new_message'            => array( 'bn.new_message', 'sent you a message' ),
			'user_warned'            => array( 'bn.user_warned', 'warning' ),
			'strike_warning'         => array( 'bn.strike_warning', 'close to an account strike' ),
			'strike_issued'          => array( 'bn.strike_issued', 'strike' ),
			'member_suspended'       => array( 'bn.member_suspended', 'suspended' ),
			'user_unsuspended'       => array( 'bn.user_unsuspended', 'reinstated' ),
			'user_shadow_banned'     => array( 'bn.user_shadow_banned', 'under review' ),
			'appeal_submitted'       => array( 'bn.appeal_submitted', 'appeal' ),
			'appeal_resolved'        => array( 'bn.appeal_resolved', 'appeal' ),
			'report_resolved'        => array( 'bn.report_resolved', 'report' ),
			'badge_awarded'          => array( 'bn.badge_awarded', 'badge' ),
			'level_up'               => array( 'bn.level_up', 'level' ),
			'onboarding_nudge'       => array( 'bn.onboarding_nudge', 'profile' ),
			'daily_digest'           => array( 'bn.daily_digest', 'daily digest' ),
			'weekly_digest'          => array( 'bn.weekly_digest', 'weekly digest' ),
			'media_favorited'        => array( 'bn.media_favorited', 'favourited' ),
			'jetonomy_reply'         => array( 'bn.jetonomy_reply', 'replied to your discussion' ),
			'test'                   => array( 'bn.test', 'Test notification' ),
		);
	}

	/**
	 * Every documented type slug must render a real message — never the
	 * legacy fallback "sent you a notification" string.
	 *
	 * @dataProvider provide_types
	 *
	 * @param string $type     Notification type slug.
	 * @param string $needle   Substring the rendered message must contain.
	 */
	public function test_every_type_renders_human_copy( string $type, string $needle ): void {
		$payload = $this->service->compose( $this->row( $type, array( 'object_id' => 1 ) ) );

		$this->assertArrayHasKey( 'message', $payload );
		$this->assertNotSame( '', $payload['message'], "Type {$type} composed an empty message" );
		$this->assertStringNotContainsString(
			'sent you a notification',
			$payload['message'],
			"Type {$type} still hit the legacy fallback string"
		);
		$this->assertStringContainsStringIgnoringCase(
			$needle,
			$payload['message'],
			"Type {$type} did not contain expected phrase \"{$needle}\""
		);
	}

	/**
	 * Every type must expose an icon, tone, and label.
	 *
	 * @dataProvider provide_types
	 *
	 * @param string $type    Notification type slug.
	 * @param string $_needle Unused.
	 */
	public function test_every_type_exposes_meta( string $type, string $_needle ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$payload = $this->service->compose( $this->row( $type ) );

		$this->assertNotSame( '', $payload['icon'], "Type {$type} has no icon" );
		$this->assertContains( $payload['tone'], array( 'info', 'success', 'warn', 'danger', 'accent' ), "Type {$type} has invalid tone" );
		$this->assertNotSame( '', $payload['label'], "Type {$type} has no label" );
	}

	/**
	 * Actor display name is resolved and present in actor_name field.
	 */
	public function test_actor_name_resolved(): void {
		$payload = $this->service->compose( $this->row( 'bn.new_follower' ) );

		$this->assertSame( 'Aiko Tanaka', $payload['actor_name'] );
		$this->assertStringContainsString( 'Aiko Tanaka', $payload['message'] );
	}

	/**
	 * Missing actor falls back to "Someone".
	 */
	public function test_missing_actor_falls_back_to_someone(): void {
		$payload = $this->service->compose( $this->row( 'bn.new_follower', array( 'sender_id' => 0 ) ) );

		$this->assertSame( 'Someone', $payload['actor_name'] );
		$this->assertStringContainsString( 'Someone', $payload['message'] );
	}

	/**
	 * Group-collapse: when group_count > 1 the copy mentions "others".
	 */
	public function test_group_collapse_renders_others_template(): void {
		$payload = $this->service->compose(
			$this->row(
				'bn.new_follower',
				array( 'group_count' => 4 )
			)
		);

		$this->assertStringContainsString( 'others', $payload['message'] );
		$this->assertStringContainsString( '3', $payload['message'] );
		$this->assertSame( 4, $payload['group_count'] );
	}

	/**
	 * Singleton-by-nature types ignore group_count and render the single template.
	 */
	public function test_singleton_types_ignore_group_count(): void {
		$payload = $this->service->compose(
			$this->row(
				'bn.member_suspended',
				array(
					'sender_id'   => 0,
					'group_count' => 5,
				)
			)
		);

		$this->assertSame(
			'Your account has been suspended.',
			$payload['message']
		);
	}

	/**
	 * Reaction notification renders the emoji slug when provided.
	 */
	public function test_reaction_message_includes_emoji(): void {
		$payload = $this->service->compose(
			$this->row(
				'bn.post_reacted',
				array(
					'object_id' => 1,
					'data'      => array( 'emoji' => 'love' ),
				)
			)
		);

		$this->assertStringContainsString( 'love', $payload['message'] );
	}

	/**
	 * URL field resolves to a real URL when the row carries enough context.
	 *
	 * @dataProvider provide_types
	 *
	 * @param string $type    Notification type slug.
	 * @param string $_needle Unused.
	 */
	public function test_every_type_resolves_url_or_empty( string $type, string $_needle ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$payload = $this->service->compose( $this->row( $type, array( 'object_id' => 1 ) ) );

		$this->assertArrayHasKey( 'url', $payload );
		// URL can be empty for purely informational types (bn.test, etc.), but
		// when non-empty it must be a valid URL string.
		if ( '' !== $payload['url'] ) {
			$this->assertNotFalse(
				filter_var( $payload['url'], FILTER_VALIDATE_URL ),
				"Type {$type} produced a non-URL string: {$payload['url']}"
			);
		}
	}

	/**
	 * Batch composer returns one composed payload per row.
	 */
	public function test_compose_batch_matches_input_size(): void {
		$rows = array(
			$this->row( 'bn.new_follower' ),
			$this->row( 'bn.post_reacted', array( 'object_id' => 1 ) ),
			$this->row( 'bn.member_suspended' ),
		);

		$out = $this->service->compose_batch( $rows );

		$this->assertCount( 3, $out );
		foreach ( $out as $payload ) {
			$this->assertArrayHasKey( 'message', $payload );
			$this->assertNotSame( '', $payload['message'] );
		}
	}

	/**
	 * Data JSON string is decoded transparently.
	 */
	public function test_data_json_string_is_decoded(): void {
		$payload = $this->service->compose(
			$this->row(
				'bn.post_reacted',
				array(
					'object_id' => 1,
					'data'      => wp_json_encode( array( 'emoji' => 'fire' ) ),
				)
			)
		);

		$this->assertStringContainsString( 'fire', $payload['message'] );
	}

	/**
	 * Unknown types still produce a non-empty message (development-friendly
	 * "Notification (type-slug)" form), never the legacy fallback.
	 */
	public function test_unknown_type_does_not_leak_legacy_fallback(): void {
		$payload = $this->service->compose( $this->row( 'bn.brand_new_unknown_type' ) );

		$this->assertNotSame( '', $payload['message'] );
		$this->assertStringNotContainsString( 'sent you a notification', $payload['message'] );
	}

	/**
	 * Bridge plugins can register copy via the buddynext_notification_message
	 * filter without forking the core service.
	 */
	public function test_filter_can_supply_message_for_unknown_type(): void {
		add_filter(
			'buddynext_notification_message',
			static function ( $message, $type ) {
				if ( 'bn.bridge_custom' === $type ) {
					return 'Bridge custom message.';
				}
				return $message;
			},
			10,
			5
		);

		$payload = $this->service->compose( $this->row( 'bn.bridge_custom' ) );

		$this->assertSame( 'Bridge custom message.', $payload['message'] );
	}
}
