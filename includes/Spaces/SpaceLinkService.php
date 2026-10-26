<?php
/**
 * Links between a space and an object in another plugin.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use BuddyNext\Addons\SpaceLinkSource;

/**
 * One meta row per link on the space (`_bn_link:{source}:{type}:{object_id}`), so there
 * is no table of its own: a deleted space or an uninstall removes the rows with it.
 * Reverse lookup (object to spaces) is an exact match on the indexed `meta_key`.
 *
 * A space is "managed" when BuddyNext created it for a link (`_bn_managed_by`). Only a
 * managed space loses members when access ends, and only if no other link on it still
 * grants access.
 *
 * @api
 * @since 1.2.7
 */
final class SpaceLinkService {

	/** Meta key prefix of a link row. */
	public const META_PREFIX = '_bn_link:';

	/** Meta key holding the source slug of the link a space was created for. */
	public const META_MANAGED = '_bn_managed_by';

	/** Action Scheduler hook that resumes a backfill after its first page. */
	public const BACKFILL_HOOK = 'buddynext_space_link_backfill_chunk';

	/** Roster rows joined per backfill run. */
	private const BACKFILL_CHUNK = 500;

	/**
	 * Depth of system joins in progress (a join no member asked for stays quiet).
	 *
	 * @var int
	 */
	private static int $system_depth = 0;

	/**
	 * Wire the backfill continuation. Called once at boot.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::BACKFILL_HOOK, array( $this, 'run_backfill_chunk' ), 10, 5 );
	}

	/**
	 * Whether a join in progress is a system join (no owner notification or webhook).
	 *
	 * @return bool
	 */
	public static function is_system_join(): bool {
		return self::$system_depth > 0;
	}

