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
 * @var string $album_creators        Optional. 'members' (default) or 'admins' - who may
 *                                    create albums in this space. Uploading INTO an
 *                                    existing album stays open to members either way.
 * @var bool   $is_space_owner        Required. Whether the viewer owns the space. This
 *                                    panel is owner-only (it decides whether the space
 *                                    has a discussion, a media tab, and whether its
 *                                    activity reaches the main feed — the last is a
 *                                    privacy control). A moderator can reach the settings
 *                                    screen, so the controls render DISABLED with an
 *                                    explanation rather than appearing live and then
 *                                    being rejected on save. Defaults to false: a caller
 *                                    that forgets to pass it gets the safe, read-only view.
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
	'album_creators'        => isset( $album_creators ) ? (string) $album_creators : 'members',
	'is_space_owner'        => isset( $is_space_owner ) ? (bool) $is_space_owner : false,
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

// Discussion (Jetonomy) — opt-in per Space, never mandatory. A Space owns ONE
// dedicated discussion for its lifetime: before it exists the owner sees a picker
// (create new / link existing); once it exists the panel shows WHICH discussion
// is linked and the toggle only enables/disables it. BuddyNext always calls this
// "Discussion" regardless of Jetonomy's own configurable label.
$bn_disc_status   = isset( $args['integrations_settings']['discussion_status'] ) && is_array( $args['integrations_settings']['discussion_status'] )
	? $args['integrations_settings']['discussion_status']
	: array(
		'has_discussion' => false,
		'enabled'        => false,
		'forum_id'       => 0,
		'name'           => '',
		'url'            => '',
	);
