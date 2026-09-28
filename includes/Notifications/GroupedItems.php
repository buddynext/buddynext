<?php
/**
 * The people behind a grouped bell row.
 *
 * A row that reads "Aisha and 2 others replied" merges every event on one object.
 * It stores each event as an item (who, and which thing they did), newest first, in
 * the row's data. The count is people, not events, so one member replying twice is
 * one person however the others interleave, and each item can be checked on its
 * own: a trashed reply or a banned replier drops out of the row instead of leaving
 * a sentence nobody can open.
 *
 * @package BuddyNext\Notifications
 * @since 1.2.2
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

/**
 * Builds and reads the item list stored in a grouped row's data.
 */
final class GroupedItems {

	/**
	 * Items kept per row. A hot topic can draw hundreds of repliers inside the
	 * merge window; beyond this the oldest go and are counted in `more`.
	 */
	private const CAP = 50;

	/**
	 * Whether a row's count is people ("X and N others"), as opposed to a tally of
	 * events (reports waiting), which must keep counting every one.
	 *
	 * @param string              $type Notification type.
	 * @param array<string,mixed> $data create() input.
	 * @return bool
	 */
	public static function is_people_row( string $type, array $data ): bool {
		return NotificationMessageService::supports_group_collapse( $type )
			|| '' !== (string) ( $data['data']['message_grouped'] ?? '' );
	}

	/**
	 * Add one event to a row's stored data.
	 *
	 * @param array<string,mixed> $stored      The row's decoded data (empty for a new row).
	 * @param int                 $prev_sender The row's current newest sender, 0 for a new row.
	 * @param int                 $actor       Who did it.
	 * @param string              $item_type   The plugin's type for the thing they did ('reply'), or ''.
	 * @param int                 $item_id     Its id, or 0.
	 * @return array<string,mixed> The data with `items` (and `more`) updated.
	 */
	public static function add( array $stored, int $prev_sender, int $actor, string $item_type, int $item_id ): array {
		$items = is_array( $stored['items'] ?? null ) ? $stored['items'] : array();
		if ( ! $items && $prev_sender > 0 ) {
			// A row written before items were kept: its one known person.
			$items[] = array(
				'a' => $prev_sender,
				't' => '',
				'i' => 0,
			);
		}

		$new = array(
			'a' => $actor,
			't' => $item_type,
			'i' => $item_id,
		);
		// Field by field: the JSON column returns keys in its own order, so whole-array
		// identity would miss a repeat of the same event.
		$items = array_values(
			array_filter(
				$items,
				static fn( $item ): bool => ! ( (int) $item['a'] === $actor && (string) $item['t'] === $item_type && (int) $item['i'] === $item_id )
			)
		);
		array_unshift( $items, $new );

		if ( count( $items ) > self::CAP ) {
			$kept           = array_slice( $items, 0, self::CAP );
			$dropped        = array_diff( array_column( array_slice( $items, self::CAP ), 'a' ), array_column( $kept, 'a' ) );
			$stored['more'] = (int) ( $stored['more'] ?? 0 ) + count( array_unique( $dropped ) );
			$items          = $kept;
		}

		$stored['items'] = $items;
		return $stored;
	}

	/**
	 * How many people a row stands for.
	 *
	 * @param array<string,mixed> $stored The row's decoded data.
	 * @return int
	 */
	public static function people( array $stored ): int {
		$items = is_array( $stored['items'] ?? null ) ? $stored['items'] : array();
		return count( array_unique( array_column( $items, 'a' ) ) ) + (int) ( $stored['more'] ?? 0 );
	}
}
