<?php
/**
 * BuddyNext template part: space-sidebar — the unified space right rail.
 *
 * The single right-sidebar every space sub-page renders, so the rail is uniform
 * across Feed / About / Media / Members / Moderation (and any integration tab) —
 * switching tabs keeps the same shell + cards instead of dropping the rail on the
 * dedicated Members/Moderation pages. It resolves the space, its moderators, a
 * capped member preview and the top contributors from just the space id + viewer,
 * then registers the cards on the shared `buddynext_right_sidebar` action (the
 * hub shell renders the right column when anything is hooked there).
 *
 * Only the WIDGETS vary per tab: the Members-preview card is skipped on the
 * Members tab, where the full roster is already the page body (no duplication).
 *
 * @package BuddyNext
 *
 * @var int    $space_id   Required. The space's primary key.
 * @var int    $viewer_id  Optional. Current viewer user ID (0 = logged out).
 * @var string $active_tab Optional. Active tab id (feed|about|media|members|moderation|…).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bn_sh_avatar_tone' ) ) {
	/**
	 * Return a deterministic avatar tone slug based on a user id.
	 *
	 * Maps to the shared `.bn-avatar[data-tone]` palette in bn-base.css (the same
	 * six tones the member/space cover cards use). The slug is applied as
	 * `data-tone` so the colour is theme- and dark-mode-aware via tokens rather
	 * than a hardcoded hex inline style.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Tone slug (sky|cyan|emerald|lime|amber|coral).
	 */
	function bn_sh_avatar_tone( int $user_id ): string {
		$tones = array( 'sky', 'cyan', 'emerald', 'lime', 'amber', 'coral' );
		return $tones[ $user_id % count( $tones ) ];
	}
}

$bn_ss_space_id = isset( $space_id ) ? absint( $space_id ) : 0;
if ( $bn_ss_space_id <= 0 ) {
	return;
}

$bn_ss_space = ( new \BuddyNext\Spaces\SpaceService() )->get_object( $bn_ss_space_id );
if ( null === $bn_ss_space ) {
	return;
}

$bn_ss_viewer     = isset( $viewer_id ) ? absint( $viewer_id ) : 0;
$bn_ss_active_tab = isset( $active_tab ) && '' !== (string) $active_tab ? (string) $active_tab : 'feed';
$bn_ss_member_svc = new \BuddyNext\Spaces\SpaceMemberService();

// Owners/moderators always lead the rail; fetch them in full (they are few) and a
// capped preview of regular members. Each row is exposed as an object so the
// markup keeps simple property access (mirrors space-members-panel's fallback to
// user_login when display_name is empty).
$bn_ss_to_objects = static function ( array $rows ): array {
	return array_map(
		static function ( array $r ): object {
			$r['user_login'] = $r['user_login'] ?? ( $r['user_nicename'] ?? '' );
			return (object) $r;
		},
		$rows
	);
};

$bn_ss_mods            = array_merge(
	$bn_ss_member_svc->get_members( $bn_ss_space_id, $bn_ss_viewer, 0, 0, array( 'role' => 'owner' ) ),
	$bn_ss_member_svc->get_members( $bn_ss_space_id, $bn_ss_viewer, 0, 0, array( 'role' => 'moderator' ) )
);
$bn_ss_regulars        = $bn_ss_member_svc->get_members( $bn_ss_space_id, $bn_ss_viewer, 10, 0, array( 'role' => 'member' ) );
$bn_ss_sidebar_members = $bn_ss_to_objects( array_merge( $bn_ss_mods, $bn_ss_regulars ) );
$bn_ss_contributors    = $bn_ss_to_objects( ( new \BuddyNext\Spaces\SpaceService() )->top_contributors( $bn_ss_space_id, 3 ) );

$bn_ss_meta = \BuddyNext\Spaces\SpaceService::display_meta( $bn_ss_space );

$bn_ss_args = array(
	'space'            => $bn_ss_space,
	'space_id'         => $bn_ss_space_id,
	'active_tab'       => $bn_ss_active_tab,
	'sidebar_members'  => $bn_ss_sidebar_members,
	'top_contributors' => $bn_ss_contributors,
	'privacy_label'    => $bn_ss_meta['privacy_label'],
	'privacy_tone'     => $bn_ss_meta['privacy_tone'],
);

