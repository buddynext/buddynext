<?php
/**
 * Template: Space Settings
 *
 * Renders the settings panel for a single space. Only accessible to
 * space admins/moderators or site admins. Composes from v2 primitives
 * (.bn-card, .bn-tabs, .bn-input, .bn-textarea, .bn-select, .bn-toggle,
 * .bn-btn, .bn-modal-backdrop, .bn-badge) — no bespoke design language.
 *
 * Expected context var (set by template loader):
 *   $space_id (int) — the current space's primary key.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bn_space_category_icon' ) ) {
	/**
	 * Return inline SVG for a space category slug.
	 *
	 * @param string|null $cat_slug Category slug.
	 * @return string SVG markup.
	 */
	function bn_space_category_icon( ?string $cat_slug ): string {
		$map  = array(
			'technology'  => 'cpu',
			'design'      => 'image',
			'marketing'   => 'megaphone',
			'startups'    => 'rocket',
			'ai-ml'       => 'cpu',
			'data'        => 'bar-chart',
			'product'     => 'target',
			'writing'     => 'edit',
			'open-source' => 'globe',
			'business'    => 'briefcase',
			'creative'    => 'star',
		);
		$slug = $map[ (string) $cat_slug ] ?? 'home';
		return buddynext_get_icon( $slug );
	}
}

global $wpdb;

// ── Resolve space ─────────────────────────────────────────────────────────────

$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( ! $space_id ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Permission gate ───────────────────────────────────────────────────────────

if ( ! buddynext_can( get_current_user_id(), 'buddynext-spaces/manage-settings', array( 'space_id' => $space_id ) ) ) {
	wp_die( esc_html__( 'You do not have permission to manage this space.', 'buddynext' ) );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$space = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT s.*, c.name AS category_name, c.slug AS category_slug
		FROM {$wpdb->prefix}bn_spaces s
		LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id
		WHERE s.id = %d LIMIT 1",
		$space_id
	)
);

if ( ! $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$categories = $wpdb->get_results(
	"SELECT id, name, slug FROM {$wpdb->prefix}bn_space_categories ORDER BY name ASC"
);

// ── Active settings tab ───────────────────────────────────────────────────────

$settings_tab = isset( $_GET['bn_stab'] ) ? sanitize_key( wp_unslash( $_GET['bn_stab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$allowed_tabs = array( 'general', 'privacy', 'permissions', 'members', 'moderation', 'branding', 'integrations', 'notifications', 'danger' );
if ( ! in_array( $settings_tab, $allowed_tabs, true ) ) {
	$settings_tab = 'general';
}

// ── Handle saved settings (POST) ─────────────────────────────────────────────

$save_notice = '';

$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
if ( 'POST' === $request_method && isset( $_POST['bn_space_settings_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_settings_nonce'] ) ), 'bn_space_settings_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		$update_data = array();

		if ( isset( $_POST['space_name'] ) ) {
			$update_data['name'] = sanitize_text_field( wp_unslash( $_POST['space_name'] ) );
		}
		if ( isset( $_POST['space_description'] ) ) {
			$update_data['description'] = sanitize_textarea_field( wp_unslash( $_POST['space_description'] ) );
		}
		if ( isset( $_POST['space_rules'] ) ) {
			$update_data['rules'] = sanitize_textarea_field( wp_unslash( $_POST['space_rules'] ) );
		}
		if ( isset( $_POST['space_cover_image_url'] ) ) {
			$raw_cover = trim( (string) wp_unslash( $_POST['space_cover_image_url'] ) );
			$update_data['cover_image_url'] = ( '' === $raw_cover ) ? '' : esc_url_raw( $raw_cover );
		}
		if ( isset( $_POST['space_category_id'] ) ) {
			$update_data['category_id'] = absint( $_POST['space_category_id'] );
		}
		if ( isset( $_POST['space_type'] ) && in_array( wp_unslash( $_POST['space_type'] ), array( 'open', 'private', 'secret' ), true ) ) {
			$update_data['type'] = sanitize_key( wp_unslash( $_POST['space_type'] ) );
		}
		update_option( 'bn_space_' . $space_id . '_allow_member_posts', isset( $_POST['allow_member_posts'] ) ? 1 : 0 );
		update_option( 'bn_space_' . $space_id . '_require_post_approval', isset( $_POST['require_post_approval'] ) ? 1 : 0 );
		update_option( 'bn_space_' . $space_id . '_push_to_feed', isset( $_POST['push_to_feed'] ) ? 1 : 0 );
		update_option( 'bn_space_' . $space_id . '_mvs_media_tab', isset( $_POST['mvs_media_tab'] ) ? 1 : 0 );
		if ( isset( $_POST['jetonomy_forum_id'] ) ) {
			update_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', absint( $_POST['jetonomy_forum_id'] ) );
		}

		if ( ! empty( $update_data ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_spaces',
				$update_data,
				array( 'id' => $space_id ),
				null,
				array( '%d' )
			);

			// Bust SpaceService caches so subsequent reads (incl. the re-fetch
			// below and the next page load) reflect the new values.
			wp_cache_delete( "space_{$space_id}", 'buddynext_spaces' );
			if ( isset( $space->slug ) && '' !== (string) $space->slug ) {
				wp_cache_delete( "space_slug_{$space->slug}", 'buddynext_spaces' );
			}
		}

		// Re-fetch fresh space data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space       = $wpdb->get_row( $wpdb->prepare( "SELECT s.*, c.name AS category_name, c.slug AS category_slug FROM {$wpdb->prefix}bn_spaces s LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id WHERE s.id = %d LIMIT 1", $space_id ) );
		$save_notice = 'success';
	}
}

// ── Handle permissions settings POST ─────────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_permissions_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_permissions_nonce'] ) ), 'bn_space_permissions_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		update_option( 'bn_space_' . $space_id . '_allow_member_posts', isset( $_POST['allow_member_posts'] ) ? 1 : 0 );
		update_option( 'bn_space_' . $space_id . '_require_post_approval', isset( $_POST['require_post_approval'] ) ? 1 : 0 );
		update_option( 'bn_space_' . $space_id . '_require_join_approval', isset( $_POST['require_join_approval'] ) ? 1 : 0 );
		$bn_who_post = isset( $_POST['who_can_post'] ) ? sanitize_key( wp_unslash( $_POST['who_can_post'] ) ) : 'members';
		if ( ! in_array( $bn_who_post, array( 'members', 'mods', 'owner' ), true ) ) {
			$bn_who_post = 'members';
		}
		update_option( 'bn_space_' . $space_id . '_who_can_post', $bn_who_post );

		$bn_who_invite = isset( $_POST['who_can_invite'] ) ? sanitize_key( wp_unslash( $_POST['who_can_invite'] ) ) : 'mods';
		if ( ! in_array( $bn_who_invite, array( 'members', 'mods', 'owner' ), true ) ) {
			$bn_who_invite = 'mods';
		}
		update_option( 'bn_space_' . $space_id . '_who_can_invite', $bn_who_invite );
		$save_notice = 'success';
	}
}

