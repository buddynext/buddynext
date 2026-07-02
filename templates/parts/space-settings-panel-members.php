<?php
/**
 * BuddyNext template part: space-settings-panel-members.
 *
 * Renders the "Members" panel — member list + invite form. Self-contained:
 * each row is its own POST form using the shared `bn_space_members_*` nonce.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space        Required. Space row.
 * @var array  $members_data Required. Bundle:
 *   - `space_id` (int)
 *   - `members`  (array<object>) Active member rows.
 * @var array  $classes      Optional. Extra CSS classes appended to root `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_members_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_members_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_members_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_members_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'        => isset( $space ) ? $space : null,
	'members_data' => isset( $members_data ) && is_array( $members_data ) ? $members_data : array(),
	'classes'      => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_members_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}

$bn_space_id      = isset( $args['members_data']['space_id'] ) ? (int) $args['members_data']['space_id'] : 0;
$bn_space_members = isset( $args['members_data']['members'] ) && is_array( $args['members_data']['members'] )
	? $args['members_data']['members']
	: array();

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_members_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_space_settings_panel_members_before', $args );

// REST/Interactivity context for the buddynext/space-members store — the same
// store the Members tab uses. Management actions (promote/demote/remove/ban/
// invite) all run through buddynext/v1, never a server-side POST.
$bn_sspm_ctx = (string) wp_json_encode(
	array(
		'spaceId'   => $bn_space_id,
		'restNonce' => wp_create_nonce( 'wp_rest' ),
	)
);
?>
<div
	class="bn-space-settings__members"
	data-wp-interactive="buddynext/space-members"
	data-wp-context="<?php echo esc_attr( $bn_sspm_ctx ); ?>"
>
	<div class="<?php echo esc_attr( $bn_class ); ?>">
		<header class="bn-space-settings__panel-head">
			<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Members', 'buddynext' ); ?></h2>
			<p class="bn-space-settings__panel-desc">
				<?php
				$bn_sspm_count = count( $bn_space_members );
				printf(
					/* translators: %d: number of active members */
					esc_html( _n( '%d active member', '%d active members', $bn_sspm_count, 'buddynext' ) ),
					(int) $bn_sspm_count
				);
				?>
			</p>
		</header>

		<?php if ( empty( $bn_space_members ) ) : ?>
			<p class="bn-space-settings__empty">
				<?php esc_html_e( 'No active members yet.', 'buddynext' ); ?>
			</p>
		<?php else : ?>
			<ul class="bn-space-settings__member-list" role="list">
				<?php foreach ( $bn_space_members as $bn_member ) : ?>
					<?php
					$bn_member_avatar_url = get_avatar_url( (int) $bn_member->user_id, array( 'size' => 72 ) );
					$bn_member_role       = in_array( $bn_member->role, array( 'owner', 'moderator', 'member' ), true )
						? $bn_member->role
						: 'member';
					$bn_is_owner          = ( 'owner' === $bn_member_role );

					$role_tone_map  = array(
						'owner'     => 'accent',
						'moderator' => 'info',
						'member'    => 'default',
					);
					$role_label_map = array(
						'owner'     => __( 'Owner', 'buddynext' ),
						'moderator' => __( 'Moderator', 'buddynext' ),
						'member'    => __( 'Member', 'buddynext' ),
					);
					$role_tone      = $role_tone_map[ $bn_member_role ];
					$role_label     = $role_label_map[ $bn_member_role ];
					$bn_uid_attr    = esc_attr( (string) $bn_member->user_id );
					?>
					<li class="bn-space-settings__member-row" role="listitem">
						<span class="bn-avatar" data-size="md" aria-hidden="true">
							<?php if ( $bn_member_avatar_url ) : ?>
								<img
									src="<?php echo esc_url( $bn_member_avatar_url ); ?>"
									alt=""
									loading="lazy"
								>
							<?php else : ?>
								<?php echo esc_html( strtoupper( substr( $bn_member->display_name, 0, 1 ) ) ); ?>
							<?php endif; ?>
						</span>

						<div class="bn-space-settings__member-info">
							<p class="bn-space-settings__member-name"><?php echo esc_html( $bn_member->display_name ); ?></p>
							<p class="bn-space-settings__member-meta">@<?php echo esc_html( $bn_member->user_login ); ?></p>
						</div>

						<span class="bn-badge" data-tone="<?php echo esc_attr( $role_tone ); ?>"><?php echo esc_html( $role_label ); ?></span>

						<?php if ( ! $bn_is_owner ) : ?>
							<div class="bn-space-settings__member-actions">
								<?php if ( 'member' === $bn_member_role ) : ?>
									<button
										type="button"
										class="bn-btn"
										data-variant="ghost"
										data-size="sm"
										data-user-id="<?php echo $bn_uid_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>"
										data-role="moderator"
										data-wp-on--click="actions.changeRole"
									>
										<?php esc_html_e( 'Make moderator', 'buddynext' ); ?>
									</button>
								<?php elseif ( 'moderator' === $bn_member_role ) : ?>
									<button
										type="button"
										class="bn-btn"
										data-variant="ghost"
										data-size="sm"
										data-user-id="<?php echo $bn_uid_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>"
										data-role="member"
										data-wp-on--click="actions.changeRole"
									>
										<?php esc_html_e( 'Remove moderator', 'buddynext' ); ?>
									</button>
								<?php endif; ?>

								<button
									type="button"
									class="bn-btn"
									data-variant="ghost"
									data-size="sm"
									data-user-id="<?php echo $bn_uid_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>"
									data-wp-on--click="actions.removeMember"
								>
									<?php esc_html_e( 'Remove', 'buddynext' ); ?>
								</button>

								<button
									type="button"
									class="bn-btn"
									data-variant="danger"
									data-size="sm"
									data-user-id="<?php echo $bn_uid_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>"
									data-wp-on--click="actions.banMember"
								>
									<?php esc_html_e( 'Ban', 'buddynext' ); ?>
								</button>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>

	<div class="bn-card bn-space-settings__panel">
		<header class="bn-space-settings__panel-head">
			<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Invite member', 'buddynext' ); ?></h2>
			<p class="bn-space-settings__panel-desc">
				<?php esc_html_e( 'Enter a username or email address to send an invitation.', 'buddynext' ); ?>
			</p>
		</header>
		<form class="bn-space-settings__invite-row" data-bn-invite-form data-wp-on--submit="actions.inviteMember">
			<label class="bn-sr-only" for="bn_invite_identifier">
				<?php esc_html_e( 'Username or email address', 'buddynext' ); ?>
			</label>
			<input
				type="text"
				id="bn_invite_identifier"
				class="bn-input"
				data-bn-invite-identifier
				placeholder="<?php esc_attr_e( 'Username or email address', 'buddynext' ); ?>"
				autocomplete="off"
			>
			<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
				<?php esc_html_e( 'Send invite', 'buddynext' ); ?>
			</button>
		</form>
	</div>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_members_after', $args );
