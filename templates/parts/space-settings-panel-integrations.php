<?php
/**
 * BuddyNext template part: space-settings-panel-integrations.
 *
 * Renders the "Integrations" panel (Jetonomy forum link, push-to-feed toggle,
 * WPMediaVerse media tab toggle). Lives inside the outer general-settings form
 * — the form wrapper is owned by the composer.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space                 Required. Space row.
 * @var array  $integrations_settings Required. Bundle:
 *   - `jetonomy_forum_id` (int)   Legacy linked-forum id (kept for BC).
 *   - `push_to_feed`      (bool)
 *   - `discussion_status` (array) {linked:bool, forum_id:int, name:string, url:string}
 *     The link picker is a REST typeahead (discussion-search), not a prefetched
 *     list — role-scoped server-side (site admin: all; space owner: their own).
 * @var bool   $mvs_media_tab         Required. Current value of the media-tab toggle.
 * @var array  $classes               Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_integrations_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_integrations_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_integrations_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_integrations_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'                 => isset( $space ) ? $space : null,
	'integrations_settings' => isset( $integrations_settings ) && is_array( $integrations_settings ) ? $integrations_settings : array(),
	'mvs_media_tab'         => isset( $mvs_media_tab ) ? (bool) $mvs_media_tab : false,
	'classes'               => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_integrations_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}

$bn_jetonomy_forum_id = isset( $args['integrations_settings']['jetonomy_forum_id'] ) ? (int) $args['integrations_settings']['jetonomy_forum_id'] : 0;
$bn_push_to_feed      = ! empty( $args['integrations_settings']['push_to_feed'] );
$bn_mvs_media_tab     = (bool) $args['mvs_media_tab'];

// Discussion (Jetonomy) — opt-in per Space, never mandatory. BuddyNext always
// calls this "Discussion" regardless of Jetonomy's own configurable label.
$bn_disc_status   = isset( $args['integrations_settings']['discussion_status'] ) && is_array( $args['integrations_settings']['discussion_status'] )
	? $args['integrations_settings']['discussion_status']
	: array( 'linked' => false, 'forum_id' => 0, 'name' => '', 'url' => '' );
$bn_disc_linked      = ! empty( $bn_disc_status['linked'] );
$bn_disc_url         = isset( $bn_disc_status['url'] ) ? (string) $bn_disc_status['url'] : '';
$bn_disc_linkedid    = isset( $bn_disc_status['forum_id'] ) ? (int) $bn_disc_status['forum_id'] : 0;
$bn_disc_linked_name = isset( $bn_disc_status['name'] ) ? (string) $bn_disc_status['name'] : '';
$bn_disc_space_id    = isset( $bn_space->id ) ? (int) $bn_space->id : 0;

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_integrations_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_space_settings_panel_integrations_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<header class="bn-space-settings__panel-head">
		<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Integrations', 'buddynext' ); ?></h2>
		<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Turn on optional features for this Space.', 'buddynext' ); ?></p>
	</header>

	<div class="bn-toggle-row">
		<div class="bn-toggle-row__copy">
			<div class="bn-toggle-row__label"><?php esc_html_e( 'Discussion', 'buddynext' ); ?></div>
			<div class="bn-toggle-row__desc">
				<?php esc_html_e( 'Give this Space its own discussion area. Optional — turn it on only when this Space needs threaded conversations.', 'buddynext' ); ?>
			</div>
			<?php if ( class_exists( 'Jetonomy\\Jetonomy' ) ) : ?>
				<?php if ( $bn_disc_linked && '' !== $bn_disc_url ) : ?>
					<p class="bn-space-settings__hint">
						<?php esc_html_e( 'Active.', 'buddynext' ); ?>
						<a href="<?php echo esc_url( $bn_disc_url ); ?>"><?php esc_html_e( 'View discussion', 'buddynext' ); ?></a>
					</p>
				<?php endif; ?>

				<?php
				// Typeahead picker — the actionable frontend control for BOTH roles.
				// Leave the field empty to create a new discussion on enable; search
				// + pick to link an existing one. The search endpoint scopes results
				// by role server-side (admins: all; members: their own), so it scales
				// past a bounded <select> on sites with thousands of discussions.
				?>
				<div class="bn-space-settings__discussion-picker" data-bn-discussion-picker data-space-id="<?php echo esc_attr( (string) $bn_disc_space_id ); ?>">
					<input type="hidden" name="bn_discussion_link_id" value="<?php echo esc_attr( (string) $bn_disc_linkedid ); ?>" data-bn-discussion-link-id>
					<label class="bn-sr-only" for="bn_discussion_search"><?php esc_html_e( 'Link an existing discussion', 'buddynext' ); ?></label>
					<input
						type="text"
						id="bn_discussion_search"
						class="bn-input bn-space-settings__discussion-search"
						value="<?php echo esc_attr( $bn_disc_linked ? $bn_disc_linked_name : '' ); ?>"
						placeholder="<?php esc_attr_e( 'Create a new discussion, or search to link an existing one…', 'buddynext' ); ?>"
						autocomplete="off"
						role="combobox"
						aria-expanded="false"
						aria-controls="bn_discussion_results"
						data-bn-discussion-search
						data-wp-on--input="actions.discussionSearch"
						data-wp-on--focus="actions.discussionSearch">
					<button type="button" class="bn-space-settings__discussion-clear" data-bn-discussion-clear hidden data-wp-on--click="actions.discussionClear" aria-label="<?php esc_attr_e( 'Clear — create a new discussion instead', 'buddynext' ); ?>">&times;</button>
					<ul id="bn_discussion_results" class="bn-space-settings__discussion-results" data-bn-discussion-results role="listbox" hidden></ul>
				</div>
			<?php else : ?>
				<p class="bn-space-settings__hint">
					<?php esc_html_e( 'Discussions are unavailable on this site right now.', 'buddynext' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php if ( class_exists( 'Jetonomy\\Jetonomy' ) ) : ?>
			<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Enable discussion', 'buddynext' ); ?>">
				<input type="checkbox" class="bn-space-settings__toggle-input" name="bn_discussion_enabled" value="1" <?php checked( $bn_disc_linked ); ?>>
				<span class="bn-toggle" aria-hidden="true"></span>
			</label>
		<?php endif; ?>
	</div>

	<div class="bn-toggle-row">
		<div class="bn-toggle-row__copy">
			<div class="bn-toggle-row__label"><?php esc_html_e( 'Push space posts to activity feed', 'buddynext' ); ?></div>
			<div class="bn-toggle-row__desc"><?php esc_html_e( "Space posts appear in members' home feeds. Off = space-only.", 'buddynext' ); ?></div>
		</div>
		<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Push space posts to activity feed', 'buddynext' ); ?>">
			<input type="checkbox" class="bn-space-settings__toggle-input" name="push_to_feed" value="1" <?php checked( $bn_push_to_feed ); ?>>
			<span class="bn-toggle" aria-hidden="true"></span>
		</label>
	</div>

	<div class="bn-toggle-row">
		<div class="bn-toggle-row__copy">
			<div class="bn-toggle-row__label"><?php esc_html_e( 'Media tab', 'buddynext' ); ?></div>
			<div class="bn-toggle-row__desc"><?php esc_html_e( 'Show a Media tab for uploading and sharing files in this space.', 'buddynext' ); ?></div>
			<?php if ( ! class_exists( 'WPMediaVerse\\Core\\Plugin' ) ) : ?>
				<p class="bn-space-settings__hint">
					<?php esc_html_e( 'Media sharing is unavailable on this site right now.', 'buddynext' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php if ( class_exists( 'WPMediaVerse\\Core\\Plugin' ) ) : ?>
			<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Enable Media tab', 'buddynext' ); ?>">
				<input type="checkbox" class="bn-space-settings__toggle-input" name="mvs_media_tab" value="1" <?php checked( $bn_mvs_media_tab ); ?>>
				<span class="bn-toggle" aria-hidden="true"></span>
			</label>
		<?php endif; ?>
	</div>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_integrations_after', $args );
