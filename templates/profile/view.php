<?php
/**
 * BuddyNext — User Profile View template.
 *
 * Renders the hero card, tab bar, and primary tab panels inside
 * `.bn-app__main`. Sidebar widgets (profile completion, social links,
 * work, education, interests, spaces) are hooked into the shell's
 * `buddynext_right_sidebar` action; when anything is hooked the shell
 * auto-renders the right column.
 *
 * Context variables expected (set by PageRouter before include):
 *   $user_id  int  The ID of the profile being viewed.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Guard: require a valid user ID.
if ( empty( $user_id ) || (int) $user_id <= 0 ) {
	return;
}

$profile_user = get_userdata( $user_id );
if ( ! $profile_user ) {
	return;
}

global $wpdb;

$current_user_id = get_current_user_id();
$is_own_profile  = ( $current_user_id === $user_id );

if ( ! $is_own_profile && ! current_user_can( 'manage_options' ) ) {
	$privacy_svc = buddynext_service( 'privacy' );
	if ( ! $privacy_svc->can_view_profile( $current_user_id, $user_id ) ) {
		?>
		<div class="bn-profile-private">
			<p><?php esc_html_e( 'This profile is private.', 'buddynext' ); ?></p>
		</div>
		<?php
		return;
	}
}

// --- Avatar & display name ------------------------------------------------
// AvatarService hooks pre_get_avatar_data: returns custom upload or SVG initials
// data-URI — no Gravatar lookup, works offline.
$avatar_url   = (string) get_avatar_url( $user_id, array( 'size' => 96 ) );
$cover_url    = (string) get_user_meta( $user_id, 'buddynext_cover_url', true );
$display_name = $profile_user->display_name;

$joined = gmdate( 'M Y', strtotime( $profile_user->user_registered ) );

// --- Stats ----------------------------------------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$follower_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE following_id = %d",
		$user_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$following_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
		$user_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$connection_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_connections
		WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'",
		$user_id,
		$user_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$post_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'published'",
		$user_id
	)
);

// --- Social graph state (viewer vs. this profile) -------------------------
$is_following        = false;
$is_connected        = false;
$connection_pending  = false;
$connection_received = false;
$is_blocked          = false;
$is_muted            = false;
$degree_badge        = '';

if ( ! $is_own_profile && $current_user_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$is_following = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_follows
			WHERE follower_id = %d AND following_id = %d",
			$current_user_id,
			$user_id
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$is_connected = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_connections
			WHERE ( ( requester_id = %d AND recipient_id = %d )
			     OR ( requester_id = %d AND recipient_id = %d ) )
			AND status = 'accepted'",
			$current_user_id,
			$user_id,
			$user_id,
			$current_user_id
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$connection_pending = ! $is_connected && (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_connections
			WHERE requester_id = %d AND recipient_id = %d AND status = 'pending'",
			$current_user_id,
			$user_id
		)
	);

	// Viewer received a request from this profile user (pending-received direction).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$connection_received = ! $is_connected && ! $connection_pending && (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_connections
			WHERE requester_id = %d AND recipient_id = %d AND status = 'pending'",
			$user_id,
			$current_user_id
		)
	);

	$degree_badge = $is_connected ? '1st' : ( $is_following ? '2nd' : '3rd+' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$is_blocked = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_blocks
			 WHERE blocker_id = %d AND blocked_id = %d AND type = 'block'
			 LIMIT 1",
			$current_user_id,
			$user_id
		)
	);

	$is_muted = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_blocks
			 WHERE blocker_id = %d AND blocked_id = %d AND type = 'mute'
			 LIMIT 1",
			$current_user_id,
			$user_id
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// --- Mutual connections count ---------------------------------------------
$mutual_count = 0;
if ( ! $is_own_profile && $current_user_id ) {
	$mutual_count = count( buddynext_service( 'connections' )->mutual_connections( $current_user_id, $user_id ) );
}

// --- Member type badge ---------------------------------------------------
$member_type = buddynext_service( 'member_types' )->get_user_type( $user_id );

// --- Profile data via ProfileService -------------------------------------
$profile_svc  = buddynext_service( 'profiles' );
$profile_data = $profile_svc->get_profile( $user_id, $current_user_id );

// Build group_key → group data lookup.
$group_data = array();
if ( is_array( $profile_data ) ) {
	foreach ( $profile_data['groups'] as $group ) {
		$group_data[ $group['group_key'] ] = $group;
	}
}

// Helper: get a single field value from a flat group.
$get_fv = static function ( string $group_key, string $field_key ) use ( $group_data ): string {
	if ( ! isset( $group_data[ $group_key ]['fields'] ) ) {
		return '';
	}
	foreach ( $group_data[ $group_key ]['fields'] as $field ) {
		if ( $field['field_key'] === $field_key ) {
			return (string) ( $field['value'] ?? '' );
		}
	}
	return '';
};

// Helper: get a field value from a repeater entry array by field_key.
$entry_fv = static function ( array $entry_fields, string $fkey ): string {
	foreach ( $entry_fields as $f ) {
		if ( $f['field_key'] === $fkey ) {
			return (string) ( $f['value'] ?? '' );
		}
	}
	return '';
};

$headline = $get_fv( 'basic_info', 'headline' );
$bio      = $get_fv( 'basic_info', 'bio' );
$location = $get_fv( 'basic_info', 'location' );
$website  = $get_fv( 'basic_info', 'website' );
$pronouns = $get_fv( 'basic_info', 'pronouns' );

// Social links: only show fields that have a value.
$social_link_fields = isset( $group_data['social_links']['fields'] ) ? $group_data['social_links']['fields'] : array();
$social_links       = array_filter(
	$social_link_fields,
	static fn( array $f ): bool => '' !== (string) ( $f['value'] ?? '' )
);

// Repeater groups — filter out blank entries so widget guards work correctly.
$work_entries = array_values(
	array_filter(
		isset( $group_data['work_experience']['entries'] ) ? $group_data['work_experience']['entries'] : array(),
		static function ( array $e ) use ( $entry_fv ): bool {
			return '' !== $entry_fv( $e, 'work_company' ) || '' !== $entry_fv( $e, 'work_title' );
		}
	)
);
$edu_entries  = array_values(
	array_filter(
		isset( $group_data['education']['entries'] ) ? $group_data['education']['entries'] : array(),
		static function ( array $e ) use ( $entry_fv ): bool {
			return '' !== $entry_fv( $e, 'edu_institution' ) || '' !== $entry_fv( $e, 'edu_degree' );
		}
	)
);

// Profile URL slug (safe — never exposes WP login).
$profile_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
if ( '' === $profile_slug ) {
	// Fall back to user_nicename (already URL-safe, matches PageRouter::profile_url()).
	$profile_slug = $profile_user instanceof WP_User ? $profile_user->user_nicename : 'user-' . $user_id;
}

// --- Recent posts (tab: Posts default) ------------------------------------
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$recent_posts = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, type, user_id, content, privacy, media_ids, reaction_count, comment_count,
		        share_count, is_pinned, is_announcement, content_warning,
		        content_warning_type, shared_post_id, link_meta, created_at
		FROM {$wpdb->prefix}bn_posts
		WHERE user_id = %d AND status = 'published'
		ORDER BY created_at DESC
		LIMIT 10",
		$user_id
	),
	ARRAY_A
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// --- Spaces the user is a member of ---------------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$member_spaces = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT s.id, s.name, sm.role
		FROM {$wpdb->prefix}bn_spaces s
		INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id
		WHERE sm.user_id = %d AND sm.status = 'active'
		ORDER BY sm.joined_at DESC
		LIMIT 5",
		$user_id
	)
);

// --- Interests — stored as comma-separated in the skills group ------------
$interests_raw = $get_fv( 'skills', 'interests' );
$interests     = array_filter( array_map( 'trim', explode( ',', $interests_raw ) ) );

// --- Profile completion (only fetched for profile owner) ------------------
$completion = null;
if ( $is_own_profile ) {
	$completion = $profile_svc->get_completion_score( $user_id );
}

// --- Online indicator -----------------------------------------------------
$last_active = (int) get_user_meta( $user_id, 'bn_last_active', true );
$is_online   = $last_active && ( time() - $last_active ) < 300;

// --- Number formatter helper ----------------------------------------------
$format_count = static function ( int $n ): string {
	if ( $n >= 1000 ) {
		return round( $n / 1000, 1 ) . 'k';
	}
	return (string) $n;
};

// --- Sidebar widget hook --------------------------------------------------
// The shell renders `templates/shell/right-sidebar.php` when anything is
// hooked on `buddynext_right_sidebar`. We register a single closure that
// emits the profile-specific widgets, captured here via `use ( ... )` so
// no globals leak.
$bn_pf_sidebar = static function () use (
	$is_own_profile,
	$completion,
	$social_links,
	$work_entries,
	$edu_entries,
	$interests,
	$member_spaces,
	$get_fv,
	$entry_fv
): void {
	// Profile completion (own profile only).
	if ( $is_own_profile && null !== $completion ) {
		$c_pct      = (int) $completion['percent'];
		$c_complete = 100 === $c_pct;
		$edit_url   = \BuddyNext\Core\PageRouter::edit_profile_url();
		?>
		<div class="bn-widget">
			<div class="bn-widget-title"><?php esc_html_e( 'Profile Strength', 'buddynext' ); ?></div>
			<div class="bn-completion-bar-wrap">
				<div class="bn-completion-header">
					<span class="bn-completion-label">
						<?php
						echo $c_complete
							? esc_html__( 'Complete!', 'buddynext' )
							: esc_html__( 'Profile completion', 'buddynext' );
						?>
					</span>
					<span class="bn-completion-pct"><?php echo esc_html( $c_pct . '%' ); ?></span>
				</div>
				<div class="bn-completion-track">
					<div class="bn-completion-fill<?php echo $c_complete ? ' bn-complete' : ''; ?>"
						style="width:<?php echo esc_attr( $c_pct . '%' ); ?>"></div>
				</div>
			</div>
			<?php if ( ! $c_complete ) : ?>
			<div class="bn-prompt-cards">
				<?php if ( '' === $get_fv( 'basic_info', 'bio' ) ) : ?>
				<a href="<?php echo esc_url( $edit_url ); ?>" class="bn-prompt-card">
					<span class="bn-prompt-card-icon"><?php buddynext_icon( 'edit' ); ?></span>
					<?php esc_html_e( 'Add a bio', 'buddynext' ); ?>
				</a>
				<?php endif; ?>
				<?php if ( empty( $work_entries ) ) : ?>
				<a href="<?php echo esc_url( $edit_url ); ?>" class="bn-prompt-card">
					<span class="bn-prompt-card-icon"><?php buddynext_icon( 'briefcase' ); ?></span>
					<?php esc_html_e( 'Add your work experience', 'buddynext' ); ?>
				</a>
				<?php endif; ?>
				<?php if ( empty( $interests ) ) : ?>
				<a href="<?php echo esc_url( $edit_url ); ?>" class="bn-prompt-card">
					<span class="bn-prompt-card-icon"><?php buddynext_icon( 'layers' ); ?></span>
					<?php esc_html_e( 'Add your skills', 'buddynext' ); ?>
				</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	if ( $social_links ) :
		?>
		<div class="bn-widget">
			<div class="bn-widget-title"><?php esc_html_e( 'Connect', 'buddynext' ); ?></div>
			<?php foreach ( $social_links as $field ) : ?>
				<div class="bn-field-row">
					<span class="bn-field-label"><?php echo esc_html( $field['label'] ); ?></span>
					<span class="bn-field-value">
						<a href="<?php echo esc_url( (string) ( $field['value'] ?? '' ) ); ?>"
							target="_blank" rel="noopener noreferrer me">
							<?php
							$parsed_host = wp_parse_url( (string) ( $field['value'] ?? '' ), PHP_URL_HOST );
							echo esc_html( $parsed_host ? $parsed_host : (string) ( $field['value'] ?? '' ) );
							?>
						</a>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	endif;

	if ( $work_entries ) :
		?>
		<div class="bn-widget">
			<div class="bn-widget-title"><?php esc_html_e( 'Work Experience', 'buddynext' ); ?></div>
			<?php foreach ( $work_entries as $entry_fields ) : ?>
				<?php
				$we_company     = $entry_fv( $entry_fields, 'work_company' );
				$we_title       = $entry_fv( $entry_fields, 'work_title' );
				$we_location    = $entry_fv( $entry_fields, 'work_location' );
				$we_daterange   = $entry_fv( $entry_fields, 'work_daterange' );
				$we_current     = $entry_fv( $entry_fields, 'work_current' );
				$we_description = $entry_fv( $entry_fields, 'work_description' );
				if ( '' === $we_company && '' === $we_title ) {
					continue;
				}
				$we_date_display = '' !== $we_daterange
					? ( '1' === $we_current
						? $we_daterange . ' &ndash; ' . esc_html__( 'Present', 'buddynext' )
						: $we_daterange )
					: ( '1' === $we_current ? esc_html__( 'Current', 'buddynext' ) : '' );
				?>
				<div class="bn-repeater-entry">
					<?php if ( $we_title ) : ?>
						<div class="bn-entry-title"><?php echo esc_html( $we_title ); ?></div>
					<?php endif; ?>
					<?php if ( $we_company ) : ?>
						<div class="bn-entry-sub"><?php echo esc_html( $we_company ); ?></div>
					<?php endif; ?>
					<?php if ( '' !== $we_location ) : ?>
						<div class="bn-entry-sub"><?php echo esc_html( $we_location ); ?></div>
					<?php endif; ?>
					<?php if ( '' !== $we_date_display ) : ?>
						<div class="bn-entry-meta"><?php echo wp_kses( $we_date_display, array() ); ?></div>
					<?php endif; ?>
					<?php if ( $we_description ) : ?>
						<div class="bn-entry-desc"><?php echo wp_kses_post( $we_description ); ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	endif;

	if ( $edu_entries ) :
		?>
		<div class="bn-widget">
			<div class="bn-widget-title"><?php esc_html_e( 'Education', 'buddynext' ); ?></div>
			<?php foreach ( $edu_entries as $entry_fields ) : ?>
				<?php
				$edu_institution = $entry_fv( $entry_fields, 'edu_institution' );
				$edu_degree      = $entry_fv( $entry_fields, 'edu_degree' );
				$edu_field_study = $entry_fv( $entry_fields, 'edu_field' );
				$edu_daterange   = $entry_fv( $entry_fields, 'edu_daterange' );
				$edu_current     = $entry_fv( $entry_fields, 'edu_current' );
				if ( '' === $edu_institution ) {
					continue;
				}
				$edu_degree_line  = implode( ', ', array_filter( array( $edu_degree, $edu_field_study ) ) );
				$edu_date_display = '' !== $edu_daterange
					? ( '1' === $edu_current
						? $edu_daterange . ' &ndash; ' . esc_html__( 'Present', 'buddynext' )
						: $edu_daterange )
					: ( '1' === $edu_current ? esc_html__( 'Current', 'buddynext' ) : '' );
				?>
				<div class="bn-repeater-entry">
					<div class="bn-entry-title"><?php echo esc_html( $edu_institution ); ?></div>
					<?php if ( $edu_degree_line ) : ?>
						<div class="bn-entry-sub"><?php echo esc_html( $edu_degree_line ); ?></div>
					<?php endif; ?>
					<?php if ( '' !== $edu_date_display ) : ?>
						<div class="bn-entry-meta"><?php echo wp_kses( $edu_date_display, array() ); ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	endif;

	if ( $interests ) :
		?>
		<div class="bn-widget">
			<div class="bn-widget-title"><?php esc_html_e( 'Interests', 'buddynext' ); ?></div>
			<div class="bn-skill-chips">
				<?php foreach ( $interests as $interest ) : ?>
					<span class="bn-skill-chip"><?php echo esc_html( $interest ); ?></span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	endif;

	if ( $member_spaces ) :
		?>
		<div class="bn-widget">
			<div class="bn-widget-title"><?php esc_html_e( 'Member of', 'buddynext' ); ?></div>
			<?php foreach ( $member_spaces as $space ) : ?>
				<div class="bn-space-row">
					<div class="bn-space-icon">
						<?php buddynext_icon( 'home' ); ?>
					</div>
					<div>
						<div class="bn-space-name"><?php echo esc_html( $space->name ); ?></div>
						<div class="bn-space-role"><?php echo esc_html( ucfirst( (string) $space->role ) ); ?></div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	endif;
};

add_action( 'buddynext_right_sidebar', $bn_pf_sidebar );

/**
 * Fires before the profile main content. Allows host plugins to inject
 * banners or alerts above the hero card.
 *
 * @param int $user_id ID of the profile being viewed.
 */
