<?php
/**
 * BuddyNext template part: profile-edit-hero.
 *
 * Renders the edit-profile hero card that mirrors the view.php visual
 * language — cover image with "Change cover" trigger, avatar with edit
 * trigger, and editable display-name + headline fields plus the @handle
 * badge. Used by `templates/profile/edit.php`.
 *
 * The two file-inputs for avatar/cover upload live inside the hero card
 * (see the bottom of the markup) so the section stays self-contained.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var int    $profile_user_id Required. ID of the profile being edited.
 * @var string $display_name    Required. Current display-name value.
 * @var string $headline        Optional. Current headline value.
 * @var string $username        Required. WP user_login string used in the @handle badge.
 * @var string $avatar_url      Optional. Resolved avatar URL.
 * @var string $cover_url       Optional. Resolved cover URL.
 * @var string $initials        Optional. Fallback initials shown when no avatar URL.
 * @var array  $classes         Optional. Extra CSS classes appended to `.bn-pf-hero`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_edit_hero_before', $args )
 *   - do_action( 'buddynext_part_profile_edit_hero_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_edit_hero_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_edit_hero_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'profile_user_id'   => isset( $profile_user_id ) ? (int) $profile_user_id : 0,
	'display_name'      => isset( $display_name ) ? (string) $display_name : '',
	'headline'          => isset( $headline ) ? (string) $headline : '',
	'username'          => isset( $username ) ? (string) $username : '',
	'avatar_url'        => isset( $avatar_url ) ? (string) $avatar_url : '',
	'cover_url'         => isset( $cover_url ) ? (string) $cover_url : '',
	'initials'          => isset( $initials ) ? (string) $initials : '',
	'has_custom_avatar' => isset( $has_custom_avatar ) ? (bool) $has_custom_avatar : false,
	'classes'           => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_edit_hero_args', $args );

if ( $args['profile_user_id'] <= 0 ) {
	return;
}

$bn_classes = array_merge( array( 'bn-pf-hero', 'bn-card', 'bn-ep-hero' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_edit_hero_classes', $bn_classes, $args );
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

$bn_ep_name       = (string) $args['display_name'];
$bn_ep_headline   = (string) $args['headline'];
$bn_ep_login      = (string) $args['username'];
$bn_ep_avatar     = (string) $args['avatar_url'];
$bn_ep_cover      = (string) $args['cover_url'];
$bn_ep_init       = (string) $args['initials'];
$bn_ep_has_avatar = (bool) $args['has_custom_avatar'];

do_action( 'buddynext_part_profile_edit_hero_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>">
	<?php
	// Same reposition contract as the public hero (object-position + scale).
	$bn_ep_focal = (array) get_user_meta( $args['profile_user_id'], 'buddynext_cover_focal', true );
	$bn_ep_fx    = isset( $bn_ep_focal['x'] ) ? max( 0.0, min( 100.0, (float) $bn_ep_focal['x'] ) ) : 50.0;
	$bn_ep_fy    = isset( $bn_ep_focal['y'] ) ? max( 0.0, min( 100.0, (float) $bn_ep_focal['y'] ) ) : 50.0;
	$bn_ep_zoom  = isset( $bn_ep_focal['zoom'] ) ? max( 1.0, min( 3.0, (float) $bn_ep_focal['zoom'] ) ) : 1.0;
	?>
	<div class="bn-pf-cover<?php echo '' !== $bn_ep_cover ? ' bn-pf-cover--has-image' : ''; ?>">
		<img class="bn-pf-cover__img"
			data-bn-cover-preview
			src="<?php echo esc_url( $bn_ep_cover ); ?>"
			alt=""
			style="object-position:<?php echo esc_attr( (string) $bn_ep_fx ); ?>% <?php echo esc_attr( (string) $bn_ep_fy ); ?>%;transform:scale(<?php echo esc_attr( (string) $bn_ep_zoom ); ?>);<?php echo '' === $bn_ep_cover ? 'display:none;' : ''; ?>" />
		<button class="bn-pf-cover__edit bn-ep-cover-btn"
			type="button"
			data-wp-on--click="actions.triggerCoverUpload">
			<?php buddynext_icon( 'camera' ); ?>
			<span><?php esc_html_e( 'Change cover', 'buddynext' ); ?></span>
		</button>
	</div>

	<div class="bn-pf-head bn-ep-hero-head">
		<div class="bn-pf-avatar-wrap bn-ep-avatar-wrap">
			<span class="bn-avatar bn-ep-avatar-preview" data-size="2xl" data-bn-initials="<?php echo esc_attr( $bn_ep_init ); ?>">
				<?php if ( '' !== $bn_ep_avatar ) : ?>
					<img src="<?php echo esc_url( $bn_ep_avatar ); ?>"
						alt="<?php echo esc_attr( $bn_ep_name ); ?>" />
				<?php else : ?>
					<?php echo esc_html( $bn_ep_init ); ?>
				<?php endif; ?>
			</span>
			<button class="bn-ep-avatar-btn"
				type="button"
				aria-label="<?php esc_attr_e( 'Change profile photo', 'buddynext' ); ?>"
				data-wp-on--click="actions.triggerAvatarUpload">
				<?php buddynext_icon( 'edit' ); ?>
			</button>
			<button class="bn-ep-avatar-remove"
				type="button"
				data-bn-avatar-remove
				aria-label="<?php esc_attr_e( 'Remove profile photo', 'buddynext' ); ?>"
				title="<?php esc_attr_e( 'Remove profile photo', 'buddynext' ); ?>"
				<?php echo $bn_ep_has_avatar ? '' : 'hidden'; ?>
				data-wp-on--click="actions.removeAvatar">
				<?php buddynext_icon( 'trash' ); ?>
			</button>
		</div>

		<div class="bn-pf-id bn-ep-hero-id">
			<div class="bn-ep-hero-field"
				<?php
				// Controlled value: this is the only field that carries BOTH a reactive
				// directive (the error class bound to context.errors) AND a pre-filled
				// value. Without a reactive value binding, the Interactivity re-render
				// fired when validateField touches context.errors on blur would reset
				// this uncontrolled input back to its server-rendered value (the login),
				// so the name appeared un-editable. Seeding the value into context and
				// binding data-wp-bind--value makes the re-render preserve what the
				// member typed.
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context() returns an escaped data-wp-context attribute.
				echo wp_interactivity_data_wp_context( array( 'nameValue' => (string) $bn_ep_name ) );
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			>
				<label class="bn-ep-hero-label" for="bn-ep-name">
					<?php esc_html_e( 'Display name', 'buddynext' ); ?>
					<span class="bn-ep-required" aria-hidden="true">*</span>
				</label>
				<input class="bn-input bn-ep-hero-name"
					type="text"
					id="bn-ep-name"
					name="display_name"
					value="<?php echo esc_attr( $bn_ep_name ); ?>"
					data-wp-bind--value="context.nameValue"
					placeholder="<?php esc_attr_e( 'Your full name', 'buddynext' ); ?>"
					required
					aria-required="true"
					aria-describedby="bn-ep-error-display_name"
					data-wp-class--bn-input--error="context.errors.display_name"
					data-wp-on--input="actions.syncNameField"
					data-wp-on--blur="actions.validateField" />
				<span class="bn-ep-field-error"
					id="bn-ep-error-display_name"
					role="alert"
					data-wp-text="context.errors.display_name"
					data-wp-bind--hidden="!context.errors.display_name"></span>
			</div>
			<div class="bn-ep-hero-field">
				<label class="bn-ep-hero-label" for="bn-ep-headline">
					<?php esc_html_e( 'Headline', 'buddynext' ); ?>
				</label>
				<input class="bn-input bn-ep-hero-headline"
					type="text"
					id="bn-ep-headline"
					name="headline"
					value="<?php echo esc_attr( $bn_ep_headline ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. Software Engineer at Acme Co.', 'buddynext' ); ?>"
					aria-describedby="bn-ep-headline-hint"
					data-wp-on--blur="actions.autosave" />
				<span class="bn-ep-hint" id="bn-ep-headline-hint">
					<?php esc_html_e( 'Shown under your name across the community.', 'buddynext' ); ?>
				</span>
			</div>
			<div class="bn-ep-hero-handle">
				<span class="bn-badge" data-tone="accent">@<?php echo esc_html( $bn_ep_login ); ?></span>
			</div>
		</div>
	</div>

	<input
		type="file"
		id="bn-ep-avatar-file"
		accept="image/jpeg,image/png,image/gif,image/webp"
		class="bn-ep-file-hidden"
		data-wp-on--change="actions.handleAvatarFileChange"
	/>
	<input
		type="file"
		id="bn-ep-cover-file"
		accept="image/jpeg,image/png,image/gif,image/webp"
		class="bn-ep-file-hidden"
		data-wp-on--change="actions.handleCoverFileChange"
	/>
</section>
<?php
do_action( 'buddynext_part_profile_edit_hero_after', $args );
