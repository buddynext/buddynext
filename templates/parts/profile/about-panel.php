<?php
/**
 * BuddyNext template part: profile About panel (schema-driven).
 *
 * Renders EVERY admin-defined profile field group through one type-driven
 * engine — no hardcoded group or sub-field keys. We cannot predict the owner's
 * schema (they add / rename / delete groups and fields), so we never render by
 * identity: we render by TYPE. Each group is dispatched by its group type
 * (flat vs repeater); each field by its field type's presentation mode
 * (FieldType::presentation_for → block | chips | link | inline), with the value
 * produced by FieldType::render_display(). A custom group or a renamed field
 * flows through unchanged; a deleted one simply isn't there.
 *
 * Basic Info's spine fields (headline / bio / pronouns / location / website) are
 * skipped here — the profile hero surfaces them — to avoid duplication.
 *
 * Rendered by ProfileNav's About `render` callable (which only registers the tab
 * when there is content). Returns nothing when empty.
 *
 * @package BuddyNext
 *
 * @var int $profile_user_id Required. Profile being viewed.
 * @var int $viewer_id       Optional. Current viewer (drives per-field visibility).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Profile\FieldType;

$bn_ap_uid    = isset( $profile_user_id ) ? (int) $profile_user_id : 0;
$bn_ap_viewer = isset( $viewer_id ) ? (int) $viewer_id : 0;
if ( $bn_ap_uid <= 0 ) {
	return;
}

$bn_ap_profile = buddynext_service( 'profiles' )->get_profile( $bn_ap_uid, $bn_ap_viewer );
if ( ! is_array( $bn_ap_profile ) ) {
	return;
}

/**
 * Fires before the About tab's field-group cards. Admins can echo extra
 * content (an integration panel, a custom section) at the top of the tab.
 *
 * @param int   $profile_user_id Profile being viewed.
 * @param int   $viewer_id       Current viewer.
 * @param array $profile         The hydrated, viewer-gated profile.
 */
do_action( 'buddynext_profile_about_before', $bn_ap_uid, $bn_ap_viewer, $bn_ap_profile );

// Spine fields the hero already shows — skipped only within basic_info.
$bn_ap_hero_keys = array( 'headline', 'bio', 'pronouns', 'location', 'website' );

/**
 * Whether a stored value is empty (works for scalar + array/multi values).
 *
 * @param mixed $value Stored field value.
 * @return bool
 */
$bn_ap_is_empty = static function ( $value ): bool {
	return is_array( $value ) ? array() === $value : '' === trim( (string) $value );
};

/**
 * Render one field as a presentation-mode-tagged detail row: the label + the
 * type-rendered value, wrapped in a `.bn-pf-detail--{mode}` block so the CSS can
 * lay out each mode (block / chips / link / inline) appropriately.
 *
 * @param array<string,mixed> $field Hydrated field { type, label, value, field_key }.
 * @return string HTML ('' when the field has no displayable value).
 */
$bn_ap_render_field = static function ( array $field ) use ( $bn_ap_is_empty ): string {
	$value = $field['value'] ?? '';
	if ( $bn_ap_is_empty( $value ) ) {
		return '';
	}
	$display = FieldType::render_display( $field, $value );
	if ( '' === trim( $display ) ) {
		return '';
	}
	$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';
	$mode  = FieldType::presentation_for( $type );
	$label = isset( $field['label'] ) && '' !== (string) $field['label']
		? (string) $field['label']
		: ucwords( str_replace( '_', ' ', (string) ( $field['field_key'] ?? '' ) ) );

	return sprintf(
		'<div class="bn-pf-detail bn-pf-detail--%1$s"><dt class="bn-pf-detail__label">%2$s</dt><dd class="bn-pf-detail__value">%3$s</dd></div>',
		esc_attr( $mode ),
		esc_html( $label ),
		// $display is escaped per the FieldType engine contract.
		$display
	);
};

/**
 * Render one repeater ENTRY as a premium card — a prominent heading, a muted
 * meta line (short scalars + a collapsed date range), a description block, and
 * any remaining fields as labelled rows. All schema-driven: the heading is the
 * entry's first plain-text value, the date range is detected by the
 * `{prefix}_start_(date|year)` / `_end_` / `_current` SUFFIX pattern (never a
 * group name), and everything else falls back to typed detail rows. A custom
 * repeater with unconventional fields still renders — it just leans on the row
 * fallback.
 *
 * @param array<string,mixed> $entry Hydrated entry (field_key => field, + _visibility).
 * @return string HTML ('' when the entry has no displayable value).
 */
