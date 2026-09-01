<?php
/**
 * A code-registered profile field must survive the second call.
 *
 * `get_fields()` returned the cached tree verbatim on a cache HIT, applying
 * `filter_fields()` only when it had just read the database. Since the cache holds
 * DB rows and code-registered fields live only in the filter, every such field
 * disappeared after the first call in a request - making
 * `buddynext_register_member_field()` and the whole programmatic field API
 * unusable.
 *
 * ## Why it was worse than "the API does not work"
 *
 * Registration resolves its requirements THREE times per submission - `missing()`,
 * `validate_data()`, then `save_fields()`. So a registered field rendered on the
 * signup form from the first call, and was then validated against a field list that
 * no longer contained it. The visitor answered a question and the answer was
 * silently discarded, with no error anywhere.
 *
 * With a persistent object cache the failure inverts and gets stranger: the field
 * vanishes from the form entirely once the cache is primed, and reappears for a
 * single request whenever the TTL lapses.
 *
 * ## The contract was already written down
 *
 * `filter_fields()`'s own docblock says: "Runs on every call - the DB rows are what
 * get cached, filters layer on top so a plugin loading/unloading is reflected
 * immediately." Only the cache-hit branch disagreed with it. This is the test that
 * keeps them agreeing.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use WP_UnitTestCase;

/**
 * Code-registered fields across cache misses and hits.
 *
 * @covers \BuddyNext\Profile\ProfileService::get_fields
 */
class RegisteredFieldsSurviveTheCacheTest extends WP_UnitTestCase {

	/**
	 * The filter that injects the test field, so it can be removed again.
	 *
	 * @var callable|null
	 */
	private $injector = null;

	/**
	 * Start each test with a cold cache and no injected fields.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_cache_delete( 'all_fields', 'buddynext_profiles' );
	}

	/**
	 * Remove the injector so it cannot leak into other tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->injector ) {
			remove_filter( 'buddynext_profile_fields', $this->injector );
			$this->injector = null;
		}

		wp_cache_delete( 'all_fields', 'buddynext_profiles' );

		parent::tear_down();
	}

	/**
	 * Register a virtual field through the public filter.
	 *
	 * @param string $key Field key.
	 * @return void
	 */
	private function register_field( string $key ): void {
		$this->injector = static function ( array $groups ) use ( $key ): array {
			$groups[] = array(
				'group_key' => 'test_group_' . $key,
				'label'     => 'Test group',
				'type'      => 'custom',
				'fields'    => array(
					array(
						'field_key'        => $key,
						'label'            => 'Test field',
						'type'             => 'text',
						'show_on_register' => true,
					),
				),
			);

			return $groups;
		};

		add_filter( 'buddynext_profile_fields', $this->injector );
	}

	/**
	 * How many times a field key appears in the tree.
	 *
	 * @param string $key Field key.
	 * @return int
	 */
	private function count_field( string $key ): int {
		$found = 0;

		foreach ( buddynext_service( 'profiles' )->get_fields() as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				if ( $key === (string) ( $field['field_key'] ?? '' ) ) {
					++$found;
				}
			}
		}

		return $found;
	}

	/**
	 * THE bug: the field is still there on the second call and every call after.
	 *
	 * Four calls rather than two, because the first read populates the cache and
	 * everything after it takes the branch that used to drop the field.
	 *
	 * @return void
	 */
	public function test_a_registered_field_survives_repeated_calls(): void {
		$this->register_field( 'bn_test_persist' );

		$this->assertSame( 1, $this->count_field( 'bn_test_persist' ), 'Not present even on the first (cache-miss) call.' );

		for ( $call = 2; $call <= 4; $call++ ) {
			$this->assertSame(
				1,
				$this->count_field( 'bn_test_persist' ),
				"Dropped on call {$call} - the cache-hit path returned the DB tree without layering the filter."
			);
		}
	}

	/**
	 * Layering on every call must not ACCUMULATE the field.
	 *
	 * The opposite failure, and the one a careless fix introduces: if the filtered
	 * tree were what got cached, each call would filter an already-filtered tree and
	 * the field would multiply. Safe only because the cache stores the raw DB rows -
	 * this asserts that invariant from the outside.
	 *
	 * @return void
	 */
	public function test_the_field_is_not_duplicated_by_repeated_filtering(): void {
		$this->register_field( 'bn_test_nodupe' );

		for ( $call = 1; $call <= 5; $call++ ) {
			$this->assertSame(
				1,
				$this->count_field( 'bn_test_nodupe' ),
				"Appeared {$call} times - the filtered tree is being cached and re-filtered."
			);
		}
	}

	/**
	 * A field registered AFTER the cache is warm still appears.
	 *
	 * This is the "plugin loading is reflected immediately" half of the documented
	 * contract, and the practical case: a plugin that boots late, or a field
	 * registered on a later hook than the first `get_fields()` call.
	 *
	 * @return void
	 */
	public function test_a_field_registered_after_the_cache_is_warm_appears(): void {
		// Warm the cache with no injected field.
		buddynext_service( 'profiles' )->get_fields();

		$this->register_field( 'bn_test_late' );

		$this->assertSame(
			1,
			$this->count_field( 'bn_test_late' ),
			'A plugin that registered its field after the cache was primed was ignored until the TTL lapsed.'
		);
	}

	/**
	 * Unregistering is reflected immediately too.
	 *
	 * The other half of the same sentence in the docblock. A plugin being
	 * deactivated must not leave a phantom field on the signup form.
	 *
	 * @return void
	 */
	public function test_unregistering_a_field_is_reflected_immediately(): void {
		$this->register_field( 'bn_test_gone' );
		$this->assertSame( 1, $this->count_field( 'bn_test_gone' ) );

		remove_filter( 'buddynext_profile_fields', $this->injector );
		$this->injector = null;

		$this->assertSame(
			0,
			$this->count_field( 'bn_test_gone' ),
			'The field outlived the plugin that registered it.'
		);
	}

	/**
	 * Registration sees it too - the surface where the silent data loss happened.
	 *
	 * `get_registration_fields()` is what the signup form and its validator both
	 * read, three times per submission. If it disagrees with itself between calls,
	 * a visitor's answer is discarded.
	 *
	 * @return void
	 */
	public function test_registration_sees_the_field_on_every_call(): void {
		$this->register_field( 'bn_test_signup' );

		$service = buddynext_service( 'profiles' );
		$first   = count( $service->get_registration_fields() );

		$this->assertGreaterThan( 0, $first, 'The registered field never reached the signup form.' );

		// The three calls one submission makes.
		$this->assertSame( $first, count( $service->get_registration_fields() ) );
		$this->assertSame( $first, count( $service->get_registration_fields() ) );
		$this->assertSame( $first, count( $service->get_registration_fields() ) );
	}
}
