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

// This screen admits a MODERATOR. It does not follow that a moderator may change
// everything on it — moderation settings are their job (who can post, who can
// invite, join approval, banned words, the notification default); identity, reach
// and structure are the owner's (name, description, type, rules, category, the
// integrations, and who is auto-joined at signup).
//
// Every write below goes through SpaceFieldRegistry, which decides per FIELD via
// can_write() — the same authority the REST route uses. Until this existed the two
// disagreed: SpaceService::update() enforced owner-only while the update_space_meta()
// calls on this very screen enforced nothing, so a moderator could not rename a space
// but COULD rewrite who was allowed to post in it. That was not a policy, it was an
// accident of which code path each field happened to take.
$bn_field_registry = \BuddyNext\Spaces\SpaceFieldRegistry::instance();
$bn_actor_id       = get_current_user_id();
$bn_is_space_owner = buddynext_can( $bn_actor_id, 'buddynext-manage-space', array( 'space_id' => $space_id ) );

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

$save_notice = '';

// Specific failure text, when a handler has one worth showing (an ownership
// transfer refused by the primitive, say). Empty = show the generic message.
$bn_save_error_message = '';

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
		// Move under a new parent, or detach to the top level (0). SpaceService::update()
		// does the real work — depth, cycles, the per-parent cap and manage permission on
		// the TARGET — and it is owner-gated, so a moderator is rejected there. All of
		// that already existed and was reachable only over REST; this is the control.
		if ( isset( $_POST['space_parent_id'] ) ) {
			$update_data['parent_id'] = absint( $_POST['space_parent_id'] );
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

		// The Integrations panel is OWNER-only, and deliberately so. It decides
		// whether the space has a discussion at all, whether its media tab exists,
		// and — via push_to_feed — whether the space's content reaches the public
		// feed. That last one is a privacy control: it is exactly what a private or
		// secret space relies on to keep its content in. None of that is moderation;
		// it is the shape and the reach of the space. A moderator gets no write here.
		//
		// The panel renders read-only for a moderator (see the integrations panel), so
		// this is the crafted-POST backstop, not the primary UX.
		// Did this request actually write anything? The Integrations panel posts none of
		// the general fields, so $update_data is ALWAYS empty on that sub-tab — and the
		// old blanket `else { success }` below therefore reported "Changes saved
		// successfully" for every Integrations save, including the ones that wrote
		// nothing at all. A form that reports success on failure is worse than one that
		// errors: the owner walks away believing a privacy control is set.
		$bn_wrote_something = false;

		if ( 'integrations' === $bn_subtab ) {
			if ( ! $bn_is_space_owner ) {
				// The panel renders read-only for a moderator, so reaching here means a
				// crafted POST or a stale form. Either way nothing is written — say so,
				// rather than congratulate them on a save that did not happen.
				$save_notice           = 'error';
				$bn_save_error_message = __( 'Only the space owner can change these integrations.', 'buddynext' );
			} else {
				$bn_integration_values = array(
					'push_to_feed' => isset( $_POST['push_to_feed'] ) ? '1' : '0',
				);

				// Only write the media toggle when its checkbox was actually rendered.
				// The control only renders while WPMediaVerse is active, so an
				// unconditional write silently zeroed the owner's setting whenever they
				// saved this tab with the plugin deactivated (e.g. mid-update) — and the
				// Media tab then stayed gone after reactivation, with nothing to explain
				// why. Same guard the Discussion block below already uses.
				if ( \BuddyNext\Media\MediaClient::available() ) {
					$bn_integration_values['mvs_media_tab'] = isset( $_POST['mvs_media_tab'] ) ? '1' : '0';
				}

				// Report what the registry actually did. Discarding this result is how a
				// rejected write — can_write(), or a value a sanitiser refused — still
				// rendered "Saved". The permissions panel below already reads it; this
				// one threw it away.
				$bn_int_result = $bn_field_registry->save_for_space( $space_id, $bn_integration_values, $bn_actor_id );

				if ( empty( $bn_int_result['errors'] ) ) {
					$bn_wrote_something = true;
				} else {
					$save_notice           = 'error';
					$bn_save_error_message = (string) reset( $bn_int_result['errors'] );
				}

				// Discussion (powered by Jetonomy) is opt-in per Space and never
				// mandatory. Each Space owns exactly ONE dedicated discussion for its
				// lifetime: it is created (or linked) the first time the owner turns it
				// on, and after that the toggle only shows/hides it — the discussion and
				// its content are never discarded or duplicated.
				// - first enable : create a new dedicated discussion, OR (initial
				// setup only) link one the caller is allowed to.
				// - later on/off : just flip the enabled flag; same discussion.
				if ( class_exists( 'Jetonomy\\Jetonomy' ) && 'error' !== $save_notice ) {
					$bn_disc_bridge  = new \BuddyNext\Bridges\JetonomyBridge();
					$bn_disc_on      = isset( $_POST['bn_discussion_enabled'] );
					$bn_disc_link_id = isset( $_POST['bn_discussion_link_id'] ) ? absint( wp_unslash( $_POST['bn_discussion_link_id'] ) ) : 0;

					if ( $bn_disc_on ) {
						// Establish the dedicated discussion once, if it has none yet.
						if ( ! $bn_disc_bridge->space_has_discussion( $space_id ) ) {
							// Initial-setup link is role-aware: a SITE ADMIN may adopt any
							// existing discussion; a space owner may only adopt one THEY
							// authored. Re-validated here so a crafted POST cannot widen it.
							$bn_disc_owner    = (int) ( $space->owner_id ?? 0 );
							$bn_disc_is_admin = current_user_can( 'manage_options' );
							$bn_disc_may_link = $bn_disc_link_id > 0 && (
								$bn_disc_is_admin
									? $bn_disc_bridge->discussion_exists( $bn_disc_link_id )
									: $bn_disc_bridge->discussion_owned_by( $bn_disc_link_id, $bn_disc_owner )
							);
							if ( $bn_disc_may_link ) {
								update_space_meta( $space_id, 'jetonomy_forum_id', $bn_disc_link_id );
							} elseif ( $bn_disc_bridge->provision_space_forum( $space_id ) <= 0 ) {
								// Provisioning failed. Leaving the toggle on would give
								// every member a Discussion tab with no discussion behind
								// it — so refuse to enable, and say why.
								$save_notice           = 'error';
								$bn_save_error_message = __( 'The discussion could not be created, so it was not enabled. Your other changes were saved.', 'buddynext' );
							}
						}

						if ( 'error' !== $save_notice ) {
							$bn_disc_bridge->set_discussion_enabled( $space_id, true );
						}
					} else {
						$bn_disc_bridge->set_discussion_enabled( $space_id, false );
					}
				}
			}
		}

		if ( 'error' !== $save_notice ) {
			if ( ! empty( $update_data ) ) {
				// Route through the service: validation, cache invalidation and the
				// buddynext_space_updated hook all run here (the raw $wpdb->update
				// path skipped every one of them).
				//
				// Report what ACTUALLY happened. This screen is reachable by a
				// moderator (cap `buddynext-spaces/manage-settings`), but update()
				// requires `buddynext-manage-space` (owner-only) — so a moderator was
				// rejected by the service and still shown "Saved", with their edit
				// silently discarded.
				$bn_update_result = $bn_space_service->update( $space_id, get_current_user_id(), $update_data );

				if ( is_wp_error( $bn_update_result ) ) {
					$save_notice           = 'error';
					$bn_save_error_message = $bn_update_result->get_error_message();
				} else {
					$save_notice = 'success';
				}
			} elseif ( $bn_wrote_something ) {
				$save_notice = 'success';
			}

			// Nothing posted and nothing written: stay silent rather than claim a save.
		}

		// Re-fetch fresh space data through the service (cache busted above).
		$space = $bn_load_space( $space_id );
	}
}

