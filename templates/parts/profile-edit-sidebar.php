<?php
/**
 * BuddyNext template part: profile-edit-sidebar.
 *
 * Renders the right-rail of the edit-profile page — the profile preview
 * card (avatar/name/headline + stat tiles) plus the field-visibility
 * legend card.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array $profile Required. Resolved profile context. Shape:
 *   - `user_id`      (int)
 *   - `display_name` (string)
 *   - `headline`     (string)
 *   - `location`     (string)
 *   - `avatar_url`   (string)
 *   - `initials`     (string)
 *   - `stats`        (array<string,string>) Pre-formatted count strings,
 *                                            keyed: posts, followers, following.
 * @var array $completeness        Optional. Reserved for future use:
 *   - `percent`   (int)
 *   - `breakdown` (array)
 * @var array $visibility_settings Optional. Reserved for future use; today
 *                                  the visibility card uses fixed copy that
 *                                  matches the live design.
 * @var array $classes             Optional. Extra CSS classes on the `<aside>`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_edit_sidebar_before', $args )
 *   - do_action( 'buddynext_part_profile_edit_sidebar_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_edit_sidebar_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_edit_sidebar_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'profile'             => isset( $profile ) && is_array( $profile ) ? $profile : array(),
	'completeness'        => isset( $completeness ) && is_array( $completeness ) ? $completeness : array(),
	'visibility_settings' => isset( $visibility_settings ) && is_array( $visibility_settings ) ? $visibility_settings : array(),
	'classes'             => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_edit_sidebar_args', $args );

$bn_p = (array) $args['profile'];
if ( empty( $bn_p ) ) {
	return;
}

$bn_name     = isset( $bn_p['display_name'] ) ? (string) $bn_p['display_name'] : '';
$bn_headline = isset( $bn_p['headline'] ) ? (string) $bn_p['headline'] : '';
$bn_location = isset( $bn_p['location'] ) ? (string) $bn_p['location'] : '';
$bn_avatar   = isset( $bn_p['avatar_url'] ) ? (string) $bn_p['avatar_url'] : '';
$bn_initials = isset( $bn_p['initials'] ) ? (string) $bn_p['initials'] : '';
$bn_stats    = isset( $bn_p['stats'] ) && is_array( $bn_p['stats'] ) ? $bn_p['stats'] : array();

$bn_posts     = isset( $bn_stats['posts'] ) ? (string) $bn_stats['posts'] : '0';
$bn_followers = isset( $bn_stats['followers'] ) ? (string) $bn_stats['followers'] : '0';
$bn_following = isset( $bn_stats['following'] ) ? (string) $bn_stats['following'] : '0';

$bn_classes = array_merge( array( 'bn-ep-sidebar' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_edit_sidebar_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_profile_edit_sidebar_before', $args );
?>
<aside class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'Profile preview', 'buddynext' ); ?>">

	<section class="bn-card bn-ep-preview-card">
		<header class="bn-ep-preview-header">
			<?php esc_html_e( 'Profile Preview', 'buddynext' ); ?>
		</header>
		<div class="bn-ep-preview-body">
			<span class="bn-avatar bn-ep-preview-avatar" data-size="lg">
				<?php if ( '' !== $bn_avatar ) : ?>
					<img src="<?php echo esc_url( $bn_avatar ); ?>"
						alt="<?php echo esc_attr( $bn_name ); ?>" />
				<?php else : ?>
					<?php echo esc_html( $bn_initials ); ?>
				<?php endif; ?>
			</span>
			<div class="bn-ep-preview-name"><?php echo esc_html( $bn_name ); ?></div>
			<div class="bn-ep-preview-headline">
				<?php echo esc_html( '' !== $bn_headline ? $bn_headline : $bn_location ); ?>
			</div>
			<div class="bn-ep-preview-stats">
				<div class="bn-ep-preview-stat">
					<div class="bn-ep-preview-stat-num"><?php echo esc_html( $bn_posts ); ?></div>
					<div class="bn-ep-preview-stat-lbl"><?php esc_html_e( 'Posts', 'buddynext' ); ?></div>
				</div>
				<div class="bn-ep-preview-stat">
					<div class="bn-ep-preview-stat-num"><?php echo esc_html( $bn_followers ); ?></div>
					<div class="bn-ep-preview-stat-lbl"><?php esc_html_e( 'Followers', 'buddynext' ); ?></div>
				</div>
				<div class="bn-ep-preview-stat">
					<div class="bn-ep-preview-stat-num"><?php echo esc_html( $bn_following ); ?></div>
					<div class="bn-ep-preview-stat-lbl"><?php esc_html_e( 'Following', 'buddynext' ); ?></div>
				</div>
			</div>
		</div>
		<footer class="bn-ep-preview-note">
			<?php esc_html_e( 'How other members see your profile card across the community.', 'buddynext' ); ?>
		</footer>
	</section>

	<section class="bn-card bn-ep-visibility-card">
		<header class="bn-ep-vis-title">
			<?php buddynext_icon( 'lock' ); ?>
			<?php esc_html_e( 'Field Visibility', 'buddynext' ); ?>
		</header>
		<div class="bn-ep-vis-row">
			<span class="bn-ep-vis-dot bn-ep-vis-dot--public" aria-hidden="true"></span>
			<div class="bn-ep-vis-label">
				<strong><?php esc_html_e( 'Public', 'buddynext' ); ?></strong>
				<span><?php esc_html_e( 'visible to everyone', 'buddynext' ); ?></span>
			</div>
		</div>
		<div class="bn-ep-vis-row">
			<span class="bn-ep-vis-dot bn-ep-vis-dot--followers" aria-hidden="true"></span>
			<div class="bn-ep-vis-label">
				<strong><?php esc_html_e( 'Followers', 'buddynext' ); ?></strong>
				<span><?php esc_html_e( 'logged-in followers only', 'buddynext' ); ?></span>
			</div>
		</div>
		<div class="bn-ep-vis-row">
			<span class="bn-ep-vis-dot bn-ep-vis-dot--connections" aria-hidden="true"></span>
			<div class="bn-ep-vis-label">
				<strong><?php esc_html_e( 'Connections', 'buddynext' ); ?></strong>
				<span><?php esc_html_e( 'your accepted connections only', 'buddynext' ); ?></span>
			</div>
		</div>
		<div class="bn-ep-vis-row">
			<span class="bn-ep-vis-dot bn-ep-vis-dot--private" aria-hidden="true"></span>
			<div class="bn-ep-vis-label">
				<strong><?php esc_html_e( 'Only me', 'buddynext' ); ?></strong>
				<span><?php esc_html_e( 'only you can see', 'buddynext' ); ?></span>
			</div>
		</div>
		<footer class="bn-ep-vis-note">
			<?php esc_html_e( 'Use the lock on each field to choose who sees it. You can make a field more private than the site default, but not less.', 'buddynext' ); ?>
		</footer>
	</section>

</aside>
<?php
do_action( 'buddynext_part_profile_edit_sidebar_after', $args );
