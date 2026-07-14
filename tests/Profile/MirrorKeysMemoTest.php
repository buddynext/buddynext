<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The searchable-mirror memo must still be flushable.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\MemberDirectoryService;
use WP_UnitTestCase;

/**
 * The subtraction card called this memo "over-engineering". It is a FIX.
 *
 * Three layers were reported: a static memo, over a wp_cache, wrapping get_fields() — which
 * is itself already object-cached. The wp_cache layer really was redundant (it filtered an
 * already-cached array in PHP; there was no query to save) and it is gone.
 *
 * The MEMO stays. Deleting it would reintroduce the exact bug it was written to close: the
 * original memo was a `static $keys` INSIDE the method that nothing could reach, so when a
 * field changed, the cache key was invalidated and the memo was not. Anything that had
 * already asked in the same request went on indexing with the pre-edit key list, and a
 * newly-searchable field silently missed the index.
 *
 * A cache you cannot invalidate is not a cache. It is a bug with a speed benefit. This test is
 * what stops the next round of tidying from putting it back.
 *
 * @covers \BuddyNext\Profile\MemberDirectoryService::flush_mirror_keys_memo
 */
class MirrorKeysMemoTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		MemberDirectoryService::flush_mirror_keys_memo();
	}

	/**
	 * The memo is reachable from outside, so a field edit can clear it.
	 *
	 * @return void
	 */
	public function test_the_memo_can_be_flushed_from_outside_the_method(): void {
		$this->assertTrue(
			method_exists( MemberDirectoryService::class, 'flush_mirror_keys_memo' ),
			'The mirror-key memo is unreachable again. A field edit cannot clear it, so a newly-searchable field will silently miss the index for the rest of the request.'
		);
	}

	/**
	 * A field becoming searchable mid-request is picked up after the flush.
	 *
	 * This is the bug in behavioural form: ask once (warming the memo), change the field, then
	 * ask again. Without the flush the second answer is the stale one.
	 *
	 * @return void
	 */
	public function test_a_newly_searchable_field_is_picked_up_after_a_flush(): void {
		global $wpdb;

		$directory = new MemberDirectoryService();

		$before = $directory->searchable_mirror_keys();
		$this->assertNotContains( 'bn_field_mirror_probe', $before, 'precondition: not searchable yet' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_groups',
			array(
				'group_key'  => 'mirror-probe-group',
				'label'      => 'Skills',
				'type'       => 'flat',
				'visibility' => 'public',
			)
		);
		$group_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'      => $group_id,
				'field_key'     => 'mirror_probe',
				'label'         => 'Skills',
				'type'          => 'text',
				'is_searchable' => 1,
				'visibility'    => 'public',
			)
		);

		// Both caches the field list rides on must be cleared, exactly as a real field edit does.
		wp_cache_delete( 'all_fields', 'buddynext_profiles' );
		wp_cache_delete( 'all_groups', 'buddynext_profiles' );
		MemberDirectoryService::flush_mirror_keys_memo();

		$this->assertContains(
			'bn_field_mirror_probe',
			$directory->searchable_mirror_keys(),
			'A field made searchable is still missing from the mirror-key list. The memo went stale and the field will never enter the search index.'
		);
	}
}
