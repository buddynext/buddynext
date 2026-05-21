<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Hub shell top bar.
 *
 * Renders the BN-owned top bar that sits inside .bn-app, below the host
 * theme's get_header() output. Provides brand, global search, theme toggle,
 * and font-size controls.
 *
 * Context variables (all optional):
 *   $hub  string  Current hub slug (feed / people / spaces / messages / …).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

use BuddyNext\Core\PageRouter;

if ( ! isset( $hub ) ) {
	$hub = (string) get_query_var( 'bn_hub', '' );
}

$bn_search_url = PageRouter::search_url();
$bn_feed_url   = PageRouter::activity_url();
$bn_brand_name = (string) get_bloginfo( 'name' );
$bn_brand_init = '' !== $bn_brand_name ? strtoupper( substr( $bn_brand_name, 0, 1 ) ) : 'B';
?>
<header class="bn-app__topbar" role="banner">
	<div class="bn-app__topbar-inner">

		<a href="<?php echo esc_url( $bn_feed_url ); ?>" class="bn-app__brand" aria-label="<?php echo esc_attr( $bn_brand_name ); ?>">
			<span class="bn-app__brand-mark" aria-hidden="true"><?php echo esc_html( $bn_brand_init ); ?></span>
			<span class="bn-app__brand-text"><?php echo esc_html( $bn_brand_name ); ?></span>
		</a>

		<form class="bn-app__search" action="<?php echo esc_url( $bn_search_url ); ?>" method="get" role="search">
			<label for="bn-app-search" class="bn-sr-only"><?php esc_html_e( 'Search', 'buddynext' ); ?></label>
			<span class="bn-app__search-icon" aria-hidden="true">
				<?php buddynext_icon( 'search' ); ?>
			</span>
			<input
				type="search"
				id="bn-app-search"
				name="q"
				class="bn-app__search-input"
				placeholder="<?php esc_attr_e( 'Search posts, people, spaces…', 'buddynext' ); ?>"
				autocomplete="off"
				value="
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the current search query, no state mutation.
				echo esc_attr( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '' );
				?>
				"
			/>
		</form>

		<div class="bn-app__topbar-spacer"></div>

		<div class="bn-app__topbar-actions">

			<div class="bn-app__font-scale" role="group" aria-label="<?php esc_attr_e( 'Font size', 'buddynext' ); ?>">
				<button
					type="button"
					class="bn-app__font-scale-btn is-active"
					data-scale="100"
					aria-pressed="true"
					aria-label="<?php esc_attr_e( 'Default font size', 'buddynext' ); ?>"
				>A</button>
				<button
					type="button"
					class="bn-app__font-scale-btn"
					data-scale="110"
					aria-pressed="false"
					aria-label="<?php esc_attr_e( 'Large font size', 'buddynext' ); ?>"
				>A+</button>
				<button
					type="button"
					class="bn-app__font-scale-btn"
					data-scale="120"
					aria-pressed="false"
					aria-label="<?php esc_attr_e( 'Extra large font size', 'buddynext' ); ?>"
				>A++</button>
			</div>

			<button
				type="button"
				class="bn-app__icon-btn"
				data-bn-action="toggle-theme"
				aria-label="<?php esc_attr_e( 'Toggle light/dark theme', 'buddynext' ); ?>"
			>
				<?php buddynext_icon( 'globe' ); ?>
			</button>

			<?php if ( is_user_logged_in() ) : ?>
				<?php $bn_current_user_id = get_current_user_id(); ?>
				<a
					href="<?php echo esc_url( PageRouter::profile_url( $bn_current_user_id ) ); ?>"
					class="bn-app__icon-btn"
					aria-label="<?php esc_attr_e( 'Open profile', 'buddynext' ); ?>"
				>
					<?php buddynext_icon( 'user' ); ?>
				</a>
			<?php else : ?>
				<a
					href="<?php echo esc_url( PageRouter::auth_url() ); ?>"
					class="bn-app__icon-btn"
					aria-label="<?php esc_attr_e( 'Log in', 'buddynext' ); ?>"
				>
					<?php buddynext_icon( 'user' ); ?>
				</a>
			<?php endif; ?>

		</div>
	</div>
</header>
