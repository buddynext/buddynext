<?php
/**
 * BuddyNext interactive media lightbox shell.
 *
 * Printed once in the footer on BuddyNext front-end pages. BN-native UX (no
 * WPMediaVerse JS/CSS). Left pane = media stage (image/video + gallery nav);
 * right pane = interaction panel (author, views, reactions, favorite/share/
 * download/open, comments). The panel's per-media data is populated by
 * assets/js/media/lightbox.js, which calls the engine REST routes
 * (mvs/v1/media/{id}/reactions|comments|favorite|view) — API-level only.
 *
 * The reaction row is server-rendered from the owner-enabled reaction set
 * (ReactionService::reaction_types(), which honours the enabled-reactions option
 * and the Pro buddynext_reaction_types filter); JS only toggles the active state
 * + counts. Emoji render as images via buddynext_get_emoji() so BuddyNext never
 * emits raw emoji characters in markup.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Honor the owner's enabled-reactions palette (Settings -> Activity Feed) and any
// Pro custom slugs, mirroring parts/post-actions.php - previously this hardcoded
// all six, so a reaction the owner disabled still showed here (and its click was
// silently coerced server-side). ReactionService::enabled_reactions() is the one
// source for the set + each slug's translated label.
$bn_lb_reactions = class_exists( '\\BuddyNext\\Reactions\\ReactionService' )
	? (array) \BuddyNext\Reactions\ReactionService::enabled_reactions()
	: array();

// Every WRITE here (react / favorite / share / comment) hits an auth-required
// engine route, so rather than show controls that fail on click (a "Log in to
// react" toast), they are omitted from the DOM for logged-out visitors.
// lightbox.js null-checks each control, so their absence is a no-op there.
//
// READING is a different question, and conflating the two was a bug. This one
// flag used to gate the comments PANEL as well as the comment FORM, so a guest
// was shown a media with no comments on it at all — while
// GET /mvs/v1/media/{id}/comments answers 200 with the thread to an anonymous
// caller. The comments are public; only posting is not. A visitor deciding
// whether to join was being shown an empty, lifeless version of an active
// conversation.
$bn_lb_can_interact = is_user_logged_in();
?>
<div class="bn-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Media viewer', 'buddynext' ); ?>" hidden>
	<?php
	// The backdrop is a click-to-dismiss affordance for POINTER users only. It is
	// kept out of the tab order (tabindex="-1"): keyboard users already get the
	// real Close button (focused on open) and Escape, and a focusable, invisible,
	// viewport-sized button would otherwise be a tab stop with nowhere to draw a
	// focus ring. That is why its focus styles stay flat.
	?>
	<button type="button" class="bn-lightbox__backdrop" data-bn-lb-close tabindex="-1" aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"></button>
	<div class="bn-lightbox__dialog">

		<div class="bn-lightbox__stage">
			<button type="button" class="bn-lightbox__nav bn-lightbox__nav--prev" data-bn-lb-prev aria-label="<?php esc_attr_e( 'Previous', 'buddynext' ); ?>"><?php buddynext_icon( 'chevron-left' ); ?></button>
			<div class="bn-lightbox__media-wrap" data-bn-lb-stage></div>
			<button type="button" class="bn-lightbox__nav bn-lightbox__nav--next" data-bn-lb-next aria-label="<?php esc_attr_e( 'Next', 'buddynext' ); ?>"><?php buddynext_icon( 'chevron-right' ); ?></button>

			<?php // Private DM media has no social layer, so the side panel is dropped (.bn-lightbox--dm) and the media goes full-bleed. These floating controls over the stage carry the only chrome a 1:1 image needs: sender, download, close. Populated by assets/js/media/lightbox.js. ?>
			<div class="bn-lightbox__dm-chrome">
				<div class="bn-lightbox__dm-author" data-bn-lb-dm-author></div>
				<span class="bn-lightbox__dm-spacer"></span>
				<a class="bn-lightbox__dm-btn" data-bn-lb-dm-download download target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'Download', 'buddynext' ); ?>"><?php buddynext_icon( 'download' ); ?></a>
				<button type="button" class="bn-lightbox__dm-btn" data-bn-lb-close aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
			</div>
		</div>

		<aside class="bn-lightbox__panel">
			<header class="bn-lightbox__panel-head">
				<div class="bn-lightbox__author" data-bn-lb-author></div>
				<button type="button" class="bn-lightbox__close" data-bn-lb-close aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
			</header>

			<div class="bn-lightbox__panel-body">
				<p class="bn-lightbox__views" data-bn-lb-views></p>

				<?php if ( $bn_lb_can_interact && $bn_lb_reactions ) : ?>
				<div class="bn-lightbox__reactions" role="group" aria-label="<?php esc_attr_e( 'React to this media', 'buddynext' ); ?>">
					<?php
					foreach ( $bn_lb_reactions as $bn_lb_reaction ) :
						$bn_lb_slug  = (string) ( $bn_lb_reaction['slug'] ?? '' );
						$bn_lb_label = (string) ( $bn_lb_reaction['label'] ?? '' );
						if ( '' === $bn_lb_slug ) {
							continue;
						}
						?>
						<button type="button" class="bn-lightbox__reaction" data-reaction="<?php echo esc_attr( $bn_lb_slug ); ?>" aria-label="<?php echo esc_attr( $bn_lb_label ); ?>" aria-pressed="false">
							<?php echo buddynext_get_emoji( $bn_lb_slug, 'bn-lightbox__reaction-emoji' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="bn-lightbox__reaction-count" data-bn-lb-reaction-count hidden>0</span>
						</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<div class="bn-lightbox__actions">
					<?php if ( $bn_lb_can_interact ) : ?>
					<button type="button" class="bn-lightbox__action" data-bn-lb-favorite aria-pressed="false">
						<?php buddynext_icon( 'heart' ); ?><span><?php esc_html_e( 'Favorite', 'buddynext' ); ?></span>
					</button>
					<button type="button" class="bn-lightbox__action" data-bn-lb-share>
						<?php buddynext_icon( 'share' ); ?><span><?php esc_html_e( 'Share', 'buddynext' ); ?></span>
					</button>
					<?php endif; ?>
					<a class="bn-lightbox__action" data-bn-lb-download download target="_blank" rel="noopener">
						<?php buddynext_icon( 'download' ); ?><span><?php esc_html_e( 'Download', 'buddynext' ); ?></span>
					</a>

					<?php
					/*
					 * Abuse controls. With BuddyNext active, /media/{slug}/ redirects to the source
					 * activity by design, so WPMediaVerse's own media-single template — the one that
					 * carries Report and Block — never renders. This lightbox IS the media viewer on
					 * a BuddyNext site, and it offered Favorite / Share / Download and nothing else:
					 * a UGC community with no in-UI way to report a piece of media at all.
					 *
					 * Report posts to WPMediaVerse's existing queue (mvs/v1/media/{id}/report); we do
					 * not keep a second one. Block is BuddyNext's own (users/{id}/block) — it is the
					 * member being blocked, not the file.
					 *
					 * Hidden for the author's own media (nobody reports themselves) — the JS toggles
					 * these off once the media meta says the viewer is the uploader.
					 */
					?>
					<?php if ( $bn_lb_can_interact ) : ?>
					<button type="button" class="bn-lightbox__action bn-lightbox__action--danger" data-bn-lb-report hidden>
						<?php buddynext_icon( 'flag' ); ?><span><?php esc_html_e( 'Report', 'buddynext' ); ?></span>
					</button>
					<button type="button" class="bn-lightbox__action bn-lightbox__action--danger" data-bn-lb-block hidden>
						<?php buddynext_icon( 'ban' ); ?><span><?php esc_html_e( 'Block', 'buddynext' ); ?></span>
					</button>
					<?php endif; ?>
				</div>

				<?php // Rendered for everyone — reading a public thread needs no account. ?>
				<div class="bn-lightbox__comments" data-bn-lb-comments aria-live="polite"></div>
			</div>

			<?php if ( $bn_lb_can_interact ) : ?>
				<?php // Posting does need an account, so the form stays gated. ?>
			<form class="bn-lightbox__comment-form" data-bn-lb-comment-form>
				<?php // A placeholder is not an accessible name - screen readers announce the input as unlabelled once the user types. aria-label carries the name. ?>
				<input type="text" class="bn-lightbox__comment-input" data-bn-lb-comment-input aria-label="<?php esc_attr_e( 'Add a comment', 'buddynext' ); ?>" placeholder="<?php esc_attr_e( 'Add a comment…', 'buddynext' ); ?>" autocomplete="off">
				<button type="submit" class="bn-btn" data-variant="primary" data-size="sm"><?php esc_html_e( 'Post', 'buddynext' ); ?></button>
			</form>
			<?php else : ?>
				<?php
				// Same idiom as ShortcodeService: BuddyNext's own auth page when the
				// owner has one, wp_login_url() otherwise, and the current page as the
				// return. Deliberately not hand-built from $_SERVER — a login link is
				// not worth reading unsanitised superglobals for.
				$bn_lb_auth  = \BuddyNext\Core\PageRouter::auth_url();
				$bn_lb_login = '' !== $bn_lb_auth
					? add_query_arg( 'redirect_to', rawurlencode( (string) get_permalink() ), $bn_lb_auth )
					: wp_login_url( (string) get_permalink() );
				?>
				<p class="bn-lightbox__comment-login">
					<?php
					printf(
						/* translators: %s: log-in link. */
						esc_html__( '%s to comment.', 'buddynext' ),
						'<a href="' . esc_url( $bn_lb_login ) . '">' . esc_html__( 'Log in', 'buddynext' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>
		</aside>
	</div>
</div>
