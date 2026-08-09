<?php
/**
 * Block template: Member Directory (v2 design system).
 *
 * Renders the SAME member cards the /members/ directory renders, by delegating
 * to `parts/member-directory-grid.php` — the part that resolves per-member state
 * (follow, connection, mutuals, presence, muted) and then hands each member to
 * `parts/member-card.php`.
 *
 * It used to hand-roll a `<ul class="bn-member-list">` of avatar + name + bio
 * rows instead. That markup existed nowhere else, so it had no shared styles to
 * inherit and drifted from the directory it is named after: no type badge, no
 * degree, no mutuals, no actions, and — until the row classes were given rules
 * of their own — names flowing off the side of a full-size avatar. Rendering the
 * shared part means the block cannot drift from the directory again, because
 * there is only one implementation (Basecamp 10184505772).
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
$rows     = $result['items'] ?? array();
$has_more = null !== ( $result['next_cursor'] ?? null );
$cursor   = $result['next_cursor'] ?? '';

// The grid part takes WP_User objects; the service returns hydrated rows. One
// query with orderby=include preserves the service's ranking instead of paying
// a get_userdata() per row.
$bn_md_ids  = array_values( array_filter( array_map( static fn( $r ): int => (int) ( $r['user_id'] ?? 0 ), (array) $rows ) ) );
$bn_members = array();
if ( ! empty( $bn_md_ids ) ) {
	$bn_members = get_users(
		array(
			'include' => $bn_md_ids,
			'orderby' => 'include',
		)
	);
}

// Presence, batched. Left to its default the grid asks per member, which is an
// N+1 across a 24-card block; every other signal it needs (follows, mutuals,
// connection status, mutes) it already batch-primes for itself.
$bn_md_directory = buddynext_service( 'member_directory' );
$bn_md_online    = ( $bn_md_directory && ! empty( $bn_md_ids ) ) ? $bn_md_directory->online_among( $bn_md_ids ) : array();
$bn_md_online_fn = static function ( int $id ) use ( $bn_md_online ): bool {
	return ! empty( $bn_md_online[ $id ] );
};
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
	<?php if ( empty( $bn_members ) ) : ?>
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
		<?php
		buddynext_get_template(
			'parts/member-directory-grid.php',
			array(
				'members'      => $bn_members,
				'viewer_id'    => $viewer_id,
				'view_mode'    => $layout,
				'avatar_tones' => array( 'accent', 'success', 'jetonomy', 'media', 'events', 'warn', 'danger', 'info' ),
				'is_online_fn' => $bn_md_online_fn,
				// The directory's own list view is the same grid with .is-list;
				// reusing it keeps the block's two layouts and the hub's two
				// layouts the same two layouts.
				'classes'      => 'list' === $layout ? array( 'is-list' ) : array(),
			)
		);
		?>
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