$bn_disc_has      = ! empty( $bn_disc_status['has_discussion'] );
$bn_disc_enabled  = ! empty( $bn_disc_status['enabled'] );
$bn_disc_url      = isset( $bn_disc_status['url'] ) ? (string) $bn_disc_status['url'] : '';
$bn_disc_name     = isset( $bn_disc_status['name'] ) ? (string) $bn_disc_status['name'] : '';
$bn_disc_space_id = isset( $bn_space->id ) ? (int) $bn_space->id : 0;

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

	<?php
	// Owner-only panel: it decides whether the Space has a discussion at all, whether
	// it has a media tab, and whether its content reaches the public feed. A moderator
	// is told plainly that this is not theirs, instead of being shown live controls
	// whose save would be rejected.
	if ( ! $args['is_space_owner'] ) :
		?>
		<p class="bn-space-settings__hint bn-space-settings__hint--locked">
			<?php esc_html_e( 'Only the space owner can change these. They decide whether this space has a discussion, a media tab, and whether its activity reaches the main feed.', 'buddynext' ); ?>
		</p>
	<?php endif; ?>

	<div class="bn-toggle-row">
		<div class="bn-toggle-row__copy">
			<div class="bn-toggle-row__label"><?php esc_html_e( 'Discussion', 'buddynext' ); ?></div>
			<div class="bn-toggle-row__desc">
				<?php
				if ( $bn_disc_has ) {
					esc_html_e( 'This Space has its own discussion area for threaded conversations.', 'buddynext' );
				} else {
					esc_html_e( 'Give this Space its own discussion area. Turn the switch on and a discussion is created for it automatically.', 'buddynext' );
				}
				?>
			</div>
			<?php if ( class_exists( 'Jetonomy\\Jetonomy' ) ) : ?>
				<?php if ( $bn_disc_has ) : ?>
					<?php
					// This Space already owns its one dedicated discussion. Show WHICH
					// one is linked (permanent — it is never swapped or duplicated);
					// the toggle beside this row only enables/disables it.
					?>
					<p class="bn-space-settings__hint bn-space-settings__discussion-linked">
						<?php
						printf(
							/* translators: %s: the linked discussion's name. */
							esc_html__( 'Linked discussion: %s', 'buddynext' ),
							'<strong>' . esc_html( '' !== $bn_disc_name ? $bn_disc_name : __( 'Discussion', 'buddynext' ) ) . '</strong>'
						);
						?>
						<?php if ( '' !== $bn_disc_url ) : ?>
							&middot; <a href="<?php echo esc_url( $bn_disc_url ); ?>"><?php esc_html_e( 'View discussion', 'buddynext' ); ?></a>
						<?php endif; ?>
					</p>
				<?php else : ?>
					<?php
					// First-time setup only: create a new dedicated discussion (default)
					// or link an existing one. The typeahead search scopes results by
					// role server-side (site admin: all; space owner: their own), so it
					// scales past a bounded <select> on sites with thousands of
					// discussions. Once set, this picker is replaced by the line above.
					?>
					<div class="bn-space-settings__discussion-picker" data-bn-discussion-picker data-space-id="<?php echo esc_attr( (string) $bn_disc_space_id ); ?>">
						<input type="hidden" name="bn_discussion_link_id" value="0" data-bn-discussion-link-id>
						<label class="bn-space-settings__discussion-picker-label" for="bn_discussion_search"><?php esc_html_e( 'Already have a discussion? Link it instead (optional):', 'buddynext' ); ?></label>
						<input
							type="text"
							id="bn_discussion_search"
							class="bn-input bn-space-settings__discussion-search"
							value=""
							placeholder="<?php esc_attr_e( 'Search your discussions to link one…', 'buddynext' ); ?>"
							autocomplete="off"
							role="combobox"
							aria-expanded="false"
							aria-controls="bn_discussion_results"
							data-bn-discussion-search
							data-wp-on--input="actions.discussionSearch"
							data-wp-on--focus="actions.discussionSearch">
						<button type="button" class="bn-space-settings__discussion-clear" data-bn-discussion-clear hidden data-wp-on--click="actions.discussionClear" aria-label="<?php esc_attr_e( 'Clear — create a new discussion instead', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
						<ul id="bn_discussion_results" class="bn-space-settings__discussion-results" data-bn-discussion-results role="listbox" hidden></ul>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<p class="bn-space-settings__hint">
					<?php esc_html_e( 'Discussions are unavailable on this site right now.', 'buddynext' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php if ( class_exists( 'Jetonomy\\Jetonomy' ) ) : ?>
			<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Enable discussion', 'buddynext' ); ?>">
				<input type="checkbox" class="bn-space-settings__toggle-input" name="bn_discussion_enabled" value="1" <?php checked( $bn_disc_enabled ); ?> <?php disabled( ! $args['is_space_owner'] ); ?>>
				<span class="bn-toggle" aria-hidden="true"></span>
			</label>
		<?php endif; ?>
	</div>

	<div class="bn-toggle-row">
		<div class="bn-toggle-row__copy">
			<div class="bn-toggle-row__label"><?php esc_html_e( 'Share activity to the main feed', 'buddynext' ); ?></div>
			<div class="bn-toggle-row__desc"><?php esc_html_e( 'When on, new posts and discussion topics from this Space also appear in the main activity feed. When off, they stay inside this Space.', 'buddynext' ); ?></div>
		</div>
		<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Share activity to the main feed', 'buddynext' ); ?>">
			<input type="checkbox" class="bn-space-settings__toggle-input" name="push_to_feed" value="1" <?php checked( $bn_push_to_feed ); ?> <?php disabled( ! $args['is_space_owner'] ); ?>>
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
				<input type="checkbox" class="bn-space-settings__toggle-input" name="mvs_media_tab" value="1" <?php checked( $bn_mvs_media_tab ); ?> <?php disabled( ! $args['is_space_owner'] ); ?>>
				<span class="bn-toggle" aria-hidden="true"></span>
			</label>
		<?php endif; ?>
	</div>

	<?php
	/*
	 * Who may START an album here. Off by default: any member can, which is the
	 * same line the space already draws for posting. A curated space can turn it
	 * on and keep album creation with its organisers - adding photos to an album
	 * that already exists stays open to members either way, because an album only
	 * its creator can fill is not a shared album.
	 */
	?>
	<?php if ( class_exists( 'WPMediaVerse\\Core\\Plugin' ) ) : ?>
		<div class="bn-toggle-row">
			<div class="bn-toggle-row__copy">
				<div class="bn-toggle-row__label"><?php esc_html_e( 'Only organisers can create albums', 'buddynext' ); ?></div>
				<div class="bn-toggle-row__desc"><?php esc_html_e( 'When off, any member can create an album in this space. Members can always add photos to an album that already exists.', 'buddynext' ); ?></div>
			</div>
			<label class="bn-space-settings__toggle-shell" aria-label="<?php esc_attr_e( 'Only organisers can create albums', 'buddynext' ); ?>">
				<input type="checkbox" class="bn-space-settings__toggle-input" name="album_creators_admins" value="1" <?php checked( 'admins' === $args['album_creators'] ); ?> <?php disabled( ! $args['is_space_owner'] ); ?>>
				<span class="bn-toggle" aria-hidden="true"></span>
			</label>
		</div>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_integrations_after', $args );
