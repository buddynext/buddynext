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

// ── Resolve space ─────────────────────────────────────────────────────────────

$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( ! $space_id ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Permission gate ───────────────────────────────────────────────────────────

if ( ! buddynext_can( get_current_user_id(), 'buddynext-spaces/manage-settings', array( 'space_id' => $space_id ) ) ) {
	// A demoted moderator may still hold this URL — render a friendly in-shell
	// notice with a way back instead of a bare wp_die() white screen.
	printf(
		'<div class="bn-empty-state bn-space-no-access"><div class="bn-empty-title">%1$s</div><p class="bn-empty-text">%2$s</p><a class="bn-btn" data-variant="primary" href="%3$s">%4$s</a></div>',
		esc_html__( 'You no longer manage this space', 'buddynext' ),
		esc_html__( 'Your access to manage this space has changed. You can still view and take part in it.', 'buddynext' ),
		esc_url( \BuddyNext\Core\PageRouter::space_url( $space_id ) ),
		esc_html__( 'Back to space', 'buddynext' )
	);
	return;
}

// Services own every read/write below; the template holds no SQL. The
// settings parts expect `$space` as an object carrying the joined
// `category_name`/`category_slug`, so each fresh load runs through this
// closure: SpaceService::get() (canonical hydrate + cache) enriched with the
// category row via SpaceCategoryService, then cast to an object.
$bn_space_service    = new \BuddyNext\Spaces\SpaceService();
$bn_category_service = new \BuddyNext\Spaces\SpaceCategoryService();

$bn_load_space = static function ( int $sid ) use ( $bn_space_service, $bn_category_service ): ?object {
	$row = $bn_space_service->get( $sid );
	if ( null === $row ) {
		return null;
	}
	$row['category_name'] = null;
	$row['category_slug'] = null;
	if ( ! empty( $row['category_id'] ) ) {
		$cat = $bn_category_service->get_by_id( (int) $row['category_id'] );
		if ( null !== $cat ) {
			$row['category_name'] = $cat['name'] ?? null;
			$row['category_slug'] = $cat['slug'] ?? null;
		}
	}
	return (object) $row;
};

$space = $bn_load_space( $space_id );

if ( ! $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// Category options for the General panel's <select> — the part reads each row
// as an object (`->id`, `->name`), so map the service arrays accordingly.
$categories = array_map(
	static fn( array $c ): object => (object) array(
		'id'   => (int) ( $c['id'] ?? 0 ),
		'name' => (string) ( $c['name'] ?? '' ),
		'slug' => (string) ( $c['slug'] ?? '' ),
	),
	$bn_category_service->get_all()
);

// ── Active settings tab ───────────────────────────────────────────────────────
// Read the raw value from the URL — validation against the (possibly
// Pro-extended) tab registry happens after `$builtin_tabs` is filtered, below.

$settings_tab = isset( $_GET['bn_stab'] ) ? sanitize_key( wp_unslash( $_GET['bn_stab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Handle saved settings (POST) ─────────────────────────────────────────────

$save_notice        = '';
$save_error_message = '';

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
		// general / privacy / integrations share this one nonce + form, but only
		// the active sub-tab's panel (and thus its fields) is in the POST. Writing
		// every checkbox unconditionally forced the OTHER tabs' options to 0 on
		// each save (a checkbox absent from the request reads as unchecked). Gate
		// each option to the sub-tab that actually owns and renders it.
		$bn_subtab = isset( $_POST['bn_settings_subtab'] ) ? sanitize_key( wp_unslash( $_POST['bn_settings_subtab'] ) ) : 'general';

		if ( 'privacy' === $bn_subtab ) {
		}

		if ( 'integrations' === $bn_subtab ) {
			update_option( 'bn_space_' . $space_id . '_push_to_feed', isset( $_POST['push_to_feed'] ) ? 1 : 0 );
			update_option( 'bn_space_' . $space_id . '_mvs_media_tab', isset( $_POST['mvs_media_tab'] ) ? 1 : 0 );
			if ( isset( $_POST['jetonomy_forum_id'] ) ) {
				update_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', absint( $_POST['jetonomy_forum_id'] ) );
			}
		}

		if ( ! empty( $update_data ) ) {
			// Route through the service: validation, cache invalidation and the
			// buddynext_space_updated hook all run here (the raw $wpdb->update
			// path skipped every one of them).
			$bn_space_service->update( $space_id, get_current_user_id(), $update_data );
		}

		// Re-fetch fresh space data through the service (cache busted above).
		$space       = $bn_load_space( $space_id );
		$save_notice = 'success';
	}
}

// ── Handle permissions settings POST ─────────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_permissions_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_permissions_nonce'] ) ), 'bn_space_permissions_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
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
				// Single service call: it demotes the current owner, promotes the
				// new owner (both via change_role), updates bn_spaces.owner_id,
				// busts the cache and fires buddynext_space_ownership_transferred.
				$bn_space_service->transfer_ownership( $space_id, $bn_new_owner, get_current_user_id() );
				$save_notice = 'success';
				$space       = $bn_load_space( $space_id );
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
		// No post pre-approval: members post freely, moderation is reactive.
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
				// change_role validates the role + acting permission, busts the
				// member cache and fires the role-change hook (the raw UPDATE did none).
				$promote_result = $member_service->change_role( $space_id, $target_user, 'moderator', $acting_user_id );
				$save_notice    = is_wp_error( $promote_result ) ? 'error' : 'success';
				if ( is_wp_error( $promote_result ) ) {
					$save_error_message = $promote_result->get_error_message();
				}
			} elseif ( $target_user && 'demote' === $member_action ) {
				$demote_result = $member_service->change_role( $space_id, $target_user, 'member', $acting_user_id );
				$save_notice   = is_wp_error( $demote_result ) ? 'error' : 'success';
				if ( is_wp_error( $demote_result ) ) {
					$save_error_message = $demote_result->get_error_message();
				}
			} elseif ( $target_user && 'remove' === $member_action ) {
				$remove_result = $member_service->remove( $space_id, $target_user, $acting_user_id );
				$save_notice   = ( ! is_wp_error( $remove_result ) ) ? 'success' : 'error';
			} elseif ( $target_user && 'ban' === $member_action ) {
				$ban_result  = $member_service->ban( $space_id, $acting_user_id, $target_user );
				$save_notice = ( ! is_wp_error( $ban_result ) ) ? 'success' : 'error';
				if ( is_wp_error( $ban_result ) ) {
					$save_error_message = $ban_result->get_error_message();
				}
			} elseif ( 'invite' === $member_action ) {
				$invite_identifier = isset( $_POST['invite_identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['invite_identifier'] ) ) : '';
				// Accept an @-prefixed username, matching the @username mention format.
				$invite_identifier = ltrim( $invite_identifier, '@' );
				if ( $invite_identifier ) {
					$invite_user = is_email( $invite_identifier )
						? get_user_by( 'email', $invite_identifier )
						: get_user_by( 'login', $invite_identifier );
					if ( $invite_user ) {
						$invite_result = $member_service->invite( $space_id, $acting_user_id, $invite_user->ID );
						$save_notice   = ( ! is_wp_error( $invite_result ) ) ? 'invite_sent' : 'error';
						if ( is_wp_error( $invite_result ) ) {
							$save_error_message = $invite_result->get_error_message();
						}
					} else {
						$save_notice = 'error';
					}
				}
			}
		}
	}
}

$require_join_approval = (bool) get_option( 'bn_space_' . $space_id . '_require_join_approval', 0 );
$push_to_feed          = (bool) get_option( 'bn_space_' . $space_id . '_push_to_feed', 1 );
$mvs_media_tab         = (bool) get_option( 'bn_space_' . $space_id . '_mvs_media_tab', 0 );
$jetonomy_forum_id     = (int) get_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', 0 );
$who_can_post          = (string) get_option( 'bn_space_' . $space_id . '_who_can_post', 'members' );
$who_can_invite        = (string) get_option( 'bn_space_' . $space_id . '_who_can_invite', 'mods' );

// Moderation options.
$mod_banned_words = (string) get_option( 'bn_space_' . $space_id . '_banned_words', '' );

// Notifications option.
$default_notification_pref = (string) get_option( 'bn_space_' . $space_id . '_default_notification_pref', 'all' );

// Members list — always fetched so the members tab renders without a
// conditional query. SpaceMemberService::get_members() returns the active
// roster (limit 200) as arrays; the members part reads each row as an object
// (`->user_id`, `->role`, `->display_name`, `->user_login`), so map to objects,
// using user_nicename as the @handle. Re-group owner → moderator → member in
// PHP to keep the previous visual order (the service orders by join date).
$bn_member_service = new \BuddyNext\Spaces\SpaceMemberService();
$bn_member_rows    = $bn_member_service->get_members( $space_id, get_current_user_id(), 200 );
$bn_role_rank      = array(
	'owner'     => 0,
	'moderator' => 1,
	'member'    => 2,
);
usort(
	$bn_member_rows,
	static function ( array $a, array $b ) use ( $bn_role_rank ): int {
		$ra = $bn_role_rank[ $a['role'] ?? 'member' ] ?? 3;
		$rb = $bn_role_rank[ $b['role'] ?? 'member' ] ?? 3;
		if ( $ra !== $rb ) {
			return $ra <=> $rb;
		}
		return strcasecmp( (string) ( $a['display_name'] ?? '' ), (string) ( $b['display_name'] ?? '' ) );
	}
);
$space_members = array_map(
	static fn( array $m ): object => (object) array(
		'user_id'      => (int) ( $m['user_id'] ?? 0 ),
		'role'         => (string) ( $m['role'] ?? 'member' ),
		'display_name' => (string) ( $m['display_name'] ?? '' ),
		'user_login'   => (string) ( $m['user_nicename'] ?? '' ),
	),
	$bn_member_rows
);

$space_url     = buddynext_space_url( $space->slug ?? '' );
$settings_base = buddynext_space_settings_url( $space->slug ?? '' );

// Privacy badge tone for the hero. Labels resolve via SpaceService::type_label()
// so the wording stays in lockstep with the directory + space home + Pro tabs.
$privacy_tone  = \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( (string) ( $space->type ?? 'open' ) );
$privacy_label = \BuddyNext\Spaces\SpaceService::type_label( (string) ( $space->type ?? 'open' ) );

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
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
				'restUrl'       => rest_url( 'buddynext/v1' ),
				// Reactive savebar + danger-modal state (single source). The
				// savebar binds its hidden states off `savebarState`; each
				// danger modal binds its `hidden` off its own boolean flag.
				'savebarState'  => 'idle',
				'modalTransfer' => false,
				'modalDelete'   => false,
				'modalArchive'  => false,
			)
		)
	);
	?>
	'
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
				<?php if ( ! empty( $space->avatar_url ) ) : ?>
					<img src="<?php echo esc_url( $space->avatar_url ); ?>" alt="">
				<?php else : ?>
					<?php echo wp_kses( bn_space_category_icon( $space->category_slug ?? '' ), \BuddyNext\Core\IconService::allowed_tags() ); ?>
				<?php endif; ?>
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

		<?php if ( 'invite_sent' === $save_notice ) : ?>
			<div class="bn-card bn-space-settings__notice" data-tone="success" role="status">
				<span class="bn-space-settings__notice-icon" aria-hidden="true"><?php buddynext_icon( 'check-circle' ); ?></span>
				<?php esc_html_e( 'Invitation sent successfully.', 'buddynext' ); ?>
			</div>
		<?php elseif ( 'success' === $save_notice ) : ?>
			<div class="bn-card bn-space-settings__notice" data-tone="success" role="status">
				<span class="bn-space-settings__notice-icon" aria-hidden="true"><?php buddynext_icon( 'check-circle' ); ?></span>
				<?php esc_html_e( 'Changes saved successfully.', 'buddynext' ); ?>
			</div>
		<?php elseif ( 'error' === $save_notice ) : ?>
			<div class="bn-card bn-space-settings__notice" data-tone="danger" role="alert">
				<span class="bn-space-settings__notice-icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
				<?php
				if ( '' !== $save_error_message ) {
					echo esc_html( $save_error_message );
				} else {
					esc_html_e( 'Security check failed. Please try again.', 'buddynext' );
				}
				?>
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
						'require_join_approval' => $require_join_approval,
					),
				),
			),
			'moderation'    => array(
				'parts/space-settings-panel-moderation.php',
				array(
					'space'               => $space,
					'moderation_settings' => array(
						'space_id'     => $space_id,
						'space_url'    => $space_url,
						'banned_words' => $mod_banned_words,
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
			// transfer_candidates() returns every active member except the owner,
			// joined to wp_users. Map to objects for the danger part's <select>.
			$bn_xfer_candidates  = array_map(
				static fn( array $c ): object => (object) array(
					'user_id'      => (int) ( $c['user_id'] ?? 0 ),
					'display_name' => (string) ( $c['display_name'] ?? '' ),
				),
				$bn_member_service->transfer_candidates( $space_id, (int) $space->owner_id )
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
			<form method="post" action="" enctype="multipart/form-data" class="bn-space-settings__form" data-bn-settings-general-form data-space-id="<?php echo esc_attr( (string) $space_id ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-wp-on--input="actions.savebarMarkDirty" data-wp-on--change="actions.savebarMarkDirty">
				<?php wp_nonce_field( 'bn_space_settings_' . $space_id, 'bn_space_settings_nonce' ); ?>
				<input type="hidden" name="bn_settings_subtab" value="<?php echo esc_attr( $settings_tab ); ?>" />
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
				<?php // Save is handled by the shared sticky save bar below. ?>
			</form>
			<?php
		endif;
		?>

	</div>

	<!-- Sticky save bar — matches Profile edit + Notification prefs pattern.
		REACTIVE: visibility is driven by `context.savebarState` (idle | dirty |
		saving | saved) via data-wp-bind--hidden + state getters; no imperative
		.hidden/dataset paint loop. assets/js/spaces/store.js owns the
		dirty-tracking (delegated input/change) + rollback + form submit and
		mutates `savebarState`; the markup never sets .hidden itself. -->
	<div
		class="bn-space-settings__savebar"
		role="region"
		aria-label="<?php esc_attr_e( 'Save changes', 'buddynext' ); ?>"
		data-bn-space-settings-savebar
		data-wp-bind--hidden="state.savebarHidden"
		hidden
	>
		<div class="bn-space-settings__savebar-inner">
			<div
				class="bn-space-settings__savebar-status bn-space-settings__savebar-status--dirty"
				data-bn-savebar-state="dirty"
				data-wp-bind--hidden="state.savebarDirtyHidden"
			>
				<span class="bn-space-settings__savebar-dot" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Unsaved changes', 'buddynext' ); ?></span>
			</div>
			<div
				class="bn-space-settings__savebar-status bn-space-settings__savebar-status--saving"
				data-bn-savebar-state="saving"
				data-wp-bind--hidden="state.savebarSavingHidden"
				hidden
			>
				<span class="bn-space-settings__savebar-spinner" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Saving…', 'buddynext' ); ?></span>
			</div>
			<div
				class="bn-space-settings__savebar-status bn-space-settings__savebar-status--saved"
				data-bn-savebar-state="saved"
				data-wp-bind--hidden="state.savebarSavedHidden"
				hidden
			>
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
					data-wp-on--click="actions.savebarCancel"
				><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
				<button
					type="button"
					class="bn-btn"
					data-variant="primary"
					data-size="md"
					data-bn-savebar-submit
					data-wp-on--click="actions.savebarSubmit"
				><?php esc_html_e( 'Save changes', 'buddynext' ); ?></button>
			</div>
		</div>
	</div>
</div>
