<?php
/**
 * Bring unedited email templates up to their current default wording.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Email templates are seeded into bn_email_templates once, so a later change to
 * a default's wording never reaches an existing site on its own. This class is
 * the one way it does, and it never touches an owner's edits.
 *
 * Every plugin that changes a default registers it on the
 * `buddynext_email_default_history` filter: the template type, its current
 * default, and each previous default. refresh_unedited() then updates a field
 * only where the stored value still equals one of those previous defaults
 * exactly. A field the owner edited matches none of them and is left alone.
 * Fields are handled one at a time, so an owner who rewrote the subject still
 * gets an improved body.
 *
 * Runs synchronously and is idempotent: the upgrade step and the Tools >
 * Repair "Restore default emails" button both call it, and a second run
 * changes nothing.
 */
final class EmailDefaults {

	/**
	 * Template columns a default can change.
	 */
	private const FIELDS = array( 'subject', 'preview_text', 'body_html' );

	/**
	 * Seeded defaults per locale, built once per request.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private static array $seeds = array();

	/**
	 * Swap every unedited field of a template row for the default in the current locale.
	 *
	 * A row is seeded once, in the site language (English before 1.3.0). Call this
	 * inside switch_to_user_locale() and a member whose language differs from the
	 * site's gets the default wording in their own language. A field the owner
	 * edited matches neither seed and is returned as stored.
	 *
	 * @param string $type Template type.
	 * @param object $row  bn_email_templates row (subject, preview_text, body_html).
	 * @return object The row, with unedited fields localised.
	 */
	public static function localise( string $type, object $row ): object {
		$site_locale = (string) get_option( 'WPLANG' );
		$site_locale = '' === $site_locale ? 'en_US' : $site_locale;
		$current     = self::seed_in( determine_locale(), $type );
		if ( array() === $current ) {
			return $row;
		}
		$seeded = array( self::seed_in( 'en_US', $type ), self::seed_in( $site_locale, $type ) );

		$row = clone $row;
		foreach ( self::FIELDS as $field ) {
			$stored = self::normalise( (string) ( $row->$field ?? '' ) );
			foreach ( $seeded as $seed ) {
				if ( isset( $seed[ $field ], $current[ $field ] ) && self::normalise( $seed[ $field ] ) === $stored ) {
					$row->$field = $current[ $field ];
					break;
				}
			}
		}

		return $row;
	}

	/**
	 * The default fields of one template in a locale.
	 *
	 * @param string $locale Locale code.
	 * @param string $type   Template type.
	 * @return array<string, string>
	 */
	private static function seed_in( string $locale, string $type ): array {
		if ( ! isset( self::$seeds[ $locale ] ) ) {
			$switched = determine_locale() !== $locale && switch_to_locale( $locale );
			foreach ( \BuddyNext\Core\Installer::email_template_seeds() as $seed ) {
				self::$seeds[ $locale ][ (string) $seed['type'] ] = array_intersect_key( $seed, array_flip( self::FIELDS ) );
			}
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		return self::$seeds[ $locale ][ $type ] ?? array();
	}

	/**
	 * Every registered default change.
	 *
	 * @return array<string, array{current: array<string,string>, previous: array<int, array<string,string>>}>
	 *         Keyed by template type. `current` and each `previous` entry hold any of
	 *         subject / preview_text / body_html.
	 */
	public static function history(): array {
		/**
		 * Register email templates whose default wording changed.
		 *
		 * @since 1.2.4
		 *
		 * @param array $history Template type => array{ current: array, previous: array[] }.
		 *                       Starts with BuddyNext's own (Installer::email_default_history()).
		 */
		return (array) apply_filters( 'buddynext_email_default_history', \BuddyNext\Core\Installer::email_default_history() );
	}

	/**
	 * Update every unedited template field to its current default.
	 *
	 * @param array<string, array<string, mixed>>|null $history Defaults to history().
	 * @return int Number of template fields updated.
	 */
	public static function refresh_unedited( ?array $history = null ): int {
		global $wpdb;

		$updated = 0;
		foreach ( self::stale_rows( $history ) as $hit ) {
			// Column from the FIELDS whitelist; every value is prepared. updated_at is
			// kept as it was: this is a wording refresh, not an owner edit.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_email_templates SET `{$hit['field']}` = %s, updated_at = updated_at WHERE id = %d",
					$hit['current'],
					$hit['id']
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $updated;
	}

	/**
	 * How many templates still carry an old default somewhere (for Tools > Repair).
	 *
	 * @param array<string, array<string, mixed>>|null $history Defaults to history().
	 * @return int Number of templates refresh_unedited() would change.
	 */
	public static function outdated_count( ?array $history = null ): int {
		return count( array_unique( array_column( self::stale_rows( $history ), 'id' ) ) );
	}

	/**
	 * Stored template fields that still equal a previous default.
	 *
	 * Compared after normalise(), so a copy that only differs in the whitespace
	 * between tags (an editor save puts each paragraph on its own line) still
	 * counts as unedited. Any change to the words is an edit and is kept.
	 *
	 * @param array<string, array<string, mixed>>|null $history Defaults to history().
	 * @return array<int, array{id: int, field: string, current: string}>
	 */
	private static function stale_rows( ?array $history ): array {
		global $wpdb;

		$hits = array();
		foreach ( self::stale_fields( $history ) as $change ) {
			$old = array_map( array( self::class, 'normalise' ), $change['old'] );
			// Column from the FIELDS whitelist; the type is prepared.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT id, `{$change['field']}` AS value FROM {$wpdb->prefix}bn_email_templates WHERE type = %s", $change['type'] ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				if ( in_array( self::normalise( (string) $row['value'] ), $old, true ) ) {
					$hits[] = array(
						'id'      => (int) $row['id'],
						'field'   => $change['field'],
						'current' => $change['current'],
					);
				}
			}
		}
		return $hits;
	}

	/**
	 * A template value with formatting-only whitespace removed.
	 *
	 * @param string $value Stored or default value.
	 * @return string
	 */
	private static function normalise( string $value ): string {
		return trim( (string) preg_replace( '/>\s+</', '><', str_replace( "\r\n", "\n", $value ) ) );
	}

	/**
	 * The (type, field) pairs that have previous defaults to replace.
	 *
	 * @param array<string, array<string, mixed>>|null $history Defaults to history().
	 * @return array<int, array{type: string, field: string, current: string, old: array<int,string>}>
	 */
	private static function stale_fields( ?array $history ): array {
		$changes = array();
		foreach ( ( null === $history ? self::history() : $history ) as $type => $entry ) {
			$current  = (array) ( $entry['current'] ?? array() );
			$previous = (array) ( $entry['previous'] ?? array() );
			foreach ( self::FIELDS as $field ) {
				if ( ! isset( $current[ $field ] ) ) {
					continue;
				}
				$now = (string) $current[ $field ];
				$old = array();
				foreach ( $previous as $version ) {
					$value = (string) ( ( (array) $version )[ $field ] ?? '' );
					if ( '' !== $value && $value !== $now ) {
						$old[] = $value;
					}
				}
				if ( array() !== $old ) {
					$changes[] = array(
						'type'    => (string) $type,
						'field'   => $field,
						'current' => $now,
						'old'     => array_values( array_unique( $old ) ),
					);
				}
			}
		}
		return $changes;
	}
}
