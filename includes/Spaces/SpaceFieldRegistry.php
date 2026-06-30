<?php
/**
 * Space field registry — typed, owner-editable space fields over bn_space_meta.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use BuddyNext\Profile\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * The single registration surface for per-space fields.
 *
 * One registration drives all four surfaces with no parallel logic:
 *   1. Storage     — register_meta('bn_space', …) over the bn_space_meta table.
 *   2. Render+save — the FieldType engine renders the management input and
 *                    sanitises the saved value (same engine the profile editor uses).
 *   3. REST/app    — show_in_rest exposes the field on the space meta surface.
 *   4. Search      — public, searchable fields can be folded into the space index.
 *
 * Core registers its own built-in space options here too, through the exact same
 * path a third party uses (no two-tier system). Registrants hook the
 * `buddynext_register_space_fields` action (fired on init, mirroring the Nav
 * API's `buddynext_register_nav`) and call register() / buddynext_register_space_field().
 */
final class SpaceFieldRegistry {

	/**
	 * Field types eligible to be promoted to a space tab — long-form reference
	 * content that earns its own navigation tab (textarea = a content page, url =
	 * a labelled link/CTA). Short/scalar types (boolean/select/number) show on
	 * About instead.
	 *
	 * @var string[]
	 */
	private const TAB_TYPES = array( 'textarea', 'url' );

	/**
	 * Per-space meta key holding the list of field keys an owner has promoted to
	 * tabs on that space.
	 *
	 * @var string
	 */
	private const PROMOTED_TABS_KEY = 'promoted_field_tabs';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered field definitions, keyed by field key.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $fields = array();

	/**
	 * Whether register_meta() has already run for collected fields. Late
	 * registrations after boot register their meta immediately.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Resolve the shared instance.
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
	 * Register a space field.
	 *
	 * Idempotent on key — the last registration for a key wins, so a site owner
	 * filter can override a core field without a duplicate row.
	 *
	 * @param string              $key  Field/meta key.
	 * @param array<string,mixed> $args Field definition (label, type, single,
	 *                                  show_in_rest, searchable, visibility,
	 *                                  section, sort_order, options, is_required,
	 *                                  default, description).
	 * @return void
	 */
	public function register( string $key, array $args = array() ): void {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return;
		}

		$type       = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : 'text';
		$visibility = in_array( $args['visibility'] ?? 'public', array( 'public', 'members' ), true )
			? (string) $args['visibility']
			: 'public';

		$field = array(
			'key'          => $key,
			'label'        => isset( $args['label'] ) ? (string) $args['label'] : ucfirst( str_replace( '_', ' ', $key ) ),
			'description'  => isset( $args['description'] ) ? (string) $args['description'] : '',
			'type'         => $type,
			'single'       => ! isset( $args['single'] ) || (bool) $args['single'],
			'show_in_rest' => ! isset( $args['show_in_rest'] ) || (bool) $args['show_in_rest'],
			// Only text-capable types can back a searchable mirror.
			'searchable'   => ! empty( $args['searchable'] ) && FieldType::is_text_searchable( $type ),
			'visibility'   => $visibility,
			'section'      => isset( $args['section'] ) ? sanitize_key( (string) $args['section'] ) : 'general',
			'sort_order'   => isset( $args['sort_order'] ) ? (int) $args['sort_order'] : 10,
			'options'      => isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array(),
			'is_required'  => ! empty( $args['is_required'] ),
			'default'      => $args['default'] ?? '',
			// BuddyNext's own built-in fields set core=true; they have bespoke
			// settings UI. Third-party fields (core=false) surface in the generic
			// "Custom fields" settings panel via get_custom_fields().
			'core'         => ! empty( $args['core'] ),
		);

		$this->fields[ $key ] = $field;

