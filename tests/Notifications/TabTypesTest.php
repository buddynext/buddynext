<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The notifications page tabs come from one map, NotificationService::TAB_TYPES,
 * which the page also hands to the store (card 10427033336). These keep the map
 * honest: every type it names is real, and a new space notification cannot be
 * left off the Spaces tab. Moderation, digest and partner types are deliberately
 * tabless; they show under All and Unread.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Notifications\NotificationPrefCatalogue;
use BuddyNext\Notifications\NotificationService;

/**
 * @coversNothing Data contract between TAB_TYPES and the notification catalogue.
 */
class TabTypesTest extends \WP_UnitTestCase {

	public function test_every_core_tab_type_is_a_catalogued_type(): void {
		$catalogue = ( new NotificationPrefCatalogue() )->all();
		// Only the tabs this site shows; Messages drops out with DMs off, as does its type.
		foreach ( array_intersect_key( NotificationService::TAB_TYPES, array_flip( NotificationService::inbox_filters() ) ) as $tab => $types ) {
			// Partner types are catalogued only while their plugin declares them.
			foreach ( array_filter( $types, static fn( string $t ): bool => str_starts_with( $t, 'bn.' ) ) as $type ) {
				$this->assertArrayHasKey( $type, $catalogue, "{$type} on the {$tab} tab is not a notification type." );
			}
		}
	}

	public function test_every_core_space_type_is_on_the_spaces_tab(): void {
		foreach ( ( new NotificationPrefCatalogue() )->all() as $type => $entry ) {
			if ( 'spaces' === ( $entry['group'] ?? '' ) && str_starts_with( $type, 'bn.' ) ) {
				$this->assertContains( $type, NotificationService::TAB_TYPES['space'], "{$type} is missing from the Spaces tab." );
			}
		}
	}

	public function test_a_type_sits_on_one_tab_only(): void {
		$all = array_merge( ...array_values( NotificationService::TAB_TYPES ) );
		$this->assertSame( array_unique( $all ), $all );
	}
}
