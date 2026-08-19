<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Records access grants that arrive without an HTTP request behind them.
 *
 * The signed webhook logs itself, in full, from AccessWebhookController. Nothing
 * logged the other door: `buddynext_ability_granted` is a plain action, and a
 * plugin on the same site grants by firing it directly — a membership plugin
 * mirroring a level, a storefront completing an order, an LMS enrolling someone.
 * Those grants left no trace at all, which is the one thing an audit log exists
 * to prevent, and it was the door BuddyNext most wants third parties to use.
 *
 * This listener is deliberately in Free. Putting it in Pro would have meant a
 * Free-only site silently losing its grant trail, and a membership plugin
 * granting into Free alone is a perfectly ordinary setup.
 *
 * @package BuddyNext\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Outbound;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Logs ability grants and revokes fired outside a webhook request.
 */
class WebhookLogListener implements ListenerInterface {

	/**
	 * Attach to the grant and revoke actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'buddynext_ability_granted', array( $this, 'on_granted' ), 10, 3 );
		add_action( 'buddynext_ability_revoked', array( $this, 'on_revoked' ), 10, 2 );
	}

	/**
	 * Record an in-process grant.
	 *
	 * @param int    $user_id User receiving the ability.
	 * @param string $ability Ability slug.
	 * @param string $source  Source tag; '' when the caller supplied none.
	 * @return void
	 */
	public function on_granted( int $user_id, string $ability, string $source = '' ): void {
		$this->record( 'grant_ability', $user_id, $ability, $source );
	}

	/**
	 * Record an in-process revoke.
	 *
	 * @param int    $user_id User losing the ability.
	 * @param string $ability Ability slug.
	 * @return void
	 */
	public function on_revoked( int $user_id, string $ability ): void {
		$this->record( 'revoke_ability', $user_id, $ability, '' );
	}

	/**
	 * Write the row, unless the webhook controller is already going to.
	 *
	 * @param string $action  Log action slug.
	 * @param int    $user_id User the change is about.
	 * @param string $ability Ability slug.
	 * @param string $source  Source tag.
	 * @return void
	 */
	private function record( string $action, int $user_id, string $ability, string $source ): void {
		if ( WebhookLog::in_request_scope() ) {
			return;
		}

		WebhookLog::write(
			$action,
			$user_id,
			array(
				'ability' => $ability,
				// An in-process caller that names itself is worth far more than one
				// that does not: it is the difference between "something granted
				// this" and "WooCommerce granted this". Absent stays absent rather
				// than being guessed at.
				'source'  => $source,
				'via'     => 'action',
			),
			WebhookLog::STATUS_SUCCESS
		);
	}
}
