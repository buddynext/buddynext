<?php
/**
 * Member Directory template (v2 design system).
 *
 * Displays a searchable, filterable grid of WordPress users with
 * social-graph context (follow status, mutual connections, online status).
 *
 * Renders against the v2 attribute API (.bn-card[data-interactive],
 * .bn-btn[data-variant], .bn-input, .bn-badge[data-tone],
 * .bn-avatar[data-size][data-presence], .bn-tabs/.bn-tab). All visual
 * styling lives in assets/css/bn-members.css using v2 tokens — no inline
 * <style> blocks, no raw hex/px values in this template.
 *
 * Variables expected from the rendering context:
 *   (none required — all data fetched internally)
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// ── Query parameters ──────────────────────────────────────────────────────────
$bn_current_page = max( 1, absint( get_query_var( 'paged', 1 ) ) );
$bn_per_page     = 20;
$search_term     = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );       // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$orderby_raw     = sanitize_key( $_GET['orderby'] ?? 'registered' );             // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$relation_raw    = sanitize_key( $_GET['relation'] ?? 'all' );                   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
// Accept type slug from the pretty URL rewrite (/members/{slug}/) or a ?type= query arg.
$type_slug_filter = sanitize_key( (string) get_query_var( 'bn_member_type', '' ) );
if ( '' === $type_slug_filter ) {
	$type_slug_filter = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}
$allowed_sort = array( 'registered', 'display_name', 'post_count' );
$bn_orderby   = in_array( $orderby_raw, $allowed_sort, true ) ? $orderby_raw : 'registered';
$bn_order     = 'registered' === $bn_orderby ? 'DESC' : 'ASC';

$allowed_relations = array( 'all', 'following', 'connections' );
$bn_relation       = in_array( $relation_raw, $allowed_relations, true ) ? $relation_raw : 'all';

// ── Current user context ──────────────────────────────────────────────────────
$current_user_id = get_current_user_id();

// ── Member types for directory pill tabs and card badges ──────────────────────
$all_types_raw = buddynext_service( 'member_types' )->get_all();
$dir_types     = array_values( array_filter( $all_types_raw, static fn( $t ) => ! empty( $t['show_in_dir'] ) ) );
// Flat slug → type data map for O(1) card badge lookup inside the member loop.
$type_map = array();
foreach ( $all_types_raw as $t ) {
	$type_map[ (string) $t['slug'] ] = $t;
}
unset( $all_types_raw, $t );

// ── Fetch users ───────────────────────────────────────────────────────────────

// Resolve user IDs to exclude: active suspensions + shadow-banned users.
global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$bn_dir_suspended_ids = $wpdb->get_col(
	"SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions
	 WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())"
);

$bn_dir_shadow_banned_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT user_id FROM {$wpdb->usermeta}
		 WHERE meta_key = %s AND meta_value = '1'",
		'bn_shadow_banned'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$bn_dir_excluded_ids = array_unique(
	array_map( 'intval', array_merge( $bn_dir_suspended_ids, $bn_dir_shadow_banned_ids ) )
);

$user_query_args = array(
	'number'      => $bn_per_page,
	'paged'       => $bn_current_page,
	'orderby'     => $bn_orderby,
	'order'       => $bn_order,
	'fields'      => 'all',
	'count_total' => true,
);

if ( ! empty( $bn_dir_excluded_ids ) ) {
	$user_query_args['exclude'] = $bn_dir_excluded_ids;
}

if ( '' !== $search_term ) {
	$user_query_args['search']         = '*' . $search_term . '*';
	$user_query_args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email' );
}

// Relation filter (Following / Connections) — only relevant when logged in.
if ( $current_user_id > 0 && 'all' !== $bn_relation ) {
	if ( 'following' === $bn_relation ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$relation_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT following_id FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
				$current_user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		// connections() returns a flat list of accepted peer user IDs.
		$relation_ids = buddynext_service( 'connections' )->connections( $current_user_id, 500, 0 );
	}
	$relation_ids = array_map( 'intval', (array) $relation_ids );
	if ( empty( $relation_ids ) ) {
		// Force zero results when the relation set is empty.
		$user_query_args['include'] = array( 0 );
	} else {
		$user_query_args['include'] = $relation_ids;
	}
}

// Filter by member type via denormalised usermeta (write-through cache — no JOIN needed).
if ( '' !== $type_slug_filter ) {
	$user_query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		array(
			'key'     => 'bn_member_type',
			'value'   => $type_slug_filter,
			'compare' => '=',
		),
	);
}

$user_query  = new WP_User_Query( $user_query_args );
$members     = $user_query->get_results();
$total_users = (int) $user_query->get_total();
$total_pages = (int) ceil( $total_users / $bn_per_page );

// ── Helper: online status (last_active within 5 minutes) ─────────────────────
$online_threshold = time() - 300;

/**
 * Determine if a user is considered online.
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
$bn_is_online = static function ( int $user_id ) use ( $online_threshold ): bool {
	$last_active = (int) get_user_meta( $user_id, 'bn_last_active', true );
	return $last_active >= $online_threshold;
};

/**
 * Return the two-character initials for a display name.
 *
 * @param string $name Display name.
 * @return string
 */