// ── Handle permissions settings POST ─────────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_permissions_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_permissions_nonce'] ) ), 'bn_space_permissions_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		// Moderation thresholds — a moderator's job, so they are in this set for
		// everyone who can reach this screen. The registry sanitises each value
		// against its registered type, so the hand-rolled allow-lists that used to
		// live here (and silently coerced a bad value to a default) are gone.
		$bn_perm_values = array(
			'require_join_approval' => isset( $_POST['require_join_approval'] ) ? '1' : '0',
			'who_can_post'          => isset( $_POST['who_can_post'] ) ? sanitize_key( wp_unslash( $_POST['who_can_post'] ) ) : 'members',
			'who_can_invite'        => isset( $_POST['who_can_invite'] ) ? sanitize_key( wp_unslash( $_POST['who_can_invite'] ) ) : 'mods',
		);

		// Auto-join is OWNER-only: it decides who is pulled into this space
		// automatically at signup, which is reach across the whole site's membership,
		// not moderation of the people already here. A moderator must not be able to
		// auto-enrol every new member of the site into the space they moderate.
		//
		// Added to the payload ONLY for an owner — so a moderator's save simply omits
		// these keys and leaves them untouched. The old code wrote them
		// unconditionally, so a moderator saving this panel silently ZEROED the
		// owner's auto-join settings; can_write() rejects a forged POST regardless.
		if ( $bn_is_space_owner ) {
			$bn_perm_values['auto_join_on_signup'] = isset( $_POST['auto_join_on_signup'] ) ? '1' : '0';

			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by the field engine (member_type_multiselect), which also drops any slug that is not a live member type.
			$bn_perm_values['auto_join_member_types'] = isset( $_POST['auto_join_member_types'] )
				? (array) wp_unslash( $_POST['auto_join_member_types'] )
				: array();
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$bn_perm_result = $bn_field_registry->save_for_space( $space_id, $bn_perm_values, $bn_actor_id );

		if ( empty( $bn_perm_result['errors'] ) ) {
			$save_notice = 'success';
		} else {
			$save_notice           = 'error';
			$bn_save_error_message = (string) reset( $bn_perm_result['errors'] );
		}
	}
}