// ── Handle transfer-ownership POST ───────────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_transfer_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_transfer_nonce'] ) ), 'bn_space_transfer_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		$bn_new_owner = absint( $_POST['new_owner_id'] ?? 0 );
		if ( $bn_new_owner > 0 && $bn_new_owner !== (int) $space->owner_id ) {
			$bn_xfer_service = new \BuddyNext\Spaces\SpaceMemberService();
			if ( $bn_xfer_service->is_member( $space_id, $bn_new_owner ) ) {
				// Demote current owner to member, promote new owner.
				$bn_xfer_service->change_role( $space_id, (int) $space->owner_id, 'member', get_current_user_id() );
				$bn_xfer_service->change_role( $space_id, $bn_new_owner, 'owner', get_current_user_id() );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'bn_spaces',
					array( 'owner_id' => $bn_new_owner ),
					array( 'id' => $space_id ),
					array( '%d' ),
					array( '%d' )
				);
				wp_cache_delete( "space_{$space_id}", 'buddynext_spaces' );
				$save_notice = 'success';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$space = $wpdb->get_row( $wpdb->prepare( "SELECT s.*, c.name AS category_name, c.slug AS category_slug FROM {$wpdb->prefix}bn_spaces s LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id WHERE s.id = %d LIMIT 1", $space_id ) );
			} else {
				$save_notice = 'error';
			}
		} else {
			$save_notice = 'error';
		}
	}
}

// ── Handle moderation settings POST ─────────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_moderation_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_moderation_nonce'] ) ), 'bn_space_moderation_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		update_option(
			'bn_space_' . $space_id . '_require_post_approval',
			isset( $_POST['require_post_approval'] ) ? 1 : 0
		);

		$raw_banned_words = isset( $_POST['banned_words'] ) ? sanitize_textarea_field( wp_unslash( $_POST['banned_words'] ) ) : '';
		update_option( 'bn_space_' . $space_id . '_banned_words', $raw_banned_words );
		$save_notice = 'success';
	}
}

// ── Handle notifications settings POST ───────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_notifications_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_notifications_nonce'] ) ), 'bn_space_notifications_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		$allowed_prefs = array( 'all', 'mentions', 'none' );
		$pref_value    = isset( $_POST['default_notification_pref'] )
			? sanitize_key( wp_unslash( $_POST['default_notification_pref'] ) )
			: 'all';
		if ( ! in_array( $pref_value, $allowed_prefs, true ) ) {
			$pref_value = 'all';
		}
		update_option( 'bn_space_' . $space_id . '_default_notification_pref', $pref_value );
		$save_notice = 'success';
	}
}

// ── Handle members tab POST actions ──────────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_members_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_members_nonce'] ) ), 'bn_space_members_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		$member_action = isset( $_POST['member_action'] ) ? sanitize_key( wp_unslash( $_POST['member_action'] ) ) : '';
		$target_user   = isset( $_POST['target_user_id'] ) ? absint( $_POST['target_user_id'] ) : 0;

		if ( in_array( $member_action, array( 'promote', 'demote', 'remove', 'ban', 'invite' ), true ) ) {
			$member_service = new \BuddyNext\Spaces\SpaceMemberService();
			$acting_user_id = get_current_user_id();

			if ( $target_user && 'promote' === $member_action ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'bn_space_members',
					array( 'role' => 'moderator' ),
					array(
						'space_id' => $space_id,
						'user_id'  => $target_user,
					),
					array( '%s' ),
					array( '%d', '%d' )
				);
				$save_notice = 'success';
			} elseif ( $target_user && 'demote' === $member_action ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'bn_space_members',
					array( 'role' => 'member' ),
					array(
						'space_id' => $space_id,
						'user_id'  => $target_user,
					),
					array( '%s' ),
					array( '%d', '%d' )
				);
				$save_notice = 'success';
			} elseif ( $target_user && 'remove' === $member_action ) {
				$remove_result = $member_service->remove( $space_id, $target_user, $acting_user_id );
				$save_notice   = ( ! is_wp_error( $remove_result ) ) ? 'success' : 'error';
			} elseif ( $target_user && 'ban' === $member_action ) {
				$ban_result  = $member_service->ban( $space_id, $target_user, $acting_user_id );
				$save_notice = ( ! is_wp_error( $ban_result ) ) ? 'success' : 'error';
			} elseif ( 'invite' === $member_action ) {
				$invite_identifier = isset( $_POST['invite_identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['invite_identifier'] ) ) : '';
				if ( $invite_identifier ) {
					$invite_user = is_email( $invite_identifier )
						? get_user_by( 'email', $invite_identifier )
						: get_user_by( 'login', $invite_identifier );
					if ( $invite_user ) {
						$invite_result = $member_service->invite( $space_id, $acting_user_id, $invite_user->ID );
						$save_notice   = ( ! is_wp_error( $invite_result ) ) ? 'success' : 'error';
					} else {
						$save_notice = 'error';
					}
				}
			}
		}
	}
}

$allow_member_posts    = (bool) get_option( 'bn_space_' . $space_id . '_allow_member_posts', 1 );
$require_post_approval = (bool) get_option( 'bn_space_' . $space_id . '_require_post_approval', 0 );
$require_join_approval = (bool) get_option( 'bn_space_' . $space_id . '_require_join_approval', 0 );
$push_to_feed          = (bool) get_option( 'bn_space_' . $space_id . '_push_to_feed', 1 );
$mvs_media_tab         = (bool) get_option( 'bn_space_' . $space_id . '_mvs_media_tab', 0 );
$jetonomy_forum_id     = (int) get_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', 0 );
$who_can_post          = (string) get_option( 'bn_space_' . $space_id . '_who_can_post', 'members' );
$who_can_invite        = (string) get_option( 'bn_space_' . $space_id . '_who_can_invite', 'mods' );

// Moderation options.
$mod_require_approval = (bool) get_option( 'bn_space_' . $space_id . '_require_post_approval', 0 );
$mod_banned_words     = (string) get_option( 'bn_space_' . $space_id . '_banned_words', '' );

// Notifications option.
$default_notification_pref = (string) get_option( 'bn_space_' . $space_id . '_default_notification_pref', 'all' );

// Members list — always fetched so members tab renders without conditional query.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$space_members = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT sm.user_id, sm.role, sm.status, u.display_name, u.user_login, u.user_email
		FROM {$wpdb->prefix}bn_space_members sm
		INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
		WHERE sm.space_id = %d AND sm.status = 'active'
		ORDER BY FIELD(sm.role,'owner','moderator','member'), u.display_name ASC
		LIMIT 200",
		$space_id
	)
);

