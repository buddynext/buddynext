<?php
/**
 * BuddyNext template part: sidebar-member-row.
 *
 * ONE canonical member row for every sidebar widget that lists people —
 * People to Follow, People to discover, Online now. Avatar + display name +
 * handle (two lines), with an optional trailing Follow action. Styled by the
 * globally-loaded `.bn-member-row` rules in bn-base.css, so it looks identical
 * on every surface (feed / members / profile / explore) — before this, each
 * widget hand-rolled its own row classes (.bn-sbar-row, .bn-ex-person,
 * .bn-md-sidebar-item) and only one carried the polished styling, and only on
 * its own surface.
 *
 * @package BuddyNext
 *
 * @var int    $row_user_id  Member ID.
 * @var string $row_name     Display name.
 * @var string $row_handle   @handle (nicename/login, WITHOUT the leading @).
 * @var string $row_url      Profile URL.
 * @var string $row_avatar   Avatar URL ('' → initials fallback).
 * @var string $row_tone     Optional avatar tone token (e.g. 'violet').
 * @var bool   $row_online   Optional — render the online presence dot.
 * @var bool   $row_follow   Optional — render the Follow button (partials/follow-button.php).
 * @var string $row_meta     Optional — override the secondary line (defaults to @handle).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$row_user_id = isset( $row_user_id ) ? (int) $row_user_id : 0;
$row_name    = isset( $row_name ) ? (string) $row_name : '';
$row_handle  = isset( $row_handle ) ? (string) $row_handle : '';
$row_url     = isset( $row_url ) ? (string) $row_url : '';
$row_avatar  = isset( $row_avatar ) ? (string) $row_avatar : '';
$row_tone    = isset( $row_tone ) ? (string) $row_tone : '';
$row_online  = ! empty( $row_online );
$row_follow  = ! empty( $row_follow );
$row_meta    = isset( $row_meta ) && '' !== (string) $row_meta
	? (string) $row_meta
	: ( '' !== $row_handle ? '@' . $row_handle : '' );

if ( $row_user_id <= 0 ) {
	return;
}

$row_initial = '' !== $row_name ? mb_strtoupper( mb_substr( $row_name, 0, 1 ) ) : '?';
?>
<li class="bn-member-row">
	<a class="bn-member-row__link" href="<?php echo esc_url( $row_url ); ?>">
		<span
			class="bn-avatar"
			data-size="sm"
			<?php echo $row_online ? 'data-presence="online"' : ''; ?>
			<?php echo '' !== $row_tone ? 'data-tone="' . esc_attr( $row_tone ) . '"' : ''; ?>
		>
			<?php if ( '' !== $row_avatar ) : ?>
				<img src="<?php echo esc_url( $row_avatar ); ?>" alt="" width="36" height="36" loading="lazy" decoding="async">
			<?php else : ?>
				<?php echo esc_html( $row_initial ); ?>
			<?php endif; ?>
		</span>
		<span class="bn-member-row__text">
			<span class="bn-member-row__name"><?php echo esc_html( $row_name ); ?></span>
			<?php if ( '' !== $row_meta ) : ?>
				<span class="bn-member-row__meta"><?php echo esc_html( $row_meta ); ?></span>
			<?php endif; ?>
		</span>
	</a>
	<?php
	if ( $row_follow ) {
		$user_id = $row_user_id;
		buddynext_get_template( 'partials/follow-button.php', array( 'user_id' => $row_user_id ) );
	}
	?>
</li>
<?php
unset( $row_meta, $row_initial );
