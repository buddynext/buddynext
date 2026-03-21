<?php
/**
 * Block template: Profile Fields
 *
 * Variables:
 *   int    $user_id WordPress user ID (0 = current user)
 *   string $group   Field group slug to filter by ('' = all groups)
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$user_id   = $user_id ?? 0;
$group     = $group ?? '';
$viewer_id = get_current_user_id();

if ( ! $user_id ) {
	$user_id = (int) get_query_var( 'author' );
}

if ( ! $user_id ) {
	$user_id = $viewer_id;
}

if ( ! $user_id ) {
	return;
}

$profile_svc = buddynext_service( 'profiles' );
$profile     = $profile_svc->get_profile( $user_id, $viewer_id );

if ( ! $profile ) {
	return;
}

$fields = $profile['fields'] ?? array();

if ( $group ) {
	$fields = array_filter(
		$fields,
		static function ( $field ) use ( $group ) {
			return isset( $field['group_name'] ) && $field['group_name'] === $group;
		}
	);
}
?>
<div class="bn-block-profile-fields" data-user-id="<?php echo absint( $user_id ); ?>">
	<?php if ( empty( $fields ) ) : ?>
		<p class="bn-empty"><?php esc_html_e( 'No profile information available.', 'buddynext' ); ?></p>
	<?php else : ?>
		<dl class="bn-profile-fields-list">
			<?php foreach ( $fields as $key => $field ) : ?>
				<?php if ( empty( $field['value'] ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<div class="bn-profile-field">
					<dt class="bn-profile-field__label"><?php echo esc_html( $field['label'] ?? ucfirst( (string) $key ) ); ?></dt>
					<dd class="bn-profile-field__value"><?php echo esc_html( $field['value'] ); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>
</div>
