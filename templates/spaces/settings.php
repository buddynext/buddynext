<?php
/**
 * Template: Space Settings
 *
 * Renders the settings panel for a single space. Only accessible to
 * space admins/moderators or site admins. Composes from v2 primitives
 * (.bn-card, .bn-tabs, .bn-input, .bn-textarea, .bn-select, .bn-toggle,
 * .bn-btn, .bn-modal-backdrop, .bn-badge) — no bespoke design language.
 *
 * Tab markup + per-tab panels live in `templates/parts/space-settings-*`.
 * This file is a thin composer: permission gate → POST handlers → render.
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
// Read the raw value from the URL — validation against the (possibly
// Pro-extended) tab registry happens after `$builtin_tabs` is filtered, below.

$settings_tab = isset( $_GET['bn_stab'] ) ? sanitize_key( wp_unslash( $_GET['bn_stab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Handle saved settings (POST) ─────────────────────────────────────────────

$save_notice = '';

// sanitize_key() lowercases, so uppercase the result before comparing against
// 'POST' — otherwise every POST handler below is skipped and saves are dropped.
$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
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
			$raw_cover                      = sanitize_text_field( wp_unslash( $_POST['space_cover_image_url'] ) );
			$update_data['cover_image_url'] = ( '' === trim( $raw_cover ) ) ? '' : esc_url_raw( $raw_cover );
		}
		if ( isset( $_POST['space_category_id'] ) ) {
			$update_data['category_id'] = absint( $_POST['space_category_id'] );
		}
		if ( isset( $_POST['space_type'] ) && \BuddyNext\Spaces\SpaceTypeRegistry::instance()->is_valid( sanitize_key( (string) wp_unslash( $_POST['space_type'] ) ) ) ) {
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
		$allowed_prefs = array( 'all', 'mentions_only', 'none' );
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
$privacy_tone     = \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( (string) ( $space->type ?? 'open' ) );
$privacy_label    = \BuddyNext\Spaces\SpaceService::type_label( (string) ( $space->type ?? 'open' ) );

// ── Tab registry ─────────────────────────────────────────────────────────────
// Built-in tabs. Row shape `{ slug, label, icon, cap, panel }` is the seam
// Pro extends via the `buddynext_part_space_settings_tabs_args` filter.
$builtin_tabs = array(
	array(
		'slug'  => 'general',
		'label' => __( 'General', 'buddynext' ),
		'icon'  => 'info',
	),
	array(
		'slug'  => 'permissions',
		'label' => __( 'Permissions', 'buddynext' ),
		'icon'  => 'lock',
	),
	array(
		'slug'  => 'members',
		'label' => __( 'Members', 'buddynext' ),
		'icon'  => 'users',
	),
	array(
		'slug'  => 'branding',
		'label' => __( 'Branding', 'buddynext' ),
		'icon'  => 'image',
	),
	array(
		'slug'  => 'moderation',
		'label' => __( 'Moderation', 'buddynext' ),
		'icon'  => 'shield',
	),
	array(
		'slug'  => 'integrations',
		'label' => __( 'Integrations', 'buddynext' ),
		'icon'  => 'link',
	),
	array(
		'slug'  => 'notifications',
		'label' => __( 'Notifications', 'buddynext' ),
		'icon'  => 'mail',
	),
	array(
		'slug'  => 'danger',
		'label' => __( 'Danger zone', 'buddynext' ),
		'icon'  => 'alert-triangle',
	),
);

// Apply the canonical tab-registry filter once at composer level so Pro and
// bridge-registered tabs (e.g. P6.2 Brand tab) are recognized as valid
// `bn_stab` values before the active-tab validator runs. The part fires the
// same filter again on render — registrants must be idempotent (guard on
// "slug already present" before appending).
$bn_registry = (array) apply_filters(
	'buddynext_part_space_settings_tabs_args',
	array(
		'space_id'   => $space_id,
		'active_tab' => $settings_tab,
		'tabs'       => $builtin_tabs,
	)
);
if ( isset( $bn_registry['tabs'] ) && is_array( $bn_registry['tabs'] ) ) {
	$builtin_tabs = $bn_registry['tabs'];
}

// Re-validate the active tab against the (possibly extended) tab list.
$allowed_tabs = array_column( $builtin_tabs, 'slug' );
if ( ! in_array( $settings_tab, $allowed_tabs, true ) ) {
	$settings_tab = 'general';
}

// Resolve the active tab row (used by the dispatch below for Pro slugs).
$active_tab_row = null;
foreach ( $builtin_tabs as $bn_t ) {
	if ( $bn_t['slug'] === $settings_tab ) {
		$active_tab_row = $bn_t;
		break;
	}
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

		<?php
		buddynext_get_template(
			'parts/space-settings-tabs.php',
			array(
				'space_id'   => $space_id,
				'active_tab' => $settings_tab,
				'tabs'       => $builtin_tabs,
				'base_url'   => $settings_base,
			)
		);
		?>
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

		<?php
		// ── Panel dispatch ──────────────────────────────────────────────────
		// Built-in slug → [ part-name, args-array ]. general/privacy/integrations
		// share the outer `bn_space_settings_nonce` form (added by the wrapper
		// branch below). Other built-ins own their own form or have none.
		$bn_panels = array(
			'general'       => array(
				'parts/space-settings-panel-general.php',
				array(
					'space'            => $space,
					'settings_general' => array( 'categories' => $categories ),
				),
			),
			'privacy'       => array(
				'parts/space-settings-panel-privacy.php',
				array(
					'space'            => $space,
					'privacy_settings' => array(
						'allow_member_posts'    => $allow_member_posts,
						'require_post_approval' => $require_post_approval,
					),
				),
			),
			'integrations'  => array(
				'parts/space-settings-panel-integrations.php',
				array(
					'space'                 => $space,
					'integrations_settings' => array(
						'jetonomy_forum_id' => $jetonomy_forum_id,
						'push_to_feed'      => $push_to_feed,
					),
					'mvs_media_tab'         => $mvs_media_tab,
				),
			),
			'permissions'   => array(
				'parts/space-settings-panel-permissions.php',
				array(
					'space'                => $space,
					'permissions_settings' => array(
						'space_id'              => $space_id,
						'space_url'             => $space_url,
						'who_can_post'          => $who_can_post,
						'who_can_invite'        => $who_can_invite,
						'allow_member_posts'    => $allow_member_posts,
						'require_join_approval' => $require_join_approval,
						'require_post_approval' => $require_post_approval,
					),
				),
			),
			'moderation'    => array(
				'parts/space-settings-panel-moderation.php',
				array(
					'space'               => $space,
					'moderation_settings' => array(
						'space_id'              => $space_id,
						'space_url'             => $space_url,
						'require_post_approval' => $mod_require_approval,
						'banned_words'          => $mod_banned_words,
					),
				),
			),
			'notifications' => array(
				'parts/space-settings-panel-notifications.php',
				array(
					'space'                 => $space,
					'notification_settings' => array(
						'space_id'                  => $space_id,
						'space_url'                 => $space_url,
						'default_notification_pref' => $default_notification_pref,
					),
				),
			),
			'members'       => array(
				'parts/space-settings-panel-members.php',
				array(
					'space'        => $space,
					'members_data' => array(
						'space_id' => $space_id,
						'members'  => $space_members,
					),
				),
			),
			'branding'      => array(
				'parts/space-settings-panel-branding.php',
				array(
					'space'             => $space,
					'branding_settings' => array( 'space_id' => $space_id ),
				),
			),
		);

		// Danger: build eligible-new-owner list lazily, then register the row.
		if ( 'danger' === $settings_tab ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$bn_xfer_candidates  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT sm.user_id, u.display_name FROM {$wpdb->prefix}bn_space_members sm INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id WHERE sm.space_id = %d AND sm.status = 'active' AND sm.user_id != %d ORDER BY u.display_name ASC LIMIT 200",
					$space_id,
					(int) $space->owner_id
				)
			);
			$bn_panels['danger'] = array(
				'parts/space-settings-panel-danger.php',
				array(
					'space'       => $space,
					'permissions' => array(
						'space_id'        => $space_id,
						'owner_id'        => (int) $space->owner_id,
						'space_name'      => (string) ( $space->name ?? '' ),
						'xfer_candidates' => $bn_xfer_candidates,
					),
				),
			);
		}

		// general / privacy / integrations share the outer general-settings form.
		$bn_wrap_form = in_array( $settings_tab, array( 'general', 'privacy', 'integrations' ), true );
		if ( $bn_wrap_form ) :
			?>
			<form method="post" action="" enctype="multipart/form-data" class="bn-space-settings__form" data-bn-settings-general-form data-space-id="<?php echo esc_attr( (string) $space_id ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
				<?php wp_nonce_field( 'bn_space_settings_' . $space_id, 'bn_space_settings_nonce' ); ?>
				<?php
		endif;

		if ( isset( $bn_panels[ $settings_tab ] ) ) {
			buddynext_get_template( $bn_panels[ $settings_tab ][0], $bn_panels[ $settings_tab ][1] );
		} else {
			// Pro-registered slug: prefer the row's `panel` callable/part, else
			// fall back to the General panel.
			$bn_panel      = is_array( $active_tab_row ) && isset( $active_tab_row['panel'] ) ? $active_tab_row['panel'] : '';
			$bn_panel_args = array(
				'space'    => $space,
				'space_id' => $space_id,
			);
			if ( is_callable( $bn_panel ) ) {
				call_user_func( $bn_panel, $settings_tab, $bn_panel_args );
			} elseif ( is_string( $bn_panel ) && '' !== $bn_panel ) {
				buddynext_get_template( $bn_panel, $bn_panel_args );
			} else {
				buddynext_get_template( $bn_panels['general'][0], $bn_panels['general'][1] );
			}
		}

		if ( $bn_wrap_form ) :
			?>
				<div class="bn-space-settings__save-row">
					<a href="<?php echo esc_url( $space_url ); ?>" class="bn-btn" data-variant="ghost" data-size="md"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></a>
					<button type="submit" class="bn-btn" data-variant="primary" data-size="md"><?php esc_html_e( 'Save changes', 'buddynext' ); ?></button>
				</div>
			</form>
			<?php
		endif;
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