$bn_initials = static function ( string $name ): string {
	$parts = array_filter( explode( ' ', $name ) );
	if ( count( $parts ) >= 2 ) {
		return mb_strtoupper( mb_substr( (string) reset( $parts ), 0, 1 ) . mb_substr( (string) end( $parts ), 0, 1 ) );
	}
	return mb_strtoupper( mb_substr( $name, 0, 2 ) );
};

/**
 * Return the number of mutual connections between two users.
 *
 * @param int $user_a First user ID.
 * @param int $user_b Second user ID.
 * @return int
 */
$bn_mutual_count = static function ( int $user_a, int $user_b ): int {
	if ( 0 === $user_a || 0 === $user_b || $user_a === $user_b ) {
		return 0;
	}
	return count( buddynext_service( 'connections' )->mutual_connections( $user_a, $user_b ) );
};

/**
 * Check whether the current user follows a given user.
 *
 * @param int $target_user_id User ID to check.
 * @return bool
 */
$bn_is_following = static function ( int $target_user_id ) use ( $current_user_id ): bool {
	if ( 0 === $current_user_id ) {
		return false;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'bn_follows';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE follower_id = %d AND following_id = %d LIMIT 1",
			$current_user_id,
			$target_user_id
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return '1' === (string) $exists;
};

/**
 * Build a pagination URL preserving current query args.
 *
 * @param int $page_number Target page number.
 * @return string Escaped URL.
 */
$bn_paged_url = static function ( int $page_number ) use ( $search_term, $bn_orderby, $bn_relation ): string {
	$args = array( 'paged' => $page_number );
	if ( '' !== $search_term ) {
		$args['s'] = $search_term;
	}
	if ( 'registered' !== $bn_orderby ) {
		$args['orderby'] = $bn_orderby;
	}
	if ( 'all' !== $bn_relation ) {
		$args['relation'] = $bn_relation;
	}
	return esc_url( add_query_arg( $args ) );
};

/**
 * Build a relation-tab URL.
 *
 * @param string $relation Relation slug.
 * @return string Escaped URL.
 */
$bn_relation_url = static function ( string $relation ) use ( $search_term, $bn_orderby ): string {
	$args = array();
	if ( 'all' !== $relation ) {
		$args['relation'] = $relation;
	}
	if ( '' !== $search_term ) {
		$args['s'] = $search_term;
	}
	if ( 'registered' !== $bn_orderby ) {
		$args['orderby'] = $bn_orderby;
	}
	return esc_url( add_query_arg( $args, remove_query_arg( array( 'relation', 'paged' ) ) ) );
};

// ── Page URLs (hoisted — do not call inside member loop) ─────────────────────
$bn_messages_base = \BuddyNext\Core\PageRouter::messages_url();

// PageRouter resolves /profile/{slug}/ pretty URLs for each member card.

// ── Nonce for interactive actions ─────────────────────────────────────────────
$action_nonce = wp_create_nonce( 'bn_member_action' );

// Avatar tone palette — cycles deterministically by user ID.
$bn_avatar_tones = array( 'accent', 'success', 'jetonomy', 'media', 'events', 'warn', 'danger', 'info' );

$bn_directory_context = wp_json_encode(
	array(
		'search'   => $search_term,
		'orderby'  => $bn_orderby,
		'relation' => $bn_relation,
		'nonce'    => $action_nonce,
		'restUrl'  => esc_url_raw( rest_url( 'buddynext/v1/members' ) ),
	)
);
if ( false === $bn_directory_context ) {
	$bn_directory_context = '{}';
}
?>
<?php
$bn_nav_active = 'members';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div
	class="bn-member-directory"
	data-wp-interactive="buddynext/members"
	data-wp-context="<?php echo esc_attr( (string) $bn_directory_context ); ?>"
>

<div class="bn-hub-shell">
<div class="bn-hub-content">

	<header class="bn-md-header">
		<div>
			<h1 class="bn-md-title"><?php esc_html_e( 'Member Directory', 'buddynext' ); ?></h1>
			<p class="bn-md-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: formatted number of community members */
						__( '%s members in the community', 'buddynext' ),
						number_format_i18n( $total_users )
					)
				);
				?>
			</p>
		</div>
		<?php if ( $current_user_id > 0 ) : ?>
			<a
				class="bn-btn"
				data-variant="secondary"
				data-size="md"
				href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"
			>
				<?php buddynext_icon( 'edit' ); ?>
				<span><?php esc_html_e( 'Edit profile', 'buddynext' ); ?></span>
			</a>
		<?php endif; ?>
	</header>

	<?php if ( $current_user_id > 0 ) : ?>
	<nav class="bn-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Member filter', 'buddynext' ); ?>">
		<a
			class="bn-tab"
			href="<?php echo esc_url( $bn_relation_url( 'all' ) ); ?>"
			role="tab"
			aria-selected="<?php echo 'all' === $bn_relation ? 'true' : 'false'; ?>"
		>
			<?php esc_html_e( 'All members', 'buddynext' ); ?>
		</a>
		<a
			class="bn-tab"
			href="<?php echo esc_url( $bn_relation_url( 'following' ) ); ?>"
			role="tab"
			aria-selected="<?php echo 'following' === $bn_relation ? 'true' : 'false'; ?>"
		>
			<?php esc_html_e( 'Following', 'buddynext' ); ?>
		</a>
		<a
			class="bn-tab"
			href="<?php echo esc_url( $bn_relation_url( 'connections' ) ); ?>"
			role="tab"
			aria-selected="<?php echo 'connections' === $bn_relation ? 'true' : 'false'; ?>"
		>
			<?php esc_html_e( 'Connections', 'buddynext' ); ?>
		</a>
	</nav>
	<?php endif; ?>

	<form class="bn-md-filters" method="get" action="" role="search" aria-label="<?php esc_attr_e( 'Filter members', 'buddynext' ); ?>">
		<div class="bn-md-search">
			<label class="screen-reader-text" for="bn-md-search-input">
				<?php esc_html_e( 'Search members', 'buddynext' ); ?>
			</label>
			<span class="bn-md-search__icon" aria-hidden="true">
				<?php buddynext_icon( 'search' ); ?>
			</span>
			<input
				id="bn-md-search-input"
				class="bn-input bn-md-search__input"
				type="search"
				name="s"
				value="<?php echo esc_attr( $search_term ); ?>"
				placeholder="<?php esc_attr_e( 'Search by name, skills, location...', 'buddynext' ); ?>"
			>
		</div>

		<label class="screen-reader-text" for="bn-md-sort"><?php esc_html_e( 'Sort members', 'buddynext' ); ?></label>
		<select id="bn-md-sort" class="bn-select bn-md-filters__sort" name="orderby">
			<option value="registered" <?php selected( $bn_orderby, 'registered' ); ?>><?php esc_html_e( 'Newest first', 'buddynext' ); ?></option>
			<option value="display_name" <?php selected( $bn_orderby, 'display_name' ); ?>><?php esc_html_e( 'Alphabetical', 'buddynext' ); ?></option>
			<option value="post_count" <?php selected( $bn_orderby, 'post_count' ); ?>><?php esc_html_e( 'Most active', 'buddynext' ); ?></option>
		</select>

		<?php if ( 'all' !== $bn_relation ) : ?>
			<input type="hidden" name="relation" value="<?php echo esc_attr( $bn_relation ); ?>">
		<?php endif; ?>

		<div class="bn-md-filters__view" role="group" aria-label="<?php esc_attr_e( 'View layout', 'buddynext' ); ?>">
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-size="sm"
				data-view="grid"
				aria-label="<?php esc_attr_e( 'Grid view', 'buddynext' ); ?>"
				data-wp-on--click="actions.setGridView"
				data-wp-bind--aria-pressed="state.isGridPressed"
			>
				<?php buddynext_icon( 'grid' ); ?>
			</button>
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-size="sm"
				data-view="list"
				aria-label="<?php esc_attr_e( 'List view', 'buddynext' ); ?>"
				data-wp-on--click="actions.setListView"
				data-wp-bind--aria-pressed="state.isListPressed"
			>
				<?php buddynext_icon( 'list' ); ?>
			</button>
		</div>

		<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
			<?php esc_html_e( 'Apply', 'buddynext' ); ?>
		</button>
	</form>

	<?php if ( ! empty( $dir_types ) ) : ?>
	<div class="bn-md-types" role="navigation" aria-label="<?php esc_attr_e( 'Filter by member type', 'buddynext' ); ?>">
		<a
			href="<?php echo esc_url( \BuddyNext\Core\PageRouter::people_url() ); ?>"
			class="bn-badge bn-md-type-pill"
			data-tone="<?php echo '' === $type_slug_filter ? 'accent' : ''; ?>"
			aria-current="<?php echo '' === $type_slug_filter ? 'page' : 'false'; ?>"
		><?php esc_html_e( 'All', 'buddynext' ); ?></a>

		<?php foreach ( $dir_types as $dir_type ) : ?>
			<?php $bn_pill_active = $type_slug_filter === $dir_type['slug']; ?>
			<a
				href="<?php echo esc_url( \BuddyNext\Core\PageRouter::member_type_url( (string) $dir_type['slug'] ) ); ?>"
				class="bn-badge bn-md-type-pill"
				data-tone="<?php echo $bn_pill_active ? 'accent' : ''; ?>"
				aria-current="<?php echo $bn_pill_active ? 'page' : 'false'; ?>"
			>
				<?php echo esc_html( $dir_type['name'] ); ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div
		class="bn-md-grid"
		data-wp-class--is-list="state.isListView"
		role="list"
	>

		<?php if ( empty( $members ) ) : ?>
			<div class="bn-md-empty">
				<div class="bn-md-empty__icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></div>
				<h2 class="bn-md-empty__title"><?php esc_html_e( 'No members found', 'buddynext' ); ?></h2>
				<p class="bn-md-empty__hint"><?php esc_html_e( 'Try a different search term or clear your filters.', 'buddynext' ); ?></p>
			</div>
		<?php else : ?>

			<?php foreach ( $members as $member ) : ?>
				<?php
				$member_id    = (int) $member->ID;
				$display_name = (string) $member->display_name;
				$member_login = (string) $member->user_login;
				$bio          = (string) get_user_meta( $member_id, 'bn_field_bio', true );
				if ( '' === $bio ) {
					$bio = (string) get_user_meta( $member_id, 'description', true );
				}
				$profile_url      = \BuddyNext\Core\PageRouter::profile_url( $member_id );
				$avatar_url       = (string) get_avatar_url( $member_id, array( 'size' => 96 ) );
				$is_online        = $bn_is_online( $member_id );
				$is_following     = $bn_is_following( $member_id );
				$mutual           = $bn_mutual_count( $current_user_id, $member_id );
				$member_type_slug = (string) get_user_meta( $member_id, 'bn_member_type', true );
				$member_type_data = '' !== $member_type_slug ? ( $type_map[ $member_type_slug ] ?? null ) : null;
				$messages_url     = add_query_arg( array( 'recipient' => $member_id ), $bn_messages_base );
				$follow_nonce     = wp_create_nonce( 'bn_follow_' . $member_id );
				$connect_nonce    = wp_create_nonce( 'bn_connect_' . $member_id );
				$bn_conn_status   = $current_user_id > 0
					? buddynext_service( 'connections' )->status( $current_user_id, $member_id )
					: null;
				$bn_avatar_tone   = $bn_avatar_tones[ $member_id % count( $bn_avatar_tones ) ];
				$bn_presence_attr = $is_online ? 'online' : 'offline';
				$bn_initials_text = $bn_initials( $display_name );
				?>
				<article
					class="bn-card bn-md-card"
					data-interactive
					role="listitem"
					data-user-id="<?php echo esc_attr( (string) $member_id ); ?>"
				>

					<a href="<?php echo esc_url( $profile_url ); ?>" class="bn-md-card__avatar-link" tabindex="-1" aria-hidden="true">
						<span
							class="bn-avatar bn-md-card__avatar"
							data-size="xl"
							data-presence="<?php echo esc_attr( $bn_presence_attr ); ?>"
							data-tone="<?php echo esc_attr( $bn_avatar_tone ); ?>"
						>
							<?php if ( '' !== $avatar_url ) : ?>
								<img
									src="<?php echo esc_url( $avatar_url ); ?>"
									alt=""
									width="72"
									height="72"
									loading="lazy"
									decoding="async"
								>
							<?php else : ?>
								<?php echo esc_html( $bn_initials_text ); ?>
							<?php endif; ?>
						</span>
					</a>

					<h3 class="bn-md-card__name">
						<a href="<?php echo esc_url( $profile_url ); ?>">
							<?php echo esc_html( $display_name ); ?>
						</a>
					</h3>

					<p class="bn-md-card__handle">@<?php echo esc_html( $member_login ); ?></p>

					<?php if ( null !== $member_type_data ) : ?>
						<span class="bn-badge bn-md-card__type" data-tone="accent">
							<?php echo esc_html( (string) $member_type_data['name'] ); ?>
						</span>
					<?php endif; ?>

					<?php if ( '' !== $bio ) : ?>
						<p class="bn-md-card__bio"><?php echo esc_html( wp_trim_words( $bio, 18 ) ); ?></p>
					<?php endif; ?>

					<?php if ( $mutual > 0 ) : ?>
						<p class="bn-md-card__mutual">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of mutual connections */
									_n( '%d mutual connection', '%d mutual connections', $mutual, 'buddynext' ),
									$mutual
								)
							);
							?>
						</p>
					<?php endif; ?>

					<div class="bn-md-card__actions">
						<?php if ( 0 === $current_user_id ) : ?>
							<a
								class="bn-btn"
								data-variant="primary"
								data-size="sm"
								href="<?php echo esc_url( wp_login_url( $profile_url ) ); ?>"
							>
								<?php esc_html_e( 'View profile', 'buddynext' ); ?>
							</a>
						<?php elseif ( $current_user_id === $member_id ) : ?>
							<a
								class="bn-btn"
								data-variant="secondary"
								data-size="sm"
								href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"
							>
								<?php esc_html_e( 'Edit profile', 'buddynext' ); ?>
							</a>
						<?php else : ?>
							<button
								type="button"
								class="bn-btn bn-md-card__follow"
								data-variant="<?php echo $is_following ? 'secondary' : 'primary'; ?>"
								data-size="sm"
								data-member-id="<?php echo esc_attr( (string) $member_id ); ?>"
								data-nonce="<?php echo esc_attr( $follow_nonce ); ?>"
								data-action="<?php echo $is_following ? 'unfollow' : 'follow'; ?>"
								data-label-follow="<?php esc_attr_e( 'Follow', 'buddynext' ); ?>"
								data-label-following="<?php esc_attr_e( 'Following', 'buddynext' ); ?>"
								data-wp-on--click="actions.toggleFollow"
							>
								<?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
							</button>

							<?php if ( 'accepted' === $bn_conn_status ) : ?>
								<a
									class="bn-btn"
									data-variant="ghost"
									data-size="sm"
									href="<?php echo esc_url( $messages_url ); ?>"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member display name */ __( 'Message %s', 'buddynext' ), $display_name ) ); ?>"
								>
									<?php buddynext_icon( 'message-circle' ); ?>
								</a>
							<?php elseif ( 'pending' === $bn_conn_status ) : ?>
								<button
									type="button"
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
									disabled
									aria-disabled="true"
								>
									<?php esc_html_e( 'Pending', 'buddynext' ); ?>
								</button>
							<?php elseif ( ! $is_following ) : ?>
								<button
									type="button"
									class="bn-btn bn-md-card__connect"
									data-variant="secondary"
									data-size="sm"
									data-member-id="<?php echo esc_attr( (string) $member_id ); ?>"
									data-nonce="<?php echo esc_attr( $connect_nonce ); ?>"
									data-label-pending="<?php esc_attr_e( 'Pending', 'buddynext' ); ?>"
									data-wp-on--click="actions.sendConnection"
								>
									<?php esc_html_e( 'Connect', 'buddynext' ); ?>
								</button>
							<?php else : ?>
								<a
									class="bn-btn"
									data-variant="ghost"
									data-size="sm"
									href="<?php echo esc_url( $messages_url ); ?>"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member display name */ __( 'Message %s', 'buddynext' ), $display_name ) ); ?>"
								>
									<?php buddynext_icon( 'message-circle' ); ?>
								</a>
							<?php endif; ?>
						<?php endif; ?>
					</div>

				</article>
			<?php endforeach; ?>

		<?php endif; ?>

	</div>

	<?php if ( $total_pages > 1 ) : ?>
	<nav class="bn-md-pagination" aria-label="<?php esc_attr_e( 'Member directory pages', 'buddynext' ); ?>">

		<?php $bn_prev_disabled = 1 === $bn_current_page; ?>
		<a
			href="<?php echo $bn_prev_disabled ? '#' : $bn_paged_url( $bn_current_page - 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
			class="bn-btn"
			data-variant="ghost"
			data-size="sm"
			aria-label="<?php esc_attr_e( 'Previous page', 'buddynext' ); ?>"
			<?php echo $bn_prev_disabled ? 'aria-disabled="true"' : ''; ?>
		><?php buddynext_icon( 'chevron-left' ); ?></a>

		<?php
		$window_start = max( 1, $bn_current_page - 2 );
		$window_end   = min( $total_pages, $bn_current_page + 2 );

		if ( $window_start > 1 ) :
			?>
			<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo $bn_paged_url( 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">1</a>
			<?php if ( $window_start > 2 ) : ?>
				<span class="bn-md-pagination__gap" aria-hidden="true">&hellip;</span>
			<?php endif; ?>
		<?php endif; ?>

		<?php for ( $p = $window_start; $p <= $window_end; $p++ ) : ?>
			<a
				class="bn-btn"
				data-variant="<?php echo $p === $bn_current_page ? 'primary' : 'ghost'; ?>"
				data-size="sm"
				href="<?php echo $bn_paged_url( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
				<?php echo $p === $bn_current_page ? 'aria-current="page"' : ''; ?>
			><?php echo esc_html( (string) $p ); ?></a>
		<?php endfor; ?>

		<?php if ( $window_end < $total_pages ) : ?>
			<?php if ( $window_end < $total_pages - 1 ) : ?>
				<span class="bn-md-pagination__gap" aria-hidden="true">&hellip;</span>
			<?php endif; ?>
			<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo $bn_paged_url( $total_pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
				<?php echo esc_html( (string) $total_pages ); ?>
			</a>
		<?php endif; ?>

		<?php $bn_next_disabled = $bn_current_page >= $total_pages; ?>
		<a
			href="<?php echo $bn_next_disabled ? '#' : $bn_paged_url( $bn_current_page + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
			class="bn-btn"
			data-variant="ghost"
			data-size="sm"
			aria-label="<?php esc_attr_e( 'Next page', 'buddynext' ); ?>"
			<?php echo $bn_next_disabled ? 'aria-disabled="true"' : ''; ?>
		><?php buddynext_icon( 'chevron-right' ); ?></a>

	</nav>
	<?php endif; ?>

</div><!-- /.bn-hub-content -->

<?php buddynext_get_template( 'partials/sidebar.php' ); ?>

</div><!-- /.bn-hub-shell -->
</div><!-- /.bn-member-directory -->
