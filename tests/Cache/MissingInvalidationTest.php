<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Bucket [C] — writes that changed the data but never told the cache.
 *
 * @package BuddyNext\Tests\Cache
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Cache;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationPrefService;
use BuddyNext\Profile\MemberDirectoryListener;
use BuddyNext\Profile\MemberDirectoryService;
use WP_UnitTestCase;

/**
 * Three caches served data the write had already changed.
 *
 * C1  set_pref() busted pref_{uid}_{type} but not all_prefs_{uid}, so a member who
 *     turned an email notification OFF kept receiving it: the mailer reads the whole
 *     preference set, and that copy was still the old one. The bulk setter happened to
 *     bust it after its loop, so the settings SCREEN looked fine — it was the single
 *     change, which is the path an unsubscribe link takes, that lied.
 *
 * C2  The directory's member-type facet counts exclude suspended, shadow-banned and
 *     directory-opted-out members, but nothing busted them when a member entered or left
 *     one of those groups. Suspend someone and the facet still promised "Moderators (12)"
 *     while the directory listed 11, for an hour.
 *
 * C3  end_announcement_now() expired the announcement in the table and left every cached
 *     home feed still showing it — at exactly the moment an owner most wants it gone,
 *     since you usually end an announcement because it is wrong.
 *
 * @covers \BuddyNext\Notifications\NotificationPrefService::set_pref
 * @covers \BuddyNext\Profile\MemberDirectoryListener
 */
class MissingInvalidationTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, clean cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
	}

	/**
	 * C1 — turning one notification off is visible to the code that sends the email.
	 *
	 * @return void
	 */
	public function test_changing_one_pref_busts_the_whole_pref_set(): void {
		$user_id = self::factory()->user->create();
		$service = new NotificationPrefService();

		$service->set_pref( $user_id, 'mention', array( 'on_site' => 1, 'email_freq' => 'immediate' ) );

		// Warm all_prefs_{uid} — this is the copy the mailer reads.
		$before = $service->get_all_prefs( $user_id );
		$this->assertSame( 'immediate', (string) ( $before['mention']['email_freq'] ?? '' ) );

		// The member clicks "unsubscribe" — a SINGLE pref write.
		$service->set_pref( $user_id, 'mention', array( 'on_site' => 1, 'email_freq' => 'off' ) );

		$after = $service->get_all_prefs( $user_id );

		$this->assertSame(
			'off',
			(string) ( $after['mention']['email_freq'] ?? '' ),
			'The unsubscribe did not reach the preference set the mailer reads. set_pref() busted only the per-type key, so the member keeps getting the email they just turned off until the TTL expires.'
		);
	}

	/**
	 * C2 — the listener busts the facet counts when a member is suspended.
	 *
	 * Driven through the real hook rather than by calling the flush directly, because the
	 * bug was never in the flush - it was that nothing CALLED it.
	 *
	 * @return void
	 */
	public function test_suspending_a_member_busts_the_type_facet_counts(): void {
		( new MemberDirectoryListener() )->register();

		$service = new MemberDirectoryService();

		// Warm the counts.
		$service->type_member_counts();
		$this->assertNotFalse(
			wp_cache_get( MemberDirectoryService::TYPE_COUNTS_CACHE_KEY, 'buddynext_directory' ),
			'Precondition: the facet counts should be cached after a read.'
		);

		do_action( 'buddynext_member_suspended', 123, 1 );

		$this->assertFalse(
			wp_cache_get( MemberDirectoryService::TYPE_COUNTS_CACHE_KEY, 'buddynext_directory' ),
			'Suspending a member left the cached type-facet counts in place. The counts EXCLUDE suspended members, so the directory advertises a count it will not show.'
		);
	}

	/**
	 * C2 — and the OTHER suspend API, which fires a different action entirely.
	 *
	 * Two suspend APIs exist: one fires buddynext_member_suspended, the other fires
	 * buddynext_user_suspended. Hooking only the family that looked canonical would have
	 * left half the suspensions on the site still rotting the count.
	 *
	 * @return void
	 */
	public function test_the_other_suspend_hook_family_busts_them_too(): void {
		( new MemberDirectoryListener() )->register();

		( new MemberDirectoryService() )->type_member_counts();
		$this->assertNotFalse( wp_cache_get( MemberDirectoryService::TYPE_COUNTS_CACHE_KEY, 'buddynext_directory' ) );

		do_action( 'buddynext_user_suspended', 123, 1, 'spam', null );

		$this->assertFalse(
			wp_cache_get( MemberDirectoryService::TYPE_COUNTS_CACHE_KEY, 'buddynext_directory' ),
			'The second suspend API fires buddynext_user_suspended, and it did not bust the counts.'
		);
	}

	/**
	 * C2 — shadow-banning, written as plain user meta by three different call sites.
	 *
	 * Hooking WP's own meta hooks is what makes this hold for a writer nobody has written
	 * yet, including a bridge or WP-CLI calling update_user_meta() directly.
	 *
	 * @return void
	 */
	public function test_shadow_banning_via_plain_user_meta_busts_the_counts(): void {
		( new MemberDirectoryListener() )->register();

		$user_id = self::factory()->user->create();

		( new MemberDirectoryService() )->type_member_counts();
		$this->assertNotFalse( wp_cache_get( MemberDirectoryService::TYPE_COUNTS_CACHE_KEY, 'buddynext_directory' ) );

		// Exactly what a bridge, WP-CLI, or a future call site would do.
		update_user_meta( $user_id, 'bn_shadow_banned', '1' );

		$this->assertFalse(
			wp_cache_get( MemberDirectoryService::TYPE_COUNTS_CACHE_KEY, 'buddynext_directory' ),
			'A shadow-ban written with a plain update_user_meta() did not bust the counts, so any writer that does not go through the moderation service rots them.'
		);
	}

	/**
	 * C2 — a user-meta write we do NOT watch must not flush the counts.
	 *
	 * Without this, "bust on every user-meta change" would pass every test above while
	 * throwing the directory counts away on every profile save on the site.
	 *
	 * @return void
	 */
	public function test_an_unrelated_user_meta_write_does_not_flush_the_counts(): void {
		( new MemberDirectoryListener() )->register();

		$user_id = self::factory()->user->create();

		( new MemberDirectoryService() )->type_member_counts();
		$this->assertNotFalse( wp_cache_get( MemberDirectoryService::TYPE_COUNTS_CACHE_KEY, 'buddynext_directory' ) );

		update_user_meta( $user_id, 'bn_some_unrelated_thing', 'x' );

		$this->assertNotFalse(
			wp_cache_get( MemberDirectoryService::TYPE_COUNTS_CACHE_KEY, 'buddynext_directory' ),
			'An unrelated user-meta write flushed the directory counts. The listener is busting on every meta change, which would throw the counts away on every profile save on the site.'
		);
	}
}
