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
 * Tests the notification preference catalogue (types, groups, resolution).
 *
 * @covers \BuddyNext\Notifications\NotificationPrefCatalogue
 */
class NotificationPrefCatalogueTest extends \WP_UnitTestCase {

	/**
	 * Returns a slug-keyed map with the full entry shape.
	 *
	 * @return void
	 */
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

	/**
	 * Every single-notification compose type has a catalogue row.
	 *
	 * @return void
	 */
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

	/**
	 * Buckets entries into the six known groups.
	 *
	 * @return void
	 */
	public function test_grouped_returns_six_known_groups(): void {
		$grouped = ( new NotificationPrefCatalogue() )->grouped();

		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_SOCIAL, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_FEED, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_SPACES, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_MESSAGES, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_MODERATION, $grouped );
		$this->assertArrayHasKey( NotificationPrefCatalogue::GROUP_GROWTH, $grouped );
	}

	/**
	 * Partner-sourced notifications are collect-only: BN mirrors them in the
	 * center for display but never emails on the integration's behalf (the
	 * integration owns its own email templates). So every integration-sourced
	 * type must be can_email=false — badges/level-ups come from wb-gamification,
	 * discussions from Jetonomy.
	 */
	public function test_partner_sourced_notifications_are_collect_only(): void {
		$catalogue = ( new NotificationPrefCatalogue() )->all();

		foreach ( array( 'bn.badge_awarded', 'bn.level_up', 'jt.notification' ) as $slug ) {
			if ( ! isset( $catalogue[ $slug ] ) ) {
				continue; // jt.notification is registered by the Jetonomy listener, may be absent here.
			}
			$this->assertFalse(
				$catalogue[ $slug ]['can_email'],
				"{$slug} is partner-sourced and must never be emailed by BN (collect-only)."
			);
		}
	}

	/**
	 * The digest master switch is a real, member-facing catalogue entry.
	 *
	 * Class-preventing guard for card 10264293350 (digest unsubscribe was a
	 * one-way door). The one-click unsubscribe writes a (user, 'digest',
	 * email_freq='off') row and the cron suppresses on it; if 'digest' is not a
	 * catalogue entry then get_all_prefs()/resolve_for_user() intersect it away
	 * and the REST validator rejects it, so no member-facing control can ever turn
	 * digests back on. Keeping it catalogued (with can_email so the email
	 * frequency renders, and email_only so no meaningless in-app toggle shows) is
	 * what makes the re-subscribe control exist on the settings page and in REST.
	 *
	 * @return void
	 */
	public function test_digest_master_switch_is_catalogued_and_resubscribable(): void {
		$catalogue = ( new NotificationPrefCatalogue() )->all();

		$this->assertArrayHasKey(
			'digest',
			$catalogue,
			'digest must be a catalogue entry or the unsubscribe is a one-way door.'
		);
		$this->assertTrue( $catalogue['digest']['can_email'], 'digest must render an email-frequency control.' );
		$this->assertTrue( ! empty( $catalogue['digest']['email_only'] ), 'digest is email-only (no in-app channel).' );

		// The re-subscribe row must be present even for a member with NO stored
		// prefs — that is the exact case after an unsubscribe writes only the
		// digest=off suppressor.
		$resolved = ( new NotificationPrefCatalogue() )->resolve_for_user( array() );
		$this->assertArrayHasKey( 'digest', $resolved, 'settings UI + REST must render a digest control by default.' );

		// A stored digest=off (the unsubscribe state) surfaces so the member sees
		// it is off and can flip it back.
		$off = ( new NotificationPrefCatalogue() )->resolve_for_user(
			array( 'digest' => array( 'email_freq' => 'off' ) )
		);
		$this->assertSame( 'off', $off['digest']['email_freq'] );
	}

	/**
	 * Fills tier defaults when the user has no stored rows.
	 *
	 * @return void
	 */
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

	/**
	 * Overlays the user stored values on the defaults.
	 *
	 * @return void
	 */
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

	/**
	 * The buddynext_notification_prefs_catalogue filter can add a type.
	 *
	 * @return void
	 */
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local plugin source file for static analysis, not a remote URL.
		$source = (string) file_get_contents( $file );
		preg_match_all( "/case\s+'(bn\\.[a-z0-9_]+)'/i", $source, $m );

		return array_values( array_unique( $m[1] ?? array() ) );
	}

	/**
	 * When the site owner defaults a type OFF, resolve_for_user() (the settings-page
	 * source) must reflect that for a member with no stored choice — matching the
	 * delivery path, not the blanket catalogue "on" (card 10268684581).
	 *
	 * @return void
	 */
	public function test_resolve_for_user_honours_the_admin_default(): void {
		$catalogue = new NotificationPrefCatalogue();

		// Baseline: 'New follower' defaults on.
		$this->assertTrue( $catalogue->effective_default_on_site( 'bn.new_follower' ) );
		$before = $catalogue->resolve_for_user( array() );
		$this->assertTrue( (bool) $before['bn.new_follower']['on_site'] );

		// Owner turns the default off.
		update_option( 'buddynext_notif_default_follow', '0' );

		$this->assertFalse( $catalogue->effective_default_on_site( 'bn.new_follower' ), 'The effective default must follow the owner option.' );
		$after = $catalogue->resolve_for_user( array() );
		$this->assertFalse( (bool) $after['bn.new_follower']['on_site'], 'The settings page must show the type OFF once the owner defaults it off.' );

		// A member who explicitly turned it back ON still wins over the admin default.
		$stored = $catalogue->resolve_for_user( array( 'bn.new_follower' => array( 'on_site' => true ) ) );
		$this->assertTrue( (bool) $stored['bn.new_follower']['on_site'], 'A stored member choice overrides the admin default.' );

		delete_option( 'buddynext_notif_default_follow' );
	}

	/**
	 * Flatten grouped() into a slug list.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $grouped Grouped catalogue.
	 * @return array<int, string>
	 */
	private function grouped_slugs( array $grouped ): array {
		$slugs = array();
		foreach ( $grouped as $entries ) {
			foreach ( $entries as $entry ) {
				$slugs[] = (string) ( $entry['slug'] ?? '' );
			}
		}
		return $slugs;
	}

	/**
	 * grouped() hides moderator-only rows from a plain member but shows them to an
	 * admin, while all() keeps every type for both (delivery must not be filtered).
	 *
	 * @return void
	 */
	public function test_grouped_hides_moderator_only_rows_from_members(): void {
		$catalogue = new NotificationPrefCatalogue();

		// all() always carries the moderator-only types — delivery relies on it.
		$this->assertArrayHasKey( 'bn.new_report', $catalogue->all() );
		$this->assertArrayHasKey( 'bn.appeal_submitted', $catalogue->all() );

		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member );
		$member_slugs = $this->grouped_slugs( $catalogue->grouped() );
		$this->assertNotContains( 'bn.new_report', $member_slugs, 'A member must not see the moderator-only "New reports to review" row.' );
		$this->assertNotContains( 'bn.appeal_submitted', $member_slugs, 'A member must not see the moderator-only "Appeal received" row.' );
		// A recipient-facing moderation row is still shown.
		$this->assertContains( 'bn.member_suspended', $member_slugs, 'Member-facing moderation rows stay visible.' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$admin_slugs = $this->grouped_slugs( $catalogue->grouped() );
		$this->assertContains( 'bn.new_report', $admin_slugs, 'A moderator must see the moderator-only rows.' );
		$this->assertContains( 'bn.appeal_submitted', $admin_slugs, 'A moderator must see the moderator-only rows.' );

		wp_set_current_user( 0 );
	}
}
