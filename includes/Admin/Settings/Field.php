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
	 * Build a field from an associative descriptor.
	 *
	 * @param array{key:string,type:string,label?:string,hint?:string,default?:mixed,choices?:array<int|string,string>,min?:int,max?:int,sanitize?:callable|string} $args Descriptor.
	 */
	public function __construct( array $args ) {
		$this->key      = (string) $args['key'];
		$this->type     = (string) $args['type'];
		$this->label    = (string) ( $args['label'] ?? '' );
		$this->hint     = (string) ( $args['hint'] ?? '' );
		$this->default  = $args['default'] ?? '';
		$this->choices  = (array) ( $args['choices'] ?? array() );
		$this->min      = isset( $args['min'] ) ? (int) $args['min'] : null;
		$this->max      = isset( $args['max'] ) ? (int) $args['max'] : null;
		$this->sanitize = $args['sanitize'] ?? null;
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
