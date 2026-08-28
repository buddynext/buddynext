<?php
/**
 * Human-readable labels for moderation objects.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Resolves a (object_type, object_id) pair to something a moderator can act on.
 *
 * The member side of this problem was solved once, in buddynext_member_label():
 * an admin table's identity column has to say WHO, and a bare "#409" says
 * nothing. The OBJECT side was never swept, so two moderation surfaces printed
 * the raw pair — the report queue rendered "User #519" (the moderator has to
 * decide whether to strike or suspend that person, with no name to judge), and
 * the moderation log rendered "post #4046" for a post that no longer exists,
 * indistinguishable from one that does.
 *
 * That second half became load-bearing when bn_mod_log stopped being deleted
 * alongside its target (Basecamp 10233672699): audit rows now deliberately
 * outlive their objects, so "this target is gone" is information the log has to
 * carry rather than imply.
 *
 * Honesty rule: this NEVER claims a target is deleted for a type BuddyNext
 * cannot check. Messages live in the WPMediaVerse engine and the moderation
 * layer is extensible (buddynext_content_removal_handled lets an add-on claim
 * its own object type), so an unrecognised type is labelled without any
 * existence claim, and the buddynext_object_label filter lets whoever owns that
 * type answer properly.
 *
 * @since 1.1.6
 */
final class ObjectLabels {

	/**
	 * Resolved existence, keyed "type:id". True = alive, false = gone.
	 *
	 * @var array<string, bool>
	 */
	private static array $exists = array();

	/**
	 * Resolved display names for types that have one, keyed "type:id".
	 *
	 * @var array<string, string>
	 */
	private static array $names = array();

	/**
	 * Types this plugin owns and can therefore make an existence claim about.
	 *
	 * Each maps to its table and, where the type has a human name worth showing
	 * instead of an id, the column holding it.
	 *
	 * @var array<string, array{table:string, name_column:?string}>
	 */
	private const OWNED = array(
		'post'    => array(
			'table'       => 'bn_posts',
			'name_column' => null,
		),
		'comment' => array(
			'table'       => 'bn_comments',
			'name_column' => null,
		),
		'space'   => array(
			'table'       => 'bn_spaces',
			'name_column' => 'name',
		),
	);

	/**
	 * Resolve one object to a label.
	 *
	 * @param string $object_type Object type slug.
	 * @param int    $object_id   Object id.
	 * @return string Plain text; callers still escape at output.
	 */
	public static function label( string $object_type, int $object_id ): string {
		$object_type = sanitize_key( $object_type );

		if ( '' === $object_type || $object_id <= 0 ) {
			return "\u{2014}";
		}

		// A reported "user" IS a member, so it gets the member vocabulary rather
		// than a second, subtly different one.
		if ( 'user' === $object_type ) {
			$label = buddynext_member_label( $object_id );
		} elseif ( isset( self::OWNED[ $object_type ] ) ) {
			$label = self::owned_label( $object_type, $object_id );
		} else {
			// Not ours to judge. Name it, claim nothing about whether it lives.
			$label = sprintf( '%s #%d', ucfirst( $object_type ), $object_id );
		}

		/**
		 * Filters the label for a moderation object.
		 *
		 * An extension that claims an object type through
		 * buddynext_content_removal_handled should also name it here, or its
		 * reports and log rows show a bare "Thing #12" to the moderator.
		 *
		 * @since 1.1.6
		 *
		 * @param string $label       Resolved label.
		 * @param string $object_type Object type slug.
		 * @param int    $object_id   Object id.
		 */
		return (string) apply_filters( 'buddynext_object_label', $label, $object_type, $object_id );
	}

	/**
	 * Whether an object still exists, when BuddyNext is in a position to know.
	 *
	 * Returns null for a type this plugin does not own (a message, or a type an
	 * add-on claimed), because "I cannot check" and "it is gone" are different
	 * answers and a caller must not treat them alike.
	 *
	 * @since 1.1.6
	 *
	 * @param string $object_type Object type slug.
	 * @param int    $object_id   Object id.
	 * @return bool|null True alive, false gone, null unknowable.
	 */
	public static function exists( string $object_type, int $object_id ): ?bool {
		$object_type = sanitize_key( $object_type );

		if ( '' === $object_type || $object_id <= 0 ) {
			return null;
		}

		if ( 'user' === $object_type ) {
			return (bool) get_userdata( $object_id );
		}

		if ( ! isset( self::OWNED[ $object_type ] ) ) {
			return null;
		}

		$key = $object_type . ':' . $object_id;
		if ( ! array_key_exists( $key, self::$exists ) ) {
			self::prime( array( array( $object_type, $object_id ) ) );
		}

		return ! empty( self::$exists[ $key ] );
	}