$space_url     = buddynext_space_url( $space->slug ?? '' );
$settings_base = buddynext_space_settings_url( $space->slug ?? '' );

// Privacy badge tone for the hero. Labels resolve via SpaceService::type_label()
// so the wording stays in lockstep with the directory + space home + Pro tabs.
$privacy_tone_map = array(
	'open'    => 'success',
	'private' => 'warn',
	'secret'  => 'danger',
);
$privacy_tone  = $privacy_tone_map[ $space->type ?? 'open' ] ?? $privacy_tone_map['open'];
$privacy_label = \BuddyNext\Spaces\SpaceService::type_label( (string) ( $space->type ?? 'open' ) );

// Tabs definition.
$nav_items = array(
	'general'       => array(
		'icon'  => 'info',
		'label' => __( 'General', 'buddynext' ),
	),
	'permissions'   => array(
		'icon'  => 'lock',
		'label' => __( 'Permissions', 'buddynext' ),
	),
	'members'       => array(
		'icon'  => 'users',
		'label' => __( 'Members', 'buddynext' ),
	),
	'branding'      => array(
		'icon'  => 'image',
		'label' => __( 'Branding', 'buddynext' ),
	),
	'moderation'    => array(
		'icon'  => 'shield',
		'label' => __( 'Moderation', 'buddynext' ),
	),
	'integrations'  => array(
		'icon'  => 'link',
		'label' => __( 'Integrations', 'buddynext' ),
	),
	'notifications' => array(
		'icon'  => 'mail',
		'label' => __( 'Notifications', 'buddynext' ),
	),
	'danger'        => array(
		'icon'  => 'alert-triangle',
		'label' => __( 'Danger zone', 'buddynext' ),
	),
);

/**
 * Filter the per-space settings tabs.
 *
 * Pro modules (notably P6.2 per-space brand override) inject additional tabs
 * here. Each entry is keyed by tab slug and must provide `icon` (Lucide slug
 * present in assets/icons/) and `label` (translated string). Pro modules that
 * register a tab must also render that tab's content via the
 * `buddynext_space_settings_tab_content` action.
 *
 * @since 0.3.0
 *
 * @param array<string, array{icon: string, label: string}> $nav_items Tab definitions.
 * @param int                                               $space_id  Space being configured.
 */
$nav_items = apply_filters( 'buddynext_space_settings_tabs', $nav_items, $space_id );

// Re-validate the active tab against the (possibly extended) tab list.
$allowed_tabs = array_keys( $nav_items );
if ( ! in_array( $settings_tab, $allowed_tabs, true ) ) {
	$settings_tab = 'general';
}
?>
<div
	class="bn-space-settings"
	data-wp-interactive="buddynext/spaces"
	data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