		if ( $this->booted ) {
			$this->register_meta_for( $field );
		}
	}

	/**
	 * Collect registrations and wire register_meta for each. Hooked on init.
	 *
	 * @return void
	 */
	public function boot(): void {
		/**
		 * Register per-space fields.
		 *
		 * Core and third parties call $registry->register() (or
		 * buddynext_register_space_field()) inside this action.
		 *
		 * @since 1.0.4
		 *
		 * @param SpaceFieldRegistry $registry The registry instance.
		 */
		do_action( 'buddynext_register_space_fields', $this );

		foreach ( $this->fields as $field ) {
			$this->register_meta_for( $field );
		}

		$this->booted = true;
	}

	/**
	 * Wire a single field to the native metadata API.
	 *
	 * @param array<string,mixed> $field Normalised field definition.
	 * @return void
	 */
	private function register_meta_for( array $field ): void {
		register_meta(
			'bn_space',
			(string) $field['key'],
			array(
				'type'              => 'string',
				'single'            => (bool) $field['single'],
				'show_in_rest'      => (bool) $field['show_in_rest'],
				'sanitize_callback' => static function ( $value ) use ( $field ) {
					return FieldType::sanitize( $field, $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $object_id, $user_id ) {
					// Only someone who manages the space may write its fields.
					return (bool) buddynext_service( 'permissions' )->can(
						(int) $user_id,
						'buddynext-manage-space',
						array( 'space_id' => (int) $object_id )
					);
				},
			)
		);
	}

	/**
	 * All registered fields, ordered by section then sort_order then key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_fields(): array {
		$fields = $this->fields;
		uasort(
			$fields,
			static function ( array $a, array $b ): int {
				return array( $a['section'], $a['sort_order'], $a['key'] )
					<=> array( $b['section'], $b['sort_order'], $b['key'] );
			}
		);

		return $fields;
	}

	/**
	 * Third-party (non-core) fields, ordered by section then sort_order. These
	 * have no bespoke settings UI, so the generic "Custom fields" panel renders
	 * them. Empty on a stock install (only the 8 built-in core fields exist).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_custom_fields(): array {
		return array_filter(
			$this->get_fields(),
			static function ( array $field ): bool {
				return empty( $field['core'] );
			}
		);
	}

	/**
	 * Whether a field may be promoted to a space tab: a third-party (non-core)
	 * field of a reference-content type (see TAB_TYPES).
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @return bool
	 */
	public function is_tab_eligible( array $field ): bool {
		return empty( $field['core'] )
			&& in_array( (string) ( $field['type'] ?? '' ), self::TAB_TYPES, true );
	}

	/**
	 * The eligible fields an owner has promoted to tabs on a space, ordered by
	 * section then sort_order. Intersects the stored promotion list with the
	 * currently-registered eligible fields, so a removed or now-ineligible field
	 * silently drops its tab.
	 *
	 * @param int $space_id Space ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function promoted_tab_fields( int $space_id ): array {
		$promoted = get_space_meta( $space_id, self::PROMOTED_TABS_KEY, true );
		$promoted = is_array( $promoted ) ? array_map( 'strval', $promoted ) : array();
		if ( empty( $promoted ) ) {
			return array();
		}

		$out = array();
		foreach ( $this->get_fields() as $field ) {
			if ( $this->is_tab_eligible( $field ) && in_array( (string) $field['key'], $promoted, true ) ) {
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * Whether a single field is currently promoted to a tab on a space.
	 *
	 * @param int    $space_id Space ID.
	 * @param string $key      Field key.
	 * @return bool
	 */
	public function is_promoted_tab( int $space_id, string $key ): bool {
		$promoted = get_space_meta( $space_id, self::PROMOTED_TABS_KEY, true );
		$promoted = is_array( $promoted ) ? array_map( 'strval', $promoted ) : array();
		return in_array( sanitize_key( $key ), $promoted, true );
	}

	/**
	 * Persist the set of fields promoted to tabs on a space. Validated against the
	 * currently-registered eligible fields so only real, promotable keys are saved.
	 *
	 * @param int      $space_id Space ID.
	 * @param string[] $keys     Field keys to promote.
	 * @return void
	 */
	public function set_promoted_tabs( int $space_id, array $keys ): void {
		$eligible = array();
		foreach ( $this->get_fields() as $field ) {
			if ( $this->is_tab_eligible( $field ) ) {
				$eligible[] = (string) $field['key'];
			}
		}
		$clean = array_values( array_intersect( array_map( 'sanitize_key', $keys ), $eligible ) );
		update_space_meta( $space_id, self::PROMOTED_TABS_KEY, $clean );
	}

	/**
	 * Concatenated plain text of a space's searchable + public field values, for
	 * folding into the public search index. Members-only / non-searchable values
	 * are excluded — global search is public-facing.
	 *
	 * @param int $space_id Space ID.
	 * @return string
	 */
	public function searchable_public_text( int $space_id ): string {
		$parts = array();
		foreach ( $this->get_fields() as $field ) {
			if ( empty( $field['searchable'] ) || 'public' !== ( $field['visibility'] ?? '' ) ) {
				continue;
			}
			$value = (string) get_space_meta( $space_id, (string) $field['key'], true );
			if ( '' !== $value ) {
				$parts[] = wp_strip_all_tags( $value );
			}
		}
		return trim( implode( ' ', $parts ) );
	}

	/**
	 * Whether a space has at least one searchable + public field with a value
	 * among a given set of keys (used to decide whether a field save needs a
	 * search re-index).
	 *
	 * @param string[] $keys Field keys that were just saved.
	 * @return bool
	 */
	public function any_searchable_public( array $keys ): bool {
		$keys = array_map( 'strval', $keys );
		foreach ( $this->get_fields() as $field ) {
			if ( ! empty( $field['searchable'] )
				&& 'public' === ( $field['visibility'] ?? '' )
				&& in_array( (string) $field['key'], $keys, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fields belonging to one management section, ordered by sort_order.
	 *
	 * @param string $section Section slug.
	 * @return array<string,array<string,mixed>>
	 */
	public function get_section_fields( string $section ): array {
		$section = sanitize_key( $section );

		return array_filter(
			$this->get_fields(),
			static function ( array $field ) use ( $section ): bool {
				return $field['section'] === $section;
			}
		);
	}

	/**
	 * A single field definition by key.
	 *
	 * @param string $key Field key.
	 * @return array<string,mixed>|null
	 */
	public function get_field( string $key ): ?array {
		return $this->fields[ sanitize_key( $key ) ] ?? null;
	}

	/**
	 * Resolve every visible field for a space with its current value.
	 *
	 * One payload shape consumed by both REST (GET /spaces/{id}) and the web
	 * management panel — so the app and the website render from identical data.
	 *
	 * @param int  $space_id            Space ID.
	 * @param bool $include_member_only Include 'members'-visibility fields (the
	 *                                  caller decides this from viewer membership /
	 *                                  manage capability).
	 * @return array<int,array<string,mixed>> Ordered list of field rows with value + display.
	 */
	public function resolve_for_space( int $space_id, bool $include_member_only ): array {
		$out = array();

		foreach ( $this->get_fields() as $field ) {
			if ( 'members' === $field['visibility'] && ! $include_member_only ) {
				continue;
			}

			$value = get_space_meta( $space_id, (string) $field['key'], true );
			if ( '' === (string) $value && '' !== (string) $field['default'] ) {
				$value = $field['default'];
			}

			$out[] = array(
				'key'         => $field['key'],
				'label'       => $field['label'],
				'description' => $field['description'],
				'type'        => $field['type'],
				'options'     => $field['options'],
				'section'     => $field['section'],
				'sort_order'  => $field['sort_order'],
				'visibility'  => $field['visibility'],
				'is_required' => $field['is_required'],
				'core'        => ! empty( $field['core'] ),
				'value'       => FieldType::rest_value( $field, $value ),
				'display'     => FieldType::display_text( $field, $value ),
			);
		}

		return $out;
	}

	/**
	 * Validate and persist submitted field values for a space.
	 *
	 * Atomic: if any submitted field fails validation nothing is written, so a
	 * form never half-saves. Unknown keys are ignored. Each value is sanitised
	 * through the FieldType engine before storage in bn_space_meta.
	 *
	 * @param int                 $space_id Space ID.
	 * @param array<string,mixed> $values   key => raw submitted value.
	 * @return array{saved:array<string,mixed>,errors:array<string,string>}
	 */
	public function save_for_space( int $space_id, array $values ): array {
		$errors    = array();
		$validated = array();

		foreach ( $values as $key => $raw ) {
			$field = $this->get_field( (string) $key );
			if ( null === $field ) {
				continue; // Ignore keys that are not registered fields.
			}

			$clean = FieldType::sanitize( $field, $raw );

			// FieldType::sanitize returns a WP_Error for an invalid value (bad
			// select option, malformed email/url/number, …).
			if ( is_wp_error( $clean ) ) {
				$errors[ $field['key'] ] = $clean->get_error_message();
				continue;
			}

			if ( $field['is_required'] && '' === (string) $clean ) {
				$errors[ $field['key'] ] = __( 'This field is required.', 'buddynext' );
				continue;
			}

			$validated[ $field['key'] ] = array(
				'field' => $field,
				'value' => $clean,
			);
		}

		if ( ! empty( $errors ) ) {
			return array(
				'saved'  => array(),
				'errors' => $errors,
			);
		}

		$saved = array();
		foreach ( $validated as $key => $entry ) {
			update_space_meta( $space_id, $key, $entry['value'] );
			$saved[ $key ] = FieldType::rest_value( $entry['field'], $entry['value'] );
		}

		if ( ! empty( $saved ) ) {
			/**
			 * Fires after space custom fields are saved — for ANY field, unlike
			 * buddynext_space_updated below which fires only when a searchable +
			 * public field changed (search re-index). Lets add-ons sync or react to
			 * non-searchable field changes too.
			 *
			 * @param int                 $space_id Space ID.
			 * @param array<string,mixed> $saved    Map of saved field key => REST value.
			 */
			do_action( 'buddynext_space_fields_saved', $space_id, $saved );
		}

		// When a searchable + public field changed, re-index the space so its value
		// becomes discoverable (the search content build folds it in). Consumed
		// only by SearchIndexListener::on_space_updated.
		if ( ! empty( $saved ) && $this->any_searchable_public( array_keys( $saved ) ) ) {
			do_action( 'buddynext_space_updated', $space_id );
		}

		return array(
			'saved'  => $saved,
			'errors' => array(),
		);
	}
}
