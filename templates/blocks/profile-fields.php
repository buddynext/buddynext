<?php
/**
 * Block template: Profile Fields (v2 design system).
 *
 * Static profile metadata, wrapped in .bn-card so it sits in the profile
 * sidebar alongside other v2 surfaces.
 *
 * Variables:
 *   int    $user_id WordPress user ID (0 = current user).
 *   string $group   Field group slug to filter by ('' = all groups).
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
<section class="bn-card bn-block-profile-fields" data-user-id="<?php echo absint( $user_id ); ?>">
	<?php if ( empty( $fields ) ) : ?>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'user',
				'title' => __( 'No profile info', 'buddynext' ),
				'body'  => __( 'There is nothing to display yet.', 'buddynext' ),
			)
		);
		?>
	<?php else : ?>
		<dl class="bn-profile-fields-list">
			<?php
			foreach ( $fields as $key => $field ) :
				if ( empty( $field['value'] ) ) {
					continue;
				}

				$field_type  = (string) ( $field['type'] ?? '' );
				$field_value = $field['value'] ?? '';

				// Build Free's default HTML for the value cell.
				if ( 'url' === $field_type && ! empty( $field_value ) ) {
					$default_html = sprintf(
						'<a href="%1$s" class="bn-field-link" rel="nofollow noopener noreferrer" target="_blank">%2$s</a>',
						esc_url( (string) $field_value ),
						esc_html( (string) $field_value )
					);
				} else {
					$default_html = esc_html( (string) $field_value );
				}

				/**
				 * Filter the rendered HTML for a single profile-field value.
				 *
				 * Pro hooks here to render advanced field types (location maps,
				 * file links, conditional widgets) without touching Free. The
				 * default $default_html is already escaped — handlers MUST
				 * return safe HTML.
				 *
				 * @since 1.1.0
				 *
				 * @param string               $default_html Default escaped HTML.
				 * @param string               $type         Field type slug.
				 * @param array<string, mixed> $field        Full field row.
				 * @param mixed                $value        Current value.
				 * @param int                  $user_id      User whose profile is being rendered.
				 */
				$rendered_html = apply_filters(
					'buddynext_profile_field_render',
					$default_html,
					$field_type,
					$field,
					$field_value,
					(int) $user_id
				);
				?>
				<div class="bn-profile-field">
					<dt class="bn-profile-field__label">
						<?php echo esc_html( $field['label'] ?? ucfirst( (string) $key ) ); ?>
					</dt>
					<dd class="bn-profile-field__value">
						<?php echo wp_kses_post( $rendered_html ); ?>
					</dd>
				</div>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>
</section>
