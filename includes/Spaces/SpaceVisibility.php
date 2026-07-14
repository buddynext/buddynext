<?php
/**
 * The canonical "can this viewer see this space?" resolver.
 *
 * ONE decision point, consumed by EVERY surface — server-rendered template and
 * REST alike — so the page and the app can never disagree. Before this class,
 * each surface re-implemented the check (templates/spaces/members.php had none
 * at all; the REST roster/pinned/sub-space routes only tested the SECRET case
 * and never private membership), which leaked private + secret rosters to
 * logged-out visitors while the REST contract correctly refused them.
 *
 * The three questions a surface can ask, all derived from the space type's
 * visibility level via SpaceTypeRegistry (so a custom registered type works
 * without touching this class):
 *
 *   can_view_space()   — may the viewer know this space EXISTS?
 *                        Secret: members / owner / site admins only (404 otherwise).
 *                        Private + open: yes. Private is LISTED but GATED —
 *                        a stranger must be able to see what the space is in
 *                        order to decide to request to join.
 *   can_view_roster()  — may the viewer see WHO IS IN THE ROOM (the member list)?
 *                        Private + secret: members / moderators / owner / site
 *                        admins only. Re-openable per site via the
 *                        `buddynext_space_can_view_roster` filter.
 *   can_view_content() — may the viewer see the space's CONTENT (feed, pinned
 *                        posts, sub-spaces)? Private + secret: members only.
 *
 * What stays PUBLIC on a private space: name, description, house rules, avatar,
 * cover, category, the member COUNT, the owner + moderator list (who is in
 * CHARGE, which a stranger legitimately needs before requesting to join), and
 * its place in the directory + search.
 *
 * SCALE: every check here is O(1) — an indexed, object-cached single-row
 * membership lookup. Callers apply the answer BEFORE fetching a roster, never
 * by fetching 100k rows and filtering them in PHP.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves space visibility for a viewer.
 */
final class SpaceVisibility {

	/**
	 * Whether the viewer may know the space exists (and open its pages).
	 *
	 * Secret (hidden) spaces are leak-proof: a non-member gets a hard 404 and we
	 * never confirm the slug. Every other type — including private — is visible,
	 * because "private" means listed-but-gated, not hidden.
	 *
	 * @param array<string, mixed>|null $space     Hydrated space row (SpaceService::get()), or null.
	 * @param int                       $viewer_id Viewer user ID (0 = logged out).
	 * @return bool True when the viewer may see that the space exists.
	 */
	public static function can_view_space( ?array $space, int $viewer_id ): bool {
		if ( null === $space ) {
			return false;
		}

		if ( ! SpaceTypeRegistry::instance()->is_hidden_from_non_members( self::type( $space ) ) ) {
			return true;
		}

		return self::is_privileged( $space, $viewer_id );
	}

	/**
	 * Whether the viewer may see the space's member roster.
	 *
	 * THE single decision point for the member list, on the template AND the REST
	 * path. A private space's roster is members-only by default — a non-member is
	 * refused whether they are logged out or logged in — exactly like a secret
	 * space's. A site owner who wants Facebook-style open rosters re-opens them
	 * with ONE `add_filter()`, which changes the page and the app together
	 * because both read the answer from here.
	 *
	 * @param array<string, mixed>|null $space     Hydrated space row (SpaceService::get()), or null.
	 * @param int                       $viewer_id Viewer user ID (0 = logged out).
	 * @return bool True when the viewer may see the member roster.
	 */
	public static function can_view_roster( ?array $space, int $viewer_id ): bool {
		if ( null === $space ) {
			return false;
		}

		$type     = self::type( $space );
		$can_view = ! SpaceTypeRegistry::instance()->content_requires_membership( $type )
			|| self::is_privileged( $space, $viewer_id );

		/**
		 * Filter whether a viewer may see a space's member roster.
		 *
		 * Default: true for open spaces; false for private and secret spaces
		 * unless the viewer is an active member, a moderator, the owner, or a
		 * site admin. Return true to re-open private rosters Facebook-style.
		 *
		 * This is the SINGLE decision point behind both the server-rendered
		 * members page and `GET /buddynext/v1/spaces/{id}/members`, so one
		 * add_filter() changes the page and the app at the same time.
		 *
		 * @param bool   $can_view  Whether the roster is visible to this viewer.
		 * @param int    $space_id  Space ID.
		 * @param int    $viewer_id Viewer user ID (0 = logged out).
		 * @param string $type      Space type slug ('open', 'private', 'secret', or a custom type).
		 */
		return (bool) apply_filters(
			'buddynext_space_can_view_roster',
			$can_view,
			(int) ( $space['id'] ?? 0 ),
			$viewer_id,
			$type
		);
	}

	/**
	 * Whether the viewer may see the space's content (feed, pinned posts, sub-spaces).
	 *
	 * Derived from the type's visibility: public content is open to all; private
	 * and secret content is members-only. Mirrors the feed gate the space home
	 * already applied, so the REST content routes agree with the page.
	 *
	 * @param array<string, mixed>|null $space     Hydrated space row (SpaceService::get()), or null.
	 * @param int                       $viewer_id Viewer user ID (0 = logged out).
	 * @return bool True when the viewer may see the space's content.
	 */
	public static function can_view_content( ?array $space, int $viewer_id ): bool {
		if ( null === $space ) {
			return false;
		}

		if ( ! SpaceTypeRegistry::instance()->content_requires_membership( self::type( $space ) ) ) {
			return true;
		}

		return self::is_privileged( $space, $viewer_id );
	}

	/**
	 * Whether the viewer is one of the people who may see inside the space:
	 * the owner, an active member (which covers moderators), or a site admin.
	 *
	 * The owner is checked off the denormalised bn_spaces.owner_id column as well
	 * as the membership table, so a space whose owner row is missing (repairable
	 * via `wp buddynext repair-space-owners`) never locks its own owner out.
	 *
	 * @param array<string, mixed> $space     Hydrated space row.
	 * @param int                  $viewer_id Viewer user ID (0 = logged out).
	 * @return bool True when the viewer is the owner, an active member, or a site admin.
	 */
	private static function is_privileged( array $space, int $viewer_id ): bool {
		if ( $viewer_id <= 0 ) {
			return false;
		}

		if ( (int) ( $space['owner_id'] ?? 0 ) === $viewer_id ) {
			return true;
		}

		if ( user_can( $viewer_id, 'manage_options' ) ) {
			return true;
		}

		// Indexed, object-cached single-row lookup — safe on a 100k-member space.
		return ( new SpaceMemberService() )->is_member( (int) ( $space['id'] ?? 0 ), $viewer_id );
	}

	/**
	 * Read a space row's type slug, defaulting to the most restrictive-safe 'open'
	 * fallback the registry itself uses for unknown slugs.
	 *
	 * @param array<string, mixed> $space Hydrated space row.
	 * @return string Type slug.
	 */
	private static function type( array $space ): string {
		return (string) ( $space['type'] ?? 'open' );
	}
}
