<?php
/**
 * BuddyNext template part: member-directory-filter-bar.
 *
 * Renders the directory filter strip — optional relation tabs (All /
 * Following / Connections), a debounced search input, and the sort
 * select. Wraps `.bn-md-strip .bn-filter-strip`.
 *
 * Used by: templates/directory/members.php.
 *
 * @package BuddyNext
 *
 * @var string $current_search Optional. Current search term. Default ''.
 * @var string $current_sort   Optional. Active sort (REST value: newest|alphabetical|most_active|online). Default 'newest'.
 * @var string $current_type   Optional. Currently-selected member-type slug. Default ''.
 * @var string $current_url    Optional. Base URL (reserved). Default ''.
 * @var bool   $current_online Optional. Whether the "Online only" toggle is on. Default false.
 * @var array  $member_types   Optional. Member-type rows `{ slug, label|name }` for the type <select>.
 *                             When omitted, directory-visible types are fetched from the member_types service.
 * @var array  $relation_tabs  Optional. Relation tab list of `{ key, label }` rows. Default [].
 * @var string $active_relation Optional. Active relation key. Default 'all'.
 * @var array  $classes        Optional. Extra CSS classes appended to `.bn-md-strip`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_member_directory_filter_bar_before', $args )
 *   - do_action( 'buddynext_part_member_directory_filter_bar_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_member_directory_filter_bar_args',    array $args )
 *   - apply_filters( 'buddynext_part_member_directory_filter_bar_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'current_search'  => isset( $current_search ) ? (string) $current_search : '',
	'current_sort'    => isset( $current_sort ) ? (string) $current_sort : 'newest',
	'current_type'    => isset( $current_type ) ? (string) $current_type : '',
	'current_url'     => isset( $current_url ) ? (string) $current_url : '',
	'current_online'  => ! empty( $current_online ),
	'member_types'    => isset( $member_types ) ? (array) $member_types : array(),
	'relation_tabs'   => isset( $relation_tabs ) ? (array) $relation_tabs : array(),
	'active_relation' => isset( $active_relation ) ? (string) $active_relation : 'all',
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_member_directory_filter_bar_args', $args );

$bn_classes = array_merge( array( 'bn-md-strip', 'bn-filter-strip' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_member_directory_filter_bar_classes', $bn_classes, $args );
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

$bn_search          = (string) $args['current_search'];
$bn_sort            = (string) $args['current_sort'];
$bn_current_type    = (string) $args['current_type'];
$bn_online_only     = (bool) $args['current_online'];
$bn_relation_tabs   = (array) $args['relation_tabs'];
$bn_active_relation = (string) $args['active_relation'];

// Member types for the type <select>. Prefer the rows passed by the caller;
// otherwise source the same directory-visible set the directory template uses
// so the control is self-sufficient when included standalone.
$bn_member_types_raw = (array) $args['member_types'];
if ( empty( $bn_member_types_raw ) && function_exists( 'buddynext_service' ) ) {
	$bn_types_service = buddynext_service( 'member_types' );
	if ( $bn_types_service && method_exists( $bn_types_service, 'get_all_with_counts' ) ) {
		$bn_member_types_raw = array_values(
			array_filter(
				$bn_types_service->get_all_with_counts(),
				static function ( $t ): bool {
					return is_array( $t ) && ! empty( $t['show_in_dir'] );
				}
			)
		);
	}
}

// Normalise each row to a { slug, label } shape, dropping invalid entries.
$bn_type_options = array();
foreach ( $bn_member_types_raw as $bn_mt ) {
	if ( ! is_array( $bn_mt ) ) {
		continue;
	}
	$bn_mt_slug = isset( $bn_mt['slug'] ) ? (string) $bn_mt['slug'] : '';
	$bn_mt_label = '';
	if ( isset( $bn_mt['label'] ) && '' !== (string) $bn_mt['label'] ) {
		$bn_mt_label = (string) $bn_mt['label'];
	} elseif ( isset( $bn_mt['name'] ) ) {
		$bn_mt_label = (string) $bn_mt['name'];
	}
	if ( '' === $bn_mt_slug || '' === $bn_mt_label ) {
		continue;
	}
	$bn_type_options[] = array(
		'slug'  => $bn_mt_slug,
		'label' => $bn_mt_label,
	);
}
unset( $bn_member_types_raw, $bn_mt );

do_action( 'buddynext_part_member_directory_filter_bar_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<?php if ( ! empty( $bn_relation_tabs ) ) : ?>
		<div class="bn-tabs" role="tablist">
			<?php
			foreach ( $bn_relation_tabs as $bn_rt ) :
				if ( ! is_array( $bn_rt ) ) {
					continue;
				}
				$bn_rt_key   = isset( $bn_rt['key'] ) ? (string) $bn_rt['key'] : '';
				$bn_rt_label = isset( $bn_rt['label'] ) ? (string) $bn_rt['label'] : '';
				if ( '' === $bn_rt_key || '' === $bn_rt_label ) {
					continue;
				}
				$bn_rt_active = ( $bn_rt_key === $bn_active_relation );
				?>
				<button
					type="button"
					class="bn-tab<?php echo $bn_rt_active ? ' is-active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo $bn_rt_active ? 'true' : 'false'; ?>"
					data-relation="<?php echo esc_attr( $bn_rt_key ); ?>"
					data-wp-on--click="actions.selectRelation"
				>
					<span class="bn-tab__label"><?php echo esc_html( $bn_rt_label ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="bn-md-strip__form" role="search">
		<label class="bn-md-strip__search">
			<span class="screen-reader-text"><?php esc_html_e( 'Search members', 'buddynext' ); ?></span>
			<input
				type="search"
				class="bn-input bn-md-strip__search-input"
				name="s"
				value="<?php echo esc_attr( $bn_search ); ?>"
				placeholder="<?php esc_attr_e( 'Search members by name or profile details…', 'buddynext' ); ?>"
				aria-label="<?php esc_attr_e( 'Search members', 'buddynext' ); ?>"
				data-wp-on--input="actions.handleSearchInput"
			>
			<span
				class="bn-md-strip__searching"
				aria-hidden="true"
				data-wp-bind--hidden="!state.searching"
				hidden
			><?php esc_html_e( 'Searching…', 'buddynext' ); ?></span>
		</label>

		<?php if ( ! empty( $bn_type_options ) ) : ?>
			<select
				class="bn-select bn-md-strip__type"
				aria-label="<?php esc_attr_e( 'Filter members by type', 'buddynext' ); ?>"
				data-wp-on--change="actions.selectMemberType"
			>
				<option value="" <?php selected( $bn_current_type, '' ); ?>><?php esc_html_e( 'All member types', 'buddynext' ); ?></option>
				<?php foreach ( $bn_type_options as $bn_type_option ) : ?>
					<option
						value="<?php echo esc_attr( $bn_type_option['slug'] ); ?>"
						<?php selected( $bn_current_type, $bn_type_option['slug'] ); ?>
					><?php echo esc_html( $bn_type_option['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>

		<label class="bn-md-strip__online">
			<input
				type="checkbox"
				class="bn-md-strip__online-input"
				value="1"
				<?php checked( $bn_online_only ); ?>
				data-wp-on--change="actions.toggleOnlineOnly"
			>
			<span class="bn-md-strip__online-label">
				<span class="bn-md-strip__online-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Online only', 'buddynext' ); ?>
			</span>
		</label>

		<select
			class="bn-select bn-md-strip__sort"
			aria-label="<?php esc_attr_e( 'Sort members', 'buddynext' ); ?>"
			data-wp-on--change="actions.selectSort"
		>
			<option value="newest" <?php selected( $bn_sort, 'newest' ); ?>><?php esc_html_e( 'Newest first', 'buddynext' ); ?></option>
			<option value="alphabetical" <?php selected( $bn_sort, 'alphabetical' ); ?>><?php esc_html_e( 'Alphabetical', 'buddynext' ); ?></option>
			<option value="most_active" <?php selected( $bn_sort, 'most_active' ); ?>><?php esc_html_e( 'Most active', 'buddynext' ); ?></option>
			<option value="online" <?php selected( $bn_sort, 'online' ); ?>><?php esc_html_e( 'Online now', 'buddynext' ); ?></option>
		</select>
	</div>
</div>
<?php
do_action( 'buddynext_part_member_directory_filter_bar_after', $args );
