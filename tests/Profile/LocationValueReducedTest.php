<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A location field must publish an area, never cm-precision coordinates.
 *
 * A member who shares a location expects others to see the place they chose (an
 * address / area). They do not expect every viewer — and every REST/app client —
 * to receive their exact latitude and longitude. That precision is the owner's
 * own data: it reaches them via value_raw for the edit map, and nobody else.
 *
 * The Pro map type stores {"address":"…","lat":…,"lng":…}. get_profile() emitted
 * `value` straight from that row, so the JSON blob — coordinates included — reached
 * strangers and the API under the member's name. This is the same class of leak the
 * Date "Age only" bug had, and the fix is at the same seam: view_value() reduces the
 * value where it ENTERS the payload. Location's reducer is Pro's
 * buddynext_field_rest_value shaper; Free's job is to route location through it.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use WP_UnitTestCase;

/**
 * The profile payload must carry a location's address, never its exact point.
 */
class LocationValueReducedTest extends WP_UnitTestCase {

	private const LOCATION_JSON = '{"address":"Pune, Maharashtra, India","lat":18.5204,"lng":73.8567}';
	private const ADDRESS       = 'Pune, Maharashtra, India';

	/**
	 * Stand in for Pro's shaper: decode the blob, return the address.
	 *
	 * Pro's AdvancedFieldRenderer::rest_value_filter does exactly this via
	 * location_display_text; that half is covered in the Pro suite. Here we prove
	 * Free hands location to the seam at all.
	 *
	 * @var callable|null
	 */
	private $shaper;

	/**
	 * Remove any shaper a test registered.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->shaper ) {
			remove_filter( 'buddynext_field_rest_value', $this->shaper, 10 );
			$this->shaper = null;
		}
		parent::tear_down();
	}

	/**
	 * Register a location shaper on the REST-value seam.
	 *
	 * @return void
	 */
	private function register_shaper(): void {
		$this->shaper = static function ( $custom, array $field, $value ) {
			if ( null !== $custom || 'location' !== ( $field['type'] ?? '' ) ) {
				return $custom;
			}
			$decoded = json_decode( is_string( $value ) ? $value : '', true );
			return is_array( $decoded ) && isset( $decoded['address'] )
				? (string) $decoded['address']
				: $value;
		};
		add_filter( 'buddynext_field_rest_value', $this->shaper, 10, 3 );
	}

	/**
	 * view_value() routes a location through the REST-value seam and returns the
	 * reduced address — never the coordinates.
	 *
	 * @return void
	 */
	public function test_view_value_reduces_a_location_to_its_address(): void {
		$this->register_shaper();

		$out = $this->call_view_value( array( 'type' => 'location' ), self::LOCATION_JSON );

		$this->assertSame( self::ADDRESS, $out );
		$this->assertStringNotContainsString( '18.5204', (string) $out, 'The latitude reached the payload.' );
		$this->assertStringNotContainsString( '73.8567', (string) $out, 'The longitude reached the payload.' );
	}

	/**
	 * THE MUTATION GUARD. Remove the apply_filters() line in view_value() and this
	 * fails: with no shaper the raw blob would pass through, but the seam call is
	 * what a shaper hooks — so with a shaper present, an un-wired view_value() would
	 * return the raw JSON instead of the address.
	 *
	 * @return void
	 */
	public function test_a_registered_shaper_actually_changes_the_output(): void {
		$before = $this->call_view_value( array( 'type' => 'location' ), self::LOCATION_JSON );
		$this->assertSame( self::LOCATION_JSON, $before, 'With no shaper, Free must not depend on Pro — the value passes through.' );

		$this->register_shaper();
		$after = $this->call_view_value( array( 'type' => 'location' ), self::LOCATION_JSON );

		$this->assertNotSame( $before, $after, 'view_value() ignored the shaper — the location seam is not wired.' );
	}

