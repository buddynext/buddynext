<?php
/**
 * BuddyNext template part: profile About panel.
 *
 * The registry content seam for the profile About tab. Self-contained: resolves
 * the viewer-gated profile field data via ProfileService (the same path the hero
 * and the REST controller use), renders the curated about-cards (work / education
 * / interests) and then every other admin-defined field through the single
 * field-type engine. Rendered by ProfileNav's About `render` callable (which only
 * registers the tab when there is content). Returns nothing when empty so the
 * callable can decide visibility.
 *
 * @package BuddyNext
 *
 * @var int $profile_user_id Required. Profile being viewed.
 * @var int $viewer_id       Optional. Current viewer (drives per-field visibility).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_ap_uid    = isset( $profile_user_id ) ? (int) $profile_user_id : 0;
$bn_ap_viewer = isset( $viewer_id ) ? (int) $viewer_id : 0;
if ( $bn_ap_uid <= 0 ) {
	return;
}

$bn_ap_profile = buddynext_service( 'profiles' )->get_profile( $bn_ap_uid, $bn_ap_viewer );
$bn_ap_groups  = array();
if ( is_array( $bn_ap_profile ) ) {
	foreach ( (array) ( $bn_ap_profile['groups'] ?? array() ) as $bn_ap_group ) {
		$bn_ap_groups[ $bn_ap_group['group_key'] ] = $bn_ap_group;
	}
}

$bn_ap_entry_fv = static function ( array $entry_fields, string $fkey ): string {
	foreach ( $entry_fields as $f ) {
		if ( ( $f['field_key'] ?? '' ) === $fkey ) {
			return (string) ( $f['value'] ?? '' );
		}
	}
	return '';
};

$bn_ap_get_fv = static function ( string $gkey, string $fkey ) use ( $bn_ap_groups ): string {
	if ( ! isset( $bn_ap_groups[ $gkey ]['fields'] ) ) {
		return '';
	}
	foreach ( $bn_ap_groups[ $gkey ]['fields'] as $f ) {
		if ( ( $f['field_key'] ?? '' ) === $fkey ) {
			return (string) ( $f['value'] ?? '' );
		}
	}
	return '';
};

$bn_ap_work      = array_values(
	array_filter(
		isset( $bn_ap_groups['work_experience']['entries'] ) ? $bn_ap_groups['work_experience']['entries'] : array(),
		static fn( array $e ): bool => '' !== $bn_ap_entry_fv( $e, 'work_company' ) || '' !== $bn_ap_entry_fv( $e, 'work_title' )
	)
);
$bn_ap_edu       = array_values(
	array_filter(
		isset( $bn_ap_groups['education']['entries'] ) ? $bn_ap_groups['education']['entries'] : array(),
		static fn( array $e ): bool => '' !== $bn_ap_entry_fv( $e, 'edu_institution' ) || '' !== $bn_ap_entry_fv( $e, 'edu_degree' )
	)
);
$bn_ap_interests = array_filter( array_map( 'trim', explode( ',', $bn_ap_get_fv( 'skills', 'interests' ) ) ) );

buddynext_get_template(
	'partials/profile-about-cards.php',
	array(
		'work_entries' => $bn_ap_work,
		'edu_entries'  => $bn_ap_edu,
		'interests'    => $bn_ap_interests,
		'entry_fv'     => $bn_ap_entry_fv,
	)
);

// Every other admin-defined field — including custom types the curated cards
// above don't know — renders through the single field-type engine. get_profile()
// has already applied per-field visibility, so anything present is allowed. Keys
// the hero + about-cards surface prominently are skipped to avoid duplication.
$bn_ap_hero_keys   = array( 'headline', 'bio', 'pronouns', 'location', 'website' );
$bn_ap_skip_groups = array( 'work_experience', 'education', 'social_links' );

foreach ( (array) ( $bn_ap_profile['groups'] ?? array() ) as $bn_ap_g ) {
	$bn_ap_gkey  = isset( $bn_ap_g['group_key'] ) ? (string) $bn_ap_g['group_key'] : '';
	$bn_ap_gtype = isset( $bn_ap_g['type'] ) ? (string) $bn_ap_g['type'] : 'flat';
	if ( '' === $bn_ap_gkey || in_array( $bn_ap_gkey, $bn_ap_skip_groups, true ) ) {
		continue;
	}

	$bn_ap_rows = '';
	if ( 'repeater' === $bn_ap_gtype ) {
		foreach ( ( isset( $bn_ap_g['entries'] ) && is_array( $bn_ap_g['entries'] ) ? $bn_ap_g['entries'] : array() ) as $bn_ap_entry ) {
			if ( ! is_array( $bn_ap_entry ) ) {
				continue;
			}
			$bn_ap_entry_rows = '';
			foreach ( $bn_ap_entry as $bn_ap_f ) {
				if ( ! is_array( $bn_ap_f ) || empty( $bn_ap_f['field_key'] ) || '' === (string) ( $bn_ap_f['value'] ?? '' ) ) {
					continue;
				}
				$bn_ap_entry_rows .= '<div class="bn-pf-detail"><dt class="bn-pf-detail__label">' . esc_html( (string) ( $bn_ap_f['label'] ?? '' ) ) . '</dt><dd class="bn-pf-detail__value">' . \BuddyNext\Profile\FieldType::render_display( $bn_ap_f, $bn_ap_f['value'] ?? '' ) . '</dd></div>';
			}
			if ( '' !== $bn_ap_entry_rows ) {
				$bn_ap_rows .= '<dl class="bn-pf-detail-list bn-pf-detail-entry">' . $bn_ap_entry_rows . '</dl>';
			}
		}
	} else {
		foreach ( ( isset( $bn_ap_g['fields'] ) && is_array( $bn_ap_g['fields'] ) ? $bn_ap_g['fields'] : array() ) as $bn_ap_f ) {
			if ( ! is_array( $bn_ap_f ) || empty( $bn_ap_f['field_key'] ) ) {
				continue;
			}
			$bn_ap_fkey = (string) $bn_ap_f['field_key'];
			if ( 'basic_info' === $bn_ap_gkey && in_array( $bn_ap_fkey, $bn_ap_hero_keys, true ) ) {
				continue;
			}
			if ( '' === (string) ( $bn_ap_f['value'] ?? '' ) ) {
				continue;
			}
			$bn_ap_label = isset( $bn_ap_f['label'] ) ? (string) $bn_ap_f['label'] : ucwords( str_replace( '_', ' ', $bn_ap_fkey ) );
			$bn_ap_rows .= '<div class="bn-pf-detail"><dt class="bn-pf-detail__label">' . esc_html( $bn_ap_label ) . '</dt><dd class="bn-pf-detail__value">' . \BuddyNext\Profile\FieldType::render_display( $bn_ap_f, $bn_ap_f['value'] ?? '' ) . '</dd></div>';
		}
		if ( '' !== $bn_ap_rows ) {
			$bn_ap_rows = '<dl class="bn-pf-detail-list">' . $bn_ap_rows . '</dl>';
		}
	}

	if ( '' === $bn_ap_rows ) {
		continue;
	}
	$bn_ap_glabel = isset( $bn_ap_g['label'] ) ? (string) $bn_ap_g['label'] : ucwords( str_replace( '_', ' ', $bn_ap_gkey ) );
	?>
	<section class="bn-card bn-pf-about-card bn-pf-detail-card">
		<header class="bn-pf-about-card__header">
			<h2 class="bn-pf-about-card__title"><?php echo esc_html( $bn_ap_glabel ); ?></h2>
		</header>
		<?php
		// Detail rows are FieldType::render_display output (escaped per the
		// field_type_engine contract) plus esc_html() labels.
		echo $bn_ap_rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</section>
	<?php
}