// Ownership transfer is a REST action, not a form POST. The modal in
// templates/parts/space-settings-panel-danger.php has no <form> and no nonce field —
// its button calls actions.transferOwnership, which PUTs to the REST route (see
// assets/js/spaces/store.js). The form handler that used to live here read a
// `bn_space_transfer_nonce` that nothing on any surface emitted, so it could never
// run; its error branches were the only "transfer failed" UX in this file and never
// fired once. Removed rather than left as a second, unreachable door onto
// SpaceService::assign_owner().

// ── Handle moderation settings POST ─────────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_moderation_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_moderation_nonce'] ) ), 'bn_space_moderation_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		// No post pre-approval: members post freely, moderation is reactive.
		// Banned words are moderator-writable — this is the moderator's core job.
		$bn_mod_result = $bn_field_registry->save_for_space(
			$space_id,
			array( 'banned_words' => isset( $_POST['banned_words'] ) ? sanitize_textarea_field( wp_unslash( $_POST['banned_words'] ) ) : '' ),
			$bn_actor_id
		);

		if ( empty( $bn_mod_result['errors'] ) ) {
			$save_notice = 'success';
		} else {
			$save_notice           = 'error';
			$bn_save_error_message = (string) reset( $bn_mod_result['errors'] );
		}
	}
}

// ── Handle notifications settings POST ───────────────────────────────────────

if ( 'POST' === $request_method && isset( $_POST['bn_space_notifications_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bn_space_notifications_nonce'] ) ), 'bn_space_notifications_' . $space_id ) ) {
		$save_notice = 'error';
	} else {
		// The default notification pref is moderator-writable: it shapes how noisy the
		// space is for its members, which is the moderator's remit, and it takes
		// nothing away from anyone (each member can still override it for themselves).
		// The registry validates it against the field's registered options.
		$bn_notif_result = $bn_field_registry->save_for_space(
			$space_id,
			array(
				'default_notification_pref' => isset( $_POST['default_notification_pref'] )
					? sanitize_key( wp_unslash( $_POST['default_notification_pref'] ) )
					: 'all',
			),
			$bn_actor_id
		);

		if ( empty( $bn_notif_result['errors'] ) ) {
			$save_notice = 'success';
		} else {
			$save_notice           = 'error';
			$bn_save_error_message = (string) reset( $bn_notif_result['errors'] );
		}
	}
}

// Member-management actions (promote / demote / remove / ban / invite) now run
// through the buddynext/space-members Interactivity store against buddynext/v1
// (see templates/parts/space-settings-panel-members.php) — the same store the
// Members tab uses. The legacy server-side POST handler was removed; the panel
// is a REST client like the rest of the app layer.

