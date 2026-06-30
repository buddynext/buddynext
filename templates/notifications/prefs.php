<?php
/**
 * Notification preferences page (v2).
 *
 * Lives inside the shared hub-shell main column. Composed of four sections:
 *   1. Channels - master toggles (in-app / email / push).
 *   2. Activity types - accordion grouped by NotificationPrefCatalogue group.
 *   3. Spaces you are in - per-space all / mentions only / none chip-select.
 *   4. Quiet hours - coming-soon placeholder (REST not yet extended).
 *
 * Powered by the Interactivity API store `buddynext/notification-prefs`.
 *
 * Overridable: copy to {theme}/buddynext/notifications/prefs.php.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BuddyNext\Core\PageRouter;
use BuddyNext\Notifications\NotificationPrefCatalogue;
use BuddyNext\Notifications\NotificationPrefService;
use BuddyNext\Profile\AvatarService;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().
$current_user_id = get_current_user_id();

// Resolve catalogue + stored prefs server-side so the initial render is fully
// hydrated and a no-JS visit still shows every row with the correct state.
$catalogue_service = null;
if ( function_exists( 'buddynext_service' ) ) {
	$catalogue_service = buddynext_service( 'notification_pref_catalogue' );
}
if ( ! $catalogue_service instanceof NotificationPrefCatalogue ) {
	$catalogue_service = new NotificationPrefCatalogue();
}

$pref_service = new NotificationPrefService();
$stored_prefs = $pref_service->get_all_prefs( $current_user_id );
$resolved     = $catalogue_service->resolve_for_user( $stored_prefs );
$groups       = $catalogue_service->grouped();

// Channels: usermeta bn_channel_prefs, defaults driven by push availability.
$push_available  = class_exists( '\\BuddyNextPro\\Push\\PushDispatcher' );
$stored_channels = get_user_meta( $current_user_id, 'bn_channel_prefs', true );
if ( ! is_array( $stored_channels ) ) {
	$stored_channels = array();
}
$channels = array(
	'in_app' => array_key_exists( 'in_app', $stored_channels ) ? (bool) $stored_channels['in_app'] : true,
	'email'  => array_key_exists( 'email', $stored_channels ) ? (bool) $stored_channels['email'] : true,
	'push'   => array_key_exists( 'push', $stored_channels ) ? (bool) $stored_channels['push'] : $push_available,
	'sound'  => array_key_exists( 'sound', $stored_channels ) ? (bool) $stored_channels['sound'] : false,
);

// Spaces the user belongs to with their notification_pref (active memberships
// only). Service returns assoc rows: space_id, name, slug, avatar_url, pref.
$joined_spaces = $pref_service->list_space_notification_prefs( $current_user_id );

$space_prefs = array();
foreach ( $joined_spaces as $sp ) {
	$space_prefs[ (int) $sp['space_id'] ] = (string) $sp['pref'];
}

// Frequency option metadata.
$freq_options = array(
	'immediate' => __( 'Immediate', 'buddynext' ),
	'daily'     => __( 'Daily', 'buddynext' ),
	'weekly'    => __( 'Weekly', 'buddynext' ),
	'off'       => __( 'Off', 'buddynext' ),
);

$space_pref_options = array(
	'all'           => __( 'All activity', 'buddynext' ),
	'mentions_only' => __( 'Mentions only', 'buddynext' ),
	'none'          => __( 'None', 'buddynext' ),
);

// Initial Interactivity context. The store mutates this in place; the server
// snapshot in $resolved/$channels/$space_prefs lives on as the "initial" map
// for diff + rollback.
$nonce             = wp_create_nonce( 'wp_rest' );
$rest_prefs_url    = esc_url_raw( rest_url( 'buddynext/v1/me/notification-prefs' ) );
$rest_channels_url = esc_url_raw( rest_url( 'buddynext/v1/me/notification-channels' ) );
$rest_spaces_url   = esc_url_raw( rest_url( 'buddynext/v1/me/space-notification-prefs' ) );

// Strip catalogue down to JS-essential keys to keep the payload small.
$context_prefs = array();
foreach ( $resolved as $slug => $entry ) {
	$context_prefs[ $slug ] = array(
		'on_site'    => (bool) $entry['on_site'],
		'email_freq' => (string) $entry['email_freq'],
	);
}

$context_catalogue = array();
foreach ( $catalogue_service->all() as $slug => $entry ) {
	$context_catalogue[] = array(
		'slug'               => (string) $slug,
		'default_on_site'    => (bool) ( $entry['default_on_site'] ?? true ),
		'default_email_freq' => (string) ( $entry['default_email_freq'] ?? 'immediate' ),
	);
}

$open_groups = array();
foreach ( array_keys( $groups ) as $group_slug ) {
	$open_groups[ $group_slug ] = true;
}

$initial_context = wp_json_encode(
	array(
		'restPrefsUrl'     => $rest_prefs_url,
		'restChannelsUrl'  => $rest_channels_url,
		'restSpacesUrl'    => $rest_spaces_url,
		'nonce'            => $nonce,
		'prefs'            => $context_prefs,
		'initialPrefs'     => $context_prefs,
		'spacePrefs'       => $space_prefs,
		'channels'         => $channels,
		'initialChannels'  => $channels,
		'pushAvailable'    => $push_available,
		'catalogue'        => $context_catalogue,
		'isDirty'          => false,
		'isSaving'         => false,
		'savedAt'          => 0,
		'errors'           => (object) array(),
		'openGroups'       => $open_groups,
		'resetConfirmOpen' => false,
	)
);

/**
 * Fires before the notification preferences inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_notification_prefs_before', $current_user_id );
?>

<div class="bn-settings">

	<?php
	// Settings hub chrome — this page is the "Notifications" tab. The hub header
	// (Settings + tabs) replaces a second page title; the active tab already
	// says where you are, so no redundant "Notification preferences" heading.
	// Rendered inside the shared .bn-settings wrapper (NOT inside the interactive
	// flex container below) so the tab strip -> content rhythm matches the
	// Account/Privacy/Appearance tabs exactly (no double gap under the tabs).
	buddynext_get_template( 'parts/settings-nav.php', array( 'bn_settings_active' => 'notifications' ) );
	?>

	<div class="bn-notif-prefs"
		data-wp-interactive="buddynext/notification-prefs"
		data-wp-context='<?php echo esc_attr( (string) $initial_context ); ?>'
		data-wp-init="callbacks.init">

	<!-- Section 1: Channels -->
	<section class="bn-card bn-prefs-card" data-v2 aria-labelledby="bn-prefs-channels-title">
		<header class="bn-prefs-card__head">
			<h2 class="bn-prefs-card__title" id="bn-prefs-channels-title"><?php esc_html_e( 'Channels', 'buddynext' ); ?></h2>
			<p class="bn-prefs-card__sub"><?php esc_html_e( 'Master switches. Turning a channel off mutes every type for that delivery surface.', 'buddynext' ); ?></p>
		</header>
		<div class="bn-prefs-channels">
			<label class="bn-prefs-channel">
				<input type="checkbox"
					data-channel="in_app"
					<?php checked( $channels['in_app'] ); ?>
					data-wp-on--change="actions.setChannel">
				<span class="bn-prefs-channel__icon" aria-hidden="true"><?php buddynext_icon( 'bell' ); ?></span>
				<span class="bn-prefs-channel__body">
					<span class="bn-prefs-channel__label"><?php esc_html_e( 'In-app', 'buddynext' ); ?></span>
					<span class="bn-prefs-channel__sub"><?php esc_html_e( 'Show notifications inside BuddyNext.', 'buddynext' ); ?></span>
				</span>
			</label>

			<label class="bn-prefs-channel">
				<input type="checkbox"
					data-channel="email"
					<?php checked( $channels['email'] ); ?>
					data-wp-on--change="actions.setChannel">
				<span class="bn-prefs-channel__icon" aria-hidden="true"><?php buddynext_icon( 'mail' ); ?></span>
				<span class="bn-prefs-channel__body">
					<span class="bn-prefs-channel__label"><?php esc_html_e( 'Email', 'buddynext' ); ?></span>
					<span class="bn-prefs-channel__sub"><?php esc_html_e( 'Send transactional and digest emails.', 'buddynext' ); ?></span>
				</span>
			</label>

			<?php if ( $push_available ) : ?>
				<label class="bn-prefs-channel">
					<input type="checkbox"
						data-channel="push"
						<?php checked( $channels['push'] ); ?>
						data-wp-on--change="actions.setChannel">
					<span class="bn-prefs-channel__icon" aria-hidden="true"><?php buddynext_icon( 'smartphone' ); ?></span>
					<span class="bn-prefs-channel__body">
						<span class="bn-prefs-channel__label"><?php esc_html_e( 'Push', 'buddynext' ); ?></span>
						<span class="bn-prefs-channel__sub"><?php esc_html_e( 'Mobile + desktop push notifications.', 'buddynext' ); ?></span>
					</span>
				</label>
			<?php endif; ?>

			<label class="bn-prefs-channel">
				<input type="checkbox"
					data-channel="sound"
					<?php checked( ! empty( $channels['sound'] ) ); ?>
					data-wp-on--change="actions.setChannel">
				<span class="bn-prefs-channel__icon" aria-hidden="true"><?php buddynext_icon( 'volume-2' ); ?></span>
				<span class="bn-prefs-channel__body">
					<span class="bn-prefs-channel__label"><?php esc_html_e( 'Play a sound', 'buddynext' ); ?></span>
					<span class="bn-prefs-channel__sub"><?php esc_html_e( 'Soft chime when a new notification arrives while this tab is open.', 'buddynext' ); ?></span>
				</span>
			</label>
		</div>
	</section>

	<!-- Section 2: Activity types -->
	<section class="bn-card bn-prefs-card" data-v2 aria-labelledby="bn-prefs-types-title">
		<header class="bn-prefs-card__head">
			<h2 class="bn-prefs-card__title" id="bn-prefs-types-title"><?php esc_html_e( 'Activity types', 'buddynext' ); ?></h2>
			<p class="bn-prefs-card__sub"><?php esc_html_e( 'Tune in-app and email delivery per type. Email frequency aggregates into daily or weekly digests when you choose those.', 'buddynext' ); ?></p>
		</header>

		<div class="bn-prefs-groups">
			<?php
			foreach ( $groups as $group_slug => $group_entries ) :
				if ( empty( $group_entries ) ) {
					continue;
				}
				$group_label = $catalogue_service->group_label( (string) $group_slug );
				?>
				<details class="bn-prefs-group" data-group="<?php echo esc_attr( (string) $group_slug ); ?>" open>
					<summary class="bn-prefs-group__head" data-group="<?php echo esc_attr( (string) $group_slug ); ?>" data-wp-on--click="actions.toggleGroup">
						<span class="bn-prefs-group__title"><?php echo esc_html( $group_label ); ?></span>
						<span class="bn-prefs-group__count"><?php echo esc_html( (string) count( $group_entries ) ); ?></span>
					</summary>
					<div class="bn-prefs-group__body" role="list">
						<?php
						foreach ( $group_entries as $entry ) :
							$type_slug = (string) ( $entry['slug'] ?? '' );
							if ( '' === $type_slug ) {
								continue; }
							$resolved_row = $resolved[ $type_slug ] ?? array(
								'on_site'    => true,
								'email_freq' => 'immediate',
							);
							$can_email    = (bool) ( $entry['can_email'] ?? true );
							?>
							<div class="bn-prefs-row" role="listitem" data-wp-context="<?php echo esc_attr( (string) wp_json_encode( array( 'prefType' => $type_slug ) ) ); ?>">
								<div class="bn-prefs-row__copy">
									<label class="bn-prefs-row__label" for="bn-pref-on-site-<?php echo esc_attr( $type_slug ); ?>">
										<?php echo esc_html( (string) ( $entry['label'] ?? $type_slug ) ); ?>
									</label>
									<p class="bn-prefs-row__desc"><?php echo esc_html( (string) ( $entry['description'] ?? '' ) ); ?></p>
								</div>
								<div class="bn-prefs-row__controls">
									<label class="bn-prefs-toggle">
										<input type="checkbox"
											id="bn-pref-on-site-<?php echo esc_attr( $type_slug ); ?>"
											data-type="<?php echo esc_attr( $type_slug ); ?>"
											<?php checked( (bool) $resolved_row['on_site'] ); ?>
											data-wp-bind--checked="state.rowOnSite"
											data-wp-on--change="actions.setOnSite">
										<span class="bn-prefs-toggle__label"><?php esc_html_e( 'In-app', 'buddynext' ); ?></span>
									</label>

									<?php if ( $can_email ) : ?>
										<div class="bn-prefs-freq" role="radiogroup" aria-label="<?php esc_attr_e( 'Email frequency', 'buddynext' ); ?>">
											<?php
											foreach ( $freq_options as $freq_value => $freq_label ) :
												$is_active = ( $resolved_row['email_freq'] === $freq_value );
												?>
												<button type="button"
													class="bn-prefs-chip"
													data-type="<?php echo esc_attr( $type_slug ); ?>"
													data-freq="<?php echo esc_attr( $freq_value ); ?>"
													data-wp-context="<?php echo esc_attr( (string) wp_json_encode( array( 'chipFreq' => $freq_value ) ) ); ?>"
													aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
													data-wp-bind--aria-pressed="state.rowFreqActive"
													data-wp-on--click="actions.setEmailFreq">
													<?php echo esc_html( $freq_label ); ?>
												</button>
											<?php endforeach; ?>
										</div>
									<?php else : ?>
										<span class="bn-prefs-row__no-email"><?php esc_html_e( 'In-app only', 'buddynext' ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endforeach; ?>
		</div>
	</section>

	<!-- Section 3: Spaces you are in -->
	<section class="bn-card bn-prefs-card" data-v2 aria-labelledby="bn-prefs-spaces-title">
		<header class="bn-prefs-card__head">
			<h2 class="bn-prefs-card__title" id="bn-prefs-spaces-title"><?php esc_html_e( 'Spaces you are in', 'buddynext' ); ?></h2>
			<p class="bn-prefs-card__sub"><?php esc_html_e( 'Set how much each space notifies you. Saves immediately when you choose an option.', 'buddynext' ); ?></p>
		</header>

		<?php if ( empty( $joined_spaces ) ) : ?>
			<div class="bn-prefs-empty">
				<span class="bn-prefs-empty__emblem" aria-hidden="true"><?php buddynext_icon( 'home' ); ?></span>
				<p class="bn-prefs-empty__title"><?php esc_html_e( 'You have not joined any spaces yet.', 'buddynext' ); ?></p>
				<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( PageRouter::spaces_url() ); ?>">
					<?php esc_html_e( 'Browse spaces', 'buddynext' ); ?>
				</a>
			</div>
		<?php else : ?>
			<div class="bn-prefs-spaces" role="list">
				<?php
				foreach ( $joined_spaces as $sp ) :
					$space_id     = (int) $sp['space_id'];
					$current_pref = (string) $sp['pref'];
					$space_name   = (string) $sp['name'];
					$avatar       = (string) ( $sp['avatar_url'] ?? '' );
					$initial      = AvatarService::initials_for( $space_name );
					?>
					<div class="bn-prefs-space" role="listitem">
						<a class="bn-prefs-space__head" href="<?php echo esc_url( PageRouter::space_url( $space_id ) ); ?>">
							<?php if ( '' !== $avatar ) : ?>
								<img class="bn-prefs-space__avatar" src="<?php echo esc_url( $avatar ); ?>" alt="" width="40" height="40" loading="lazy">
							<?php else : ?>
								<span class="bn-prefs-space__avatar bn-prefs-space__avatar--initial" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
							<?php endif; ?>
							<span class="bn-prefs-space__name"><?php echo esc_html( $space_name ); ?></span>
						</a>
						<div class="bn-prefs-space__controls" role="radiogroup" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: space name */ __( 'Notification preference for %s', 'buddynext' ), $space_name ) ); ?>">
							<?php
							foreach ( $space_pref_options as $pref_value => $pref_label ) :
								$is_active = ( $current_pref === $pref_value );
								?>
								<button type="button"
									class="bn-prefs-chip"
									data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
									data-pref="<?php echo esc_attr( $pref_value ); ?>"
									aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
									data-wp-on--click="actions.setSpacePref">
									<?php echo esc_html( $pref_label ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<!-- Section 4: Quiet hours (coming soon) -->
	<section class="bn-card bn-prefs-card bn-prefs-card--soon" data-v2 aria-labelledby="bn-prefs-quiet-title">
		<header class="bn-prefs-card__head">
			<h2 class="bn-prefs-card__title" id="bn-prefs-quiet-title">
				<?php esc_html_e( 'Quiet hours', 'buddynext' ); ?>
				<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Coming soon', 'buddynext' ); ?></span>
			</h2>
			<p class="bn-prefs-card__sub"><?php esc_html_e( 'Pause email and push during a daily quiet window. We will turn this on as soon as the dispatcher honours it.', 'buddynext' ); ?></p>
		</header>
		<div class="bn-prefs-quiet">
			<div class="bn-prefs-quiet__field">
				<label class="bn-prefs-quiet__label" for="bn-prefs-quiet-start"><?php esc_html_e( 'Start', 'buddynext' ); ?></label>
				<input class="bn-input" id="bn-prefs-quiet-start" type="time" value="22:00" disabled>
			</div>
			<div class="bn-prefs-quiet__field">
				<label class="bn-prefs-quiet__label" for="bn-prefs-quiet-end"><?php esc_html_e( 'End', 'buddynext' ); ?></label>
				<input class="bn-input" id="bn-prefs-quiet-end" type="time" value="07:00" disabled>
			</div>
			<div class="bn-prefs-quiet__field">
				<span class="bn-prefs-quiet__label"><?php esc_html_e( 'Timezone', 'buddynext' ); ?></span>
				<span class="bn-prefs-quiet__tz"><?php echo esc_html( (string) wp_timezone_string() ); ?></span>
			</div>
		</div>
	</section>

	<!-- Reset to defaults -->
	<div class="bn-prefs-reset">
		<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.openResetConfirm">
			<?php buddynext_icon( 'rotate-ccw' ); ?>
			<?php esc_html_e( 'Reset every type to defaults', 'buddynext' ); ?>
		</button>
	</div>

	<!-- Sticky save bar -->
	<div class="bn-prefs-save-bar bn-ep-save-bar" role="region" aria-label="<?php esc_attr_e( 'Save preferences', 'buddynext' ); ?>"
		data-wp-bind--hidden="state.saveBarHidden">
		<div class="bn-ep-save-bar-inner">
			<div class="bn-ep-save-status bn-ep-save-status--dirty"
				data-wp-bind--hidden="!(context.isDirty &amp;&amp; !context.isSaving)">
				<span class="bn-ep-dirty-dot" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Unsaved changes', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ep-save-status bn-ep-save-status--saving"
				data-wp-bind--hidden="!context.isSaving">
				<span class="bn-ep-spinner" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Saving...', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ep-save-status bn-ep-save-status--saved"
				data-wp-bind--hidden="!(context.savedAt &amp;&amp; !context.isDirty &amp;&amp; !context.isSaving)">
				<?php buddynext_icon( 'check' ); ?>
				<span data-wp-text="state.statusLabel"><?php esc_html_e( 'Preferences saved', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ep-save-actions">
				<button class="bn-btn" type="button" data-variant="primary" data-size="md"
					data-wp-on--click="actions.saveAll"
					data-wp-bind--disabled="!context.isDirty">
					<?php esc_html_e( 'Save changes', 'buddynext' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Reset confirmation modal -->
	<div class="bn-modal-backdrop"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bn-prefs-reset-title"
		hidden
		data-wp-bind--hidden="!context.resetConfirmOpen">
		<div class="bn-modal__panel" data-size="sm">
			<header class="bn-modal__head">
				<h2 class="bn-modal__title" id="bn-prefs-reset-title">
					<?php esc_html_e( 'Reset every type to defaults?', 'buddynext' ); ?>
				</h2>
				<button class="bn-modal__close" type="button"
					aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
					data-wp-on--click="actions.closeResetConfirm">
					<?php buddynext_icon( 'x' ); ?>
				</button>
			</header>
			<div class="bn-modal__body">
				<p><?php esc_html_e( 'Every type returns to its platform default. Per-space preferences reset to "All activity". This stages the change; remember to press Save to commit.', 'buddynext' ); ?></p>
			</div>
			<footer class="bn-modal__foot">
				<button class="bn-btn" type="button" data-variant="ghost" data-size="md"
					data-wp-on--click="actions.closeResetConfirm">
					<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
				</button>
				<button class="bn-btn" type="button" data-variant="primary" data-size="md"
					data-wp-on--click="actions.resetToDefaults">
					<?php esc_html_e( 'Reset to defaults', 'buddynext' ); ?>
				</button>
			</footer>
		</div>
	</div>
	</div>
</div>

<?php
/**
 * Fires after the notification preferences inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_notification_prefs_after', $current_user_id );
