<?php
/**
 * Async notification payloads survive the trip through Action Scheduler.
 *
 * Action Scheduler's second parameter is the callback's ARGUMENT LIST, not a
 * payload, and ActionScheduler_Action::execute() dispatches it as
 * `do_action_ref_array( $hook, array_values( $this->get_args() ) )`. That
 * array_values() throws the string keys away, so an associative map is
 * flattened to a positional list and spread across the callback's parameters.
 *
 * It is NOT WordPress's array_slice() in class-wp-hook.php — array_slice always
 * preserves string keys. That is the obvious suspect and the wrong one; this
 * file pins the real mechanism so nobody re-derives it from the wrong end.
 *
 * Three hooks here take a single `array $args` parameter, and all three were
 * enqueued with a bare associative array. Each therefore received its first
 * VALUE (an int post id) where it declared an array: TypeError, action failed,
 * nothing delivered. It was silent — the in-app notification is written before
 * the email stage, and the failure only exists in the queue log. On the dev
 * site buddynext_async_space_post_emails had 63 failed runs and zero completed.
 *
 * These tests deliberately assert at the ENQUEUE BOUNDARY. Calling the
 * callbacks directly proves nothing: the inline no-Action-Scheduler fallback
 * passes $args straight through and was always correct, which is exactly why
 * the bug read as fine and survived.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

/**
 * Payload shape handed to Action Scheduler for the fan-out and email stages.
 *
 * @covers \BuddyNext\Notifications\NotificationListener
 */
class AsyncPayloadShapeTest extends \WP_UnitTestCase {

	/**
	 * Hooks whose callback takes ONE array parameter, so the enqueued argument
	 * list must be a single-element list wrapping that array.
	 *
	 * @var array<int,string>
	 */
	private const SINGLE_ARRAY_ARG_HOOKS = array(
		'buddynext_async_space_post_fanout',
		'buddynext_async_announcement_fanout',
		'buddynext_async_space_post_emails',
	);

	/**
	 * Source file scanned for the enqueue call sites.
	 *
	 * @return string
	 */
	private function listener_source(): string {
		$path = BUDDYNEXT_DIR . 'includes/Notifications/NotificationListener.php';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	/**
	 * Simulate what a callback actually receives for a given enqueued arg list.
	 *
	 * Mirrors the real path: ActionScheduler_Action::execute() flattens with
	 * array_values(), then WP_Hook trims to accepted_args with array_slice.
	 *
	 * @param array<mixed> $enqueued      The argument list passed to Action Scheduler.
	 * @param int          $accepted_args The hook's accepted_args.
	 * @return array<int,mixed> Positional arguments the callback receives.
	 */
	private function as_dispatched( array $enqueued, int $accepted_args ): array {
		return array_slice( array_values( $enqueued ), 0, $accepted_args );
	}

	/**
	 * The reindex that caused the bug is real, not folklore.
	 *
	 * If this ever stops being true, the wraps below become unnecessary — so pin
	 * the assumption rather than leaving it as a comment nobody can re-check.
	 *
	 * @return void
	 */
	public function test_action_scheduler_flattens_string_keys(): void {
		$payload = array(
			'post_id'   => 42,
			'space_id'  => 7,
			'author_id' => 3,
		);

		$received = $this->as_dispatched( $payload, 1 );

		$this->assertSame(
			array( 42 ),
			$received,
			'array_values() must flatten the map — this is why a bare associative payload arrives as an int'
		);
		$this->assertIsInt( $received[0], 'the first argument is the first VALUE, not the map' );

		// And the half that is commonly blamed but innocent: array_slice on its
		// own keeps string keys, so it is not what breaks the payload.
		$this->assertSame(
			array( 'post_id' => 42 ),
			array_slice( $payload, 0, 1 ),
			'array_slice preserves string keys; blaming it sends the next reader down the wrong path'
		);
	}

	/**
	 * Wrapping delivers the map intact; not wrapping delivers an int.
	 *
	 * The two halves of the bug and its fix, side by side.
	 *
	 * @return void
	 */
	public function test_wrapping_is_what_preserves_the_map(): void {
		$payload = array(
			'post_id'    => 42,
			'recipients' => array( 1, 2, 3 ),
		);

		$unwrapped = $this->as_dispatched( $payload, 1 );
		$wrapped   = $this->as_dispatched( array( $payload ), 1 );

		$this->assertIsInt( $unwrapped[0], 'bare payload: callback gets an int and TypeErrors on array $args' );
		$this->assertSame( $payload, $wrapped[0], 'wrapped payload: callback gets the map it declared' );
	}

	/**
	 * Every callback in SINGLE_ARRAY_ARG_HOOKS really does take one array.
	 *
	 * The wrap requirement follows from the signature, so if a signature changes
	 * to positional parameters the wrap must go too. Pin the premise.
	 *
	 * @return void
	 */
	public function test_the_callbacks_take_a_single_array_parameter(): void {
		$source = $this->listener_source();

		$methods = array(
			'async_space_post_fanout',
			'async_announcement_fanout',
			'async_space_post_emails',
		);

		foreach ( $methods as $method ) {
			$this->assertMatchesRegularExpression(
				'/public function ' . preg_quote( $method, '/' ) . '\(\s*array \$args\s*\)/',
				$source,
				$method . ' must take a single array parameter, or its enqueue must stop wrapping'
			);
		}

		// And each is registered for exactly ONE accepted arg, which is what trims
		// the flattened list down to a single element.
		foreach ( self::SINGLE_ARRAY_ARG_HOOKS as $hook ) {
			$this->assertMatchesRegularExpression(
				"/add_action\(\s*'" . preg_quote( $hook, '/' ) . "',[^;]*,\s*10,\s*1\s*\)/s",
				$source,
				$hook . ' must be registered with accepted_args = 1'
			);
		}
	}

	/**
	 * No enqueue of these hooks passes a bare associative array.
	 *
	 * This is the regression guard proper: it reads the real call sites and fails
	 * if any of them hands over a map without the wrap.
	 *
	 * @return void
	 */
	public function test_no_enqueue_passes_a_bare_associative_payload(): void {
		$source = $this->listener_source();

		foreach ( self::SINGLE_ARRAY_ARG_HOOKS as $hook ) {
			// Grab the argument-list expression between the hook name and the group.
			$pattern = "/as_enqueue_async_action\(\s*'" . preg_quote( $hook, '/' ) . "',\s*(.+?),\s*'buddynext'\s*\)/s";
			preg_match_all( $pattern, $source, $matches );

			$this->assertNotEmpty( $matches[1], 'expected at least one enqueue of ' . $hook );

			foreach ( $matches[1] as $arg_expression ) {
				$normalised = preg_replace( '/\s+/', ' ', trim( $arg_expression ) );

				$this->assertDoesNotMatchRegularExpression(
					"/^array\(\s*'[a-z_]+'\s*=>/",
					(string) $normalised,
					$hook . ' is enqueued with a bare associative array. Action Scheduler flattens it '
					. '(array_values in ActionScheduler_Action::execute), so the callback receives an '
					. 'int where it declares array $args and the action fails silently. Wrap it: array( $args ).'
				);
			}
		}
	}

	/**
	 * The emails stage specifically wraps its payload.
	 *
	 * Named separately because this is the one with 63 recorded failures.
	 *
	 * @return void
	 */
	public function test_the_email_stage_wraps_its_payload(): void {
		$this->assertStringContainsString(
			"as_enqueue_async_action( 'buddynext_async_space_post_emails', array( \$args ), 'buddynext' );",
			$this->listener_source(),
			'the email stage must enqueue array( $args ), not $args'
		);
	}
}
