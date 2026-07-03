<?php
/**
 * A single admin option descriptor.
 *
 * One field is declared once (its key, type, label, hint, default, and any
 * type-specific extras). Render, Settings-API registration, sanitize, and the
 * command-palette search index all derive from this descriptor — so adding a
 * field makes it saved, sanitized, rendered, and searchable by construction.
 *
 * @package BuddyNext\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Settings;

/**
 * Immutable option descriptor.
 */
final class Field {

	/**
	 * WP option name (also the input name/id basis).
	 *
	 * @var string
	 */
	public string $key;

	/**
	 * Field type (see FieldTypes): toggle|text|textarea|number|select|color|media|password|secret|email|url|readonly.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Human label.
	 *
	 * @var string
	 */
	public string $label;

	/**
	 * Optional hint shown beneath the control.
	 *
	 * @var string
	 */
	public string $hint;

	/**
	 * Registered default / read fallback.
	 *
	 * @var mixed
	 */
	public mixed $default;

	/**
	 * Whether a `default` was explicitly declared. When false, the driver
	 * registers no Settings-API default, so read sites keep using their own
	 * inline get_option() fallback (which may be dynamic) — matching the prior
	 * bespoke behaviour exactly.
	 *
	 * @var bool
	 */
	public bool $has_default;

	/**
	 * Value => label map for `select`.
	 *
	 * @var array<int|string, string>
	 */
	public array $choices;

	/**
	 * Minimum for `number`.
	 *
	 * @var int|null
	 */
	public ?int $min;

	/**
	 * Maximum for `number`.
	 *
	 * @var int|null
	 */
	public ?int $max;

	/**
	 * Per-field sanitize override; null = the type default from FieldTypes.
	 *
	 * @var callable|string|null
	 */
	public $sanitize;

	/**
	 * Optional callback returning the `select` choices at render time (dynamic
	 * option lists — categories, roles, pages). Null = use the static $choices.
	 *
	 * @var callable|null
	 */
	public $choices_callback;

	/**
	 * Optional callback returning the value to DISPLAY in the control (for fields
	 * whose shown value is computed/resolved, e.g. an effective sender identity or
	 * a dynamic fallback). Null = show get_option( key, default ). The input still
	 * posts under $key, so saving persists whatever is shown — matching the prior
	 * bespoke behaviour.
	 *
	 * @var callable|null
	 */
	public $value_callback;

	/**
	 * Optional callback returning the hint text at render time (for hints that
	 * change with runtime state, e.g. "requires plugin X" when X is inactive).
	 * Null = use the static $hint.
	 *
	 * @var callable|null
	 */
	public $hint_callback;

	/**
	 * Optional callback returning whether the control renders read-only/disabled
	 * (e.g. a toggle whose backing plugin is inactive). Null = always enabled.
	 *
	 * @var callable|null
	 */
	public $disabled_callback;

	/**
	 * For type `custom`: a callback that echoes bespoke markup for composite
	 * controls that are not a single standard row (e.g. a reaction palette, a
	 * provider-credentials block, a data table). Not registered or sanitized by
	 * the driver (its underlying option, if any, is registered bespoke) and not
	 * search-indexed. Null for standard field types.
	 *
	 * @var callable|null
	 */
	public $render_callback;

	/**
	 * Build a field from an associative descriptor.
	 *
	 * @param array{key:string,type:string,label?:string,hint?:string,default?:mixed,choices?:array<int|string,string>,min?:int,max?:int,sanitize?:callable|string} $args Descriptor.
	 */
	public function __construct( array $args ) {
		$this->key               = (string) $args['key'];
		$this->type              = (string) $args['type'];
		$this->label             = (string) ( $args['label'] ?? '' );
		$this->hint              = (string) ( $args['hint'] ?? '' );
		$this->default           = $args['default'] ?? '';
		$this->has_default       = array_key_exists( 'default', $args );
		$this->choices           = (array) ( $args['choices'] ?? array() );
		$this->min               = isset( $args['min'] ) ? (int) $args['min'] : null;
		$this->max               = isset( $args['max'] ) ? (int) $args['max'] : null;
		$this->sanitize          = $args['sanitize'] ?? null;
		$this->choices_callback  = $args['choices_callback'] ?? null;
		$this->value_callback    = $args['value_callback'] ?? null;
		$this->hint_callback     = $args['hint_callback'] ?? null;
		$this->disabled_callback = $args['disabled_callback'] ?? null;
		$this->render_callback   = $args['render_callback'] ?? null;
	}

	/**
	 * Resolve the hint — the dynamic callback if set, else the static hint.
	 *
	 * @return string
	 */
	public function hint(): string {
		if ( null !== $this->hint_callback ) {
			return (string) call_user_func( $this->hint_callback );
		}
		return $this->hint;
	}

	/**
	 * Whether the control renders read-only/disabled.
	 *
	 * @return bool
	 */
	public function disabled(): bool {
		if ( null !== $this->disabled_callback ) {
			return (bool) call_user_func( $this->disabled_callback );
		}
		return false;
	}

	/**
	 * Resolve the select choices — the dynamic callback if set, else the static list.
	 *
	 * @return array<int|string, string>
	 */
	public function choices(): array {
		if ( null !== $this->choices_callback ) {
			return (array) call_user_func( $this->choices_callback );
		}
		return $this->choices;
	}

	/**
	 * Resolve the value to display in the control.
	 *
	 * @return mixed
	 */
	public function display_value() {
		if ( null !== $this->value_callback ) {
			return call_user_func( $this->value_callback );
		}
		return get_option( $this->key, $this->default );
	}

	/**
	 * Stable DOM anchor for deep-linking from the command palette.
	 *
	 * @return string
	 */
	public function anchor(): string {
		return 'bn-opt-' . sanitize_key( $this->key );
	}

	/**
	 * AdminPageBase render helper for this field's type.
	 *
	 * @return string
	 */
	public function render_helper(): string {
		return FieldTypes::render_helper( $this->type );
	}

	/**
	 * Resolved sanitize callback: the per-field override, else the type default.
	 *
	 * @return callable|string
	 */
	public function sanitizer() {
		return $this->sanitize ?? FieldTypes::sanitizer( $this->type );
	}
}
