<?php
/**
 * BuddyNext template part: space-settings-panel-invite.
 *
 * The "Invite link" settings tab: create/reset one shareable link per space,
 * with an expiry and a use limit, plus copy-to-clipboard. Server-rendered from
 * the current link state (passed in); create/reset reload the tab so the new
 * state re-renders. Only shown to actors who pass can_invite() (the tab is
 * gated in settings.php and the REST routes re-check it).
 *
 * Theme-overridable at {child-theme}/buddynext/parts/space-settings-panel-invite.php.
 *
 * @package BuddyNext
 * @since   1.2.1
 *
 * @var object $space           Required. Space row.
 * @var array  $invite_settings Required. Bundle:
 *   - `space_id`    (int)
 *   - `invite_link` (array|null) Public link shape from SpaceInviteLinkService::get().
 * @var array  $classes         Optional. Extra CSS classes appended to `.bn-card`.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'           => isset( $space ) ? $space : null,
	'invite_settings' => isset( $invite_settings ) && is_array( $invite_settings ) ? $invite_settings : array(),
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_invite_args', $args );

if ( ! $args['space'] ) {
	return;
}

$bn_inv          = (array) $args['invite_settings'];
$bn_inv_space_id = isset( $bn_inv['space_id'] ) ? (int) $bn_inv['space_id'] : 0;
$bn_inv_link     = isset( $bn_inv['invite_link'] ) && is_array( $bn_inv['invite_link'] ) ? $bn_inv['invite_link'] : null;

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel', 'bn-invite-panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_invite_classes', $bn_classes, $args );
$bn_class   = trim( implode( ' ', array_unique( array_filter( $bn_classes, static fn( $c ) => is_string( $c ) && '' !== $c ) ) ) );

// Expiry presets (value => label) and max-use presets, shared by the create form.
$bn_expiry_opts = array(
	'1d'    => __( '1 day', 'buddynext' ),
	'7d'    => __( '7 days', 'buddynext' ),
	'30d'   => __( '30 days', 'buddynext' ),
	'never' => __( 'Never', 'buddynext' ),
);
$bn_max_opts    = array(
	'1'   => __( '1', 'buddynext' ),
	'10'  => __( '10', 'buddynext' ),
	'100' => __( '100', 'buddynext' ),
	'0'   => __( 'Unlimited', 'buddynext' ),
);

// Build the human status line for an existing link.
$bn_status_line = '';
$bn_status_flag = '';
if ( null !== $bn_inv_link ) {
	$bn_expiry_part = empty( $bn_inv_link['expires_at'] )
		? __( 'No expiry', 'buddynext' )
		/* translators: %s: formatted expiry date. */
		: sprintf( __( 'Expires %s', 'buddynext' ), wp_date( (string) get_option( 'date_format' ), (int) strtotime( (string) $bn_inv_link['expires_at'] . ' UTC' ) ) );

	$bn_max      = (int) ( $bn_inv_link['max_uses'] ?? 0 );
	$bn_use_part = 0 === $bn_max
		? __( 'Unlimited uses', 'buddynext' )
		/* translators: 1: number of times used, 2: maximum uses. */
		: sprintf( __( 'Used %1$d of %2$d', 'buddynext' ), (int) ( $bn_inv_link['uses'] ?? 0 ), $bn_max );

	$bn_status_line = $bn_expiry_part . ' · ' . $bn_use_part;

	$bn_status = (string) ( $bn_inv_link['status'] ?? 'active' );
	if ( 'expired' === $bn_status ) {
		$bn_status_flag = __( 'Expired', 'buddynext' );
	} elseif ( 'limit_reached' === $bn_status ) {
		$bn_status_flag = __( 'Limit reached', 'buddynext' );
	}
}

do_action( 'buddynext_part_space_settings_panel_invite_before', $args );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	data-bn-invite-panel
	data-space-id="<?php echo esc_attr( (string) $bn_inv_space_id ); ?>"
	<?php if ( null !== $bn_inv_link ) : ?>
		data-bn-invite-expires-current="<?php echo esc_attr( (string) ( $bn_inv_link['expires'] ?? '7d' ) ); ?>"
		data-bn-invite-max-current="<?php echo esc_attr( (string) ( $bn_inv_link['max_uses'] ?? 0 ) ); ?>"
	<?php endif; ?>
