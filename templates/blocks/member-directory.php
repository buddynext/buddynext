<?php
/**
 * Block template: Member Directory
 *
 * Variables:
 *   int    $per_page Number of members to display
 *   string $layout   'grid' | 'list'
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$bn_per_page = $per_page ?? 24;
$layout      = $layout ?? 'grid';
$viewer_id   = get_current_user_id();

$result   = buddynext_service( 'member_directory' )->list_members( $viewer_id, null, $bn_per_page );
$members  = $result['items'] ?? array();
$has_more = null !== ( $result['next_cursor'] ?? null );
$cursor   = $result['next_cursor'] ?? '';
?>
<div class="bn-block-member-directory bn-block-member-directory--<?php echo esc_attr( $layout ); ?>"
	data-layout="<?php echo esc_attr( $layout ); ?>">
	<div class="bn-member-directory__toolbar">
		<h3 class="bn-block-heading"><?php esc_html_e( 'Members', 'buddynext' ); ?></h3>
	</div>
	<?php if ( empty( $members ) ) : ?>
		<p class="bn-empty"><?php esc_html_e( 'No members found.', 'buddynext' ); ?></p>
	<?php else : ?>
		<ul class="bn-member-list">
			<?php foreach ( $members as $member ) : ?>
				<li class="bn-member-item">
					<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( (int) $member['user_id'] ) ); ?>" class="bn-member-link">
						<?php echo get_avatar( (int) $member['user_id'], 48, '', '', array( 'class' => 'bn-avatar' ) ); ?>
						<span class="bn-member-name"><?php echo esc_html( $member['display_name'] ?? '' ); ?></span>
						<?php if ( ! empty( $member['bio'] ) ) : ?>
							<span class="bn-member-bio"><?php echo esc_html( wp_trim_words( $member['bio'], 12 ) ); ?></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $has_more ) : ?>
			<button class="bn-load-more" data-block="member-directory" data-cursor="<?php echo esc_attr( $cursor ); ?>" data-per-page="<?php echo absint( $bn_per_page ); ?>">
				<?php esc_html_e( 'Load more', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
	<?php endif; ?>
</div>
