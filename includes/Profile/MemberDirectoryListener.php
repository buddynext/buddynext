<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Cache-bust hooks for the member-directory type-facet counts.
 *
 * Layer 2 Listener (docs/specs/MODULAR-ARCHITECTURE.md): registers the hooks that bust
 * the directory's cached counts whenever something changes what those counts include.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Keeps the member-type facet counts honest.
 *
 * The type-facet count does not count every member with a type — it counts the ones the
 * directory would actually SHOW, excluding three groups:
 *
 *   - suspended members       (bn_user_suspensions)
 *   - shadow-banned members   (bn_shadow_banned user meta)
 *   - directory opt-outs      (bn_privacy_show_in_directory user meta)
 *
 * The count is cached for an hour, and MemberTypeService busts it when a TYPE changes.
 * Nothing busted it when a member entered or left one of those three groups, so
 * suspending someone left the facet reading one too high — the directory promised
 * "Moderators (12)" and then showed 11 — until the TTL ran out.
 *
 * WHY HOOKS AND NOT CALLS AT THE WRITE SITES. The writers are not one place and never
 * will be: suspension alone fires FOUR different actions across two files (there are two
 * suspend APIs, one firing buddynext_member_suspended and the other
 * buddynext_user_suspended), the shadow-ban flag is written from three call sites, and
 * the directory opt-out is written through a generic privacy-meta loop in
 * ProfileController that never mentions the directory at all. Adding a flush call to each
 * of those would leave the next writer — the one nobody has written yet — to rot the
 * count again.
 *
 * Hooking WordPress's own meta hooks catches EVERY writer of those two meta keys, present
 * and future, including a plain update_user_meta() from a bridge or WP-CLI. And all four
 * suspension actions are hooked rather than the two that happen to be canonical today.
 */
class MemberDirectoryListener implements ListenerInterface {

	/**
	 * User-meta keys that change whether a member is counted in the directory facets.
	 *
	 * @var string[]
	 */
	private const WATCHED_META = array(
		'bn_shadow_banned',
		'bn_privacy_show_in_directory',
	);

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Both suspension hook families, both directions. Two suspend APIs exist and they
		// do not fire the same action, so hooking one family would silently miss half the
		// suspensions on the site.
		foreach (
			array(
				'buddynext_member_suspended',
				'buddynext_member_unsuspended',
				'buddynext_user_suspended',
				'buddynext_user_unsuspended',
			) as $hook
		) {
			add_action( $hook, array( $this, 'flush' ), 10, 0 );
		}

		// Any writer of the shadow-ban or directory-opt-out flags, however it writes it.
		add_action( 'added_user_meta', array( $this, 'flush_on_meta' ), 10, 3 );
		add_action( 'updated_user_meta', array( $this, 'flush_on_meta' ), 10, 3 );
		add_action( 'deleted_user_meta', array( $this, 'flush_on_meta' ), 10, 3 );
	}

	/**
	 * Bust the cached facet counts.
	 *
	 * @return void
	 */
	public function flush(): void {
		MemberDirectoryService::flush_type_counts();
	}

	/**
	 * Bust the counts when a watched user-meta key changes.
	 *
	 * @param int|string[] $meta_id  Meta row id (unused).
	 * @param int          $user_id  User the meta belongs to (unused).
	 * @param string       $meta_key Meta key that changed.
	 * @return void
	 */
	public function flush_on_meta( $meta_id, $user_id, $meta_key ): void {
		unset( $meta_id, $user_id );

		if ( in_array( (string) $meta_key, self::WATCHED_META, true ) ) {
			$this->flush();
		}
	}
}
