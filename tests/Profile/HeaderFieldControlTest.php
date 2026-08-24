<?php
/**
 * The profile hero's meta row is owner-controlled via the show_in_header flag,
 * not a hardcoded key list.
 *
 * Guards the fix for the header being hardcoded to deletable field keys: an owner
 * could delete a header field and never restore it, and could not reorder the
 * header. These assert the default (location + website), that flagging/unflagging
 * a field adds/removes it, that order follows sort_order, and that the developer
 * filter wins.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Profile\ProfileService::hero_meta_field_keys
 * @covers \BuddyNext\Profile\ProfileService::hero_field_keys
 */
class HeaderFieldControlTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function tear_down(): void {
		remove_all_filters( 'buddynext_profile_hero_fields' );
		parent::tear_down();
	}

	/**
	 * @param string $field_key Field to flag.
	 * @param int    $on        1 or 0.
	 */
	private function set_header( string $field_key, int $on ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_fields',
			array( 'show_in_header' => $on ),
			array( 'field_key' => $field_key ),
			array( '%d' ),
			array( '%s' )
		);
	}

	/**
	 * The installer seeds the header on exactly location + website — the row the
	 * hero always showed — so an upgraded/fresh site's header is unchanged.
	 */
	public function test_default_header_is_location_then_website(): void {
		$this->assertSame(
			array( 'location', 'website' ),
			ProfileService::hero_meta_field_keys(),
			'By default only location and website are in the header meta row, in sort_order.'
		);
	}

	/**
	 * Flagging a field puts it in the header; unflagging takes it back out — the
	 * recoverability the hardcoded list could not offer.
	 */
	public function test_flag_adds_and_unflag_removes(): void {
		$this->set_header( 'pronouns', 1 );
		$this->assertContains( 'pronouns', ProfileService::hero_meta_field_keys(), 'A flagged field joins the header.' );

		$this->set_header( 'pronouns', 0 );
		$this->assertNotContains( 'pronouns', ProfileService::hero_meta_field_keys(), 'Unflagging removes it again.' );
	}

	/**
	 * Order follows the field's sort_order, so reordering fields reorders the
	 * header — not a fixed hardcoded sequence.
	 */
	public function test_order_follows_sort_order(): void {
		global $wpdb;
		// Flag pronouns and force it before location by sort_order.
		$this->set_header( 'pronouns', 1 );
		$wpdb->update( $wpdb->prefix . 'bn_profile_fields', array( 'sort_order' => -5 ), array( 'field_key' => 'pronouns' ), array( '%d' ), array( '%s' ) );

		$keys = ProfileService::hero_meta_field_keys();
		$this->assertSame( 'pronouns', $keys[0], 'A lower sort_order renders first in the header.' );
	}

	/**
	 * hero_field_keys() = the fixed identity fields plus the meta row, for the
	 * About panel / ProfileNav to skip everything the hero already shows.
	 */
	public function test_hero_field_keys_includes_identity_and_meta(): void {
		$all = ProfileService::hero_field_keys();
		foreach ( array( 'headline', 'bio', 'pronouns', 'location', 'website' ) as $key ) {
			$this->assertContains( $key, $all, "hero_field_keys() must include {$key}." );
		}
	}

	/**
	 * The developer filter has the final say over the meta-row keys.
	 */
	public function test_filter_overrides_the_keys(): void {
		add_filter(
			'buddynext_profile_hero_fields',
			static fn(): array => array( 'headline' )
		);
		$this->assertSame( array( 'headline' ), ProfileService::hero_meta_field_keys(), 'The filter result wins.' );
	}
}
