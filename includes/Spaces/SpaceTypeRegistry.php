<?php
/**
 * Space-type registry for BuddyNext.
 *
 * The single source of truth for what a space type *means*. Each type declares
 * a visibility level, a join method, and UI label/tone; every behavioural rule
 * (listed in the directory? hidden from non-members? content member-gated?) is
 * DERIVED from the visibility level, so a custom type works end-to-end without
 * touching the services and templates that consult it.
 *
 * Built like a tool, not a SaaS: register a custom type via the
 * `buddynext_space_types` filter (same shape as the defaults). The three core
 * types — open / private / secret — are always guaranteed to exist; a filter
 * may tweak them but cannot remove them.
 *
 *   open    → public  visibility, direct  join — listed, content visible to all.
 *   private → private visibility, request join — listed, content gated.
 *   secret  → secret  visibility, invite  join — unlisted, whole space hidden.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves space-type semantics and UI presentation.
 */
class SpaceTypeRegistry {

	private const VISIBILITIES = array( 'public', 'private', 'secret' );
	private const JOINS        = array( 'direct', 'request', 'invite' );

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Resolved, normalised type map (memoised per request).
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private ?array $types = null;

	/**
	 * Shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Built-in type definitions.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function defaults(): array {
		return array(
			'open'    => array(
				'label'      => __( 'Open', 'buddynext' ),
				'tone'       => 'success',
				'visibility' => 'public',
				'join'       => 'direct',
			),
			'private' => array(
				'label'      => __( 'Private', 'buddynext' ),
				'tone'       => 'warn',
				'visibility' => 'private',
				'join'       => 'request',
			),
			'secret'  => array(
				'label'      => __( 'Secret', 'buddynext' ),
				'tone'       => 'danger',
				'visibility' => 'secret',
				'join'       => 'invite',
			),
		);
	}

	/**
	 * All registered types, keyed by slug.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function all(): array {
		if ( null !== $this->types ) {
			return $this->types;
		}

		/**
		 * Filter the registered space types.
		 *
		 * Each entry is keyed by a slug and provides: label (string), tone (a UI
		 * badge tone slug), visibility ('public'|'private'|'secret'), and join
		 * ('direct'|'request'|'invite'). The behavioural rules are derived from
		 * `visibility`. The built-in open/private/secret types cannot be removed.
		 *
		 * @param array<string, array<string, string>> $types Default type map.
		 */
		$raw = (array) apply_filters( 'buddynext_space_types', $this->defaults() );

		$out = array();
		foreach ( $raw as $key => $cfg ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $cfg ) ) {
				continue;
			}

			$visibility = in_array( $cfg['visibility'] ?? 'public', self::VISIBILITIES, true ) ? (string) $cfg['visibility'] : 'public';
			$join       = in_array( $cfg['join'] ?? 'direct', self::JOINS, true ) ? (string) $cfg['join'] : 'direct';

			$out[ $key ] = array(
				'label'      => (string) ( $cfg['label'] ?? ucfirst( $key ) ),
				'tone'       => (string) ( $cfg['tone'] ?? 'info' ),
				'visibility' => $visibility,
				'join'       => $join,
			);
		}

		// Guarantee the three core types always exist (a filter may tweak but not drop them).
		$this->types = $out + $this->defaults();

		return $this->types;
	}

	/**
	 * Resolve one type's config, falling back to 'open' for unknown slugs.
	 *
	 * @param string $type Type slug.
	 * @return array<string, string>
	 */
	public function get( string $type ): array {
		$all = $this->all();

		return $all[ $type ] ?? $all['open'];
	}

	/**
	 * All registered type slugs.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->all() );
	}

	/**
	 * Whether a slug is a registered type.
	 *
	 * @param string $type Type slug.
	 * @return bool
	 */
	public function is_valid( string $type ): bool {
		return isset( $this->all()[ $type ] );
	}

	/**
	 * Human label for a type.
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public function label( string $type ): string {
		return $this->get( $type )['label'];
	}

	/**
	 * UI badge tone for a type.
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public function tone( string $type ): string {
		return $this->get( $type )['tone'];
	}

	/**
	 * Visibility level for a type ('public'|'private'|'secret').
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public function visibility( string $type ): string {
		return $this->get( $type )['visibility'];
	}

	/**
	 * Join method for a type ('direct'|'request'|'invite').
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public function join_method( string $type ): string {
		return $this->get( $type )['join'];
	}

	/**
	 * Whether the type appears in public directory + search listings.
	 *
	 * @param string $type Type slug.
	 * @return bool
	 */
	public function is_listed( string $type ): bool {
		return 'secret' !== $this->visibility( $type );
	}

	/**
	 * Whether the whole space is hidden from non-members (404, no member list).
	 *
	 * @param string $type Type slug.
	 * @return bool
	 */
	public function is_hidden_from_non_members( string $type ): bool {
		return 'secret' === $this->visibility( $type );
	}

	/**
	 * Whether the space's content (feed/posts) is gated to members only.
	 *
	 * @param string $type Type slug.
	 * @return bool
	 */
	public function content_requires_membership( string $type ): bool {
		return 'public' !== $this->visibility( $type );
	}

	/**
	 * Slugs that must be excluded from public listings (secret-equivalent types).
	 *
	 * @return string[]
	 */
	public function unlisted_keys(): array {
		return array_values( array_filter( $this->keys(), fn( $k ) => ! $this->is_listed( $k ) ) );
	}
}