>

	<!-- Space header (mirrors space-home hero shape) -->
	<div class="bn-sh-header">
		<div class="bn-sh-cover">
			<?php if ( ! empty( $space->cover_image_url ) ) : ?>
				<img
					src="<?php echo esc_url( $space->cover_image_url ); ?>"
					alt="<?php echo esc_attr( $space->name ?? '' ); ?>"
					loading="lazy"
				>
			<?php endif; ?>
		</div>

		<div class="bn-sh-inner">
			<div class="bn-sh-avatar" aria-hidden="true">
				<?php echo wp_kses_data( bn_space_category_icon( $space->category_slug ?? '' ) ); ?>
			</div>

			<div class="bn-sh-info">
				<h1 class="bn-sh-name">
					<?php echo esc_html( $space->name ?? '' ); ?>
					<span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span>
				</h1>
				<div class="bn-sh-meta">
					<span><?php buddynext_icon( 'settings' ); ?> <?php esc_html_e( 'Space settings', 'buddynext' ); ?></span>
					<?php if ( ! empty( $space->category_name ) ) : ?>
						<span><?php buddynext_icon( 'hash' ); ?> <?php echo esc_html( $space->category_name ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="bn-sh-actions">
				<a
					href="<?php echo esc_url( $space_url ); ?>"
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
				><?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Back to space', 'buddynext' ); ?></a>
			</div>
		</div>

		<nav class="bn-tabs bn-sh-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'buddynext' ); ?>">
			<?php foreach ( $nav_items as $tab_key => $nav_item ) : ?>
				<?php $is_active = ( $settings_tab === $tab_key ); ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'bn_stab', $tab_key, $settings_base ) ); ?>"
					class="bn-tab bn-sh-tab"
					role="tab"
					aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
				>
					<?php buddynext_icon( $nav_item['icon'] ); ?>
					<?php echo esc_html( $nav_item['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	</div>

	<!-- Content shell -->
	<div class="bn-space-settings__shell">

		<?php if ( 'success' === $save_notice ) : ?>
			<div class="bn-card bn-space-settings__notice" data-tone="success" role="status">
				<span class="bn-space-settings__notice-icon" aria-hidden="true"><?php buddynext_icon( 'check-circle' ); ?></span>
				<?php esc_html_e( 'Changes saved successfully.', 'buddynext' ); ?>
			</div>
		<?php elseif ( 'error' === $save_notice ) : ?>
			<div class="bn-card bn-space-settings__notice" data-tone="danger" role="alert">
				<span class="bn-space-settings__notice-icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
				<?php esc_html_e( 'Security check failed. Please try again.', 'buddynext' ); ?>
			</div>
		<?php endif; ?>

		<?php if ( 'permissions' === $settings_tab ) : ?>

			<form
				method="post"
				action=""
				class="bn-space-settings__form"
				data-bn-settings-permissions-form
				data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
			>
				<?php wp_nonce_field( 'bn_space_permissions_' . $space_id, 'bn_space_permissions_nonce' ); ?>

				<div class="bn-card bn-space-settings__panel">
					<header class="bn-space-settings__panel-head">
						<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Permissions', 'buddynext' ); ?></h2>
						<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Who can post, invite, and join this space.', 'buddynext' ); ?></p>
					</header>

					<div class="bn-space-settings__field">
						<label for="bn_who_can_post"><?php esc_html_e( 'Who can post', 'buddynext' ); ?></label>
						<select id="bn_who_can_post" name="who_can_post" class="bn-select">
							<option value="members" <?php selected( $who_can_post, 'members' ); ?>>
								<?php esc_html_e( 'All members', 'buddynext' ); ?>
							</option>
							<option value="mods" <?php selected( $who_can_post, 'mods' ); ?>>
								<?php esc_html_e( 'Moderators and owner only', 'buddynext' ); ?>
							</option>
							<option value="owner" <?php selected( $who_can_post, 'owner' ); ?>>
								<?php esc_html_e( 'Owner only (announcements)', 'buddynext' ); ?>
							</option>
						</select>
					</div>

					<div class="bn-space-settings__field">
						<label for="bn_who_can_invite"><?php esc_html_e( 'Who can invite new members', 'buddynext' ); ?></label>
						<select id="bn_who_can_invite" name="who_can_invite" class="bn-select">
							<option value="members" <?php selected( $who_can_invite, 'members' ); ?>>
								<?php esc_html_e( 'All members', 'buddynext' ); ?>
							</option>
							<option value="mods" <?php selected( $who_can_invite, 'mods' ); ?>>
								<?php esc_html_e( 'Moderators and owner', 'buddynext' ); ?>
							</option>
							<option value="owner" <?php selected( $who_can_invite, 'owner' ); ?>>
								<?php esc_html_e( 'Owner only', 'buddynext' ); ?>
							</option>
						</select>
					</div>

					<div class="bn-toggle-row">
						<div class="bn-toggle-row__copy">
							<div class="bn-toggle-row__label"><?php esc_html_e( 'Allow member posts', 'buddynext' ); ?></div>
							<div class="bn-toggle-row__desc"><?php esc_html_e( 'Members can post in the space feed.', 'buddynext' ); ?></div>
						</div>
						<label class="bn-space-settings__toggle-shell">
							<input type="checkbox" class="bn-space-settings__toggle-input" name="allow_member_posts" value="1" <?php checked( $allow_member_posts ); ?>>
							<span class="bn-toggle" aria-hidden="true"></span>
						</label>
					</div>

					<div class="bn-toggle-row">
						<div class="bn-toggle-row__copy">
							<div class="bn-toggle-row__label"><?php esc_html_e( 'Require approval to join', 'buddynext' ); ?></div>
							<div class="bn-toggle-row__desc"><?php esc_html_e( 'New join requests go to the owner/mod queue.', 'buddynext' ); ?></div>
						</div>
						<label class="bn-space-settings__toggle-shell">
							<input type="checkbox" class="bn-space-settings__toggle-input" name="require_join_approval" value="1" <?php checked( $require_join_approval ); ?>>
							<span class="bn-toggle" aria-hidden="true"></span>
						</label>
					</div>

					<div class="bn-toggle-row">
						<div class="bn-toggle-row__copy">
							<div class="bn-toggle-row__label"><?php esc_html_e( 'Pre-moderate posts', 'buddynext' ); ?></div>
							<div class="bn-toggle-row__desc"><?php esc_html_e( 'New posts go to a moderation queue before appearing publicly.', 'buddynext' ); ?></div>
						</div>
						<label class="bn-space-settings__toggle-shell">
							<input type="checkbox" class="bn-space-settings__toggle-input" name="require_post_approval" value="1" <?php checked( $require_post_approval ); ?>>
							<span class="bn-toggle" aria-hidden="true"></span>
						</label>
					</div>
				</div>

				<div class="bn-space-settings__save-row">
					<a href="<?php echo esc_url( $space_url ); ?>" class="bn-btn" data-variant="ghost" data-size="md">
						<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
					</a>
					<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
						<?php esc_html_e( 'Save permissions', 'buddynext' ); ?>
					</button>
				</div>
			</form>

		<?php elseif ( 'branding' === $settings_tab ) : ?>

			<div class="bn-card bn-space-settings__panel">
				<header class="bn-space-settings__panel-head">
					<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Branding', 'buddynext' ); ?></h2>
					<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Customize how this space looks for its members.', 'buddynext' ); ?></p>
				</header>

				<?php
				/**
				 * Allow Pro to render the real branding controls (P6.2 per-space
				 * accent hue + cover override). Free renders the upsell card below
				 * when the action returns nothing.
				 */
				ob_start();
				do_action( 'buddynext_space_branding_settings', $space_id );
				$bn_pro_branding_html = (string) ob_get_clean();
				?>

				<?php if ( '' !== trim( $bn_pro_branding_html ) ) : ?>
					<?php echo wp_kses_post( $bn_pro_branding_html ); ?>
				<?php else : ?>
					<div class="bn-space-settings__upsell" data-tone="pro">
						<div class="bn-space-settings__upsell-icon" aria-hidden="true">
							<?php buddynext_icon( 'sparkles' ); ?>
						</div>
						<div class="bn-space-settings__upsell-copy">
							<h3 class="bn-space-settings__upsell-title">
								<?php esc_html_e( 'Custom space brand', 'buddynext' ); ?>
								<span class="bn-badge" data-tone="pro"><?php esc_html_e( 'Pro', 'buddynext' ); ?></span>
							</h3>
							<p class="bn-space-settings__upsell-desc">
								<?php esc_html_e( 'Set a custom accent hue and cover override per space. Available in BuddyNext Pro.', 'buddynext' ); ?>
							</p>
							<a
								href="https://wbcomdesigns.com/products/buddynext-pro/"
								class="bn-btn"
								data-variant="primary"
								data-size="md"
								target="_blank"
								rel="noopener"
							><?php esc_html_e( 'Learn more', 'buddynext' ); ?></a>
						</div>
					</div>
				<?php endif; ?>
			</div>

		<?php elseif ( 'members' === $settings_tab ) : ?>

			<div class="bn-card bn-space-settings__panel">
				<header class="bn-space-settings__panel-head">
					<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Members', 'buddynext' ); ?></h2>
					<p class="bn-space-settings__panel-desc">
						<?php
						printf(
							/* translators: %d: number of active members */
							esc_html__( '%d active members', 'buddynext' ),
							count( $space_members )
						);
						?>
					</p>
				</header>

				<?php if ( empty( $space_members ) ) : ?>
					<p class="bn-space-settings__empty">
						<?php esc_html_e( 'No active members yet.', 'buddynext' ); ?>
					</p>
				<?php else : ?>
					<ul class="bn-space-settings__member-list" role="list">
						<?php foreach ( $space_members as $bn_member ) : ?>
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
							$role_tone      = $role_tone_map[ $bn_member_role ] ?? 'default';
							$role_label     = $role_label_map[ $bn_member_role ] ?? ucfirst( $bn_member_role );
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
											<form method="post" action="">
												<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
												<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $bn_member->user_id ); ?>">
												<input type="hidden" name="member_action" value="promote">
												<button type="submit" class="bn-btn" data-variant="ghost" data-size="sm">
													<?php esc_html_e( 'Make moderator', 'buddynext' ); ?>
												</button>
											</form>
										<?php elseif ( 'moderator' === $bn_member_role ) : ?>
											<form method="post" action="">
												<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
												<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $bn_member->user_id ); ?>">
												<input type="hidden" name="member_action" value="demote">
												<button type="submit" class="bn-btn" data-variant="ghost" data-size="sm">
													<?php esc_html_e( 'Remove moderator', 'buddynext' ); ?>
												</button>
											</form>
										<?php endif; ?>

										<form method="post" action="">
											<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
											<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $bn_member->user_id ); ?>">
											<input type="hidden" name="member_action" value="remove">
											<button
												type="submit"
												class="bn-btn"
												data-variant="ghost"
												data-size="sm"
												data-bn-confirm="<?php echo esc_attr( __( 'Remove this member from the space?', 'buddynext' ) ); ?>"
											>
												<?php esc_html_e( 'Remove', 'buddynext' ); ?>
											</button>
										</form>

										<form method="post" action="">
											<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
											<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $bn_member->user_id ); ?>">
											<input type="hidden" name="member_action" value="ban">
											<button
												type="submit"
												class="bn-btn"
												data-variant="danger"
												data-size="sm"
												data-bn-confirm="<?php echo esc_attr( __( 'Ban this member? They will not be able to rejoin.', 'buddynext' ) ); ?>"
											>
												<?php esc_html_e( 'Ban', 'buddynext' ); ?>
											</button>
										</form>
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
				<form method="post" action="" class="bn-space-settings__invite-row">
					<?php wp_nonce_field( 'bn_space_members_' . $space_id, 'bn_space_members_nonce' ); ?>
					<input type="hidden" name="member_action" value="invite">
					<input type="hidden" name="target_user_id" value="0">
					<label class="bn-sr-only" for="bn_invite_identifier">
						<?php esc_html_e( 'Username or email address', 'buddynext' ); ?>
					</label>
					<input
						type="text"
						id="bn_invite_identifier"
						name="invite_identifier"
						class="bn-input"
						placeholder="<?php esc_attr_e( 'Username or email address', 'buddynext' ); ?>"
						required
					>
					<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
						<?php esc_html_e( 'Send invite', 'buddynext' ); ?>
					</button>
				</form>
			</div>

		<?php elseif ( 'moderation' === $settings_tab ) : ?>

			<form method="post" action="" class="bn-space-settings__form">
				<?php wp_nonce_field( 'bn_space_moderation_' . $space_id, 'bn_space_moderation_nonce' ); ?>

				<div class="bn-card bn-space-settings__panel">
					<header class="bn-space-settings__panel-head">
						<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Moderation', 'buddynext' ); ?></h2>
						<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Control what members can post in this space.', 'buddynext' ); ?></p>
					</header>

					<div class="bn-toggle-row">
						<div class="bn-toggle-row__copy">
							<div class="bn-toggle-row__label"><?php esc_html_e( 'Pre-moderate posts', 'buddynext' ); ?></div>
							<div class="bn-toggle-row__desc"><?php esc_html_e( 'New posts from members go to a moderation queue before appearing publicly.', 'buddynext' ); ?></div>
						</div>
						<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Pre-moderate posts', 'buddynext' ); ?>">
							<input type="checkbox" class="bn-space-settings__toggle-input" name="require_post_approval" value="1" <?php checked( $mod_require_approval ); ?>>
							<span class="bn-toggle" aria-hidden="true"></span>
						</label>
					</div>

					<div class="bn-space-settings__field">
						<label for="bn_banned_words"><?php esc_html_e( 'Banned words', 'buddynext' ); ?></label>
						<textarea
							id="bn_banned_words"
							name="banned_words"
							class="bn-textarea"
							rows="6"
							placeholder="<?php esc_attr_e( 'One word or phrase per line', 'buddynext' ); ?>"
						><?php echo esc_textarea( $mod_banned_words ); ?></textarea>
						<p class="bn-space-settings__hint">
							<?php esc_html_e( 'Posts containing these words will be held for review. One word or phrase per line.', 'buddynext' ); ?>
						</p>
					</div>
				</div>

				<div class="bn-space-settings__save-row">
					<a href="<?php echo esc_url( $space_url ); ?>" class="bn-btn" data-variant="ghost" data-size="md">
						<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
					</a>
					<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
						<?php esc_html_e( 'Save changes', 'buddynext' ); ?>
					</button>
				</div>
			</form>

		<?php elseif ( 'notifications' === $settings_tab ) : ?>

			<form method="post" action="" class="bn-space-settings__form">
				<?php wp_nonce_field( 'bn_space_notifications_' . $space_id, 'bn_space_notifications_nonce' ); ?>

				<div class="bn-card bn-space-settings__panel">
					<header class="bn-space-settings__panel-head">
						<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Notifications', 'buddynext' ); ?></h2>
						<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Default notification setting applied when a new member joins this space.', 'buddynext' ); ?></p>
					</header>

					<div class="bn-space-settings__field">
						<label for="bn_default_notification_pref"><?php esc_html_e( 'Default notification preference for new members', 'buddynext' ); ?></label>
						<select
							id="bn_default_notification_pref"
							name="default_notification_pref"
							class="bn-select"
						>
							<option value="all" <?php selected( $default_notification_pref, 'all' ); ?>>
								<?php esc_html_e( 'All activity', 'buddynext' ); ?>
							</option>
							<option value="mentions" <?php selected( $default_notification_pref, 'mentions' ); ?>>
								<?php esc_html_e( 'Mentions only', 'buddynext' ); ?>
							</option>
							<option value="none" <?php selected( $default_notification_pref, 'none' ); ?>>
								<?php esc_html_e( 'None', 'buddynext' ); ?>
							</option>
						</select>
						<p class="bn-space-settings__hint">
							<?php esc_html_e( 'Individual members can override this in their own notification settings.', 'buddynext' ); ?>
						</p>
					</div>
				</div>

				<div class="bn-space-settings__save-row">
					<a href="<?php echo esc_url( $space_url ); ?>" class="bn-btn" data-variant="ghost" data-size="md">
						<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
					</a>
					<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
						<?php esc_html_e( 'Save changes', 'buddynext' ); ?>
					</button>
				</div>
			</form>

		<?php elseif ( 'danger' === $settings_tab ) : ?>

			<div class="bn-card bn-space-settings__panel">
				<header class="bn-space-settings__panel-head">
					<h2 class="bn-space-settings__panel-title bn-space-settings__panel-title--danger">
						<?php buddynext_icon( 'alert-triangle' ); ?> <?php esc_html_e( 'Danger zone', 'buddynext' ); ?>
					</h2>
					<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'These actions are permanent and cannot be undone.', 'buddynext' ); ?></p>
				</header>

				<div class="bn-space-settings__danger-list">
					<div class="bn-space-settings__danger-row">
						<div>
							<div class="bn-space-settings__danger-title"><?php esc_html_e( 'Transfer ownership', 'buddynext' ); ?></div>
							<div class="bn-space-settings__danger-desc"><?php esc_html_e( 'Hand the space over to another active member. You will become a regular member.', 'buddynext' ); ?></div>
						</div>
						<button
							type="button"
							class="bn-btn"
							data-variant="secondary"
							data-size="md"
							data-wp-on--click="actions.openTransferOwnershipModal"
						><?php esc_html_e( 'Transfer ownership', 'buddynext' ); ?></button>
					</div>

					<div class="bn-space-settings__danger-row">
						<div>
							<div class="bn-space-settings__danger-title"><?php esc_html_e( 'Archive space', 'buddynext' ); ?></div>
							<div class="bn-space-settings__danger-desc"><?php esc_html_e( 'Make the space read-only. Members can still view posts but new activity is disabled.', 'buddynext' ); ?></div>
						</div>
						<button
							type="button"
							class="bn-btn"
							data-variant="secondary"
							data-size="md"
							data-wp-on--click="actions.openArchiveSpaceModal"
							data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
						><?php esc_html_e( 'Archive space', 'buddynext' ); ?></button>
					</div>

					<div class="bn-space-settings__danger-row">
						<div>
							<div class="bn-space-settings__danger-title"><?php esc_html_e( 'Delete space', 'buddynext' ); ?></div>
							<div class="bn-space-settings__danger-desc"><?php esc_html_e( 'Permanently remove the space, its posts, and its memberships. This action cannot be undone.', 'buddynext' ); ?></div>
						</div>
						<button
							type="button"
							class="bn-btn"
							data-variant="danger"
							data-size="md"
							data-wp-on--click="actions.openDeleteSpaceConfirm"
						><?php esc_html_e( 'Delete space', 'buddynext' ); ?></button>
					</div>
				</div>
			</div>

			<?php
			// Build the eligible-new-owner list (active members excluding current owner).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$bn_xfer_candidates = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT sm.user_id, u.display_name
					 FROM {$wpdb->prefix}bn_space_members sm
					 INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
					 WHERE sm.space_id = %d AND sm.status = 'active' AND sm.user_id != %d
					 ORDER BY u.display_name ASC
					 LIMIT 200",
					$space_id,
					(int) $space->owner_id
				)
			);
			?>

			<!-- Transfer-ownership modal -->
			<div
				class="bn-modal-backdrop"
				role="dialog"
				aria-modal="true"
				aria-labelledby="bn-transfer-title"
				hidden
				data-bn-modal="transfer-ownership"
				data-bn-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
			>
				<div class="bn-modal__panel" data-size="sm">
					<header class="bn-modal__head">
						<h2 class="bn-modal__title" id="bn-transfer-title"><?php esc_html_e( 'Transfer ownership', 'buddynext' ); ?></h2>
						<button
							type="button"
							class="bn-modal__close"
							aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
							data-bn-modal-close
						><?php buddynext_icon( 'x' ); ?></button>
					</header>
					<div class="bn-modal__body">
						<p><?php esc_html_e( 'Pick the new space owner. You will be demoted to a regular member.', 'buddynext' ); ?></p>
						<label class="bn-sr-only" for="bn_transfer_target">
							<?php esc_html_e( 'New owner', 'buddynext' ); ?>
						</label>
						<select id="bn_transfer_target" class="bn-select" data-bn-transfer-target>
							<option value=""><?php esc_html_e( '— Pick an active member —', 'buddynext' ); ?></option>
							<?php foreach ( $bn_xfer_candidates as $bn_xc ) : ?>
								<option value="<?php echo esc_attr( (string) $bn_xc->user_id ); ?>">
									<?php echo esc_html( $bn_xc->display_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="bn-modal__error" data-bn-transfer-error hidden></p>
					</div>
					<div class="bn-modal__foot">
						<button
							type="button"
							class="bn-btn"
							data-variant="ghost"
							data-size="md"
							data-bn-modal-close
						><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
						<button
							type="button"
							class="bn-btn"
							data-variant="primary"
							data-size="md"
							data-wp-on--click="actions.transferOwnership"
						><?php esc_html_e( 'Transfer', 'buddynext' ); ?></button>
					</div>
				</div>
			</div>

			<?php
			// Include the name-typing delete confirm modal.
			buddynext_get_template(
				'partials/space-delete-confirm-modal.php',
				array(
					'space_id'   => $space_id,
					'space_name' => (string) ( $space->name ?? '' ),
				)
			);
			?>

			<!-- Delete-space confirm modal -->
			<div
				class="bn-modal-backdrop"
				role="dialog"
				aria-modal="true"
				aria-labelledby="bn-delete-space-title"
				hidden
				data-bn-modal="delete-space"
			>
				<div class="bn-modal__panel" data-tone="danger" data-size="sm">
					<header class="bn-modal__head">
						<h2 class="bn-modal__title" id="bn-delete-space-title"><?php esc_html_e( 'Delete this space?', 'buddynext' ); ?></h2>
						<button
							type="button"
							class="bn-modal__close"
							aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
							data-bn-modal-close
						><?php buddynext_icon( 'x' ); ?></button>
					</header>
					<div class="bn-modal__body">
						<p><?php esc_html_e( 'This will permanently delete the space, all of its posts, and all member relationships. This cannot be undone.', 'buddynext' ); ?></p>
					</div>
					<div class="bn-modal__foot">
						<button
							type="button"
							class="bn-btn"
							data-variant="ghost"
							data-size="md"
							data-bn-modal-close
						><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
						<button
							type="button"
							class="bn-btn"
							data-variant="danger"
							data-size="md"
							data-wp-on--click="actions.deleteSpace"
							data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
						><?php esc_html_e( 'Delete permanently', 'buddynext' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Archive-space confirm modal -->
			<div
				class="bn-modal-backdrop"
				role="dialog"
				aria-modal="true"
				aria-labelledby="bn-archive-space-title"
				hidden
				data-bn-modal="archive-space"
			>
				<div class="bn-modal__panel" data-size="sm">
					<header class="bn-modal__head">
						<h2 class="bn-modal__title" id="bn-archive-space-title"><?php esc_html_e( 'Archive this space?', 'buddynext' ); ?></h2>
						<button
							type="button"
							class="bn-modal__close"
							aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
							data-bn-modal-close
						><?php buddynext_icon( 'x' ); ?></button>
					</header>
					<div class="bn-modal__body">
						<p><?php esc_html_e( 'The space will become read-only. Members can still view past activity, but new posts and joins will be disabled.', 'buddynext' ); ?></p>
					</div>
					<div class="bn-modal__foot">
						<button
							type="button"
							class="bn-btn"
							data-variant="ghost"
							data-size="md"
							data-bn-modal-close
						><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
						<button
							type="button"
							class="bn-btn"
							data-variant="primary"
							data-size="md"
							data-wp-on--click="actions.archiveSpace"
							data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
						><?php esc_html_e( 'Archive space', 'buddynext' ); ?></button>
					</div>
				</div>
			</div>

		<?php else : ?>

			<form method="post" action="" enctype="multipart/form-data" class="bn-space-settings__form">
				<?php wp_nonce_field( 'bn_space_settings_' . $space_id, 'bn_space_settings_nonce' ); ?>

				<?php if ( 'general' === $settings_tab ) : ?>

					<div class="bn-card bn-space-settings__panel">
						<header class="bn-space-settings__panel-head">
							<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'General', 'buddynext' ); ?></h2>
							<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Basic information about your space.', 'buddynext' ); ?></p>
						</header>

						<div class="bn-space-settings__field">
							<label for="bn_space_icon"><?php esc_html_e( 'Space icon', 'buddynext' ); ?></label>
							<div class="bn-space-settings__upload">
								<div class="bn-space-settings__upload-current" aria-hidden="true">
									<?php echo wp_kses_data( bn_space_category_icon( $space->category_slug ?? '' ) ); ?>
								</div>
								<div class="bn-space-settings__upload-actions">
									<button type="button" class="bn-btn" data-variant="secondary" data-size="md" id="bn_space_icon">
										<?php esc_html_e( 'Upload image', 'buddynext' ); ?>
									</button>
									<p class="bn-space-settings__hint"><?php esc_html_e( 'Or pick an icon based on the category.', 'buddynext' ); ?></p>
								</div>
							</div>
						</div>

						<div class="bn-space-settings__field" data-bn-cover-field>
							<label><?php esc_html_e( 'Cover image', 'buddynext' ); ?></label>
							<div
								class="bn-space-settings__cover<?php echo ! empty( $space->cover_image_url ) ? ' has-image' : ''; ?>"
								data-bn-cover-preview
								role="button"
								tabindex="0"
								aria-label="<?php esc_attr_e( 'Upload cover photo', 'buddynext' ); ?>"
								<?php if ( ! empty( $space->cover_image_url ) ) : ?>
									style="background-image:url('<?php echo esc_url( $space->cover_image_url ); ?>');background-size:cover;background-position:center;"
								<?php endif; ?>
							>
								<span class="bn-space-settings__cover-empty"<?php echo ! empty( $space->cover_image_url ) ? ' hidden' : ''; ?>>
									<?php buddynext_icon( 'image' ); ?> <?php esc_html_e( 'Upload cover photo', 'buddynext' ); ?>
								</span>
							</div>
							<input
								type="hidden"
								name="space_cover_image_url"
								data-bn-cover-input
								value="<?php echo esc_attr( $space->cover_image_url ?? '' ); ?>"
							>
							<div class="bn-space-settings__cover-actions">
								<button
									type="button"
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
									data-bn-cover-upload
								>
									<?php buddynext_icon( 'upload' ); ?> <?php esc_html_e( 'Upload cover photo', 'buddynext' ); ?>
								</button>
								<button
									type="button"
									class="bn-btn"
									data-variant="ghost"
									data-size="sm"
									data-bn-cover-remove
									<?php echo empty( $space->cover_image_url ) ? 'hidden' : ''; ?>
								>
									<?php esc_html_e( 'Remove', 'buddynext' ); ?>
								</button>
							</div>
							<p class="bn-space-settings__hint"><?php esc_html_e( 'Recommended 1500×500. Falls back to a gradient when empty.', 'buddynext' ); ?></p>
						</div>

						<div class="bn-space-settings__field">
							<label for="space_name"><?php esc_html_e( 'Space name', 'buddynext' ); ?> <span aria-hidden="true">*</span></label>
							<input
								type="text"
								id="space_name"
								name="space_name"
								class="bn-input"
								value="<?php echo esc_attr( $space->name ?? '' ); ?>"
								required
								maxlength="100"
							>
						</div>

						<div class="bn-space-settings__field">
							<label for="space_description"><?php esc_html_e( 'Description', 'buddynext' ); ?></label>
							<textarea
								id="space_description"
								name="space_description"
								class="bn-textarea"
								maxlength="160"
								rows="3"
							><?php echo esc_textarea( $space->description ?? '' ); ?></textarea>
							<p class="bn-space-settings__hint"><?php esc_html_e( '160 characters max. Shown in the spaces directory.', 'buddynext' ); ?></p>
						</div>

						<div class="bn-space-settings__field">
							<label for="space_rules"><?php esc_html_e( 'House rules', 'buddynext' ); ?></label>
							<textarea
								id="space_rules"
								name="space_rules"
								class="bn-textarea"
								rows="6"
								placeholder="<?php esc_attr_e( "Be kind\nNo spam\nStay on topic", 'buddynext' ); ?>"
							><?php echo esc_textarea( $space->rules ?? '' ); ?></textarea>
							<p class="bn-space-settings__hint"><?php esc_html_e( 'One rule per line. Renders as a numbered list on the About tab.', 'buddynext' ); ?></p>
						</div>

						<div class="bn-space-settings__field">
							<label for="space_category_id"><?php esc_html_e( 'Category', 'buddynext' ); ?></label>
							<select name="space_category_id" id="space_category_id" class="bn-select">
								<option value=""><?php esc_html_e( 'Select a category', 'buddynext' ); ?></option>
								<?php foreach ( $categories as $bn_cat_item ) : ?>
									<option
										value="<?php echo esc_attr( (string) $bn_cat_item->id ); ?>"
										<?php selected( (int) ( $space->category_id ?? 0 ), (int) $bn_cat_item->id ); ?>
									><?php echo esc_html( $bn_cat_item->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

				<?php elseif ( 'privacy' === $settings_tab ) : ?>

					<div class="bn-card bn-space-settings__panel">
						<header class="bn-space-settings__panel-head">
							<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Privacy', 'buddynext' ); ?></h2>
							<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Who can see and join your space.', 'buddynext' ); ?></p>
						</header>

						<div class="bn-space-settings__field">
							<label for="space_type"><?php esc_html_e( 'Space visibility', 'buddynext' ); ?></label>
							<select name="space_type" id="space_type" class="bn-select">
								<option value="open" <?php selected( $space->type, 'open' ); ?>>
									<?php esc_html_e( 'Open: listed in directory, anyone can join', 'buddynext' ); ?>
								</option>
								<option value="private" <?php selected( $space->type, 'private' ); ?>>
									<?php esc_html_e( 'Private: listed but requires approval to join', 'buddynext' ); ?>
								</option>
								<option value="secret" <?php selected( $space->type, 'secret' ); ?>>
									<?php esc_html_e( 'Secret: not listed, admin invites only', 'buddynext' ); ?>
								</option>
							</select>
						</div>

						<div class="bn-toggle-row">
							<div class="bn-toggle-row__copy">
								<div class="bn-toggle-row__label"><?php esc_html_e( 'Allow member posts', 'buddynext' ); ?></div>
								<div class="bn-toggle-row__desc"><?php esc_html_e( 'Members can post in the space feed. Disable for announcement-only spaces.', 'buddynext' ); ?></div>
							</div>
							<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Allow member posts', 'buddynext' ); ?>">
								<input type="checkbox" class="bn-space-settings__toggle-input" name="allow_member_posts" value="1" <?php checked( $allow_member_posts ); ?>>
								<span class="bn-toggle" aria-hidden="true"></span>
							</label>
						</div>

						<div class="bn-toggle-row">
							<div class="bn-toggle-row__copy">
								<div class="bn-toggle-row__label"><?php esc_html_e( 'Require post approval', 'buddynext' ); ?></div>
								<div class="bn-toggle-row__desc"><?php esc_html_e( 'New member posts go to the moderation queue before appearing publicly.', 'buddynext' ); ?></div>
							</div>
							<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Require post approval', 'buddynext' ); ?>">
								<input type="checkbox" class="bn-space-settings__toggle-input" name="require_post_approval" value="1" <?php checked( $require_post_approval ); ?>>
								<span class="bn-toggle" aria-hidden="true"></span>
							</label>
						</div>
					</div>

				<?php elseif ( 'permissions' === $settings_tab ) : ?>

					<?php /* The permissions tab uses its own nonce, but we leave the outer general form intact for back-compat. */ ?>

				<?php elseif ( 'branding' === $settings_tab ) : ?>

					<?php /* Branding tab content rendered outside the outer general form (see below). */ ?>

				<?php elseif ( 'integrations' === $settings_tab ) : ?>

					<div class="bn-card bn-space-settings__panel">
						<header class="bn-space-settings__panel-head">
							<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Integrations', 'buddynext' ); ?></h2>
							<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Connect third-party features to this space.', 'buddynext' ); ?></p>
						</header>

						<div class="bn-toggle-row">
							<div class="bn-toggle-row__copy">
								<div class="bn-toggle-row__label">
									<span class="bn-badge" data-tone="jetonomy"><?php esc_html_e( 'Jetonomy', 'buddynext' ); ?></span>
									<?php esc_html_e( 'Linked forum', 'buddynext' ); ?>
								</div>
								<div class="bn-toggle-row__desc"><?php esc_html_e( 'Link a Jetonomy forum to show a Forum tab in this space.', 'buddynext' ); ?></div>
								<?php if ( class_exists( 'Jetonomy\\Core\\Plugin' ) ) : ?>
									<div class="bn-space-settings__inline-select">
										<label class="bn-sr-only" for="bn_jetonomy_forum_id">
											<?php esc_html_e( 'Linked Jetonomy forum', 'buddynext' ); ?>
										</label>
										<select id="bn_jetonomy_forum_id" name="jetonomy_forum_id" class="bn-select">
											<option value="0" <?php selected( $jetonomy_forum_id, 0 ); ?>><?php esc_html_e( 'No forum linked', 'buddynext' ); ?></option>
										</select>
									</div>
								<?php else : ?>
									<p class="bn-space-settings__hint">
										<?php esc_html_e( 'Jetonomy is not active on this site.', 'buddynext' ); ?>
									</p>
								<?php endif; ?>
							</div>
						</div>

						<div class="bn-toggle-row">
							<div class="bn-toggle-row__copy">
								<div class="bn-toggle-row__label"><?php esc_html_e( 'Push space posts to activity feed', 'buddynext' ); ?></div>
								<div class="bn-toggle-row__desc"><?php esc_html_e( "Space posts appear in members' home feeds. Off = space-only.", 'buddynext' ); ?></div>
							</div>
							<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Push space posts to activity feed', 'buddynext' ); ?>">
								<input type="checkbox" class="bn-space-settings__toggle-input" name="push_to_feed" value="1" <?php checked( $push_to_feed ); ?>>
								<span class="bn-toggle" aria-hidden="true"></span>
							</label>
						</div>

						<div class="bn-toggle-row">
							<div class="bn-toggle-row__copy">
								<div class="bn-toggle-row__label">
									<span class="bn-badge" data-tone="media"><?php esc_html_e( 'WPMediaVerse', 'buddynext' ); ?></span>
									<?php esc_html_e( 'Media tab', 'buddynext' ); ?>
								</div>
								<div class="bn-toggle-row__desc"><?php esc_html_e( 'Show a Media tab for uploading and sharing files in this space.', 'buddynext' ); ?></div>
							</div>
							<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Enable Media tab', 'buddynext' ); ?>">
								<input type="checkbox" class="bn-space-settings__toggle-input" name="mvs_media_tab" value="1" <?php checked( $mvs_media_tab ); ?>>
								<span class="bn-toggle" aria-hidden="true"></span>
							</label>
						</div>
					</div>

				<?php endif; ?>

				<div class="bn-space-settings__save-row">
					<a
						href="<?php echo esc_url( $space_url ); ?>"
						class="bn-btn"
						data-variant="ghost"
						data-size="md"
					><?php esc_html_e( 'Cancel', 'buddynext' ); ?></a>
					<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
						<?php esc_html_e( 'Save changes', 'buddynext' ); ?>
					</button>
				</div>
			</form>

		<?php endif; ?>

		<?php
		/**
		 * Render the active settings tab's content.
		 *
		 * Fires after Free's built-in tab content. Pro modules that registered
		 * an extra tab via `buddynext_space_settings_tabs` render their panel
		 * markup here. Listeners must guard on `$active_tab` to avoid leaking
		 * content into Free's tabs.
		 *
		 * @since 0.3.0
		 *
		 * @param string $active_tab The currently selected tab slug.
		 * @param int    $space_id   Space being configured.
		 */
		do_action( 'buddynext_space_settings_tab_content', $settings_tab, $space_id );
		?>

	</div>

	<!-- Sticky save bar — matches Profile edit + Notification prefs pattern.
	     Wired by assets/js/spaces/store.js: listens for input/change on every
	     form inside .bn-space-settings, surfaces the bar when dirty, runs the
	     beforeunload guard, and submits the currently-dirty form on click. -->
	<div
		class="bn-space-settings__savebar"
		role="region"
		aria-label="<?php esc_attr_e( 'Save changes', 'buddynext' ); ?>"
		data-bn-space-settings-savebar
		hidden
	>
		<div class="bn-space-settings__savebar-inner">
			<div class="bn-space-settings__savebar-status bn-space-settings__savebar-status--dirty" data-bn-savebar-state="dirty">
				<span class="bn-space-settings__savebar-dot" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Unsaved changes', 'buddynext' ); ?></span>
			</div>
			<div class="bn-space-settings__savebar-status bn-space-settings__savebar-status--saving" data-bn-savebar-state="saving" hidden>
				<span class="bn-space-settings__savebar-spinner" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Saving…', 'buddynext' ); ?></span>
			</div>
			<div class="bn-space-settings__savebar-status bn-space-settings__savebar-status--saved" data-bn-savebar-state="saved" hidden>
				<?php buddynext_icon( 'check' ); ?>
				<span><?php esc_html_e( 'All changes saved', 'buddynext' ); ?></span>
			</div>
			<div class="bn-space-settings__savebar-actions">
				<button
					type="button"
					class="bn-btn"
					data-variant="ghost"
					data-size="md"
					data-bn-savebar-cancel
				><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
				<button
					type="button"
					class="bn-btn"
					data-variant="primary"
					data-size="md"
					data-bn-savebar-submit
				><?php esc_html_e( 'Save changes', 'buddynext' ); ?></button>
			</div>
		</div>
	</div>
</div>