$require_join_approval = (bool) buddynext_get_space_field( $space_id, 'require_join_approval' );
$push_to_feed          = (bool) buddynext_get_space_field( $space_id, 'push_to_feed' );
$mvs_media_tab         = (bool) buddynext_get_space_field( $space_id, 'mvs_media_tab' );
$jetonomy_forum_id     = (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );

// Discussion (Jetonomy) status for the opt-in per-Space control. The link picker
// itself is a REST typeahead (buddynext/v1 discussion-search), role-scoped
// server-side, so nothing is prefetched here even on 1000+ discussion sites.
$bn_discussion_status = array(
	'linked'   => false,
	'forum_id' => 0,
	'name'     => '',
	'url'      => '',
);
if ( class_exists( 'Jetonomy\\Jetonomy' ) ) {
	$bn_discussion_status = ( new \BuddyNext\Bridges\JetonomyBridge() )->space_discussion_status( $space_id );
}
$who_can_post   = (string) buddynext_get_space_field( $space_id, 'who_can_post' );
$who_can_invite = (string) buddynext_get_space_field( $space_id, 'who_can_invite' );

// Auto-join: a boolean master toggle + an optional member-type filter (comma-joined
// slugs read raw and split to an array of slugs the panel renders as checkboxes).
$auto_join_on_signup    = (bool) buddynext_get_space_field( $space_id, 'auto_join_on_signup' );
$auto_join_member_types = array_values(
	array_filter(
		array_map( 'trim', explode( ',', (string) get_space_meta( $space_id, 'auto_join_member_types', true ) ) )
	)
);

// Moderation options.
$mod_banned_words = (string) buddynext_get_space_field( $space_id, 'banned_words' );

// Notifications option.
$default_notification_pref = (string) buddynext_get_space_field( $space_id, 'default_notification_pref' );

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

// Banned members — the counterpart to the Ban action. get_space_bans() returns
// raw bn_space_bans rows (user_id only), so enrich each with the display name +
// handle so the panel can render an Unban control (DELETE /spaces/{id}/bans/{id}).
$bn_ban_rows = $bn_member_service->get_space_bans( $space_id );
$space_bans  = array();
foreach ( (array) $bn_ban_rows as $bn_ban ) {
	$bn_ban_uid = (int) ( is_array( $bn_ban ) ? ( $bn_ban['user_id'] ?? 0 ) : 0 );
	if ( $bn_ban_uid <= 0 ) {
		continue;
	}
	$bn_ban_user = get_userdata( $bn_ban_uid );
	if ( ! $bn_ban_user ) {
		continue;
	}
	$space_bans[] = (object) array(
		'user_id'      => $bn_ban_uid,
		'display_name' => (string) $bn_ban_user->display_name,
		'user_login'   => (string) $bn_ban_user->user_nicename,
		'reason'       => (string) ( is_array( $bn_ban ) ? ( $bn_ban['reason'] ?? '' ) : '' ),
	);
}

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
		'slug'  => 'privacy',
		'label' => __( 'Privacy', 'buddynext' ),
		'icon'  => 'eye',
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

