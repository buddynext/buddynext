<?php
/**
 * BuddyNext template part: profile-edit-section.
 *
 * Reusable `<section class="bn-card bn-ep-card">` wrapper used by every
 * "Section: X" card on the edit-profile page (About, Social Links, Work
 * Experience, Education, Interests, Privacy, Notifications, Account, etc).
 *
 * Body content is provided one of three ways (first non-empty wins):
 *   1. `$body_html`   — pre-built HTML (caller is responsible for escaping).
 *   2. `$body_action` — name of a `do_action()` hook fired inside the card body.
 *   3. nothing       — the part is a no-op shell (useful when the caller wraps
 *                      content with an inline open/close pair).
 *
 * Footer behaves the same way via `$footer_html` / `$footer_action`.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var string $id            Optional. HTML id for the `<section>`.
 * @var string $title         Required. Heading text.
 * @var string $subtitle      Optional. Supporting text under the title (sentence-case).
 * @var string $description   Optional. Alias for subtitle (kept for caller flexibility).
 * @var string $title_id      Optional. id for the `<h2>` (used for aria-labelledby).
 * @var string $body_html     Optional. Pre-built HTML for the card body.
 * @var string $body_action   Optional. do_action() hook fired inside the card body.
 * @var array  $body_classes  Optional. Extra CSS classes appended to `.bn-ep-card-body`.
 * @var string $footer_html   Optional. Pre-built HTML for the card footer.
 * @var string $footer_action Optional. do_action() hook fired inside the card footer.
 * @var array  $classes       Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_edit_section_before', $args )
 *   - do_action( 'buddynext_part_profile_edit_section_after',  $args )
 *   - do_action( $body_action,   $args ) (if set)
 *   - do_action( $footer_action, $args ) (if set)
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_edit_section_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_edit_section_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'id'            => isset( $id ) ? (string) $id : '',
	'title'         => isset( $title ) ? (string) $title : '',
	'subtitle'      => isset( $subtitle ) ? (string) $subtitle : '',
	'description'   => isset( $description ) ? (string) $description : '',
	'title_id'      => isset( $title_id ) ? (string) $title_id : '',
	'body_html'     => isset( $body_html ) ? (string) $body_html : '',
	'body_action'   => isset( $body_action ) ? (string) $body_action : '',
	'body_classes'  => isset( $body_classes ) ? (array) $body_classes : array(),
	'footer_html'   => isset( $footer_html ) ? (string) $footer_html : '',
	'footer_action' => isset( $footer_action ) ? (string) $footer_action : '',
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_edit_section_args', $args );

if ( '' === (string) $args['title'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-ep-card' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_edit_section_classes', $bn_classes, $args );
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

$bn_section_id = (string) $args['id'];
$bn_title_id   = (string) $args['title_id'];
$bn_title      = (string) $args['title'];
$bn_subtitle   = '' !== (string) $args['subtitle'] ? (string) $args['subtitle'] : (string) $args['description'];

$bn_has_body   = '' !== (string) $args['body_html'] || '' !== (string) $args['body_action'];
$bn_has_footer = '' !== (string) $args['footer_html'] || '' !== (string) $args['footer_action'];

do_action( 'buddynext_part_profile_edit_section_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>"
	<?php
	if ( '' !== $bn_section_id ) :
		?>
		id="<?php echo esc_attr( $bn_section_id ); ?>"<?php endif; ?>
	<?php
	if ( '' !== $bn_title_id ) :
		?>
		aria-labelledby="<?php echo esc_attr( $bn_title_id ); ?>"<?php endif; ?>>
	<header class="bn-ep-card-header">
		<h2 class="bn-ep-card-title"
			<?php
			if ( '' !== $bn_title_id ) :
				?>
				id="<?php echo esc_attr( $bn_title_id ); ?>"<?php endif; ?>>
			<?php echo esc_html( $bn_title ); ?>
		</h2>
		<?php if ( '' !== $bn_subtitle ) : ?>
			<p class="bn-ep-card-subtitle"><?php echo esc_html( $bn_subtitle ); ?></p>
		<?php endif; ?>
	</header>
	<?php
	if ( $bn_has_body ) :
		$bn_body_classes = array_merge( array( 'bn-ep-card-body' ), array_filter( (array) $args['body_classes'], 'is_string' ) );
		$bn_body_class   = trim( implode( ' ', array_unique( array_filter( $bn_body_classes ) ) ) );
		?>
		<div class="<?php echo esc_attr( $bn_body_class ); ?>">
			<?php
			if ( '' !== (string) $args['body_html'] ) {
				// Caller-supplied HTML; caller is responsible for escaping.
				echo $args['body_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( '' !== (string) $args['body_action'] ) {
				do_action( (string) $args['body_action'], $args );
			}
			?>
		</div>
	<?php endif; ?>
	<?php if ( $bn_has_footer ) : ?>
		<footer class="bn-ep-card-footer">
			<?php
			if ( '' !== (string) $args['footer_html'] ) {
				// Caller-supplied HTML; caller is responsible for escaping.
				echo $args['footer_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( '' !== (string) $args['footer_action'] ) {
				do_action( (string) $args['footer_action'], $args );
			}
			?>
		</footer>
	<?php endif; ?>
</section>
<?php
do_action( 'buddynext_part_profile_edit_section_after', $args );
