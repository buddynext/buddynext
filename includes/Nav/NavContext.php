<?php
/**
 * BuddyNext — Nav context.
 *
 * Immutable description of *where* a navigation is being resolved: the surface
 * (global rail / a member profile / a space), the subject being viewed, the
 * viewer, and (for spaces) the viewer's role. Passed to every `count`,
 * `condition` and `active` callable so item visibility + values are computed
 * against the live request without leaking logic into templates.
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

/**
 * Value object: the resolution context for a nav surface.
 */
final class NavContext {

	/**
	 * Build a resolution context.
	 *
	 * @param string              $surface    One of: global | profile | space.
	 * @param int                 $subject_id Profile user ID, or space ID (0 for global).
	 * @param int                 $viewer_id  Current viewer user ID (0 = logged out).
	 * @param string              $role       Space role of the viewer (owner|moderator|member|''),
	 *                                         empty for non-space surfaces.
	 * @param array<string,mixed> $extra      Free-form per-surface context for providers.
	 */
	public function __construct(
		public string $surface,
		public int $subject_id = 0,
		public int $viewer_id = 0,
		public string $role = '',
		public array $extra = array()
	) {}

	/**
	 * Whether the viewer is looking at their own subject (own profile).
	 */
	public function is_self(): bool {
		return $this->viewer_id > 0 && $this->viewer_id === $this->subject_id;
	}

	/**
	 * Whether the viewer holds at least the given space role.
	 *
	 * Rank: owner > moderator > member. Returns false on non-space surfaces.
	 *
	 * @param string $role Minimum role required.
	 */
	public function role_at_least( string $role ): bool {
		$rank = array(
			'member'    => 1,
			'moderator' => 2,
			'owner'     => 3,
		);
		$have = $rank[ $this->role ] ?? 0;
		$need = $rank[ $role ] ?? 0;
		return $need > 0 && $have >= $need;
	}
}
