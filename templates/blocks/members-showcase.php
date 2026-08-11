<?php
/**
 * Block template: Members showcase.
 *
 * "Here is who is here." A few members with enough detail to recognise a
 * community — the second strongest proof after activity.
 *
 * GUESTS ARE THE AUDIENCE. The block exists to convince people who have not
 * joined, so it renders in full logged-out and the per-member action degrades to
 * "View profile" instead of disappearing.
 *
 * SELF-CONTAINED. A block can land on any block surface in any theme, so every
 * class here is styled in assets/css/blocks.css on top of the bn-base token
 * layer — never a hub feature stylesheet, never a templates/parts include.
 *
 * Variables:
 *   string $source        newest | most_active | online | member_type | picked.
 *   string $member_type   Member-type slug when $source is 'member_type'.
 *   array  $user_ids      Ordered member IDs when $source is 'picked'.
 *   int    $count         How many to show (1-8).
 *   string $layout        'list' (compact rows) | 'grid' (cards).
 *   bool   $show_headline Whether to render the member's headline line.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\AvatarService;

$bn_ms_source   = isset( $source ) ? sanitize_key( (string) $source ) : 'newest';
$bn_ms_type     = isset( $member_type ) ? sanitize_key( (string) $member_type ) : '';
$bn_ms_picked   = isset( $user_ids ) ? array_values( array_filter( array_map( 'intval', (array) $user_ids ) ) ) : array();
$bn_ms_count    = isset( $count ) ? max( 1, min( 8, (int) $count ) ) : 4;
$bn_ms_layout   = isset( $layout ) && 'grid' === $layout ? 'grid' : 'list';
$bn_ms_headline = ! isset( $show_headline ) || (bool) $show_headline;
$bn_ms_viewer   = get_current_user_id();

/*
 * Every source maps onto a filter MemberDirectoryService already validates
 * (sort: newest | most_active | online, plus member_type) — the spec's rule that
 * an option must never invent a query surface. 'picked' is the one that does not
 * go through the directory at all: it is an explicit, ordered id list.
 */
$bn_ms_members = array();

if ( 'picked' === $bn_ms_source ) {
	foreach ( array_slice( $bn_ms_picked, 0, $bn_ms_count ) as $bn_ms_id ) {
		$bn_ms_user = get_userdata( $bn_ms_id );
		if ( $bn_ms_user ) {
			$bn_ms_members[] = array(
				'user_id'      => (int) $bn_ms_user->ID,
				'display_name' => $bn_ms_user->display_name,
			);
		}
	}
} else {
	$bn_ms_filters = array();
	switch ( $bn_ms_source ) {
		case 'most_active':
			$bn_ms_filters['sort'] = 'most_active';
			break;
		case 'online':
			$bn_ms_filters['sort'] = 'online';
			break;
		case 'member_type':
			$bn_ms_filters['sort'] = 'newest';
			if ( '' !== $bn_ms_type ) {
				$bn_ms_filters['member_type'] = $bn_ms_type;
			}
			break;
		default:
			$bn_ms_filters['sort'] = 'newest';
	}

	// The directory resolves per-viewer privacy itself, so a hidden profile never
	// reaches this list — the spec's privacy state is inherited, not re-implemented.
	$bn_ms_result  = buddynext_service( 'member_directory' )->list_members( $bn_ms_viewer, null, $bn_ms_count, $bn_ms_filters );
	$bn_ms_members = (array) ( $bn_ms_result['items'] ?? array() );
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes its own output.
?>
<section 
<?php
echo get_block_wrapper_attributes(
	array(
		'class'       => 'bn-card bn-block-members-showcase bn-block-members-showcase--' . $bn_ms_layout,
		'data-layout' => $bn_ms_layout,
	)
);
?>
>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
	<h3 class="bn-block-heading"><?php esc_html_e( 'Members', 'buddynext' ); ?></h3>

	<?php if ( empty( $bn_ms_members ) ) : ?>
		<?php
		// An empty community is a real state on a brand-new site, and it tells the
		// owner to invite people rather than leaving a blank gap on a landing page.
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'users',
				'title' => __( 'No members to show yet', 'buddynext' ),
				'body'  => __( 'Once people join, they will appear here.', 'buddynext' ),
			)
		);
		?>
	<?php else : ?>
		<ul class="bn-ms-list">
			<?php foreach ( $bn_ms_members as $bn_ms_member ) : ?>
				<?php
				$bn_ms_uid = (int) ( $bn_ms_member['user_id'] ?? 0 );
				if ( $bn_ms_uid <= 0 ) {
					continue;
				}

				$bn_ms_name   = (string) ( $bn_ms_member['display_name'] ?? '' );
				$bn_ms_url    = (string) PageRouter::profile_url( $bn_ms_uid );
				$bn_ms_avatar = (string) get_avatar_url( $bn_ms_uid, array( 'size' => 96 ) );
				$bn_ms_head   = $bn_ms_headline ? trim( (string) get_user_meta( $bn_ms_uid, 'bn_headline', true ) ) : '';
				$bn_ms_self   = ( $bn_ms_viewer > 0 && $bn_ms_viewer === $bn_ms_uid );
				?>
				<li class="bn-ms-item">
					<a class="bn-ms-item__id" href="<?php echo esc_url( $bn_ms_url ); ?>">
						<span class="bn-avatar bn-ms-item__avatar" data-size="md" aria-hidden="true">
							<?php if ( '' !== $bn_ms_avatar ) : ?>
								<img src="<?php echo esc_url( $bn_ms_avatar ); ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
							<?php else : ?>
								<?php // No avatar is a first-class state: an initials mark, never a broken image. ?>
								<span class="bn-ms-item__initials"><?php echo esc_html( AvatarService::initials_for( $bn_ms_name ) ); ?></span>
							<?php endif; ?>
						</span>
						<span class="bn-ms-item__text">
							<span class="bn-ms-item__name"><?php echo esc_html( $bn_ms_name ); ?></span>
							<?php if ( '' !== $bn_ms_head ) : ?>
								<span class="bn-ms-item__headline"><?php echo esc_html( $bn_ms_head ); ?></span>
							<?php endif; ?>
						</span>
					</a>

					<span class="bn-ms-item__action">
						<?php if ( $bn_ms_viewer > 0 && ! $bn_ms_self ) : ?>
							<?php buddynext_get_template( 'partials/follow-button.php', array( 'user_id' => $bn_ms_uid ) ); ?>
						<?php else : ?>
							<?php // Logged out (or looking at yourself): the action degrades, it does not vanish. ?>
							<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( $bn_ms_url ); ?>">
								<?php esc_html_e( 'View profile', 'buddynext' ); ?>
							</a>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
