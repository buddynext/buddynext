<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WP-CLI command for the demo dataset.
 *
 * Thin wrapper over DemoDataService so the CLI and the admin button share one
 * engine. Registered in Plugin::init() under `wp buddynext demo`.
 *
 *   wp buddynext demo seed       Populate the demo community.
 *   wp buddynext demo cleanup    Remove everything the seeder created.
 *   wp buddynext demo status     Show what is currently installed.
 *
 * @package BuddyNext\Demo
 */

declare( strict_types=1 );

namespace BuddyNext\Demo;

/**
 * `wp buddynext demo` command handlers.
 */
class DemoCommand {

	/**
	 * Populate a realistic demo community (members, spaces, posts, social graph)
	 * using bundled offline images.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext demo seed
	 *
	 * @when after_wp_load
	 *
	 * @return void
	 */
	public function seed(): void {
		$service = new DemoDataService();
		if ( $service->is_seeded() ) {
			\WP_CLI::warning( 'Demo data is already installed. Run `wp buddynext demo cleanup` first.' );
			return;
		}
		$summary = $service->seed(
			static function ( string $message ): void {
				\WP_CLI::log( $message );
			}
		);
		\WP_CLI::success(
			sprintf(
				'Seeded %d members, %d spaces, %d posts, %d profile fields.',
				$summary['users'],
				$summary['spaces'],
				$summary['posts'],
				$summary['fields']
			)
		);
	}

	/**
	 * Remove everything the demo seeder created.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext demo cleanup
	 *
	 * @when after_wp_load
	 *
	 * @return void
	 */
	public function cleanup(): void {
		$service = new DemoDataService();
		if ( ! $service->is_seeded() ) {
			\WP_CLI::warning( 'No demo data is installed.' );
			return;
		}
		$removed = $service->cleanup(
			static function ( string $message ): void {
				\WP_CLI::log( $message );
			}
		);
		\WP_CLI::success(
			sprintf(
				'Removed %d posts, %d spaces, %d members, %d profile fields.',
				$removed['posts'],
				$removed['spaces'],
				$removed['users'],
				$removed['fields']
			)
		);
	}

	/**
	 * Show what the demo dataset currently contains.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext demo status
	 *
	 * @when after_wp_load
	 *
	 * @return void
	 */
	public function status(): void {
		$service = new DemoDataService();
		if ( ! $service->is_seeded() ) {
			\WP_CLI::log( 'No demo data installed.' );
			return;
		}
		$s = $service->summary();
		\WP_CLI::log( sprintf( 'Members:        %d', $s['users'] ) );
		\WP_CLI::log( sprintf( 'Spaces:         %d', $s['spaces'] ) );
		\WP_CLI::log( sprintf( 'Posts:          %d', $s['posts'] ) );
		\WP_CLI::log( sprintf( 'Profile fields: %d', $s['fields'] ) );
	}
}
