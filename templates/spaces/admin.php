<?php
/**
 * BuddyNext space admin template.
 *
 * The per-space management hub for a single space, reached at
 * /spaces/{slug}/admin/. Gated to the space owner, its moderators, and site
 * admins (the same capability that guards space settings). It is a landing
 * surface that surfaces at-a-glance counts and links out to the dedicated
 * management sub-pages — Members, Settings, and Moderation — rather than
 * duplicating their forms.
 *
 * Composes from v2 primitives (.bn-sh-header hero, .bn-card, .bn-badge,
 * .bn-btn) — no bespoke design language.
 *
 * Context variable:
 *   $space_id (int) — the space's primary key.
 *
 * Overridable: copy to {theme}/buddynext/spaces/admin.php
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceTypeRegistry;
use BuddyNext\Moderation\ModerationService;

// ── Context ─────────────────────────────────────────────────────────────────────
$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( $space_id <= 0 ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

$bn_space = ( new SpaceService() )->get( $space_id );

if ( null === $bn_space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Permission gate ───────────────────────────────────────────────────────────
// Owner, space moderators, and site admins manage a space. Mirrors the gate on
// templates/spaces/settings.php so the two surfaces stay consistent.
$bn_current_user_id = get_current_user_id();

if ( ! buddynext_can( $bn_current_user_id, 'buddynext-spaces/manage-settings', array( 'space_id' => $space_id ) ) ) {
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

// ── At-a-glance counts ──────────────────────────────────────────────────────────
$bn_member_count  = ( new SpaceMemberService() )->member_count( $space_id );
$bn_pending_count = ( new SpaceMemberService() )->count_pending_requests( $space_id );
// get_queue() always scopes to open reports (status IN pending,escalated) and
// returns a COUNT(*) total; per_page=1 keeps the row fetch minimal since we only
// need the count here.
$bn_open_reports = ( new ModerationService() )->get_queue(
	array(
		'space_ids' => array( $space_id ),
		'per_page'  => 1,
	)
);
$bn_report_count = isset( $bn_open_reports['total'] ) ? (int) $bn_open_reports['total'] : 0;

// ── URLs for the management sub-pages ─────────────────────────────────────────────
$bn_slug           = (string) ( $bn_space['slug'] ?? '' );
$bn_space_url      = buddynext_space_url( $bn_slug );
$bn_members_url    = $bn_space_url . 'members/';
$bn_settings_url   = buddynext_space_settings_url( $bn_slug );
$bn_moderation_url = buddynext_space_moderation_url( $bn_slug );

// ── Privacy badge — single source via the space-type registry ─────────────────────
$bn_type    = (string) ( $bn_space['type'] ?? 'open' );
$bn_privacy = array(
	'tone'  => SpaceTypeRegistry::instance()->tone( $bn_type ),
	'label' => SpaceTypeRegistry::instance()->label( $bn_type ),
);
?>
<div class="bn-space-admin">

	<!-- Header (space-home hero shape) -->
	<div class="bn-sh-header">
		<div class="bn-sh-cover"></div>
		<div class="bn-sh-inner">
			<div class="bn-sh-avatar" aria-hidden="true">
				<?php if ( ! empty( $bn_space['avatar_url'] ) ) : ?>
					<img src="<?php echo esc_url( (string) $bn_space['avatar_url'] ); ?>" alt="" loading="lazy">
				<?php else : ?>
					<?php buddynext_icon( 'home' ); ?>
				<?php endif; ?>
			</div>

			<div class="bn-sh-info">
				<h1 class="bn-sh-name">
					<?php echo esc_html( (string) ( $bn_space['name'] ?? '' ) ); ?>
					<span class="bn-badge" data-tone="<?php echo esc_attr( $bn_privacy['tone'] ); ?>"><?php echo esc_html( $bn_privacy['label'] ); ?></span>
				</h1>
				<div class="bn-sh-meta">
					<span><?php buddynext_icon( 'shield' ); ?> <?php esc_html_e( 'Space admin', 'buddynext' ); ?></span>
				</div>
			</div>

			<div class="bn-sh-actions">
				<a
					href="<?php echo esc_url( PageRouter::space_url( $space_id ) ); ?>"
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
				><?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Back to space', 'buddynext' ); ?></a>
			</div>
		</div>
	</div>

	<!-- At-a-glance stats -->
	<div class="bn-space-admin__stats" role="list" aria-label="<?php esc_attr_e( 'Space at a glance', 'buddynext' ); ?>">
		<div class="bn-card bn-space-admin__stat" role="listitem">
			<span class="bn-space-admin__stat-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></span>
			<span class="bn-space-admin__stat-value"><?php echo esc_html( number_format_i18n( $bn_member_count ) ); ?></span>
			<span class="bn-space-admin__stat-label"><?php esc_html_e( 'Members', 'buddynext' ); ?></span>
		</div>
		<div class="bn-card bn-space-admin__stat" role="listitem">
			<span class="bn-space-admin__stat-icon" aria-hidden="true"><?php buddynext_icon( 'user-plus' ); ?></span>
			<span class="bn-space-admin__stat-value"><?php echo esc_html( number_format_i18n( $bn_pending_count ) ); ?></span>
			<span class="bn-space-admin__stat-label"><?php esc_html_e( 'Pending requests', 'buddynext' ); ?></span>
		</div>
		<div class="bn-card bn-space-admin__stat" role="listitem">
			<span class="bn-space-admin__stat-icon" aria-hidden="true"><?php buddynext_icon( 'flag' ); ?></span>
			<span class="bn-space-admin__stat-value"><?php echo esc_html( number_format_i18n( $bn_report_count ) ); ?></span>
			<span class="bn-space-admin__stat-label"><?php esc_html_e( 'Open reports', 'buddynext' ); ?></span>
		</div>
	</div>

	<!-- Management surfaces -->
	<div class="bn-space-admin__nav" role="list" aria-label="<?php esc_attr_e( 'Manage this space', 'buddynext' ); ?>">

		<a class="bn-card bn-space-admin__tile" role="listitem" href="<?php echo esc_url( $bn_members_url ); ?>">
			<span class="bn-space-admin__tile-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></span>
			<span class="bn-space-admin__tile-body">
				<span class="bn-space-admin__tile-title"><?php esc_html_e( 'Members', 'buddynext' ); ?></span>
				<span class="bn-space-admin__tile-desc"><?php esc_html_e( 'Review the roster, change roles, and remove members.', 'buddynext' ); ?></span>
			</span>
			<span class="bn-space-admin__tile-chevron" aria-hidden="true"><?php buddynext_icon( 'chevron-right' ); ?></span>
		</a>

		<a class="bn-card bn-space-admin__tile" role="listitem" href="<?php echo esc_url( $bn_settings_url ); ?>">
			<span class="bn-space-admin__tile-icon" aria-hidden="true"><?php buddynext_icon( 'settings' ); ?></span>
			<span class="bn-space-admin__tile-body">
				<span class="bn-space-admin__tile-title"><?php esc_html_e( 'Settings', 'buddynext' ); ?></span>
				<span class="bn-space-admin__tile-desc"><?php esc_html_e( 'Name, privacy, posting rules, ownership, and the danger zone.', 'buddynext' ); ?></span>
			</span>
			<span class="bn-space-admin__tile-chevron" aria-hidden="true"><?php buddynext_icon( 'chevron-right' ); ?></span>
		</a>

		<a class="bn-card bn-space-admin__tile" role="listitem" href="<?php echo esc_url( $bn_moderation_url ); ?>">
			<span class="bn-space-admin__tile-icon" aria-hidden="true"><?php buddynext_icon( 'flag' ); ?></span>
			<span class="bn-space-admin__tile-body">
				<span class="bn-space-admin__tile-title"><?php esc_html_e( 'Moderation', 'buddynext' ); ?></span>
				<span class="bn-space-admin__tile-desc"><?php esc_html_e( 'Work the report queue and manage banned members.', 'buddynext' ); ?></span>
			</span>
			<?php if ( $bn_report_count > 0 ) : ?>
				<span class="bn-badge" data-tone="danger"><?php echo esc_html( number_format_i18n( $bn_report_count ) ); ?></span>
			<?php endif; ?>
			<span class="bn-space-admin__tile-chevron" aria-hidden="true"><?php buddynext_icon( 'chevron-right' ); ?></span>
		</a>

	</div>

</div>
