<?php
/**
 * BuddyNext template part: sidebar-by-role.
 *
 * "By role" right-sidebar card showing a per-role member count breakdown:
 * total members, admins, moderators, and top member-types (Pro / Staff /
 * Verified / …). Mirrors the v2 prototype member-directory sidebar card.
 *
 * Data sources:
 *   - `count_users()`        — WordPress role → count map.
 *   - `member_types` service — type definitions (names/slugs), with per-type
 *                              counts sourced from
 *                              MemberDirectoryService::type_member_counts() so
 *                              they match the directory list + "By type" facet.
 *
 * Render shape:
 *
 *   ┌─────────────────────────────────────┐
 *   │ By role                             │
 *   │ Members          2,712              │
 *   │ Moderators          98              │
 *   │ Admins              12              │
 *   │ Pro members         25              │
 *   └─────────────────────────────────────┘
 *
 * @package BuddyNext
 *
 * @var int    $max_member_types  Optional. Max member-type rows to show. Default 3.
 * @var array  $classes           Optional. Extra CSS classes appended to `.bn-sidebar-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_sidebar_by_role_before', $args )
 *   - do_action( 'buddynext_part_sidebar_by_role_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_sidebar_by_role_args',    array $args )
 *   - apply_filters( 'buddynext_part_sidebar_by_role_classes', array $classes, array $args )
 *   - apply_filters( 'buddynext_part_sidebar_by_role_rows',    array $rows )
 *     — final list of [{ label, count, href? }] before render. Use to
 *     reorder, drop, or append rows from Pro / bridge plugins.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'max_member_types' => isset( $max_member_types ) ? max( 0, (int) $max_member_types ) : 3,
	'classes'          => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_sidebar_by_role_args', $args );

// ── Source the counts.
// Role rows need count_users()'s per-role breakdown, but the headline "Members"
// total should match the directory's filtered count (excludes suspended/
// shadow-banned) when the caller passes it — otherwise the panel shows a
// different total than the directory header on the same screen.
$bn_counts       = count_users();
$bn_total        = isset( $directory_total )
	? (int) $directory_total
	: ( isset( $bn_counts['total_users'] ) ? (int) $bn_counts['total_users'] : 0 );
$bn_avail        = isset( $bn_counts['avail_roles'] ) && is_array( $bn_counts['avail_roles'] ) ? $bn_counts['avail_roles'] : array();
$bn_admin_count  = (int) ( $bn_avail['administrator'] ?? 0 );
$bn_editor_count = (int) ( $bn_avail['editor'] ?? 0 );

// Treat editors + administrators as the "moderator" surface area —
// site moderators in BuddyNext typically wear the editor cap unless a
// dedicated bn_moderator role has been installed. Pro can swap in a
// truer count via the buddynext_part_sidebar_by_role_rows filter.
$bn_moderator_count = $bn_editor_count + $bn_admin_count;

$bn_rows   = array();
$bn_rows[] = array(
	'label' => __( 'Members', 'buddynext' ),
	'count' => $bn_total,
	'href'  => home_url( '/members/' ),
);
if ( $bn_moderator_count > 0 ) {
	$bn_rows[] = array(
		'label' => __( 'Moderators', 'buddynext' ),
		'count' => $bn_moderator_count,
	);
}
if ( $bn_admin_count > 0 ) {
	$bn_rows[] = array(
		'label' => __( 'Admins', 'buddynext' ),
		'count' => $bn_admin_count,
	);
}

// Append top member-type counts when the service is available.
$bn_type_rows = array();
if ( function_exists( 'buddynext_service' ) ) {
	$bn_service = buddynext_service( 'member_types' );
	if ( is_object( $bn_service ) && method_exists( $bn_service, 'get_all' ) ) {
		// Type definitions for names/slugs; counts come from the directory service
		// so this card matches the "By type" facet and the filtered list exactly
		// (orphan-free, discovery-gated, viewer excluded) rather than raw assignment
		// rows. Types with no directory-visible members resolve to 0 and are skipped
		// by the <= 0 guard below.
		$bn_type_rows_raw = (array) $bn_service->get_all();
		$bn_dir_service   = buddynext_service( 'member_directory' );
		$bn_type_counts   = ( is_object( $bn_dir_service ) && method_exists( $bn_dir_service, 'type_member_counts' ) )
			? (array) $bn_dir_service->type_member_counts( get_current_user_id() )
			: array();
		foreach ( $bn_type_rows_raw as &$bn_type_row ) {
			$bn_type_row['member_count'] = (int) ( $bn_type_counts[ (int) ( $bn_type_row['id'] ?? 0 ) ] ?? 0 );
		}
		unset( $bn_type_row );
		usort(
			$bn_type_rows_raw,
			static function ( array $a, array $b ): int {
				return ( (int) ( $b['member_count'] ?? 0 ) ) <=> ( (int) ( $a['member_count'] ?? 0 ) );
			}
		);
		foreach ( $bn_type_rows_raw as $bn_type ) {
			$bn_type_count = (int) ( $bn_type['member_count'] ?? 0 );
			if ( $bn_type_count <= 0 ) {
				continue;
			}
			$bn_type_rows[] = array(
				'label' => sprintf(
					/* translators: %s: member-type label */
					__( '%s members', 'buddynext' ),
					(string) ( $bn_type['name'] ?? $bn_type['slug'] ?? '' )
				),
				'count' => $bn_type_count,
				'href'  => add_query_arg( 'type', (string) ( $bn_type['slug'] ?? '' ), home_url( '/members/' ) ),
			);
			if ( count( $bn_type_rows ) >= (int) $args['max_member_types'] ) {
				break;
			}
		}
	}
}
$bn_rows = array_merge( $bn_rows, $bn_type_rows );

/** Final list of row descriptors. @var array<int, array{label:string,count:int,href?:string}> $bn_rows */
$bn_rows = (array) apply_filters( 'buddynext_part_sidebar_by_role_rows', $bn_rows );

if ( empty( $bn_rows ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-sidebar-card', 'bn-by-role' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_sidebar_by_role_classes', $bn_classes, $args );
$bn_class   = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

do_action( 'buddynext_part_sidebar_by_role_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'Members by role', 'buddynext' ); ?>">
	<h3 class="bn-by-role__title"><?php esc_html_e( 'By role', 'buddynext' ); ?></h3>
	<ul class="bn-by-role__list">
		<?php foreach ( $bn_rows as $bn_row ) : ?>
			<?php
			$bn_label = isset( $bn_row['label'] ) ? (string) $bn_row['label'] : '';
			$bn_count = isset( $bn_row['count'] ) ? (int) $bn_row['count'] : 0;
			$bn_href  = isset( $bn_row['href'] ) ? (string) $bn_row['href'] : '';
			if ( '' === $bn_label ) {
				continue;
			}
			?>
			<li class="bn-by-role__row">
				<?php if ( '' !== $bn_href ) : ?>
					<a class="bn-by-role__link" href="<?php echo esc_url( $bn_href ); ?>">
						<span class="bn-by-role__label"><?php echo esc_html( $bn_label ); ?></span>
						<span class="bn-by-role__count"><?php echo esc_html( number_format_i18n( $bn_count ) ); ?></span>
					</a>
				<?php else : ?>
					<span class="bn-by-role__label"><?php echo esc_html( $bn_label ); ?></span>
					<span class="bn-by-role__count"><?php echo esc_html( number_format_i18n( $bn_count ) ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
<?php
do_action( 'buddynext_part_sidebar_by_role_after', $args );
