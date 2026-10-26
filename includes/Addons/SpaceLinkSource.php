<?php
/**
 * Addon module: a source of objects a space can be linked to.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

/**
 * An addon whose objects (a course, a group, a store) can be linked to a space.
 *
 * Linking is how "members follow access" works: while an object is linked, everyone
 * it grants access to is added to the space, and when a link is removed or access
 * ends BuddyNext removes the member only from a space BuddyNext created for the link
 * (a "managed" space) and only if no other link on that space still grants access.
 * A space the owner linked by hand never loses a member because of a partner change.
 *
 * Your addon calls `SpaceLinkService` to link, unlink and report a member gaining or
 * losing access; it supplies the four answers below. Types are your own words
 * ("course", "group"); the source slug is the addon slug.
 *
 * @api
 * @since 1.2.7
 */
interface SpaceLinkSource {

	/**
	 * Object types that can be linked right now on this site.
	 *
	 * Return only what works today (a type needing a partner extension that is off
	 * is left out); a stored link of any other type is shown but never acted on.
	 *
	 * @return string[]
	 */
	public function link_types(): array;

	/**
	 * Name and front-end URL of an object, for the space's "Linked to" panel.
	 *
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @return array{name:string,url:string} Empty strings when the object is gone.
	 */
	public function link_label( string $type, int $object_id ): array;

	/**
	 * One page of the people the object grants access to, ascending by user id.
	 *
	 * Keyset, not offset: return ids strictly greater than `$after`, at most `$limit`.
	 *
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @param int    $after     Last user id of the previous page (0 for the first).
	 * @param int    $limit     Page size.
	 * @return int[]
	 */
	public function link_roster( string $type, int $object_id, int $after, int $limit ): array;

	/**
	 * Whether the object currently grants the user access.
	 *
	 * Asked about the space's other links before a member is removed.
	 *
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @param int    $user_id   Member.
	 * @return bool
	 */
	public function link_grants_access( string $type, int $object_id, int $user_id ): bool;
}