	/**
	 * The reduction is scoped to location. A non-location value must NOT be run
	 * through the location seam, or a multi-select / number / text value would be
	 * reshaped behind this fix's back.
	 *
	 * @return void
	 */
	public function test_a_non_location_type_is_left_untouched(): void {
		// A shaper that would fire for ANY type if view_value called it for text.
		$greedy = static function ( $custom, array $field, $value ) {
			return 'MUTATED';
		};
		add_filter( 'buddynext_field_rest_value', $greedy, 10, 3 );

		$out = $this->call_view_value( array( 'type' => 'text' ), 'hello world' );

		remove_filter( 'buddynext_field_rest_value', $greedy, 10 );

		$this->assertSame( 'hello world', $out, 'A text value was run through the location REST-value seam.' );
	}

	/**
	 * THE BROWSER CHECK, IN A TEST. The live profile payload — what view.php prints
	 * and every REST/app client reads — must carry the address for a stranger and
	 * never the coordinates, while the owner keeps the exact point via value_raw.
	 *
	 * @return void
	 */
	public function test_the_profile_payload_never_carries_coordinates_for_a_stranger(): void {
		global $wpdb;

		\BuddyNext\Core\Installer::install_schema();
		$this->register_shaper();

		$owner    = self::factory()->user->create();
		$stranger = self::factory()->user->create();

		$group_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_groups ORDER BY id ASC LIMIT 1" );
		if ( $group_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_profile_groups',
				array(
					'group_key'  => 'about-loc-probe',
					'label'      => 'About',
					'type'       => 'flat',
					'visibility' => 'public',
				)
			);
			$group_id = (int) $wpdb->insert_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'   => $group_id,
				'field_key'  => 'loc_probe',
				'label'      => 'Location',
				'type'       => 'location',
				'visibility' => 'public',
			)
		);
		$field_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_values',
			array(
				'user_id'     => $owner,
				'field_id'    => $field_id,
				'entry_index' => 0,
				'value'       => self::LOCATION_JSON,
			)
		);
		update_user_meta( $owner, 'bn_field_loc_probe', self::LOCATION_JSON );

		wp_cache_flush();

		$service = new \BuddyNext\Profile\ProfileService();
		$seen    = static function ( array $profile, string $key ): ?array {
			foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
				foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
					if ( ( $field['field_key'] ?? '' ) === $key ) {
						return $field;
					}
				}
			}
			return null;
		};

		$as_stranger = $seen( $service->get_profile( $owner, $stranger ), 'loc_probe' );

		$this->assertNotNull( $as_stranger, 'precondition: the field must be in the payload' );
		$this->assertStringContainsString( self::ADDRESS, (string) $as_stranger['value'], 'The stranger should still see the area.' );
		$this->assertStringNotContainsString( '18.5204', (string) $as_stranger['value'], 'The stranger was handed the exact latitude.' );
		$this->assertStringNotContainsString( '73.8567', (string) $as_stranger['value'], 'The stranger was handed the exact longitude.' );
		$this->assertNull( $as_stranger['value_raw'] ?? null, 'A stranger was handed value_raw; the exact point must reach nobody but the owner.' );

		// The owner keeps the exact point — the edit map prefills from value_raw.
		$as_owner = $seen( $service->get_profile( $owner, $owner ), 'loc_probe' );
		$this->assertSame(
			self::LOCATION_JSON,
			(string) ( $as_owner['value_raw'] ?? '' ),
			'The owner lost the raw location, so the edit map cannot prefill their own pin.'
		);
	}

	/**
	 * Call the private ProfileService::view_value the way the About tab does.
	 *
	 * @param array<string,mixed> $field Field.
	 * @param mixed               $value Raw stored value.
	 * @return mixed
	 */
	private function call_view_value( array $field, $value ) {
		$m = new \ReflectionMethod( \BuddyNext\Profile\ProfileService::class, 'view_value' );
		$m->setAccessible( true );

		return $m->invoke( null, $field, $value );
	}
}