	/**
	 * Label an object this plugin owns, tombstoning it when it is gone.
	 *
	 * @param string $object_type Object type slug.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	private static function owned_label( string $object_type, int $object_id ): string {
		$key = $object_type . ':' . $object_id;

		if ( ! array_key_exists( $key, self::$exists ) ) {
			self::prime( array( array( $object_type, $object_id ) ) );
		}

		$alive = ! empty( self::$exists[ $key ] );
		$name  = (string) ( self::$names[ $key ] ?? '' );

		if ( ! $alive ) {
			return self::deleted_label( $object_type, $object_id );
		}

		if ( '' !== $name ) {
			return $name;
		}

		return sprintf( '%s #%d', ucfirst( $object_type ), $object_id );
	}

	/**
	 * The tombstone wording, matching buddynext_member_label()'s shape.
	 *
	 * The id stays in parentheses because it is the only handle a moderator has
	 * left for chasing the row once the object is gone.
	 *
	 * @param string $object_type Object type slug.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	private static function deleted_label( string $object_type, int $object_id ): string {
		switch ( $object_type ) {
			case 'post':
				/* translators: %d: id of a post that no longer exists. */
				return sprintf( __( 'Deleted post (#%d)', 'buddynext' ), $object_id );
			case 'comment':
				/* translators: %d: id of a comment that no longer exists. */
				return sprintf( __( 'Deleted comment (#%d)', 'buddynext' ), $object_id );
			case 'space':
				/* translators: %d: id of a space that no longer exists. */
				return sprintf( __( 'Deleted space (#%d)', 'buddynext' ), $object_id );
		}

		/* translators: 1: object type, 2: id of an object that no longer exists. */
		return sprintf( __( 'Deleted %1$s (#%2$d)', 'buddynext' ), $object_type, $object_id );
	}

	/**
	 * Resolve a whole page of objects up front.
	 *
	 * One query per TYPE rather than one per row. A moderation queue renders up
	 * to a page of reports, and resolving each as it is printed is the N+1 the
	 * big-site rules exist to prevent.
	 *
	 * @param array<int, array{0:string, 1:int}> $pairs List of [ type, id ].
	 * @return void
	 */
	public static function prime( array $pairs ): void {
		global $wpdb;

		$by_type  = array();
		$user_ids = array();

		foreach ( $pairs as $pair ) {
			$type = sanitize_key( (string) ( $pair[0] ?? '' ) );
			$id   = (int) ( $pair[1] ?? 0 );

			if ( '' === $type || $id <= 0 ) {
				continue;
			}

			if ( 'user' === $type ) {
				$user_ids[] = $id;
				continue;
			}

			if ( ! isset( self::OWNED[ $type ] ) || array_key_exists( $type . ':' . $id, self::$exists ) ) {
				continue;
			}

			$by_type[ $type ][] = $id;
		}

		// Prime WordPress' own user cache so buddynext_member_label()'s
		// get_userdata() calls do not each hit the database.
		if ( ! empty( $user_ids ) ) {
			cache_users( array_values( array_unique( $user_ids ) ) );
		}

		foreach ( $by_type as $type => $ids ) {
			$ids = array_values( array_unique( $ids ) );

			$table       = $wpdb->prefix . self::OWNED[ $type ]['table'];
			$name_column = self::OWNED[ $type ]['name_column'];
			$columns     = null === $name_column ? 'id' : 'id, `' . $name_column . '` AS label';

			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $columns and $table come from the OWNED map (never user input); $placeholders is a counted "%d, ..." list and every id is bound.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$columns} FROM {$table} WHERE id IN ( {$placeholders} )",
					...$ids
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			// Absent ids are recorded as gone, so a second look does not re-query.
			foreach ( $ids as $id ) {
				self::$exists[ $type . ':' . $id ] = false;
			}

			foreach ( (array) $rows as $row ) {
				$key                  = $type . ':' . (int) $row['id'];
				self::$exists[ $key ] = true;
				if ( isset( $row['label'] ) ) {
					self::$names[ $key ] = (string) $row['label'];
				}
			}
		}
	}

	/**
	 * Drop the per-request cache. Test seam.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$exists = array();
		self::$names  = array();
	}
}
