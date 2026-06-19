<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext companion registry.
 *
 * A single declarative, filterable catalog of the Wbcom plugins BuddyNext
 * integrates with (MediaVerse, Jetonomy, Gamification, Career Board). Each
 * entry is DATA, not code — Pro and third parties extend the list via the
 * `buddynext_companions` filter. Every UI + integration decision keys off
 * `is_active()` (a runtime capability probe), never a hardcoded plugin path, so
 * "works standalone" and "no duplication" both hold: capability present →
 * delegate; absent → offer to install.
 *
 * The free item_id + key for each companion are the values baked into that
 * plugin's own EDD SL SDK setup (its main file), so the one-click free install
 * speaks the exact channel the companion already uses for updates.
 *
 * @package BuddyNext\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Integrations;

/**
 * Declarative catalog of installable companion plugins.
 */
final class CompanionRegistry {

	/**
	 * Resolve the companion catalog. Each entry:
	 *   label     string   Display name.
	 *   why       string   One-line value proposition.
	 *   detect    callable Returns true when the companion's capability is live.
	 *   free      array    { item_id, key, basename } for one-click free install.
	 *   store_url string   Product page for the "Get Pro" link (wbcomdesigns.com only).
	 *   unlocks   string   What this turns on inside BuddyNext when connected.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		/**
		 * Filter the BuddyNext companion catalog. Pro + third-party plugins add
		 * their own entries here; the installer + admin screen render whatever this
		 * returns.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, array<string, mixed>> $companions Slug => entry.
		 */
		return (array) apply_filters(
			'buddynext_companions',
			array(
				'wpmediaverse'    => array(
					'label'     => 'MediaVerse',
					'why'       => __( 'Direct messaging, media galleries, and social feeds.', 'buddynext' ),
					'detect'    => static fn(): bool => class_exists( '\\WPMediaVerse\\Core\\Plugin' ),
					'free'      => array(
						'item_id'  => 1660826,
						'key'      => 'wbcomfree7a9c2e5d1f8b4c6a3e0d9b2f7c1a8e44',
						'basename' => 'wpmediaverse/wpmediaverse.php',
					),
					'store_url' => 'https://wbcomdesigns.com/downloads/wpmediaverse/',
					'unlocks'   => __( 'Member-to-member direct messaging inside BuddyNext.', 'buddynext' ),
				),
				'jetonomy'        => array(
					'label'     => 'Jetonomy',
					'why'       => __( 'Forum-style threaded discussions and Q&A boards.', 'buddynext' ),
					'detect'    => static fn(): bool => class_exists( '\\Jetonomy\\Plugin' ) || function_exists( 'jetonomy' ),
					'free'      => array(
						'item_id'  => 1660320,
						'key'      => 'wbcomfreec7e2a9b45d8f1c3e6a0b9d2f7c4e8a11',
						'basename' => 'jetonomy/jetonomy.php',
					),
					'store_url' => 'https://wbcomdesigns.com/downloads/jetonomy/',
					'unlocks'   => __( 'Forum activity surfaced in the BuddyNext feed.', 'buddynext' ),
				),
				'wb-gamification' => array(
					'label'     => 'Gamification',
					'why'       => __( 'Points, badges, levels, and leaderboards.', 'buddynext' ),
					'detect'    => static fn(): bool => function_exists( 'wb_gam_submit_event' ) || defined( 'WB_GAM_VERSION' ),
					'free'      => array(
						'item_id'  => 1662147,
						'key'      => 'wbcomfree6e2a9c1d7b4f3c8a0e5d9b2f1a7c6e11',
						'basename' => 'wb-gamification/wb-gamification.php',
					),
					'store_url' => 'https://wbcomdesigns.com/downloads/wb-gamification/',
					'unlocks'   => __( 'Badges + leaderboard on member profiles.', 'buddynext' ),
				),
				'wp-career-board' => array(
					'label'     => 'Career Board',
					'why'       => __( 'Job listings and applicant management.', 'buddynext' ),
					// Probe WCB_VERSION (defined when Career Board loads) + its real
					// class \WCB\Core\Plugin. The old \WP_Career_Board\Plugin class
					// never existed, so detect() always returned false and the card
					// showed "Activate" even when the plugin was active.
					'detect'    => static fn(): bool => defined( 'WCB_VERSION' ) || class_exists( '\\WCB\\Core\\Plugin' ),
					'free'      => array(
						'item_id'  => 1659888,
						'key'      => 'wbcomfree5b8c1e7a9d3f2a4c6e0d1b7f9c2a6e00',
						'basename' => 'wp-career-board/wp-career-board.php',
					),
					'store_url' => 'https://wbcomdesigns.com/downloads/wp-career-board/',
					'unlocks'   => __( 'Job posts as activity cards in the feed.', 'buddynext' ),
					// Where to send the admin after a one-click install so they can
					// finish setup, instead of leaving them on the integrations screen.
					'setup_url' => 'admin.php?page=wpcb-settings',
				),
				'learnomy'        => array(
					'label'     => 'Learnomy',
					'why'       => __( 'Courses, lessons, and quizzes — a full LMS for your community.', 'buddynext' ),
					'detect'    => static fn(): bool => defined( 'LEARNOMY_VERSION' ) || function_exists( 'learnomy' ),
					'free'      => array(
						'item_id'  => 1662698,
						'key'      => 'wbcomfree5d8a1f3c7b2e9a4c6f0d1e8b3c9a7f25',
						'basename' => 'learnomy/learnomy.php',
					),
					'store_url' => 'https://wbcomdesigns.com/downloads/learnomy/',
					'unlocks'   => __( 'Completed courses + certificates on member profiles.', 'buddynext' ),
				),
				'wb-listora'      => array(
					'label'     => 'Listora',
					'why'       => __( 'Directory listings — members publish and manage listings.', 'buddynext' ),
					'detect'    => static fn(): bool => defined( 'WB_LISTORA_VERSION' ),
					// Free download credentials mirror WB Listora's own EDD-SL SDK
					// registration (wb-listora.php: item_id 1662779 + the shared free
					// license key). CompanionInstaller activates in place when the
					// plugin is already on disk.
					'free'      => array(
						'item_id'  => 1662779,
						'key'      => 'wbcomfree8a5d1c7e3f2b9a4c6e0d1b7f9c2a6e55',
						'basename' => 'wb-listora/wb-listora.php',
					),
					'store_url' => 'https://wbcomdesigns.com/downloads/wb-listora/',
					'unlocks'   => __( 'Member listings surfaced in the feed + on profiles.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Get one companion entry by slug, or null if unknown.
	 *
	 * @param string $slug Companion slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Whether a companion's capability is live (its detect probe passes).
	 *
	 * @param string $slug Companion slug.
	 * @return bool
	 */
	public static function is_active( string $slug ): bool {
		$entry = self::get( $slug );
		if ( null === $entry || ! is_callable( $entry['detect'] ?? null ) ) {
			return false;
		}
		return (bool) call_user_func( $entry['detect'] );
	}

	/**
	 * Whether the companion's plugin file exists on disk (installed, maybe inactive).
	 *
	 * @param string $slug Companion slug.
	 * @return bool
	 */
	public static function is_installed( string $slug ): bool {
		$entry    = self::get( $slug );
		$basename = (string) ( $entry['free']['basename'] ?? '' );
		if ( '' === $basename ) {
			return false;
		}
		return file_exists( trailingslashit( WP_PLUGIN_DIR ) . $basename );
	}

	/**
	 * Resolve a companion's lifecycle state for the UI.
	 *
	 * @param string $slug Companion slug.
	 * @return string 'active' | 'inactive' | 'not_installed' | 'unknown'.
	 */
	public static function status( string $slug ): string {
		if ( null === self::get( $slug ) ) {
			return 'unknown';
		}
		if ( self::is_active( $slug ) ) {
			return 'active';
		}
		if ( self::is_installed( $slug ) ) {
			return 'inactive';
		}
		return 'not_installed';
	}
}