>
	<header class="bn-space-settings__panel-head">
		<h2 class="bn-space-settings__panel-title"><?php buddynext_icon( 'link' ); ?> <?php esc_html_e( 'Invite link', 'buddynext' ); ?></h2>
		<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Share one link that lets people join this space directly. Reset it for a new link, or revoke it to turn it off.', 'buddynext' ); ?></p>
	</header>

	<?php if ( null === $bn_inv_link ) : ?>
		<?php // ── No link yet: create form ─────────────────────────────────── ?>
		<div class="bn-invite-panel__create">
			<div class="bn-invite-panel__fields">
				<label class="bn-invite-panel__field">
					<span class="bn-invite-panel__field-label"><?php esc_html_e( 'Expires', 'buddynext' ); ?></span>
					<select class="bn-select" data-bn-invite-expires>
						<?php foreach ( $bn_expiry_opts as $bn_v => $bn_l ) : ?>
							<option value="<?php echo esc_attr( $bn_v ); ?>" <?php selected( '7d', $bn_v ); ?>><?php echo esc_html( $bn_l ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="bn-invite-panel__field">
					<span class="bn-invite-panel__field-label"><?php esc_html_e( 'Max uses', 'buddynext' ); ?></span>
					<select class="bn-select" data-bn-invite-max>
						<?php foreach ( $bn_max_opts as $bn_v => $bn_l ) : ?>
							<option value="<?php echo esc_attr( $bn_v ); ?>" <?php selected( '0', $bn_v ); ?>><?php echo esc_html( $bn_l ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<button
				type="button"
				class="bn-btn"
				data-variant="primary"
				data-size="md"
				data-wp-on--click="actions.createInviteLink"
			><?php buddynext_icon( 'link' ); ?> <?php esc_html_e( 'Create invite link', 'buddynext' ); ?></button>
		</div>
	<?php else : ?>
		<?php // ── Link exists: display + copy + reset ──────────────────────── ?>
		<div class="bn-invite-panel__link">
			<div class="bn-invite-panel__url-row">
				<label class="bn-sr-only" for="bn-invite-url-<?php echo esc_attr( (string) $bn_inv_space_id ); ?>"><?php esc_html_e( 'Invite link URL', 'buddynext' ); ?></label>
				<input
					id="bn-invite-url-<?php echo esc_attr( (string) $bn_inv_space_id ); ?>"
					class="bn-input bn-invite-panel__url"
					type="text"
					readonly
					value="<?php echo esc_url( (string) ( $bn_inv_link['url'] ?? '' ) ); ?>"
					data-bn-invite-url
					data-wp-on--focus="actions.selectInviteUrl"
				/>
				<button
					type="button"
					class="bn-btn"
					data-variant="secondary"
					data-size="md"
					data-wp-on--click="actions.copyInviteLink"
				><?php buddynext_icon( 'copy' ); ?> <?php esc_html_e( 'Copy', 'buddynext' ); ?></button>
			</div>

			<p class="bn-invite-panel__meta">
				<?php if ( '' !== $bn_status_flag ) : ?>
					<span class="bn-badge" data-tone="warn"><?php echo esc_html( $bn_status_flag ); ?></span>
				<?php endif; ?>
				<span class="bn-invite-panel__status"><?php echo esc_html( $bn_status_line ); ?></span>
			</p>

			<div class="bn-invite-panel__actions">
				<button
					type="button"
					class="bn-btn"
					data-variant="ghost"
					data-size="md"
					data-wp-on--click="actions.resetInviteLink"
				><?php buddynext_icon( 'rotate-ccw' ); ?> <?php esc_html_e( 'Reset link', 'buddynext' ); ?></button>
				<button
					type="button"
					class="bn-btn"
					data-variant="ghost"
					data-size="md"
					data-tone="danger"
					data-wp-on--click="actions.revokeInviteLink"
				><?php buddynext_icon( 'x-circle' ); ?> <?php esc_html_e( 'Revoke link', 'buddynext' ); ?></button>
			</div>
		</div>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_invite_after', $args );
