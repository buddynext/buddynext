<?php
/**
 * BuddyNext template part: dm-thread-header.
 *
 * Renders the thread-pane header: back-to-rail button, recipient
 * identity (avatar + name + presence), and the conversation action
 * toolbar (search / delete / more).
 *
 * Used by: templates/messages/thread.php.
 *
 * @package BuddyNext
 *
 * @var string $display_name    Required. Recipient display name.
 * @var int    $other_user_id   Optional. Recipient user ID. Default 0.
 * @var bool   $is_online       Optional. Whether recipient is online. Default false.
 * @var int    $tone            Optional. Avatar tone slot 1..8. Default 1.
 * @var string $initials        Optional. Two-character initials. Default ''.
 * @var string $avatar_html     Optional. Pre-rendered `<img>` markup from get_avatar(). Default ''.
 * @var string $profile_url     Optional. Recipient profile URL. Default ''.
 * @var string $back_url        Optional. URL to return to the rail. Default ''.
 * @var array  $classes         Optional. Extra CSS classes on the `<header>`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_dm_thread_header_before', $args )
 *   - do_action( 'buddynext_part_dm_thread_header_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_dm_thread_header_args',    array $args )
 *   - apply_filters( 'buddynext_part_dm_thread_header_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'display_name'  => isset( $display_name ) ? (string) $display_name : '',
	'other_user_id' => isset( $other_user_id ) ? (int) $other_user_id : 0,
	'is_online'     => isset( $is_online ) ? (bool) $is_online : false,
	'tone'          => isset( $tone ) ? (int) $tone : 1,
	'initials'      => isset( $initials ) ? (string) $initials : '',
	'avatar_html'   => isset( $avatar_html ) ? (string) $avatar_html : '',
	'profile_url'   => isset( $profile_url ) ? (string) $profile_url : '',
	'back_url'      => isset( $back_url ) ? (string) $back_url : '',
	'is_group'      => isset( $is_group ) ? (bool) $is_group : false,
	'member_count'  => isset( $member_count ) ? (int) $member_count : 0,
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_dm_thread_header_args', $args );

if ( '' === (string) $args['display_name'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-split__pane-head', 'bn-dm-pane__head' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_dm_thread_header_classes', $bn_classes, $args );
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

$name        = (string) $args['display_name'];
$tone        = (int) $args['tone'];
$initials    = (string) $args['initials'];
$avatar_html = (string) $args['avatar_html'];
$profile_url = (string) $args['profile_url'];
$back_url    = (string) $args['back_url'];
$online      = (bool) $args['is_online'];
$presence    = $online ? 'online' : 'offline';
$is_group    = (bool) $args['is_group'];
$members     = (int) $args['member_count'];
/* translators: %d: number of group members. */
$group_sub = sprintf( _n( '%d member', '%d members', $members, 'buddynext' ), $members );

do_action( 'buddynext_part_dm_thread_header_before', $args );
?>
<header class="<?php echo esc_attr( $bn_class ); ?>">
	<a href="<?php echo esc_url( $back_url ); ?>" class="bn-btn bn-dm-pane__back" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Back to conversations', 'buddynext' ); ?>">
		<?php buddynext_icon( 'chevron-left' ); ?>
	</a>

	<?php $bn_id_tag = $is_group ? 'div' : 'a'; ?>
	<<?php echo esc_attr( $bn_id_tag ); ?> <?php echo $is_group ? '' : 'href="' . esc_url( $profile_url ) . '"'; ?> class="bn-dm-pane__identity">
		<span class="bn-avatar bn-dm-avatar<?php echo $is_group ? ' bn-dm-avatar--group' : ' bn-dm-tone-' . (int) $tone; ?>" data-size="md"<?php echo $is_group ? '' : ' data-presence="' . esc_attr( $presence ) . '"'; ?> aria-hidden="true">
			<?php if ( $is_group ) : ?>
				<?php buddynext_icon( 'users' ); ?>
			<?php elseif ( false !== strpos( $avatar_html, 'src=' ) ) : ?>
				<?php
				echo wp_kses(
					$avatar_html,
					array(
						'img' => array(
							'src'      => true,
							'class'    => true,
							'alt'      => true,
							'width'    => true,
							'height'   => true,
							'loading'  => true,
							'decoding' => true,
						),
					)
				);
				?>
			<?php else : ?>
				<span class="bn-avatar__initials"><?php echo esc_html( $initials ); ?></span>
			<?php endif; ?>
		</span>
		<span class="bn-dm-pane__identity-text">
			<span class="bn-dm-pane__identity-name"<?php echo $is_group ? ' data-wp-text="state.headerGroupName"' : ''; ?>><?php echo esc_html( $name ); ?></span>
			<span class="bn-dm-pane__identity-status<?php echo ( ! $is_group && $online ) ? ' is-online' : ''; ?>"<?php echo $is_group ? ' data-wp-text="state.headerGroupStatus"' : ''; ?>>
				<?php
				if ( $is_group ) {
					echo esc_html( $group_sub );
				} else {
					echo esc_html( $online ? __( 'Online now', 'buddynext' ) : __( 'Offline', 'buddynext' ) );
				}
				?>
			</span>
		</span>
	</<?php echo esc_attr( $bn_id_tag ); ?>>

	<div class="bn-dm-pane__actions" role="toolbar" aria-label="<?php esc_attr_e( 'Conversation actions', 'buddynext' ); ?>">
		<?php if ( $is_group ) : ?>
			<span class="bn-tooltip-trigger">
				<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Group members', 'buddynext' ); ?>" data-wp-on--click="actions.openGroupPanel">
					<?php buddynext_icon( 'users' ); ?>
				</button>
				<span class="bn-tooltip" data-pos="bottom"><?php esc_html_e( 'Members', 'buddynext' ); ?></span>
			</span>
		<?php elseif ( '' !== $profile_url ) : ?>
			<span class="bn-tooltip-trigger">
				<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( $profile_url ); ?>" aria-label="<?php esc_attr_e( 'View profile', 'buddynext' ); ?>">
					<?php buddynext_icon( 'user' ); ?>
				</a>
				<span class="bn-tooltip" data-pos="bottom"><?php esc_html_e( 'Profile', 'buddynext' ); ?></span>
			</span>
		<?php endif; ?>
		<span class="bn-tooltip-trigger">
			<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Delete conversation', 'buddynext' ); ?>" data-wp-on--click="actions.openDeleteConfirm">
				<?php buddynext_icon( 'trash' ); ?>
			</button>
			<span class="bn-tooltip" data-pos="bottom"><?php esc_html_e( 'Delete', 'buddynext' ); ?></span>
		</span>
	</div>
</header>
<?php
do_action( 'buddynext_part_dm_thread_header_after', $args );