add_action(
	'buddynext_right_sidebar',
	static function () use ( $bn_ss_args ) {
		$bn_s = $bn_ss_args;

		// Card 1: About. Qualitative context only (description + type + created +
		// category). The Members / Posts counts live in the hero stat strip, so
		// this card carries what the strip does not (no number duplication).
		ob_start();
		if ( ! empty( $bn_s['space']->description ) ) :
			?>
			<p class="bn-sh-side-text"><?php echo esc_html( $bn_s['space']->description ); ?></p>
			<?php
		endif;
		?>
		<div class="bn-sh-side-meta">
			<span class="bn-badge" data-tone="<?php echo esc_attr( $bn_s['privacy_tone'] ); ?>"><?php echo esc_html( $bn_s['privacy_label'] ); ?></span>
			<?php if ( ! empty( $bn_s['space']->created_at ) ) : ?>
				<span class="bn-sh-side-meta__row">
					<?php buddynext_icon( 'calendar' ); ?>
					<?php
					// translators: %s is the formatted date.
					printf( esc_html__( 'Created %s', 'buddynext' ), buddynext_date_local( (string) $bn_s['space']->created_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_date_local() returns esc_html()'d output.
					?>
				</span>
			<?php endif; ?>
			<?php if ( ! empty( $bn_s['space']->category_name ) ) : ?>
				<span class="bn-sh-side-meta__row">
					<?php buddynext_icon( 'hash' ); ?>
					<?php echo esc_html( $bn_s['space']->category_name ); ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
		$bn_about_html = (string) ob_get_clean();

		buddynext_get_template(
			'parts/sidebar-card.php',
			array(
				'id'         => 'space-about',
				'title'      => __( 'About this space', 'buddynext' ),
				'title_icon' => 'info',
				'body_html'  => $bn_about_html,
			)
		);

		// Split the role-ordered preview into moderators (owner + moderator) and
		// regular members so the two cards complement each other instead of
		// repeating mods. owner/moderator always lead the LIMIT-10 set, so this
		// needs no extra query.
		$bn_side_all = (array) $bn_s['sidebar_members'];
		$bn_mods     = array_values(
			array_filter(
				$bn_side_all,
				static function ( $m ) {
					return in_array( $m->role ?? '', array( 'owner', 'moderator' ), true );
				}
			)
		);
		$bn_regulars = array_values(
			array_filter(
				$bn_side_all,
				static function ( $m ) {
					return 'member' === ( $m->role ?? '' );
				}
			)
		);

		// Card 2: Moderators. DMs are owned by WPMediaVerse, so only offer the
		// Message action when that dependency is present (same signal the messages
		// hub uses); otherwise the row links to the profile.
		if ( ! empty( $bn_mods ) ) {
			$bn_msgs_on = \BuddyNext\Messages\MessagesData::available();
			ob_start();
			?>
				<ul class="bn-sh-side-members">
				<?php foreach ( $bn_mods as $bn_mod ) : ?>
						<?php
						$bn_mod_uid   = (int) $bn_mod->user_id;
						$bn_mod_name  = $bn_mod->display_name ?? __( 'Member', 'buddynext' );
						$bn_mod_init  = \BuddyNext\Profile\AvatarService::initials_for( (string) $bn_mod_name );
						$bn_mod_url   = \BuddyNext\Core\PageRouter::profile_url( $bn_mod_uid );
						$bn_mod_owner = 'owner' === $bn_mod->role;
						?>
						<li class="bn-sh-side-member bn-sh-side-mod">
							<a class="bn-sh-side-mod__id" href="<?php echo esc_url( $bn_mod_url ); ?>">
								<span class="bn-avatar bn-sh-side-member__avatar"
									data-size="sm"
									data-tone="<?php echo esc_attr( bn_sh_avatar_tone( $bn_mod_uid ) ); ?>"
									aria-hidden="true"
								><?php echo esc_html( $bn_mod_init ); ?></span>
								<span class="bn-sh-side-member__name">
									<?php echo esc_html( $bn_mod_name ); ?>
									<span class="bn-badge" data-tone="<?php echo $bn_mod_owner ? 'paid' : 'accent'; ?>">
										<?php echo $bn_mod_owner ? esc_html__( 'Admin', 'buddynext' ) : esc_html__( 'Mod', 'buddynext' ); ?>
									</span>
								</span>
							</a>
							<?php if ( $bn_msgs_on ) : ?>
								<a
									class="bn-btn bn-btn--sm bn-btn--ghost bn-sh-side-mod__msg"
									href="<?php echo esc_url( add_query_arg( 'recipient', $bn_mod_uid, home_url( '/messages/' ) ) ); ?>"
									aria-label="
									<?php
									/* translators: %s: moderator display name */
									echo esc_attr( sprintf( __( 'Message %s', 'buddynext' ), $bn_mod_name ) );
									?>
									"
								><?php buddynext_icon( 'mail' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php
				$bn_mods_html = (string) ob_get_clean();

				buddynext_get_template(
					'parts/sidebar-card.php',
					array(
						'id'         => 'space-moderators',
						'title'      => _n( 'Moderator', 'Moderators', count( $bn_mods ), 'buddynext' ),
						'title_icon' => 'shield',
						'body_html'  => $bn_mods_html,
					)
				);
		}

		// Card 3: Members preview (regular members only — mods sit in the card
		// above). Skipped on the Members tab, where the full roster IS the page
		// body, so the rail does not echo the same list twice.
		if ( ! empty( $bn_regulars ) && 'members' !== $bn_s['active_tab'] ) {
			ob_start();
			?>
			<ul class="bn-sh-side-members">
				<?php foreach ( $bn_regulars as $bn_m ) : ?>
					<?php
					$bn_uid   = (int) $bn_m->user_id;
					$bn_mname = $bn_m->display_name ?? __( 'Member', 'buddynext' );
					$bn_init  = \BuddyNext\Profile\AvatarService::initials_for( (string) $bn_mname );
					$bn_murl  = \BuddyNext\Core\PageRouter::profile_url( $bn_uid );
					?>
					<li class="bn-sh-side-member">
						<a class="bn-sh-side-member__id" href="<?php echo esc_url( $bn_murl ); ?>">
							<span class="bn-avatar bn-sh-side-member__avatar"
								data-size="sm"
								data-tone="<?php echo esc_attr( bn_sh_avatar_tone( $bn_uid ) ); ?>"
								aria-hidden="true"
							><?php echo esc_html( $bn_init ); ?></span>
							<span class="bn-sh-side-member__name">
								<?php echo esc_html( $bn_mname ); ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			$bn_members_html = (string) ob_get_clean();

			buddynext_get_template(
				'parts/sidebar-card.php',
				array(
					'id'            => 'space-members',
					'title'         => __( 'Members', 'buddynext' ),
					'title_icon'    => 'users',
					'body_html'     => $bn_members_html,
					'see_all_url'   => trailingslashit( \BuddyNext\Core\PageRouter::space_url( $bn_s['space_id'] ) ) . 'members/',
					'see_all_label' => __( 'See all members', 'buddynext' ),
				)
			);
		}

		// Card 4: Top contributors.
		if ( ! empty( $bn_s['top_contributors'] ) ) {
			ob_start();
			?>
			<ul class="bn-sh-side-members">
				<?php foreach ( $bn_s['top_contributors'] as $bn_rank => $bn_c ) : ?>
					<?php
					$bn_cuid  = (int) $bn_c->user_id;
					$bn_cname = $bn_c->display_name ?? __( 'Member', 'buddynext' );
					$bn_cinit = \BuddyNext\Profile\AvatarService::initials_for( (string) $bn_cname );
					$bn_curl  = \BuddyNext\Core\PageRouter::profile_url( $bn_cuid );
					?>
					<li class="bn-sh-side-member">
						<span class="bn-sh-side-member__rank"><?php echo esc_html( (string) ( $bn_rank + 1 ) ); ?></span>
						<a class="bn-sh-side-member__id" href="<?php echo esc_url( $bn_curl ); ?>">
							<span class="bn-avatar bn-sh-side-member__avatar"
								data-size="sm"
								data-tone="<?php echo esc_attr( bn_sh_avatar_tone( $bn_cuid ) ); ?>"
								aria-hidden="true"
							><?php echo esc_html( $bn_cinit ); ?></span>
							<span class="bn-sh-side-member__name"><?php echo esc_html( $bn_cname ); ?></span>
						</a>
						<span class="bn-sh-side-member__count">
							<?php
							// translators: %d: post count.
							printf( esc_html( _n( '%d post', '%d posts', (int) $bn_c->post_count, 'buddynext' ) ), (int) $bn_c->post_count );
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			$bn_contrib_html = (string) ob_get_clean();

			buddynext_get_template(
				'parts/sidebar-card.php',
				array(
					'id'         => 'space-contributors',
					'title'      => __( 'Top contributors', 'buddynext' ),
					'title_icon' => 'award',
					'body_html'  => $bn_contrib_html,
				)
			);
		}
	}
);
