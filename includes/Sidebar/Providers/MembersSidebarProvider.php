<?php
/**
 * Member-directory right-sidebar provider (Free core).
 *
 * Registers the two titled sidebar cards — online-now and by-type — that
 * formerly lived inline in `templates/directory/members.php` as
 * `buddynext_right_sidebar` callbacks. Distinct from FeedSidebarProvider and
 * ExploreSidebarProvider: these descriptors use the registry's DEFAULT
 * chrome (no `chrome => false`), so each `render` closure echoes ONLY the
 * inner `<ul>` body and SidebarRegistry wraps it in `parts/sidebar-card.php`
 * using the descriptor's `title`/`icon`.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\AvatarService;

/**
 * Member-directory sidebar widget descriptors.
 */
class MembersSidebarProvider {

	/**
	 * Surface this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'members' );

	/**
	 * Avatar tone palette — cycles deterministically by user ID. Mirrors
	 * the `$bn_avatar_tones` array formerly declared inline in
	 * templates/directory/members.php.
	 *
	 * @var array<int,string>
	 */
	private const AVATAR_TONES = array( 'accent', 'success', 'jetonomy', 'media', 'events', 'warn', 'danger', 'info' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * Appends the two member-directory descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( 'members' !== $surface ) {
			return $descriptors;
		}

		$current_user_id = get_current_user_id();

		// Online-now: most-recently-active members within the online window, via
		// the same directory service (block-restrict aware) members.php reads.
		$online_rows = ( function_exists( 'buddynext_service' ) && is_object( buddynext_service( 'member_directory' ) ) )
			? buddynext_service( 'member_directory' )->online_now( $current_user_id, 6 )
			: array();

		if ( ! empty( $online_rows ) ) {
			$descriptors[] = array(
				'id'       => 'members-online-now',
				'priority' => 20,
				'surfaces' => self::SURFACES,
				'title'    => sprintf(
					/* translators: %s: number of online members */
					__( 'Online now (%s)', 'buddynext' ),
					number_format_i18n( count( $online_rows ) )
				),
				'icon'     => 'users',
				'render'   => static function () use ( $online_rows ): void {
					?>
					<ul class="bn-md-sidebar-list">
						<?php foreach ( $online_rows as $row ) : ?>
							<?php
							$row_id     = (int) $row['ID'];
							$row_name   = (string) $row['display_name'];
							$row_handle = (string) ( $row['handle'] ?? '' );
							$row_url    = PageRouter::profile_url( $row_id );
							$row_av     = (string) get_avatar_url( $row_id, array( 'size' => 56 ) );
							$row_tone   = self::AVATAR_TONES[ $row_id % count( self::AVATAR_TONES ) ];
							?>
							<li class="bn-md-sidebar-item">
								<a class="bn-md-sidebar-item__link" href="<?php echo esc_url( $row_url ); ?>">
									<span
										class="bn-avatar"
										data-size="sm"
										data-presence="online"
										data-tone="<?php echo esc_attr( $row_tone ); ?>"
									>
										<?php if ( '' !== $row_av ) : ?>
											<img
												src="<?php echo esc_url( $row_av ); ?>"
												alt=""
												width="28"
												height="28"
												loading="lazy"
												decoding="async"
											>
										<?php else : ?>
											<?php echo esc_html( AvatarService::initials_for( $row_name ) ); ?>
										<?php endif; ?>
									</span>
									<span class="bn-md-sidebar-item__text">
										<span class="bn-md-sidebar-item__name"><?php echo esc_html( $row_name ); ?></span>
										<span class="bn-md-sidebar-item__handle">@<?php echo esc_html( $row_handle ); ?></span>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php
				},
			);
		}

		// By-type: member types with directory-accurate counts, filtered against
		// the active `?member_type` / `?type` request filter — same active-filter
		// resolution members.php performs before rendering the sidebar.
		$type_slug_filter = sanitize_key( (string) get_query_var( 'bn_member_type', '' ) );
		if ( '' === $type_slug_filter ) {
			$type_slug_filter = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$dir_types = $this->dir_types( $current_user_id );

		if ( ! empty( $dir_types ) ) {
			$descriptors[] = array(
				'id'       => 'members-by-type',
				'priority' => 30,
				'surfaces' => self::SURFACES,
				'title'    => __( 'By type', 'buddynext' ),
				'icon'     => 'tag',
				'render'   => static function () use ( $dir_types, $type_slug_filter ): void {
					?>
					<ul class="bn-md-sidebar-list bn-md-sidebar-list--rows">
						<?php
						// "All" row.
						$all_url    = PageRouter::people_url();
						$all_active = ( '' === $type_slug_filter );
						?>
						<li class="bn-md-sidebar-row">
							<a
								class="bn-md-sidebar-row__link<?php echo $all_active ? ' is-active' : ''; ?>"
								href="<?php echo esc_url( $all_url ); ?>"
								<?php echo $all_active ? 'aria-current="page"' : ''; ?>
							>
								<span class="bn-md-sidebar-row__label"><?php esc_html_e( 'All members', 'buddynext' ); ?></span>
							</a>
						</li>
						<?php foreach ( $dir_types as $type ) : ?>
							<?php
							$type_slug   = (string) $type['slug'];
							$type_name   = (string) $type['name'];
							$type_count  = isset( $type['member_count'] ) ? (int) $type['member_count'] : ( isset( $type['count'] ) ? (int) $type['count'] : 0 );
							$type_url    = PageRouter::member_type_url( $type_slug );
							$type_active = ( $type_slug === $type_slug_filter );
							?>
							<li class="bn-md-sidebar-row">
								<a
									class="bn-md-sidebar-row__link<?php echo $type_active ? ' is-active' : ''; ?>"
									href="<?php echo esc_url( $type_url ); ?>"
									<?php echo $type_active ? 'aria-current="page"' : ''; ?>
								>
									<span class="bn-md-sidebar-row__label"><?php echo esc_html( $type_name ); ?></span>
									<?php if ( $type_count > 0 ) : ?>
										<span class="bn-md-sidebar-row__count"><?php echo esc_html( number_format_i18n( $type_count ) ); ?></span>
									<?php endif; ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php
				},
			);
		}

		return $descriptors;
	}

	/**
	 * Directory-visible member types with directory-accurate member counts.
	 *
	 * Mirrors the computation in templates/directory/members.php (~95-133):
	 * `member_types`'s `get_all_with_counts()` filtered to `show_in_dir`
	 * rows, then each row's `member_count` overridden with
	 * `MemberDirectoryService::type_member_counts()` so a facet count always
	 * matches exactly who the member-directory list shows for that type
	 * (list_members() excludes the viewer + applies the discovery gate,
	 * which the raw assignment-row count does not).
	 *
	 * @param int $viewer_id Viewing user ID (excluded from directory-accurate counts).
	 * @return array<int,array<string,mixed>>
	 */
	private function dir_types( int $viewer_id ): array {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return array();
		}

		$member_types = buddynext_service( 'member_types' );
		if ( ! is_object( $member_types ) ) {
			return array();
		}

		$all_types_raw = $member_types->get_all_with_counts();
		$dir_types     = array_values( array_filter( $all_types_raw, static fn( $t ) => ! empty( $t['show_in_dir'] ) ) );

		$directory_service = buddynext_service( 'member_directory' );
		if ( is_object( $directory_service ) && method_exists( $directory_service, 'type_member_counts' ) ) {
			$type_counts = $directory_service->type_member_counts( $viewer_id );
			foreach ( $dir_types as &$type ) {
				$type['member_count'] = (int) ( $type_counts[ (int) ( $type['id'] ?? 0 ) ] ?? 0 );
			}
			unset( $type );
		}

		return $dir_types;
	}
}