$bn_ap_render_entry = static function ( array $entry ) use ( $bn_ap_is_empty, $bn_ap_render_field ): string {
	// Ordered, non-empty, real fields keyed by field_key.
	$fields = array();
	foreach ( $entry as $ek => $ef ) {
		if ( '_visibility' === $ek || ! is_array( $ef ) || empty( $ef['field_key'] ) ) {
			continue;
		}
		if ( $bn_ap_is_empty( $ef['value'] ?? '' ) ) {
			continue;
		}
		$fields[ (string) $ef['field_key'] ] = $ef;
	}
	if ( empty( $fields ) ) {
		return '';
	}

	// 1. Collapse a date triple (start / end / current) to one "A – B" range.
	$consumed = array();
	$range    = '';
	foreach ( array_keys( $fields ) as $bn_ap_k ) {
		if ( ! preg_match( '/^(.+)_start_(date|year)$/', $bn_ap_k, $bn_ap_m ) ) {
			continue;
		}
		$bn_ap_prefix  = $bn_ap_m[1];
		$bn_ap_unit    = $bn_ap_m[2];
		$bn_ap_fmt     = static function ( string $key ) use ( $fields, $bn_ap_unit ): string {
			$v = isset( $fields[ $key ] ) ? (string) ( $fields[ $key ]['value'] ?? '' ) : '';
			if ( '' === $v ) {
				return '';
			}
			if ( 'date' === $bn_ap_unit ) {
				$ts = strtotime( $v );
				return false !== $ts ? date_i18n( 'M Y', $ts ) : $v;
			}
			return $v;
		};
		$bn_ap_start   = $bn_ap_fmt( $bn_ap_prefix . '_start_' . $bn_ap_unit );
		$bn_ap_end     = $bn_ap_fmt( $bn_ap_prefix . '_end_' . $bn_ap_unit );
		$bn_ap_current = isset( $fields[ $bn_ap_prefix . '_current' ] )
			&& '1' === (string) ( $fields[ $bn_ap_prefix . '_current' ]['value'] ?? '' );
		if ( $bn_ap_current ) {
			$bn_ap_end = __( 'Present', 'buddynext' );
		}
		if ( '' !== $bn_ap_start || '' !== $bn_ap_end ) {
			$range = ( '' !== $bn_ap_start && '' !== $bn_ap_end )
				? $bn_ap_start . ' – ' . $bn_ap_end
				: ( '' !== $bn_ap_start ? $bn_ap_start : $bn_ap_end );
		}
		$consumed[ $bn_ap_prefix . '_start_' . $bn_ap_unit ] = true;
		$consumed[ $bn_ap_prefix . '_end_' . $bn_ap_unit ]   = true;
		$consumed[ $bn_ap_prefix . '_current' ]              = true;
		break;
	}

	// 2. Partition the rest: first text → heading, further texts → meta, textarea
	// → description, everything else → labelled rows.
	$heading   = '';
	$meta      = array();
	$desc      = '';
	$rows      = '';
	$has_title = false;
	foreach ( $fields as $bn_ap_fk => $bn_ap_f ) {
		if ( isset( $consumed[ $bn_ap_fk ] ) ) {
			continue;
		}
		$bn_ap_type = (string) ( $bn_ap_f['type'] ?? 'text' );
		$bn_ap_val  = $bn_ap_f['value'] ?? '';
		if ( 'textarea' === $bn_ap_type ) {
			$desc .= FieldType::render_display( $bn_ap_f, $bn_ap_val );
			continue;
		}
		if ( 'text' === $bn_ap_type && ! is_array( $bn_ap_val ) ) {
			if ( ! $has_title ) {
				$heading   = (string) $bn_ap_val;
				$has_title = true;
			} else {
				$meta[] = (string) $bn_ap_val;
			}
			continue;
		}
		$rows .= $bn_ap_render_field( $bn_ap_f );
	}
	if ( '' !== $range ) {
		$meta[] = $range;
	}

	$html = '';
	if ( '' !== $heading ) {
		$html .= '<h3 class="bn-pf-entry__title">' . esc_html( $heading ) . '</h3>';
	}
	if ( ! empty( $meta ) ) {
		$html .= '<p class="bn-pf-entry__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
	}
	if ( '' !== $desc ) {
		// render_display() output is escaped per the field engine contract.
		$html .= '<div class="bn-pf-entry__desc">' . $desc . '</div>';
	}
	if ( '' !== $rows ) {
		$html .= '<dl class="bn-pf-detail-list">' . $rows . '</dl>';
	}

	return '' === $html ? '' : '<div class="bn-pf-detail-entry">' . $html . '</div>';
};