do_action( 'buddynext_profile_before', (int) $user_id );

// Owner action bar: rendered above the hero when the viewer can edit.
if ( $is_own_profile || current_user_can( 'edit_users' ) ) {
	buddynext_get_template(
		'partials/profile-actions.php',
		array(
			'user_id'        => (int) $user_id,
			'is_own_profile' => (bool) $is_own_profile,
		)
	);
}
?>

<div class="bn-pf-stack"
	data-wp-interactive="buddynext/profile"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'userId'             => $user_id,
			'profileUserId'      => $user_id,
			'activeTab'          => 'posts',
			'isFollowing'        => $is_following,
			'isConnected'        => $is_connected,
			'connectionPending'  => $connection_pending,
			'connectionReceived' => $connection_received,
			'showConnect'        => ! $is_connected && ! $connection_pending && ! $connection_received,
			'followerCount'      => $follower_count,
			'restNonce'          => wp_create_nonce( 'wp_rest' ),
			'isBlocked'          => $is_blocked,
			'isMuted'            => $is_muted,
			'moreMenuOpen'       => false,
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>

	<!-- Hero card: cover + identity + stats -->
	<section class="bn-pf-hero bn-card">
		<!-- Cover -->
		<div class="bn-pf-cover<?php echo '' !== $cover_url ? ' bn-pf-cover--has-image' : ''; ?>"
			<?php if ( '' !== $cover_url ) : ?>
			style="background-image:url('<?php echo esc_url( $cover_url ); ?>');"<?php endif; ?>>
			<?php if ( $is_own_profile ) : ?>
				<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>"
					class="bn-pf-cover__edit"
					aria-label="<?php esc_attr_e( 'Edit cover photo', 'buddynext' ); ?>">
					<?php buddynext_icon( 'edit' ); ?>
					<span><?php esc_html_e( 'Edit cover', 'buddynext' ); ?></span>
				</a>
			<?php endif; ?>
		</div>

		<!-- Identity head: avatar + id block + actions -->
		<div class="bn-pf-head">

			<!-- Avatar -->
			<div class="bn-pf-avatar-wrap">
				<span class="bn-avatar"
					data-size="2xl"
					<?php echo $is_online ? 'data-presence="online"' : ''; ?>
				>
					<img src="<?php echo esc_url( $avatar_url ); ?>"
						alt="<?php echo esc_attr( $display_name ); ?>"
						width="96"
						height="96"
						loading="eager"
						decoding="async"
					/>
				</span>
			</div>

			<!-- Identity block -->
			<div class="bn-pf-id">
				<div class="bn-pf-name-row">
					<h1 class="bn-pf-name"><?php echo esc_html( $display_name ); ?></h1>
					<?php if ( $degree_badge ) : ?>
						<span class="bn-badge" data-tone="accent"><?php echo esc_html( $degree_badge ); ?></span>
					<?php endif; ?>
					<?php if ( $member_type ) : ?>
						<span
							class="bn-badge bn-pf-type-badge"
							data-tone="accent"
							style="background:<?php echo esc_attr( $member_type['color'] ); ?>;color:<?php echo esc_attr( $member_type['text_color'] ); ?>;"
						><?php echo esc_html( $member_type['name'] ); ?></span>
					<?php endif; ?>
				</div>

				<div class="bn-pf-handle">
					@<?php echo esc_html( '' !== $profile_slug ? $profile_slug : 'user-' . $user_id ); ?>
					<?php if ( $pronouns ) : ?>
						<span class="bn-pf-pronouns">(<?php echo esc_html( $pronouns ); ?>)</span>
					<?php endif; ?>
					<?php if ( $headline ) : ?>
						<span class="bn-pf-headline-sep" aria-hidden="true">&middot;</span>
						<span class="bn-pf-headline"><?php echo esc_html( $headline ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( $bio ) : ?>
					<div class="bn-pf-bio"><?php echo wp_kses_post( $bio ); ?></div>
				<?php endif; ?>

				<div class="bn-pf-meta">
					<?php if ( $location ) : ?>
						<span class="bn-pf-meta__item">
							<?php buddynext_icon( 'map-pin' ); ?>
							<span><?php echo esc_html( $location ); ?></span>
						</span>
					<?php endif; ?>
					<?php if ( $website ) : ?>
						<span class="bn-pf-meta__item">
							<?php buddynext_icon( 'link' ); ?>
							<a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer">
								<?php
								$parsed_host = wp_parse_url( $website, PHP_URL_HOST );
								echo esc_html( $parsed_host ? $parsed_host : $website );
								?>
							</a>
						</span>
					<?php endif; ?>
					<span class="bn-pf-meta__item">
						<?php buddynext_icon( 'calendar' ); ?>
						<span>
						<?php
						/* translators: %s: month and year the member joined */
						echo esc_html( sprintf( __( 'Joined %s', 'buddynext' ), $joined ) );
						?>
						</span>
					</span>
					<?php if ( $mutual_count > 0 ) : ?>
						<span class="bn-pf-meta__item">
							<?php buddynext_icon( 'users' ); ?>
							<span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of mutual connections */
									_n( '%d mutual connection', '%d mutual connections', $mutual_count, 'buddynext' ),
									$mutual_count
								)
							);
							?>
							</span>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<!-- Action buttons — shown for other users only; owners use the action bar above -->
			<?php if ( ! $is_own_profile && $current_user_id ) : ?>
			<div class="bn-pf-actions">
				<button class="bn-btn" data-variant="primary" data-size="sm"
					data-wp-on--click="actions.follow"
					data-wp-bind--hidden="context.isFollowing"
					<?php echo $is_following ? 'hidden' : ''; ?>>
					<?php esc_html_e( 'Follow', 'buddynext' ); ?>
				</button>
				<button class="bn-btn" data-variant="secondary" data-size="sm"
					data-wp-on--click="actions.unfollow"
					data-wp-bind--hidden="!context.isFollowing"
					<?php echo $is_following ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Following', 'buddynext' ); ?>
				</button>

				<button class="bn-btn" data-variant="secondary" data-size="sm"
					data-wp-on--click="actions.connect"
					data-wp-bind--hidden="!context.showConnect"
					<?php echo ( $is_connected || $connection_pending || $connection_received ) ? 'hidden' : ''; ?>>
					<?php esc_html_e( 'Connect', 'buddynext' ); ?>
				</button>
				<button class="bn-btn" data-variant="secondary" data-size="sm"
					data-wp-on--click="actions.withdrawRequest"
					data-wp-bind--hidden="!context.connectionPending"
					<?php echo $connection_pending ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Pending', 'buddynext' ); ?>
				</button>
				<span class="bn-pf-actions__group"
					data-wp-bind--hidden="!context.connectionReceived"
					<?php echo $connection_received ? '' : 'hidden'; ?>>
					<button class="bn-btn" data-variant="primary" data-size="sm"
						data-wp-on--click="actions.acceptRequest">
						<?php esc_html_e( 'Accept', 'buddynext' ); ?>
					</button>
					<button class="bn-btn" data-variant="ghost" data-size="sm"
						data-wp-on--click="actions.declineRequest">
						<?php esc_html_e( 'Decline', 'buddynext' ); ?>
					</button>
				</span>
				<button class="bn-btn" data-variant="secondary" data-size="sm"
					data-wp-on--click="actions.disconnectUser"
					data-wp-bind--hidden="!context.isConnected"
					<?php echo $is_connected ? '' : 'hidden'; ?>>
					<?php buddynext_icon( 'check' ); ?>
					<span><?php esc_html_e( 'Connected', 'buddynext' ); ?></span>
				</button>

				<a href="<?php echo esc_url( add_query_arg( 'with', $user_id, \BuddyNext\Core\PageRouter::messages_url() ) ); ?>"
					class="bn-btn" data-variant="secondary" data-size="sm">
					<?php buddynext_icon( 'message-circle' ); ?>
					<span><?php esc_html_e( 'Message', 'buddynext' ); ?></span>
				</a>

				<!-- More options dropdown -->
				<div class="bn-more-menu-wrap" data-wp-class--is-open="context.moreMenuOpen">
					<button class="bn-btn bn-pf-more-trigger"
						data-variant="ghost"
						data-size="sm"
						aria-label="<?php esc_attr_e( 'More options', 'buddynext' ); ?>"
						aria-expanded="false"
						data-wp-on--click="actions.toggleMoreMenu"
						data-wp-bind--aria-expanded="context.moreMenuOpen"><?php buddynext_icon( 'more-horizontal' ); ?></button>
					<div class="bn-more-menu" role="menu">
						<button class="bn-more-menu-item"
							role="menuitem"
							data-wp-on--click="actions.toggleMute"
							data-wp-text="state.muteLabel">
							<?php esc_html_e( 'Mute', 'buddynext' ); ?>
						</button>
						<button class="bn-more-menu-item bn-more-menu-item--danger"
							role="menuitem"
							data-wp-on--click="actions.toggleBlock"
							data-wp-text="state.blockLabel">
							<?php esc_html_e( 'Block', 'buddynext' ); ?>
						</button>
						<button class="bn-more-menu-item"
							role="menuitem"
							data-wp-on--click="actions.reportUser">
							<?php esc_html_e( 'Report', 'buddynext' ); ?>
						</button>
					</div>
				</div>
			</div>
			<?php endif; ?>

		</div><!-- /.bn-pf-head -->

		<!-- Stats strip -->
		<div class="bn-pf-stats">
			<div class="bn-pf-stat">
				<div class="bn-pf-stat__value"><?php echo esc_html( $format_count( $post_count ) ); ?></div>
				<div class="bn-pf-stat__label"><?php esc_html_e( 'Posts', 'buddynext' ); ?></div>
			</div>
			<div class="bn-pf-stat">
				<div class="bn-pf-stat__value" data-wp-text="context.followerCount"><?php echo esc_html( $format_count( $follower_count ) ); ?></div>
				<div class="bn-pf-stat__label"><?php esc_html_e( 'Followers', 'buddynext' ); ?></div>
			</div>
			<div class="bn-pf-stat">
				<div class="bn-pf-stat__value"><?php echo esc_html( $format_count( $following_count ) ); ?></div>
				<div class="bn-pf-stat__label"><?php esc_html_e( 'Following', 'buddynext' ); ?></div>
			</div>
			<div class="bn-pf-stat">
				<div class="bn-pf-stat__value"><?php echo esc_html( $format_count( $connection_count ) ); ?></div>
				<div class="bn-pf-stat__label"><?php esc_html_e( 'Connections', 'buddynext' ); ?></div>
			</div>
			<?php
			/**
			 * Extra stat blocks injected by bridge plugins (e.g. Jetonomy discussion count,
			 * WBGamification points). Each entry must be an array with 'label' and 'value' keys.
			 *
			 * @param array[] $extra           Array of ['label' => string, 'value' => string|int].
			 * @param int     $user_id         ID of the profile being viewed.
			 */
			$bn_extra_stats = apply_filters( 'buddynext_profile_extra_data', array(), (int) $user_id );
			foreach ( $bn_extra_stats as $bn_extra_stat ) :
				if ( empty( $bn_extra_stat['label'] ) || ! isset( $bn_extra_stat['value'] ) ) {
					continue;
				}
				?>
				<div class="bn-pf-stat">
					<div class="bn-pf-stat__value"><?php echo esc_html( (string) $bn_extra_stat['value'] ); ?></div>
					<div class="bn-pf-stat__label"><?php echo esc_html( $bn_extra_stat['label'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

	</section><!-- /.bn-pf-hero -->

	<!-- Tab bar (v2 .bn-tabs primitive) -->
	<div class="bn-tabs bn-pf-tabs" role="tablist">
		<button class="bn-tab"
			role="tab"
			type="button"
			aria-selected="true"
			data-wp-on--click="actions.setTab"
			data-tab="posts">
			<?php esc_html_e( 'Posts', 'buddynext' ); ?>
			<span class="bn-tab__count"><?php echo esc_html( $format_count( $post_count ) ); ?></span>
		</button>
		<button class="bn-tab"
			role="tab"
			type="button"
			aria-selected="false"
			data-wp-on--click="actions.setTab"
			data-tab="replies">
			<?php esc_html_e( 'Replies', 'buddynext' ); ?>
		</button>
		<button class="bn-tab"
			role="tab"
			type="button"
			aria-selected="false"
			data-wp-on--click="actions.setTab"
			data-tab="media">
			<?php esc_html_e( 'Media', 'buddynext' ); ?>
		</button>
		<button class="bn-tab"
			role="tab"
			type="button"
			aria-selected="false"
			data-wp-on--click="actions.setTab"
			data-tab="likes">
			<?php esc_html_e( 'Likes', 'buddynext' ); ?>
		</button>
		<?php if ( class_exists( 'Jetonomy\Jetonomy' ) ) : ?>
		<button class="bn-tab"
			role="tab"
			type="button"
			aria-selected="false"
			data-wp-on--click="actions.setTab"
			data-tab="discussions">
			<?php esc_html_e( 'Discussions', 'buddynext' ); ?>
		</button>
		<?php endif; ?>
	</div>

	<!-- Tab panels container -->
	<div class="bn-pf-tab-content">

		<!-- Posts list (default tab) -->
		<div class="bn-profile-posts-panel" data-tab-panel="posts">
		<?php if ( $recent_posts ) : ?>
			<?php
			foreach ( $recent_posts as $post_arr ) {
				// Decode media_ids JSON string for the post-card partial.
				if ( isset( $post_arr['media_ids'] ) && is_string( $post_arr['media_ids'] ) ) {
					$post_arr['media_ids'] = json_decode( $post_arr['media_ids'], true );
				}
				buddynext_get_template(
					'partials/post-card.php',
					array(
						'post'            => $post_arr,
						'current_user_id' => $current_user_id,
						'context'         => 'profile',
					)
				);
			}
			?>
		<?php else : ?>
			<div class="bn-empty-state">
				<?php
				echo esc_html(
					$is_own_profile
						? __( 'You have not posted anything yet.', 'buddynext' )
						: sprintf(
							/* translators: %s: member display name */
							__( '%s has not posted anything yet.', 'buddynext' ),
							$display_name
						)
				);
				?>
			</div>
		<?php endif; ?>
		</div><!-- /.bn-profile-posts-panel -->

		<!-- Replies tab content -->
		<?php
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_replies = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.content, c.created_at, c.object_id,
				        p.content AS post_content, p.type AS post_type,
				        u.display_name AS post_author_name
				 FROM {$wpdb->prefix}bn_comments c
				 INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = c.object_id AND c.object_type = 'post'
				 INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
				 WHERE c.user_id = %d
				 ORDER BY c.created_at DESC
				 LIMIT 20",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		?>
		<div class="bn-profile-tab-panel" data-tab-panel="replies" hidden>
			<?php if ( $user_replies ) : ?>
				<?php foreach ( $user_replies as $reply ) : ?>
				<div class="bn-reply-card">
					<div class="bn-reply-card__meta">
						<?php buddynext_icon( 'message-circle' ); ?>
						<span><?php echo esc_html( sprintf( /* translators: %s: author name */ __( 'Replied to %s', 'buddynext' ), $reply->post_author_name ) ); ?></span>
						<span class="bn-reply-card__time"><?php echo esc_html( human_time_diff( strtotime( $reply->created_at ) ) . ' ' . __( 'ago', 'buddynext' ) ); ?></span>
					</div>
					<div class="bn-reply-card__content"><?php echo wp_kses_post( wp_trim_words( $reply->content, 30 ) ); ?></div>
					<div class="bn-reply-card__context"><?php echo wp_kses_post( wp_trim_words( $reply->post_content, 15 ) ); ?></div>
				</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="bn-empty-state"><?php esc_html_e( 'No replies yet.', 'buddynext' ); ?></div>
			<?php endif; ?>
		</div>

		<!-- Media tab content -->
		<?php
		$user_media = array();
		if ( class_exists( 'WPMediaVerse\Core\Plugin' ) && post_type_exists( 'mvs_media' ) ) {
			$user_media = get_posts(
				array(
					'post_type'   => 'mvs_media',
					'author'      => $user_id,
					'numberposts' => 24,
					'post_status' => 'publish',
				)
			);
		}
		?>
		<div class="bn-profile-tab-panel" data-tab-panel="media" hidden>
			<?php if ( $user_media ) : ?>
				<div class="bn-profile-media-grid mvs-activity-media-grid">
					<?php foreach ( $user_media as $media_post ) : ?>
						<?php
						$thumb_url = get_post_meta( $media_post->ID, '_mvs_file_url', true );
						if ( ! $thumb_url ) {
							$thumb_url = wp_get_attachment_image_url( $media_post->ID, 'medium' );
						}
						if ( ! $thumb_url ) {
							$thumb_url = wp_get_attachment_url( $media_post->ID );
						}
						$full_url   = wp_get_attachment_url( $media_post->ID );
						$media_type = (string) get_post_meta( $media_post->ID, '_mvs_media_type', true );
						if ( '' === $media_type ) {
							$media_type = 'image';
						}
						?>
						<div class="bn-profile-media-item mvs-activity-media" data-mvs-media-id="<?php echo esc_attr( (string) $media_post->ID ); ?>" data-mvs-src="<?php echo esc_url( (string) $full_url ); ?>">
							<?php if ( $thumb_url && 'image' === $media_type ) : ?>
								<a href="<?php echo esc_url( (string) $full_url ); ?>" class="mvs-grid-item-link">
									<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $media_post->post_title ); ?>" loading="lazy">
								</a>
							<?php elseif ( 'video' === $media_type ) : ?>
								<div class="bn-profile-media-video"><?php buddynext_icon( 'play-circle' ); ?></div>
							<?php else : ?>
								<div class="bn-profile-media-placeholder"><?php buddynext_icon( 'camera' ); ?></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="bn-empty-state"><?php esc_html_e( 'No media uploaded yet.', 'buddynext' ); ?></div>
			<?php endif; ?>
		</div>

		<!-- Likes tab content -->
		<?php
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_likes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.emoji, r.created_at, r.object_id,
				        p.content, p.type, p.user_id AS post_author_id,
				        u.display_name AS post_author_name
				 FROM {$wpdb->prefix}bn_reactions r
				 INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = r.object_id AND r.object_type = 'post'
				 INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
				 WHERE r.user_id = %d
				 ORDER BY r.created_at DESC
				 LIMIT 20",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		?>
		<div class="bn-profile-tab-panel" data-tab-panel="likes" hidden>
			<?php if ( $user_likes ) : ?>
				<?php foreach ( $user_likes as $liked ) : ?>
				<div class="bn-like-card">
					<div class="bn-like-card__meta">
						<?php buddynext_icon( 'heart' ); ?>
						<span><?php echo esc_html( sprintf( /* translators: %s: author name */ __( 'Liked %s\'s post', 'buddynext' ), $liked->post_author_name ) ); ?></span>
						<span class="bn-like-card__time"><?php echo esc_html( human_time_diff( strtotime( $liked->created_at ) ) . ' ' . __( 'ago', 'buddynext' ) ); ?></span>
					</div>
					<div class="bn-like-card__content"><?php echo wp_kses_post( wp_trim_words( $liked->content, 30 ) ); ?></div>
				</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="bn-empty-state"><?php esc_html_e( 'No liked posts yet.', 'buddynext' ); ?></div>
			<?php endif; ?>
		</div>

		<!-- Discussions tab content (Jetonomy) -->
		<?php if ( class_exists( 'Jetonomy\Models\Post' ) ) : ?>
			<?php
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$jt_discussions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.id, p.title, p.slug, p.reply_count, p.vote_score, p.created_at,
				        s.title AS space_name, s.slug AS space_slug
				 FROM {$wpdb->prefix}jt_posts p
				 LEFT JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id
				 WHERE p.author_id = %d AND p.status = 'publish'
				 ORDER BY p.created_at DESC
				 LIMIT 20",
					$user_id
				)
			);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			?>
		<div class="bn-profile-tab-panel" data-tab-panel="discussions" hidden>
			<?php if ( $jt_discussions ) : ?>
				<?php
				foreach ( $jt_discussions as $disc ) :
					$disc_space_slug = '' !== (string) $disc->space_slug ? (string) $disc->space_slug : 'general';
					$disc_space_name = '' !== (string) $disc->space_name ? (string) $disc->space_name : __( 'General', 'buddynext' );
					?>
				<a href="<?php echo esc_url( home_url( '/community/s/' . $disc_space_slug . '/t/' . $disc->slug . '/' ) ); ?>" class="bn-reply-card bn-reply-card--link">
					<div class="bn-reply-card__meta">
						<?php buddynext_icon( 'message-circle' ); ?>
						<span><?php echo esc_html( $disc_space_name ); ?></span>
						<span class="bn-reply-card__time"><?php echo esc_html( human_time_diff( strtotime( $disc->created_at ) ) . ' ' . __( 'ago', 'buddynext' ) ); ?></span>
					</div>
					<div class="bn-reply-card__content bn-reply-card__content--strong"><?php echo esc_html( $disc->title ); ?></div>
					<div class="bn-reply-card__context">
						<?php echo esc_html( (string) $disc->reply_count ); ?> <?php esc_html_e( 'replies', 'buddynext' ); ?>
						<span aria-hidden="true">&middot;</span>
						<?php echo esc_html( (string) $disc->vote_score ); ?> <?php esc_html_e( 'votes', 'buddynext' ); ?>
					</div>
				</a>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="bn-empty-state"><?php esc_html_e( 'No discussions yet.', 'buddynext' ); ?></div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

	</div><!-- /.bn-pf-tab-content -->

</div><!-- /.bn-pf-stack -->

<?php
/**
 * Fires after the profile main content.
 *
 * @param int $user_id ID of the profile being viewed.
 */
do_action( 'buddynext_profile_after', (int) $user_id );
