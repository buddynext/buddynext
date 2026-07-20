<?php
/**
 * BuddyNext template part: sidebar-profile-member-of.
 *
 * Self-chromed "Member of" sidebar card listing the profile's active
 * spaces. Extracted verbatim from the former
 * `templates/partials/profile-right-sidebar.php` so ProfileSidebarProvider
 * can render it as a `chrome => false` widget descriptor. Empty
 * `$member_spaces` self-hides the card (no output).
 *
 * @package BuddyNext
 *
 * @var array $member_spaces Non-empty membership rows (id/name/slug/role) from SpaceMemberService::membership_rows().
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_spaces = isset( $member_spaces ) && is_array( $member_spaces ) ? $member_spaces : array();

if ( $bn_pf_spaces ) :
	?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Member of', 'buddynext' ); ?></div>
	<?php
	foreach ( $bn_pf_spaces as $space ) :
		$bn_space_slug = isset( $space->slug ) ? (string) $space->slug : '';
		$bn_space_url  = '' !== $bn_space_slug
			? \BuddyNext\Core\PageRouter::spaces_url() . rawurlencode( $bn_space_slug ) . '/'
			: \BuddyNext\Core\PageRouter::space_url( (int) ( $space->id ?? 0 ) );
		?>
		<a class="bn-space-row" href="<?php echo esc_url( $bn_space_url ); ?>">
			<div class="bn-space-icon">
				<?php buddynext_icon( 'home' ); ?>
			</div>
			<div>
				<div class="bn-space-name"><?php echo esc_html( $space->name ); ?></div>
				<?php
					// Translated label for known space roles; unknown custom slugs
					// fall back to a title-cased display (no registered translation).
					$bn_prs_role_labels = array(
						'admin'     => __( 'Admin', 'buddynext' ),
						'moderator' => __( 'Moderator', 'buddynext' ),
						'member'    => __( 'Member', 'buddynext' ),
						'banned'    => __( 'Banned', 'buddynext' ),
					);
					$bn_prs_role        = (string) $space->role;
					$bn_prs_role_label  = $bn_prs_role_labels[ $bn_prs_role ] ?? ucfirst( $bn_prs_role );
					?>
					<div class="bn-space-role"><?php echo esc_html( $bn_prs_role_label ); ?></div>
			</div>
		</a>
	<?php endforeach; ?>
</div>
<?php endif; ?>
