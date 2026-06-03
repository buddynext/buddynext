<?php
/**
 * BuddyNext template part: post-byline.
 *
 * Renders the post-card head row: avatar link, author name, optional handle,
 * optional member-type badge, timestamp, optional edited label, optional
 * privacy chip and the options-menu slot. Mirrors the markup previously
 * inlined in `templates/partials/post-card.php` between the `<!-- Head row -->`
 * comment and the closing `</header>` tag.
 *
 * The options-menu is rendered via `parts/post-options-menu.php` inside this
 * part so that the `.bn-post-card__head` flex container keeps the avatar,
 * author block, and menu-wrap as siblings (byte-identical to the pre-refactor
 * markup).
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array    $bn_post            Hydrated post array.
 * @var int      $bn_post_id         Post ID.
 * @var int      $author_id          Author user ID.
 * @var string   $display_name       Author display name.
 * @var string   $username           Author user_nicename.
 * @var string   $avatar_url         Avatar URL.
 * @var string   $initials           Two-letter fallback initials.
 * @var string   $member_type_label  Member-type badge label.
 * @var int      $degree             Viewer→author connection degree (1=1st, 2=2nd; 0/3+ hidden).
 * @var bool     $show_follow        When true, render an inline Follow button (caller gates on not-already-following).
 * @var string   $created_at         UTC MySQL datetime.
 * @var string   $post_time          Already-escaped relative time HTML.
 * @var string   $edited_label       Already-escaped "(edited)" label.
 * @var string   $privacy_label      Already-escaped privacy label.
 * @var string   $privacy_icon       Pre-rendered (wp_kses-sanitized) icon SVG.
 * @var string   $profile_link       Author profile URL.
 * @var array    $options_menu_args  Args forwarded to `parts/post-options-menu.php`.
 * @var array    $classes            Optional extra CSS classes.
 *
 * Fires:
 *   - do_action( 'buddynext_part_post_byline_before', $args )
 *   - do_action( 'buddynext_part_post_byline_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_post_byline_args',    array $args )
 *   - apply_filters( 'buddynext_part_post_byline_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'bn_post'           => isset( $bn_post ) && is_array( $bn_post ) ? $bn_post : array(),
	'bn_post_id'        => isset( $bn_post_id ) ? absint( $bn_post_id ) : 0,
	'author_id'         => isset( $author_id ) ? absint( $author_id ) : 0,
	'display_name'      => isset( $display_name ) ? (string) $display_name : '',
	'username'          => isset( $username ) ? (string) $username : '',
	'avatar_url'        => isset( $avatar_url ) ? (string) $avatar_url : '',
	'initials'          => isset( $initials ) ? (string) $initials : '',
	'member_type_label' => isset( $member_type_label ) ? (string) $member_type_label : '',
	'degree'            => isset( $degree ) ? (int) $degree : 0,
	'show_follow'       => isset( $show_follow ) ? (bool) $show_follow : false,
	'created_at'        => isset( $created_at ) ? (string) $created_at : '',
	'post_time'         => isset( $post_time ) ? (string) $post_time : '',
	'edited_label'      => isset( $edited_label ) ? (string) $edited_label : '',
	'privacy_label'     => isset( $privacy_label ) ? (string) $privacy_label : '',
	'privacy_icon'      => isset( $privacy_icon ) ? (string) $privacy_icon : '',
	'profile_link'      => isset( $profile_link ) ? (string) $profile_link : '#',
	'options_menu_args' => isset( $options_menu_args ) && is_array( $options_menu_args ) ? $options_menu_args : array(),
	'classes'           => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_post_byline_args', $args );

if ( 0 === (int) $args['bn_post_id'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-post-card__head' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_post_byline_classes', $bn_classes, $args );
$bn_class   = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

do_action( 'buddynext_part_post_byline_before', $args );
?>
<header class="<?php echo esc_attr( $bn_class ); ?>">
	<a href="<?php echo esc_url( (string) $args['profile_link'] ); ?>" class="bn-post-card__avatar-link" tabindex="-1" aria-hidden="true">
		<?php if ( '' !== (string) $args['avatar_url'] ) : ?>
			<span class="bn-avatar" data-size="md">
				<img
					src="<?php echo esc_url( (string) $args['avatar_url'] ); ?>"
					alt="<?php echo esc_attr( (string) $args['display_name'] ); ?>"
					width="40"
					height="40"
					loading="lazy"
				>
			</span>
		<?php else : ?>
			<span class="bn-avatar" data-size="md" aria-hidden="true"><?php echo esc_html( (string) $args['initials'] ); ?></span>
		<?php endif; ?>
	</a>

	<div class="bn-post-card__author-block">
		<div class="bn-post-card__author">
			<a
				id="bn-post-author-<?php echo absint( $args['bn_post_id'] ); ?>"
				href="<?php echo esc_url( (string) $args['profile_link'] ); ?>"
				class="bn-post-card__author-name bn-hover-user"
				data-bn-user-id="<?php echo absint( $args['author_id'] ); ?>"
				data-bn-user-name="<?php echo esc_attr( (string) $args['display_name'] ); ?>"
				data-bn-user-handle="<?php echo esc_attr( (string) $args['username'] ); ?>"
			><?php echo esc_html( (string) $args['display_name'] ); ?></a>

			<?php if ( '' !== (string) $args['member_type_label'] ) : ?>
				<span class="bn-badge bn-post-card__member-type" data-tone="accent"><?php echo esc_html( (string) $args['member_type_label'] ); ?></span>
			<?php endif; ?>

			<?php
			// Connection-degree pill (1st / 2nd) — mirrors parts/member-card +
			// parts/profile-hero. Only 1st/2nd are surfaced; 3rd+ and "self"
			// (degree 0) stay quiet so the byline doesn't get noisy.
			$bn_byline_degree = (int) $args['degree'];
			if ( 1 === $bn_byline_degree || 2 === $bn_byline_degree ) :
				$bn_byline_degree_label = 1 === $bn_byline_degree
					? __( '1st', 'buddynext' )
					: __( '2nd', 'buddynext' );
				?>
				<span
					class="bn-post-card__degree"
					data-degree="<?php echo esc_attr( (string) $bn_byline_degree ); ?>"
					title="<?php echo esc_attr( 1 === $bn_byline_degree ? __( '1st-degree connection', 'buddynext' ) : __( '2nd-degree connection', 'buddynext' ) ); ?>"
				><?php echo esc_html( $bn_byline_degree_label ); ?></span>
			<?php endif; ?>

			<?php
			$bn_byline_meta = (string) apply_filters(
				'buddynext_post_byline_meta_html',
				'',
				(int) $args['author_id'],
				(int) $args['bn_post_id']
			);
			if ( '' !== $bn_byline_meta ) {
				echo $bn_byline_meta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by hooked plugin per filter contract
			}
			?>
		</div>

		<div class="bn-post-card__meta">
			<?php if ( '' !== (string) $args['username'] ) : ?>
				<a class="bn-post-card__handle" href="<?php echo esc_url( (string) $args['profile_link'] ); ?>">@<?php echo esc_html( (string) $args['username'] ); ?></a>
				<span class="bn-post-card__sep" aria-hidden="true">&middot;</span>
			<?php endif; ?>

			<?php
			// Space attribution — show which space a post came from. Cached
			// per-space via SpaceService::get(), so a feed of N posts hits
			// the DB at most once per unique space.
			$bn_space_id = isset( $args['bn_post']['space_id'] ) ? (int) $args['bn_post']['space_id'] : 0;
			if ( $bn_space_id > 0 ) {
				$bn_space = buddynext_service( 'spaces' )->get( $bn_space_id );
				if ( $bn_space && ! empty( $bn_space['name'] ) && ! empty( $bn_space['slug'] ) ) {
					$bn_space_url = home_url( '/spaces/' . $bn_space['slug'] . '/' );
					?>
					<a class="bn-post-card__space-link" href="<?php echo esc_url( $bn_space_url ); ?>">
						<?php buddynext_icon( 'users' ); ?>
						<?php echo esc_html( (string) $bn_space['name'] ); ?>
					</a>
					<span class="bn-post-card__sep" aria-hidden="true">&middot;</span>
					<?php
				}
			}
			?>

			<a
				class="bn-post-card__time-link"
				href="<?php echo esc_url( PageRouter::post_url( (int) $args['bn_post_id'] ) ); ?>"
				aria-label="<?php esc_attr_e( 'Open post permalink', 'buddynext' ); ?>"
			><time
				class="bn-post-card__time"
				datetime="<?php echo esc_attr( (string) $args['created_at'] ); ?>"
				title="<?php echo esc_attr( (string) $args['created_at'] ); ?>"
			><?php echo $args['post_time']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside producer ?></time></a>

			<?php if ( '' !== (string) $args['edited_label'] ) : ?>
				<span class="bn-post-card__edited"><?php echo esc_html( (string) $args['edited_label'] ); ?></span>
			<?php endif; ?>

			<?php if ( '' !== (string) $args['privacy_label'] ) : ?>
				<span class="bn-post-card__sep" aria-hidden="true">&middot;</span>
				<span class="bn-post-card__privacy" aria-label="<?php echo esc_attr( (string) $args['privacy_label'] ); ?>">
					<?php echo $args['privacy_icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService::render wp_kses-sanitized SVG ?>
					<span><?php echo esc_html( (string) $args['privacy_label'] ); ?></span>
				</span>
			<?php endif; ?>
		</div>

		<?php
		// Inline Follow — rendered below the byline meta (inside the author
		// block) so it never competes for the head row's width and squeeze the
		// author info into a vertical stack on narrow cards (explore grid).
		// Self-guards self / guest / blocked; known state avoids a re-query.
		if ( ! empty( $args['show_follow'] ) && (int) $args['author_id'] > 0 ) :
			?>
			<div class="bn-post-card__follow">
				<?php
				buddynext_get_template(
					'partials/follow-button.php',
					array(
						'user_id'         => (int) $args['author_id'],
						'known_following' => false,
					)
				);
				?>
			</div>
			<?php
		endif;
		?>
	</div>

	<?php buddynext_get_template( 'parts/post-options-menu.php', (array) $args['options_menu_args'] ); ?>
</header>
<?php
do_action( 'buddynext_part_post_byline_after', $args );
