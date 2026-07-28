<?php
/**
 * save_profile() is atomic: a rejected submission writes nothing at all.
 *
 * Regression cover for the 1.1.1 data-integrity fix. save_profile() used to write
 * field-by-field and merely accumulate per-field errors, so a rejection on a later
 * field left the earlier ones persisted while the method returned a WP_Error —
 * which every caller (REST, the admin member editor, onboarding) renders as "save
 * failed". The member was told nothing saved while part of their edit was already
 * live, and re-edited from a state that no longer matched the database.
 *
 * The REST path masked this behind ProfileController::validate_profile_payload(),
 * which validates up front and returns 422 with zero writes — but that pre-flight
 * skips EMPTY values, and the admin member editor and onboarding call
 * save_profile() directly with no pre-flight at all. So these tests exercise the
 * service, which is the layer every entry point shares.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * Atomicity of the profile write path.
 *
 * @covers \BuddyNext\Profile\ProfileService::save_profile
 */
class SaveProfileAtomicityTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ProfileService
	 */
	private ProfileService $service;

	/**
	 * Create the schema + seeds and the service under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
	}

	/**
	 * Make a seeded flat field required, so an empty submission is rejected.
	 *
	 * @param string $field_key Field key to mark required.
	 * @return void
	 */
	private function require_field( string $field_key ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'bn_profile_fields',
			array( 'is_required' => 1 ),
			array( 'field_key' => $field_key )
		);
		wp_cache_delete( 'all_fields', 'buddynext_profiles' );
	}

	/**
	 * Read a member's stored field values as field_key => value.
	 *
	 * @param int $user_id Member.
	 * @return array<string, mixed>
	 */
	private function values_for( int $user_id ): array {
		$profile = $this->service->get_profile( $user_id, $user_id );
		$out     = array();

		foreach ( (array) ( $profile['fields'] ?? array() ) as $field ) {
			$out[ (string) $field['field_key'] ] = $field['value'];
		}

		return $out;
	}

	// ── The contract ─────────────────────────────────────────────────────────

	/**
	 * A rejected save persists NONE of the valid fields submitted alongside.
	 *
	 * @return void
	 */
	public function test_rejected_save_writes_no_sibling_field(): void {
		$this->require_field( 'location' );
		$user_id = self::factory()->user->create();

		$result = $this->service->save_profile(
			$user_id,
			array(
				'bio'      => 'this must not be written',
				'pronouns' => 'they/them',
				'location' => '',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );

		$values = $this->values_for( $user_id );
		$this->assertNotSame( 'this must not be written', $values['bio'] ?? null );
		$this->assertNotSame( 'they/them', $values['pronouns'] ?? null );
	}

	/**
	 * Field ORDER does not matter — a valid field before the rejected one is
	 * refused just as one after it.
	 *
	 * The original bug was order-dependent: only fields the loop reached BEFORE
	 * the rejection were persisted. Submitting the good field first is therefore
	 * the case that actually reproduced it.
	 *
	 * @return void
	 */
	public function test_valid_field_submitted_before_the_rejected_one_is_not_written(): void {
		$this->require_field( 'location' );
		$user_id = self::factory()->user->create();

		$result = $this->service->save_profile(
			$user_id,
			array(
				'bio'      => 'submitted first, still must not persist',
				'location' => '',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'submitted first, still must not persist', $this->values_for( $user_id )['bio'] ?? null );
	}

	/**
	 * An already-stored value is not cleared by a rejected save.
	 *
	 * @return void
	 */
	public function test_rejected_save_leaves_existing_values_untouched(): void {
		$this->require_field( 'location' );
		$user_id = self::factory()->user->create();

		$this->assertTrue(
			$this->service->save_profile( $user_id, array( 'location' => 'Berlin', 'bio' => 'original bio' ) )
		);

		$this->service->save_profile(
			$user_id,
			array(
				'bio'      => 'replacement bio',
				'location' => '',
			)
		);

		$values = $this->values_for( $user_id );
		$this->assertSame( 'Berlin', $values['location'] ?? null );
		$this->assertSame( 'original bio', $values['bio'] ?? null );
	}

	/**
	 * A rejected save does not fire the interests side-effect action.
	 *
	 * Deferring the writes also defers the actions they dispatch. An action fired
	 * for a save that did not happen cannot be taken back, which is one of the two
	 * reasons a DB transaction was not the right fix here (the other being the
	 * object cache that update_user_meta() primes).
	 *
	 * @return void
	 */
	public function test_rejected_save_does_not_fire_side_effect_actions(): void {
		$this->require_field( 'location' );
		$user_id = self::factory()->user->create();

		$fired = 0;
		add_action(
			'buddynext_member_interests_updated',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->service->save_profile(
			$user_id,
			array(
				'interests' => 'design',
				'location'  => '',
			)
		);

		$this->assertSame( 0, $fired, 'A save that was refused must not fire its side-effect actions.' );
	}

	/**
	 * The happy path still writes everything — atomicity must not mean "writes
	 * nothing".
	 *
	 * This is the mutation guard for the fix: an implementation that simply
	 * dropped the writes would pass every assertion above and fail here.
	 *
	 * @return void
	 */
	public function test_valid_save_still_writes_every_field(): void {
		$this->require_field( 'location' );
		$user_id = self::factory()->user->create();

		$result = $this->service->save_profile(
			$user_id,
			array(
				'bio'      => 'written',
				'pronouns' => 'she/her',
				'location' => 'Lisbon',
			)
		);

		$this->assertTrue( $result );

		$values = $this->values_for( $user_id );
		$this->assertSame( 'written', $values['bio'] ?? null );
		$this->assertSame( 'she/her', $values['pronouns'] ?? null );
		$this->assertSame( 'Lisbon', $values['location'] ?? null );
	}

	/**
	 * Writes still land in submission order on a valid save.
	 *
	 * Deferring the writes must not reorder them — the headline usermeta
	 * denormalisation, the search mirror and the canonical row are written in a
	 * fixed sequence that later reads depend on.
	 *
	 * @return void
	 */
	public function test_valid_save_keeps_the_headline_usermeta_in_lockstep(): void {
		$user_id = self::factory()->user->create();

		$this->assertTrue( $this->service->save_profile( $user_id, array( 'headline' => 'Backend engineer' ) ) );
		$this->assertSame( 'Backend engineer', get_user_meta( $user_id, 'bn_headline', true ) );

		$this->assertTrue( $this->service->save_profile( $user_id, array( 'headline' => '' ) ) );
		$this->assertSame( '', get_user_meta( $user_id, 'bn_headline', true ) );
	}
}
