<?php
/**
 * Block template: Member Directory (v2 design system).
 *
 * Compact directory listing — avatar, name and bio per row, in a .bn-card.
 *
 * SELF-CONTAINED. A block can land on any block surface in any theme, so every
 * class this emits is styled in assets/css/blocks.css (plus the bn-base token
 * layer) and nothing here depends on a hub feature stylesheet or a
 * templates/parts include. The row classes used to be styled nowhere at all,
 * which is why each name rendered flowing off the side of a full-size avatar.
 *
 * Variables:
 *   int    $per_page Number of members to display.
 *   string $layout   'grid' | 'list'.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$bn_per_page = $per_page ?? 24;
$layout      = $layout ?? 'grid';
$layout      = in_array( $layout, array( 'grid', 'list' ), true ) ? $layout : 'grid';
$viewer_id   = get_current_user_id();

$result   = buddynext_service( 'member_directory' )->list_members( $viewer_id, null, $bn_per_page );
$members  = $result['items'] ?? array();
$has_more = null !== ( $result['next_cursor'] ?? null );
$cursor   = $result['next_cursor'] ?? '';
?>
<section
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes its own output.
	echo get_block_wrapper_attributes(
		array(
			'class'       => 'bn-card bn-block-member-directory bn-block-member-directory--' . $layout,
			'data-layout' => $layout,
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>
	<div class="bn-member-directory__toolbar">
		<h3 class="bn-block-heading"><?php esc_html_e( 'Members', 'buddynext' ); ?></h3>
	</div>
	<?php if ( empty( $members ) ) : ?>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'users',
				'title' => __( 'No members yet', 'buddynext' ),
				'body'  => __( 'Once people join, they will show up here.', 'buddynext' ),
			)
		);
		?>
	<?php else : ?>
		<ul class="bn-member-list">
			<?php foreach ( $members as $member ) : ?>
				<?php
				$bn_uid    = (int) ( $member['user_id'] ?? 0 );
				$bn_avatar = $bn_uid ? (string) get_avatar_url( $bn_uid, array( 'size' => 96 ) ) : '';
				?>
				<li class="bn-member-item">
					<a
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $bn_uid ) ); ?>"
						class="bn-member-link"
					>
						<span class="bn-avatar bn-member-item__avatar" data-size="lg" aria-hidden="true">
							<?php if ( '' !== $bn_avatar ) : ?>
								<img
									src="<?php echo esc_url( $bn_avatar ); ?>"
									alt=""
									width="48"
									height="48"
									loading="lazy"
									decoding="async"
								>
							<?php endif; ?>
						</span>
						<span class="bn-member-item__text">
							<span class="bn-member-name"><?php echo esc_html( $member['display_name'] ?? '' ); ?></span>
							<?php if ( ! empty( $member['bio'] ) ) : ?>
								<span class="bn-member-bio"><?php echo esc_html( wp_trim_words( $member['bio'], 12 ) ); ?></span>
							<?php endif; ?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $has_more ) : ?>
			<button
				type="button"
				class="bn-btn bn-load-more"
				data-variant="ghost"
				data-size="sm"
				data-block="member-directory"
				data-cursor="<?php echo esc_attr( $cursor ); ?>"
				data-per-page="<?php echo absint( $bn_per_page ); ?>"
			>
				<?php esc_html_e( 'Load more', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
	<?php endif; ?>
</section>
