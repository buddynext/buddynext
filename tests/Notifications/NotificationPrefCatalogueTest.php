<?php
/**
 * Tests for the notification preference catalogue.
 *
 * The catalogue MUST cover every type that NotificationMessageService::compose()
 * handles. Adding a type without a catalogue row would surface an unconfigurable
 * notification in the prefs UI — the catalogue is the single source of truth
 * for the UI's per-type rows.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Notifications\NotificationPrefCatalogue;
use ReflectionClass;

/**
 * @covers \BuddyNext\Notifications\NotificationPrefCatalogue
 */
class NotificationPrefCatalogueTest extends \WP_UnitTestCase {

	public function test_all_returns_a_keyed_map(): void {
		$catalogue = ( new NotificationPrefCatalogue() )->all();

		$this->assertIsArray( $catalogue );
		$this->assertNotEmpty( $catalogue );

		foreach ( $catalogue as $slug => $entry ) {
			$this->assertIsString( $slug );
			$this->assertSame( $slug, $entry['slug'] ?? null, "slug mismatch on {$slug}" );
			$this->assertArrayHasKey( 'label', $entry );
			$this->assertArrayHasKey( 'description', $entry );
			$this->assertArrayHasKey( 'group', $entry );
			$this->assertArrayHasKey( 'default_on_site', $entry );
			$this->assertArrayHasKey( 'default_email_freq', $entry );
			$this->assertArrayHasKey( 'can_email', $entry );

			$this->assertContains(
				$entry['default_email_freq'],
				array( 'immediate', 'daily', 'weekly', 'off' ),
				"invalid default_email_freq for {$slug}"
			);
		}
	}

	public function test_catalogue_covers_every_compose_single_type(): void {
		$compose_types = $this->collect_compose_single_types();

		// `bn.test` is dev-only and exempt from coverage (see spec).
		// `bn.new_message` has a catalogue row but is intentionally removed from
		// all() when the DM engine is unavailable (WPMediaVerse inactive in tests),
		// so exempt it the same way.
		$compose_types = array_diff( $compose_types, array( 'bn.test', 'bn.new_message' ) );

		// Aliases that share semantics — only one needs catalogue presence:
		// bn.space_request_approved <=> bn.space_join_approved (alias case).
		$alias_map = array(
			'bn.space_join_approved' => 'bn.space_request_approved',
		);
		foreach ( $alias_map as $alias => $canonical ) {
			if ( in_array( $alias, $compose_types, true ) && in_array( $canonical, $compose_types, true ) ) {
				$compose_types = array_diff( $compose_types, array( $alias ) );
			}
		}

		$catalogue = ( new NotificationPrefCatalogue() )->all();
		$cat_slugs = array_keys( $catalogue );
		$missing   = array_diff( $compose_types, $cat_slugs );

		$this->assertEmpty(
			$missing,
			'Catalogue is missing rows for types fired by NotificationMessageService: ' . implode( ', ', $missing )
		);
	}

	public function test_grouped_returns_six_known_groups(): void {
		$grouped = ( new NotificationPrefCatalogue() )->grouped();

		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_SOCIAL, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_FEED, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_SPACES, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_MESSAGES, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_MODERATION, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_GROWTH, $grouped );
	}

	public function test_resolve_for_user_fills_defaults_when_no_stored_rows(): void {
		$resolved = ( new NotificationPrefCatalogue() )->resolve_for_user( array() );

		$this->assertNotEmpty( $resolved );
		$this->assertArrayHasKey( 'bn.new_follower', $resolved );
		$this->assertTrue( $resolved['bn.new_follower']['on_site'] );
		$this->assertContains(
			$resolved['bn.new_follower']['email_freq'],
			array( 'immediate', 'daily', 'weekly', 'off' )
		);
	}

	public function test_resolve_for_user_overlays_stored_values(): void {
		$resolved = ( new NotificationPrefCatalogue() )->resolve_for_user(
			array(
				'bn.new_follower' => array(
					'on_site'    => false,
					'email_freq' => 'weekly',
				),
			)
		);

		$this->assertFalse( $resolved['bn.new_follower']['on_site'] );
		$this->assertSame( 'weekly', $resolved['bn.new_follower']['email_freq'] );
	}

	public function test_filter_can_add_a_type(): void {
		$cb = static function ( $cat ) {
			$cat['bn.bridge_demo'] = array(
				'slug'               => 'bn.bridge_demo',
				'label'              => 'Demo',
				'description'        => 'Demo description',
				'group'              => NotificationPrefCatalogue::GROUP_GROWTH,
				'default_on_site'    => true,
				'default_email_freq' => 'off',
				'can_email'          => false,
			);
			return $cat;
		};

		add_filter( 'buddynext_notification_prefs_catalogue', $cb );

		$catalogue = ( new NotificationPrefCatalogue() )->all();
		$this->assertArrayHasKey( 'bn.bridge_demo', $catalogue );

		remove_filter( 'buddynext_notification_prefs_catalogue', $cb );
	}

	/**
	 * Extract every `case 'bn.*':` slug handled by
	 * NotificationMessageService::compose_single() via static analysis.
	 *
	 * Using regex on the source keeps this test independent of the service's
	 * private method visibility and survives refactors of the switch order.
	 *
	 * @return array<int,string>
	 */
	private function collect_compose_single_types(): array {
		$ref  = new ReflectionClass( \BuddyNext\Notifications\NotificationMessageService::class );
		$file = (string) $ref->getFileName();
		if ( '' === $file || ! is_readable( $file ) ) {
			return array();
		}

		$source = (string) file_get_contents( $file );
		preg_match_all( "/case\s+'(bn\\.[a-z0-9_]+)'/i", $source, $m );

		return array_values( array_unique( $m[1] ?? array() ) );
	}
}