// Custom-fields tab — shown only when a plugin has registered non-core per-space
// fields, so the typical owner (none registered) never sees an empty tab. Slotted
// in before the Danger zone, which stays last.
if ( ! empty( \BuddyNext\Spaces\SpaceFieldRegistry::instance()->get_custom_fields() ) ) {
	array_splice(
		$builtin_tabs,
		count( $builtin_tabs ) - 1,
		0,
		array(
			array(
				'slug'  => 'fields',
				'label' => __( 'Custom fields', 'buddynext' ),
				'icon'  => 'list',
			),
		)
	);
}

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
	// The savebar and the danger modals are driven by GLOBAL store state
	// (savebarPhase, modalTransferOpen, modalArchiveOpen — see assets/js/spaces/store.js),
	// not by context. This context used to also ship `savebarState`, `modalTransfer`,
	// `modalDelete`, `modalArchive` and `restUrl`; the store reads none of them, and
	// `modalDelete` never had a counterpart at all. They were emitted on every render and
	// consumed by nothing, while the comments here described bindings that did not exist —
	// which is worse than no comment, because the next person trusts them.
	echo esc_attr(
		wp_json_encode(
			array(
				'restNonce' => wp_create_nonce( 'wp_rest' ),
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
					<?php echo bn_space_category_icon( $space->category_slug ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService::space_category_icon() returns wp_kses()-sanitized SVG. Re-running wp_kses() with the narrower allowed_tags() here would strip a stored icon's <g> wrapper. ?>
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

		<?php if ( 'success' === $save_notice ) : ?>
			<div class="bn-card bn-space-settings__notice" data-tone="success" role="status">
				<span class="bn-space-settings__notice-icon" aria-hidden="true"><?php buddynext_icon( 'check-circle' ); ?></span>
				<?php esc_html_e( 'Changes saved successfully.', 'buddynext' ); ?>
			</div>
		<?php elseif ( 'error' === $save_notice ) : ?>
			<div class="bn-card bn-space-settings__notice" data-tone="danger" role="alert">
				<span class="bn-space-settings__notice-icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
				<?php
				if ( '' !== $bn_save_error_message ) {
					echo esc_html( $bn_save_error_message );
				} else {
					esc_html_e( 'Could not save your changes. Please check the form and try again.', 'buddynext' );
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
					'settings_general' => array(
						'categories'       => $categories,
						// The parent picker. eligible_parents() mirrors the service's own
						// validation, so the control can never offer a move that the save
						// would reject. Empty is meaningful — the panel explains why
						// (children of its own, or no other root the actor manages).
						'eligible_parents' => $bn_space_service->eligible_parents( $space_id, $bn_actor_id ),
						'has_subspaces'    => $bn_space_service->count_subspaces( $space_id ) > 0,
						'sub_spaces_on'    => '0' !== (string) get_option( 'buddynext_space_allow_sub', '1' ),
						// The CURRENT parent, passed separately and always — it is not
						// necessarily an "eligible parent". A space can sit under a parent
						// the actor does not manage (someone else's root), and that parent
						// is correctly absent from the move candidates. Without this the
						// select would not contain the current value, would fall back to
						// the first option ("Top level"), and an owner who saved the General
						// tab for an unrelated reason would SILENTLY DETACH their space.
						'current_parent'   => $bn_space_service->parent_summary( (int) ( $space->parent_id ?? 0 ), $bn_actor_id ),
					),
				),
			),
			'privacy'       => array(
				'parts/space-settings-panel-privacy.php',
				array(
					'space'            => $space,
					'privacy_settings' => array(),
				),
			),
			'integrations'  => array(
				'parts/space-settings-panel-integrations.php',
				array(
					'space'                 => $space,
					'integrations_settings' => array(
						'jetonomy_forum_id' => $jetonomy_forum_id,
						'push_to_feed'      => $push_to_feed,
						'discussion_status' => $bn_discussion_status,
					),
					'mvs_media_tab'         => $mvs_media_tab,
					// Owner-only panel. Passed so it can render read-only for a
					// moderator rather than show controls that would not save — a
					// control that silently does nothing is worse than no control.
					'is_space_owner'        => $bn_is_space_owner,
				),
			),
			'permissions'   => array(
				'parts/space-settings-panel-permissions.php',
				array(
					'space'                => $space,
					'permissions_settings' => array(
						'space_id'               => $space_id,
						'space_url'              => $space_url,
						'who_can_post'           => $who_can_post,
						'who_can_invite'         => $who_can_invite,
						'require_join_approval'  => $require_join_approval,
						'auto_join_on_signup'    => $auto_join_on_signup,
						'auto_join_member_types' => $auto_join_member_types,
					),
					// The moderation thresholds on this panel are moderator-writable;
					// the auto-join controls are owner-only and are hidden for a
					// moderator instead of being shown and then rejected.
					'is_space_owner'       => $bn_is_space_owner,
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
						'bans'     => $space_bans,
					),
				),
			),
			'fields'        => array(
				'parts/space-settings-panel-fields.php',
				array(
					'space'           => $space,
					'fields_settings' => array( 'space_id' => $space_id ),
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
		REACTIVE: visibility is driven by the store's global `savebarPhase`
		(idle | dirty | saving | saved) via data-wp-bind--hidden + state getters; no
		imperative .hidden/dataset paint loop. assets/js/spaces/store.js owns the
		dirty-tracking (delegated input/change) + rollback + form submit and mutates
		`savebarPhase`; the markup never sets .hidden itself. Note `saved` is set only
		after the server rendered a success notice — it is never asserted optimistically. -->
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
