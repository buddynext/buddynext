<?php
/**
 * BuddyNext — Space auto-join resolver.
 *
 * Resolves which spaces a member should be auto-joined to, from the per-space
 * owner settings stored in bn_space_meta:
 *   - `auto_join_on_signup` (bool)        — the master toggle.
 *   - `auto_join_member_types` (csv slugs)— optional member-type filter.
 *
 * Two going-forward paths (see AutoJoinListener):
 *   - At signup → spaces with the toggle on AND no type filter (every new member).
 *   - On member-type assignment → spaces with the toggle on AND the type in the filter.
 *
 * These are infrequent, single-user events, so the small indexed meta lookups run
 * inline (no cache layer / no batching). A bulk "apply to existing members" backfill
 * is intentionally NOT here — it would fan `buddynext_space_member_joined` out across
 * thousands of rows (notification + webhook consumers) and needs a separate
 * Action-Scheduler-batched, notification-suppressed path; tracked as a follow-up.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

/**
 * Reads the auto-join configuration and lists eligible spaces.
 */
final class AutoJoinService {

	/**
	 * Spaces that auto-join EVERY new member at signup: the toggle is on and there
	 * is no member-type filter (type-filtered spaces join on type assignment instead,
	 * since a brand-new account has no member type yet).
	 *
	 * @return int[] Space IDs.
	 */
	public function spaces_for_signup(): array {
		$out = array();
		foreach ( $this->enabled_space_ids() as $space_id ) {
			if ( array() === $this->type_filter( $space_id ) ) {
				$out[] = $space_id;
			}
		}
		return $out;
	}

	/**
	 * Spaces that auto-join members of a given member type: the toggle is on and the
	 * type slug is in the space's member-type filter.
	 *
	 * @param string $slug Member-type slug.
	 * @return int[] Space IDs.
	 */
	public function spaces_for_type( string $slug ): array {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return array();
		}
		$out = array();
		foreach ( $this->enabled_space_ids() as $space_id ) {
			if ( in_array( $slug, $this->type_filter( $space_id ), true ) ) {
				$out[] = $space_id;
			}
		}
		return $out;
	}

	/**
	 * Space IDs with `auto_join_on_signup` enabled. Indexed meta lookup over the small
	 * set of auto-join spaces; runs only on signup / type-assignment events.
	 *
	 * @return int[]
	 */
	private function enabled_space_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			"SELECT bn_space_id FROM {$wpdb->bn_spacemeta}
			 WHERE meta_key = 'auto_join_on_signup' AND meta_value = '1'"
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * A space's member-type filter as a list of slugs (empty = applies to all).
	 *
	 * @param int $space_id Space ID.
	 * @return string[]
	 */
	private function type_filter( int $space_id ): array {
		$raw = (string) get_space_meta( $space_id, 'auto_join_member_types', true );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}
}