/**
 * Filter the group list the About tab renders — reorder, add a synthetic group,
 * or drop one, without knowing which fields the owner created.
 *
 * @param array $groups          Ordered group list from get_profile().
 * @param int   $profile_user_id Profile being viewed.
 * @param int   $viewer_id       Current viewer.
 */
$bn_ap_groups = (array) apply_filters(
	'buddynext_profile_about_groups',
	(array) ( $bn_ap_profile['groups'] ?? array() ),
	$bn_ap_uid,
	$bn_ap_viewer
);

foreach ( $bn_ap_groups as $bn_ap_g ) {
	if ( ! is_array( $bn_ap_g ) ) {
		continue;
	}
	$bn_ap_gkey  = isset( $bn_ap_g['group_key'] ) ? (string) $bn_ap_g['group_key'] : '';
	$bn_ap_gtype = isset( $bn_ap_g['type'] ) ? (string) $bn_ap_g['type'] : 'flat';
	if ( '' === $bn_ap_gkey ) {
		continue;
	}

	$bn_ap_body = '';

	if ( 'repeater' === $bn_ap_gtype ) {
		// Each entry → a premium entry-card (heading + meta + description + rows).
		foreach ( ( is_array( $bn_ap_g['entries'] ?? null ) ? $bn_ap_g['entries'] : array() ) as $bn_ap_entry ) {
			if ( is_array( $bn_ap_entry ) ) {
				$bn_ap_body .= $bn_ap_render_entry( $bn_ap_entry );
			}
		}
	} else {
		$bn_ap_rows = '';
		foreach ( ( is_array( $bn_ap_g['fields'] ?? null ) ? $bn_ap_g['fields'] : array() ) as $bn_ap_f ) {
			if ( ! is_array( $bn_ap_f ) || empty( $bn_ap_f['field_key'] ) ) {
				continue;
			}
			// The hero already shows basic_info's spine fields.
			if ( 'basic_info' === $bn_ap_gkey && in_array( (string) $bn_ap_f['field_key'], $bn_ap_hero_keys, true ) ) {
				continue;
			}
			$bn_ap_rows .= $bn_ap_render_field( $bn_ap_f );
		}
		if ( '' !== $bn_ap_rows ) {
			$bn_ap_body = '<dl class="bn-pf-detail-list">' . $bn_ap_rows . '</dl>';
		}
	}

	if ( '' === $bn_ap_body ) {
		continue; // Self-hide a group with no displayable content.
	}

	$bn_ap_glabel = isset( $bn_ap_g['label'] ) && '' !== (string) $bn_ap_g['label']
		? (string) $bn_ap_g['label']
		: ucwords( str_replace( '_', ' ', $bn_ap_gkey ) );

	/**
	 * Fires before a rendered About group card. `$bn_ap_gkey` identifies the
	 * group so an admin can inject content for a specific one.
	 *
	 * @param string $group_key       Group key.
	 * @param array  $group           The hydrated group.
	 * @param int    $profile_user_id Profile being viewed.
	 */
	do_action( 'buddynext_profile_about_group_before', $bn_ap_gkey, $bn_ap_g, $bn_ap_uid );
	?>
	<section class="bn-card bn-pf-about-card bn-pf-detail-card" data-group="<?php echo esc_attr( $bn_ap_gkey ); ?>">
		<header class="bn-pf-about-card__header">
			<h2 class="bn-pf-about-card__title"><?php echo esc_html( $bn_ap_glabel ); ?></h2>
		</header>
		<?php
		// Body is FieldType::render_display() output (escaped per the engine
		// contract) + esc_html() labels + esc_attr() mode classes.
		echo $bn_ap_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/**
		 * Fires inside an About group card, after its rendered rows — for an
		 * admin to append content within the same card.
		 *
		 * @param string $group_key       Group key.
		 * @param array  $group           The hydrated group.
		 * @param int    $profile_user_id Profile being viewed.
		 */
		do_action( 'buddynext_profile_about_group_after', $bn_ap_gkey, $bn_ap_g, $bn_ap_uid );
		?>
	</section>
	<?php
}

/**
 * Fires after all About field-group cards — for an admin to append a custom
 * section at the bottom of the tab.
 *
 * @param int   $profile_user_id Profile being viewed.
 * @param int   $viewer_id       Current viewer.
 * @param array $profile         The hydrated, viewer-gated profile.
 */
do_action( 'buddynext_profile_about_after', $bn_ap_uid, $bn_ap_viewer, $bn_ap_profile );
