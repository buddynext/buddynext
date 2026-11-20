<?php
/**
 * Give the bridge cards published before 1.3.0 their facts line.
 *
 * @package BuddyNext
 */

declare(strict_types=1);

namespace BuddyNext\Feed;

use BuddyNext\Contracts\ListenerInterface;

/**
 * A card only carries facts once its bridge wrote them when publishing, so a site that updates
 * would show none until each partner item was saved again. This fills the cards that have no
 * facts yet, in the background, from each partner's current data.
 *
 * A bridge registers a builder for its card type on `buddynext_card_facts_backfill`
 * (`type => callable( array $card ): array`, the card row being id, user_id, type, link_url).
 * Nothing runs without a builder. A card is written once (an empty list counts), so a card
 * whose partner has no facts is not visited again; the batch is idempotent.
 *
 * @since 1.3.0
 */
final class CardFactsBackfill implements ListenerInterface {

	/**
	 * Action Scheduler hook for one batch.
	 *
	 * @since 1.3.0
	 */
	public const HOOK = 'buddynext_backfill_card_facts';

	/**
	 * Cards handled per batch.
	 *
	 * @since 1.3.0
	 */
	private const BATCH = 50;

	/**
	 * How long a "nothing to do" check stands before the admin looks again.
	 *
	 * @since 1.3.0
	 */
	private const RECHECK = 12 * HOUR_IN_SECONDS;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
		add_action( 'admin_init', array( $this, 'maybe_queue' ) );
	}

	/**
	 * Queue the backfill when a bridge asked for one, at most twice a day.
	 *
	 * @return void
	 */
	public function maybe_queue(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || false !== get_transient( 'bn_card_facts_checked' ) ) {
			return;
		}
		set_transient( 'bn_card_facts_checked', 1, self::RECHECK );

		if ( array() === self::builders() || ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, null, 'buddynext' ) ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK, array( 0 ), 'buddynext' );
	}

	/**
	 * The builders bridges registered, by card type.
	 *
	 * @return array<string,callable>
	 */
	private static function builders(): array {
		/**
		 * Register how a card type gets its facts for cards that have none yet.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string,callable> $builders Card type => callable( array $card ): array of facts.
		 */
		$builders = (array) apply_filters( 'buddynext_card_facts_backfill', array() );
		return array_filter( $builders, 'is_callable' );
	}

	/**
	 * One batch: cards after the cursor that have no facts key, oldest id first.
	 *
	 * @param int $cursor Last card id handled.
	 * @return void
	 */
	public function run( $cursor = 0 ): void {
		global $wpdb;

		$builders = self::builders();
		if ( array() === $builders ) {
			return;
		}

		$types = array_keys( $builders );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built from the registered types.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, type, link_url FROM {$wpdb->prefix}bn_posts
				 WHERE type IN ({$in}) AND id > %d AND ( link_meta IS NULL OR link_meta NOT LIKE %s )
				 ORDER BY id ASC LIMIT %d",
				array_merge( $types, array( (int) $cursor, '%' . $wpdb->esc_like( '"facts"' ) . '%', self::BATCH ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$last = (int) $cursor;
		foreach ( $rows as $card ) {
			$last  = (int) $card['id'];
			$facts = call_user_func( $builders[ (string) $card['type'] ], $card );
			IntegrationActivity::refresh( (string) $card['link_url'], (string) $card['type'], array( 'facts' => is_array( $facts ) ? IntegrationActivity::facts( $facts ) : array() ) );
		}

		if ( self::BATCH === count( $rows ) && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $last ), 'buddynext' );
		}
	}
}
