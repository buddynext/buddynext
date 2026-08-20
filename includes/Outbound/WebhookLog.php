<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The audit trail for access grants, whichever door they came through.
 *
 * `bn_webhook_log` used to be written from exactly one place: the private
 * `log()` inside AccessWebhookController, called once, at the end of the HTTP
 * request handler. That was the whole story while the signed webhook was the
 * only way in.
 *
 * It is not any more. `buddynext_ability_granted` is a plain action, and a
 * plugin on the same site — a membership plugin, a storefront, an LMS — grants
 * by firing it directly, with no HTTP hop to log. Those grants were landing
 * completely unrecorded: no row, no source, no payload, nothing to correlate a
 * member's access against afterwards. The door BuddyNext most wants third
 * parties to use was the one door it did not watch.
 *
 * So the write moves here, where anything can reach it, and the controller
 * becomes one of its callers rather than its owner.
 *
 * @package BuddyNext\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Outbound;

/**
 * Writes rows to `bn_webhook_log`.
 */
class WebhookLog {

	/**
	 * A grant, revoke or other access change that did what it said.
	 */
	public const STATUS_SUCCESS = 'success';

	/**
	 * An access change that was refused, or that resolved to nothing.
	 */
	public const STATUS_ERROR = 'error';

	/**
	 * Whether an HTTP webhook request is mid-dispatch.
	 *
	 * @var bool
	 */
	private static bool $in_request = false;

	/**
	 * Mark the start of a signed-webhook dispatch.
	 *
	 * @return void
	 */
	public static function begin_request_scope(): void {
		self::$in_request = true;
	}

	/**
	 * Mark the end of a signed-webhook dispatch.
	 *
	 * @return void
	 */
	public static function end_request_scope(): void {
		self::$in_request = false;
	}

	/**
	 * Whether the current grant arrived over the signed webhook.
	 *
	 * The listener uses this to stay quiet while the controller is handling a
	 * request it will log itself, in full.
	 *
	 * @return bool
	 */
	public static function in_request_scope(): bool {
		return self::$in_request;
	}

	/**
	 * Record one access-management call.
	 *
	 * Pro and third-party bridges call this rather than touching the table:
	 * `bn_webhook_log` is a Free table, and a plugin writing into another
	 * plugin's table is the failure `docs/standards/FREE-PRO-SEAM.md` exists to
	 * stop. Guard the call with `class_exists()` — Pro can be active while an
	 * older Free is installed.
	 *
	 * @since 1.1.6
	 *
	 * @param string              $action  Action slug ('grant_ability', 'revoke_ability', …).
	 * @param int                 $user_id User the call was about (0 when unresolved).
	 * @param array<string,mixed> $payload Everything worth keeping, stored as JSON.
	 *                                     A `source` key is lifted into its own column.
	 * @param string              $status  self::STATUS_SUCCESS | self::STATUS_ERROR.
	 * @param string              $signature Signature the call was accepted on; '' when there is none.
	 * @return void
	 */
	public static function write( string $action, int $user_id, array $payload, string $status = self::STATUS_SUCCESS, string $signature = '' ): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		// created_at is written explicitly, in UTC.
		//
		// The column's schema default is CURRENT_TIMESTAMP, which MySQL resolves in
		// the DATABASE SERVER's timezone, and every other timestamp BuddyNext
		// stores is gmdate(). An audit log exists to be correlated against other
		// records — "this webhook granted that subscription" — and on a host whose
		// database is not on UTC it was offset from everything it would be read
		// beside. Observed at +5:30: a call at 16:57 UTC logged as 22:27.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'bn_webhook_log',
			array(
				'source'     => sanitize_key( (string) ( $payload['source'] ?? '' ) ),
				'action'     => $action,
				'user_id'    => $user_id,
				'payload'    => wp_json_encode( $payload ),
				'status'     => self::STATUS_ERROR === $status ? self::STATUS_ERROR : self::STATUS_SUCCESS,
				// The signature the request was accepted on, so a replay of the same
				// captured call can be recognised. Empty for anything with no
				// signature to replay: in-process grants, and legacy body-only
				// requests, which cannot be replay-checked at all.
				'signature'  => '' !== $signature ? $signature : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