	/**
	 * Registered link sources, keyed by slug.
	 *
	 * @return array<string,SpaceLinkSource>
	 */
	public function sources(): array {
		/**
		 * Filters the objects a space can be linked to.
		 *
		 * An addon declaring a `SpaceLinkSource` module is added for you; a plugin that
		 * is not an addon adds its own instance here.
		 *
		 * @since 1.2.7
		 *
		 * @param array<string,SpaceLinkSource> $sources Sources keyed by slug.
		 */
		$sources = (array) apply_filters( 'buddynext_space_link_sources', array() );
		return array_filter(
			$sources,
			static fn( $source, $slug ): bool => $source instanceof SpaceLinkSource && is_string( $slug ) && (bool) preg_match( '/^[a-z][a-z0-9_]{1,30}$/', $slug ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * One registered source.
	 *
	 * @param string $slug Source slug.
	 * @return SpaceLinkSource|null
	 */
	public function source( string $slug ): ?SpaceLinkSource {
		return $this->sources()[ $slug ] ?? null;
	}

	/**
	 * Every link on a space.
	 *
	 * @param int $space_id Space.
	 * @return array<int,array{source:string,type:string,id:int,created_at:string}>
	 */
	public function links( int $space_id ): array {
		if ( $space_id <= 0 ) {
			return array();
		}
		$out = array();
		foreach ( (array) get_space_meta( $space_id ) as $key => $values ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, self::META_PREFIX ) ) {
				continue;
			}
			$parts = explode( ':', substr( $key, strlen( self::META_PREFIX ) ) );
			if ( 3 !== count( $parts ) || (int) $parts[2] <= 0 ) {
				continue;
			}
			$out[] = array(
				'source'     => $parts[0],
				'type'       => $parts[1],
				'id'         => (int) $parts[2],
				'created_at' => (string) ( is_array( $values ) ? ( $values[0] ?? '' ) : $values ),
			);
		}
		return $out;
	}

	/**
	 * Links on a space that belong to one source.
	 *
	 * @param int    $space_id Space.
	 * @param string $source   Source slug.
	 * @return array<int,array{source:string,type:string,id:int,created_at:string}>
	 */
	public function links_for_source( int $space_id, string $source ): array {
		return array_values( array_filter( $this->links( $space_id ), static fn( array $l ): bool => $l['source'] === $source ) );
	}

	/**
	 * Spaces an object is linked to (exact match on the indexed meta key).
	 *
	 * @param string $source    Source slug.
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @return int[]
	 */
	public function spaces_for_object( string $source, string $type, int $object_id ): array {
		global $wpdb;
		if ( $object_id <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed meta_key lookup.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT bn_space_id FROM {$wpdb->prefix}bn_space_meta WHERE meta_key = %s", self::key( $source, $type, $object_id ) ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Spaces a member belongs to that carry a link from one source.
	 *
	 * One query for the member, not one per enrolment: it also stays correct for a
	 * member whose access has lapsed but who keeps a hand-linked space by contract.
	 *
	 * @param int    $user_id Member.
	 * @param string $source  Source slug.
	 * @return array<int,array{space_id:int,type:string,id:int}>
	 */
	public function spaces_for_user( int $user_id, string $source ): array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return array();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded: the member's own spaces, prefix range on the meta_key index.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.space_id, l.meta_key
				 FROM {$wpdb->prefix}bn_space_members m
				 INNER JOIN {$wpdb->prefix}bn_space_meta l ON l.bn_space_id = m.space_id AND l.meta_key LIKE %s
				 WHERE m.user_id = %d AND m.status = 'active'
				 ORDER BY m.space_id DESC
				 LIMIT 500",
				$wpdb->esc_like( self::META_PREFIX . $source . ':' ) . '%',
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( (array) $rows as $row ) {
			$parts = explode( ':', substr( (string) $row['meta_key'], strlen( self::META_PREFIX ) ) );
			if ( 3 === count( $parts ) && (int) $parts[2] > 0 ) {
				$out[] = array(
					'space_id' => (int) $row['space_id'],
					'type'     => $parts[1],
					'id'       => (int) $parts[2],
				);
			}
		}
		return $out;
	}

	/**
	 * Whether an object is already linked to a space other than the given one.
	 *
	 * An object feeds at most one space, so a link is never stolen silently.
	 *
	 * @param string $source          Source slug.
	 * @param string $type            Object type.
	 * @param int    $object_id       Object id.
	 * @param int    $except_space_id Space being (re)linked.
	 * @return bool
	 */
	public function linked_elsewhere( string $source, string $type, int $object_id, int $except_space_id = 0 ): bool {
		return (bool) array_diff( $this->spaces_for_object( $source, $type, $object_id ), array( $except_space_id ) );
	}

	/**
	 * Whether BuddyNext created the space for a link.
	 *
	 * @param int $space_id Space.
	 * @return bool
	 */
	public function is_managed( int $space_id ): bool {
		return '' !== (string) get_space_meta( $space_id, self::META_MANAGED, true );
	}

	/**
	 * Link a space to an object, then backfill the object's current members once.
	 *
	 * Idempotent: linking what is already linked changes nothing and rescans nothing.
	 * Refused when the source or type is not available, the space is missing, or the
	 * object already feeds another space.
	 *
	 * @param int    $space_id  Space.
	 * @param string $source    Source slug.
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @return bool
	 */
	public function link( int $space_id, string $source, string $type, int $object_id ): bool {
		$src = $this->source( $source );
		if ( null === $src || $space_id <= 0 || $object_id <= 0 || ! in_array( $type, $src->link_types(), true ) || null === ( new SpaceService() )->get( $space_id ) ) {
			return false;
		}
		if ( $this->linked_elsewhere( $source, $type, $object_id, $space_id ) ) {
			return false;
		}
		if ( in_array( $object_id, array_column( $this->links_for_source_type( $space_id, $source, $type ), 'id' ), true ) ) {
			return true;
		}

		add_space_meta( $space_id, self::key( $source, $type, $object_id ), gmdate( 'Y-m-d H:i:s' ), true );

		/**
		 * Fires after a space is linked to an object.
		 *
		 * @since 1.2.7
		 *
		 * @param int    $space_id  Space.
		 * @param string $source    Source slug.
		 * @param string $type      Object type.
		 * @param int    $object_id Object id.
		 */
		do_action( 'buddynext_space_linked', $space_id, $source, $type, $object_id );

		$this->backfill( $space_id, $source, $type, $object_id );
		return true;
	}

	/**
	 * Remove one link. Members stay; a space left with no link stops being managed.
	 *
	 * @param int    $space_id  Space.
	 * @param string $source    Source slug.
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @return void
	 */
	public function unlink( int $space_id, string $source, string $type, int $object_id ): void {
		if ( ! delete_space_meta( $space_id, self::key( $source, $type, $object_id ) ) ) {
			return;
		}
		if ( ! $this->links( $space_id ) ) {
			delete_space_meta( $space_id, self::META_MANAGED );
		}

		/**
		 * Fires after a link is removed. The space and its members are untouched.
		 *
		 * @since 1.2.7
		 *
		 * @param int    $space_id  Space.
		 * @param string $source    Source slug.
		 * @param string $type      Object type.
		 * @param int    $object_id Object id.
		 */
		do_action( 'buddynext_space_unlinked', $space_id, $source, $type, $object_id );
	}

	/**
	 * Release every link to an object that no longer exists.
	 *
	 * The space and its members are left alone.
	 *
	 * @param string $source    Source slug.
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @return int[] Spaces the object was linked to.
	 */
	public function release_object( string $source, string $type, int $object_id ): array {
		$spaces = $this->spaces_for_object( $source, $type, $object_id );
		foreach ( $spaces as $space_id ) {
			$this->unlink( $space_id, $source, $type, $object_id );
		}
		return $spaces;
	}

	/**
	 * Create a private space for an object, mark it managed, and link it.
	 *
	 * @param string $source    Source slug.
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @param int    $owner_id  Member who will own the space.
	 * @return int New space id, or 0 when refused.
	 */
	public function create_and_link( string $source, string $type, int $object_id, int $owner_id ): int {
		$src = $this->source( $source );
		if ( null === $src || $owner_id <= 0 || $object_id <= 0 || ! in_array( $type, $src->link_types(), true ) || $this->spaces_for_object( $source, $type, $object_id ) ) {
			return 0;
		}

		$name = $src->link_label( $type, $object_id )['name'];
		if ( '' === $name ) {
			$name = __( 'Community', 'buddynext' );
		}

		$service  = new SpaceService();
		$space_id = $service->create(
			$owner_id,
			array(
				'name' => $name,
				'slug' => $this->unique_slug( $service, $name ),
				'type' => 'private',
			)
		);
		if ( is_wp_error( $space_id ) || (int) $space_id <= 0 ) {
			return 0;
		}

		update_space_meta( (int) $space_id, self::META_MANAGED, $source );
		$this->link( (int) $space_id, $source, $type, $object_id );
		return (int) $space_id;
	}

	/**
	 * Add a member to a space because an object grants them access.
	 *
	 * Passes the space's join gate for this one join, still honours bans, archives and
	 * a missing space, and stays quiet: no owner notification and no webhook. Idempotent.
	 *
	 * @param int $space_id Space.
	 * @param int $user_id  Member.
	 * @return bool Whether the member is in the space afterwards.
	 */
	public function join_from_source( int $space_id, int $user_id ): bool {
		$members = new SpaceMemberService();
		if ( $space_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		if ( $members->is_member( $space_id, $user_id ) ) {
			return true;
		}

		$grant = static fn(): bool => true;
		add_filter( 'buddynext_can_join_space', $grant, 99 );
		++self::$system_depth;
		try {
			$result = $members->join( $space_id, $user_id );
		} finally {
			--self::$system_depth;
			remove_filter( 'buddynext_can_join_space', $grant, 99 );
		}
		return ! is_wp_error( $result );
	}

	/**
	 * A member lost the access one link gave them: remove them if nothing else grants it.
	 *
	 * Removes only from a space BuddyNext created for a link, never the owner, and only
	 * when no other link on the space still grants the member access. A link whose
	 * source is not registered cannot be asked, so it counts as still granting.
	 *
	 * @param int    $space_id  Space.
	 * @param int    $user_id   Member.
	 * @param string $source    Source slug of the link that lost access.
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @return bool Whether the member was removed.
	 */
	public function leave_from_source( int $space_id, int $user_id, string $source, string $type, int $object_id ): bool {
		if ( $space_id <= 0 || $user_id <= 0 || ! $this->is_managed( $space_id ) ) {
			return false;
		}
		foreach ( $this->links( $space_id ) as $link ) {
			if ( $link['source'] === $source && $link['type'] === $type && $link['id'] === $object_id ) {
				continue;
			}
			$other = $this->source( $link['source'] );
			if ( null === $other || $other->link_grants_access( $link['type'], $link['id'], $user_id ) ) {
				return false;
			}
		}

		$members = new SpaceMemberService();
		if ( ! $members->is_member( $space_id, $user_id ) ) {
			return false;
		}
		// leave() already refuses the owner.
		return true === $members->leave( $space_id, $user_id );
	}

	/**
	 * Join one page of an object's roster to a space, then chain the rest.
	 *
	 * One bounded page per run, chained through Action Scheduler on the last user id:
	 * the cursor only advances, so the chain ends the first time a page comes back short.
	 * A small roster finishes inline.
	 *
	 * @param int    $space_id  Space.
	 * @param string $source    Source slug.
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @param int    $after     Resume after this user id.
	 * @return int Members handled in this run.
	 */
	public function backfill( int $space_id, string $source, string $type, int $object_id, int $after = 0 ): int {
		$src = $this->source( $source );
		if ( null === $src || ! in_array( $type, $src->link_types(), true ) ) {
			return 0;
		}

		/**
		 * Roster rows joined per backfill run.
		 *
		 * Clamped to at least 1. The name carries `_size` so it never collides with the
		 * continuation action's hook name.
		 *
		 * @since 1.2.7
		 *
		 * @param int    $size      Rows per run.
		 * @param string $source    Source slug.
		 * @param string $type      Object type.
		 * @param int    $object_id Object id.
		 */
		$chunk = max( 1, (int) apply_filters( 'buddynext_space_link_backfill_chunk_size', self::BACKFILL_CHUNK, $source, $type, $object_id ) );

		$user_ids = array_map( 'intval', $src->link_roster( $type, $object_id, $after, $chunk ) );
		foreach ( $user_ids as $user_id ) {
			$this->join_from_source( $space_id, $user_id );
		}

		if ( count( $user_ids ) >= $chunk && function_exists( 'as_enqueue_async_action' ) ) {
			$args = array( $space_id, $source, $type, $object_id, (int) max( $user_ids ) );
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::BACKFILL_HOOK, $args, 'buddynext' ) ) {
				as_enqueue_async_action( self::BACKFILL_HOOK, $args, 'buddynext' );
			}
		}
		return count( $user_ids );
	}

	/**
	 * Action Scheduler entry point for a chained backfill page.
	 *
	 * Exits quietly when the link is gone or its source is no longer registered.
	 *
	 * @param mixed $space_id  Space.
	 * @param mixed $source    Source slug.
	 * @param mixed $type      Object type.
	 * @param mixed $object_id Object id.
	 * @param mixed $after     Cursor.
	 * @return void
	 */
	public function run_backfill_chunk( $space_id = 0, $source = '', $type = '', $object_id = 0, $after = 0 ): void {
		$space_id  = (int) $space_id;
		$object_id = (int) $object_id;
		if ( $space_id <= 0 || $object_id <= 0 || ! in_array( $space_id, $this->spaces_for_object( (string) $source, (string) $type, $object_id ), true ) ) {
			return;
		}
		$this->backfill( $space_id, (string) $source, (string) $type, $object_id, (int) $after );
	}

	/**
	 * Links on a space for one source and type.
	 *
	 * @param int    $space_id Space.
	 * @param string $source   Source slug.
	 * @param string $type     Object type.
	 * @return array<int,array{source:string,type:string,id:int,created_at:string}>
	 */
	private function links_for_source_type( int $space_id, string $source, string $type ): array {
		return array_values( array_filter( $this->links_for_source( $space_id, $source ), static fn( array $l ): bool => $l['type'] === $type ) );
	}

	/**
	 * The meta key of a link.
	 *
	 * @param string $source    Source slug.
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @return string
	 */
	private static function key( string $source, string $type, int $object_id ): string {
		return self::META_PREFIX . $source . ':' . $type . ':' . $object_id;
	}

	/**
	 * A unique, non-reserved slug (SpaceService::create refuses a taken one).
	 *
	 * @param SpaceService $service Space service.
	 * @param string       $name    Space name.
	 * @return string
	 */
	private function unique_slug( SpaceService $service, string $name ): string {
		$base = sanitize_title( $name );
		$base = '' === $base ? 'community' : $base;
		$slug = $base;
		$n    = 2;
		while ( SpaceService::is_reserved_slug( $slug ) || null !== $service->get_by_slug( $slug ) ) {
			$slug = $base . '-' . $n;
			++$n;
		}
		return $slug;
	}
}
