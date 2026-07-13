<?php
/**
 * Tests that an automated `suspend` moderation action actually suspends.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * Regression cover for the automated suspend action.
 *
 * Pro's RulesService has always been able to emit a `suspend` action — it is in
 * THRESHOLD_ACTIONS, its config validation accepts it, and the admin UI offers it. Free's
 * apply_auto_actions() switch had `remove` and `warn`, no `suspend`, and NO `default`.
 *
 * So the action fell straight through in silence. An owner could configure an
 * auto-suspend rule, save it, see it listed in the admin, and it would never once fire.
 * No error, no log line, nothing. Free's own docblock four lines above the switch already
 * documented the payload it was ignoring.
 *
 * @covers \BuddyNext\Moderation\ModerationService
 */
class AutoSuspendActionTest extends \WP_UnitTestCase {

	/**
	 * The offender.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Service under test.
	 *
	 * @var ModerationService
	 */
	private ModerationService $service;

	/**
	 * Fresh schema + a member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service = new ModerationService();
		$this->user_id = (int) self::factory()->user->create();
	}

	/**
	 * Run the automated-action pipeline with the given action descriptor.
	 *
	 * Drives the real seam Pro drives: the `buddynext_moderation_auto_actions` filter
	 * that apply_auto_actions() reads.
	 *
	 * @param array<string, mixed> $action Action descriptor as RulesService emits it.
	 * @return void
	 */
	private function fire_auto_action( array $action ): void {
		// The same seam Pro's RulesService answers on.
		$inject = static fn(): array => array( $action );

		$reporter = (int) self::factory()->user->create();

		add_filter( 'buddynext_moderation_auto_actions', $inject, 10, 0 );
		$this->service->report( $reporter, 'post', 1, 'spam' );
		remove_filter( 'buddynext_moderation_auto_actions', $inject, 10 );
	}

	/**
	 * The active suspension row for the member, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function active_suspension(): ?array {
		$row = $this->service->get_active_suspension( $this->user_id );

		return is_array( $row ) ? $row : ( is_object( $row ) ? (array) $row : null );
	}

	/**
	 * THE BUG: a configured auto-suspend rule must actually suspend the member.
	 *
	 * @return void
	 */
	public function test_an_automated_suspend_action_suspends_the_member(): void {
		$this->assertNull( $this->active_suspension(), 'precondition: not suspended' );

		$this->fire_auto_action(
			array(
				'action'        => 'suspend',
				'user_id'       => $this->user_id,
				'reason'        => 'Automated: spam threshold.',
				'duration_days' => 7,
			)
		);

		$this->assertNotNull(
			$this->active_suspension(),
			'The rule was configured, saved and listed in the admin — and did nothing. Free\'s switch had no `suspend` case and no `default`, so the action was swallowed in silence.'
		);
	}

	/**
	 * THE TRAP: the suspension must be TEMPORARY, not a permanent ban.
	 *
	 * A duration of 0 (or an absent key) means PERMANENT in Free. Pro warns about exactly
	 * this: "without duration_days Free's ModerationService creates a permanent ban that
	 * only a manual unsuspend can lift." A rule the owner configured as 7 days must never
	 * become a lifetime ban because a key went missing in transit.
	 *
	 * @return void
	 */
	public function test_the_suspension_honours_duration_and_is_not_permanent(): void {
		$this->fire_auto_action(
			array(
				'action'        => 'suspend',
				'user_id'       => $this->user_id,
				'reason'        => 'Automated: spam threshold.',
				'duration_days' => 7,
			)
		);

		$row = $this->active_suspension();
		$this->assertNotNull( $row );
		$this->assertNotEmpty(
			$row['expires_at'] ?? null,
			'expires_at is NULL — the member was banned PERMANENTLY on a rule the owner configured as 7 days. That is worse than the bug this fixes.'
		);
	}

	/**
	 * A descriptor with no duration must NOT fall through to a permanent ban.
	 *
	 * @return void
	 */
	public function test_a_missing_duration_does_not_become_a_permanent_ban(): void {
		$this->fire_auto_action(
			array(
				'action'  => 'suspend',
				'user_id' => $this->user_id,
				'reason'  => 'Automated: no duration supplied.',
			)
		);

		$row = $this->active_suspension();
		$this->assertNotNull( $row );
		$this->assertNotEmpty(
			$row['expires_at'] ?? null,
			'A missing duration_days silently produced a PERMANENT ban. Default to a finite window, never to forever.'
		);
	}

	/**
	 * The system actor (0) must not be refused by the admin capability gate.
	 *
	 * Because suspend_user() checks user_can( $actor_id, 'manage_options' ), and
	 * user_can( 0, ... ) is always false — so an automated rule would fail the
	 * permission check and silently do nothing. That is the same bug one layer down.
	 *
	 * @return void
	 */
	public function test_the_system_actor_is_not_blocked_by_the_capability_check(): void {
		$result = $this->service->suspend_user( $this->user_id, 0, 'System suspension.', array( 'duration_days' => 3 ) );

		$this->assertNotWPError(
			$result,
			'The system actor (0) was refused by the admin capability gate. An automated rule can never be a logged-in admin.'
		);
	}
}
