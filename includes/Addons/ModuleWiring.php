<?php
/**
 * Connects an addon's modules to the seams BuddyNext already has.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

use BuddyNext\Admin\Settings\Field;
use BuddyNext\Admin\Settings\Section;
use BuddyNext\Admin\Settings\SettingsDriver;
use BuddyNext\Admin\Settings\SettingsRegistry;
use BuddyNext\Contracts\ProvidesSettings;
use BuddyNext\Feed\IntegrationActivity;
use BuddyNext\Feed\PostService;
use BuddyNext\Search\SearchService;

/**
 * Internal. Each module interface maps onto one existing seam; nothing here is a
 * second path. Every callback runs behind a boundary: a module that throws is
 * switched off for the request and logged, and the community keeps working.
 */
final class ModuleWiring {

	/**
	 * Modules switched off after a failure: "slug:module" => true.
	 *
	 * @var array<string,bool>
	 */
	private static array $disabled = array();

	/**
	 * Wire one module of an addon.
	 *
	 * @param AbstractAddon $addon  The addon.
	 * @param object        $module One of its modules.
	 * @return void
	 */
	public static function wire( AbstractAddon $addon, object $module ): void {
		try {
			if ( $module instanceof FeedCardModule ) {
				self::feed_cards( $addon, $module );
			}
			if ( $module instanceof NotificationSource ) {
				self::notifications( $addon, $module );
			}
			if ( $module instanceof SearchSource ) {
				self::search( $addon, $module );
			}
			if ( $module instanceof SettingsModule ) {
				self::settings( $addon, $module );
			}
			if ( $module instanceof DiscoveryModule ) {
				self::discovery( $addon, $module );
			}
			if ( $module instanceof ProfilePanelModule ) {
				self::profile_panels( $addon, $module );
			}
		} catch ( \Throwable $e ) {
			self::fail( $addon, $module, $e );
		}
	}

	/**
	 * Wrap a callback so a throw switches its module off instead of fataling.
	 *
	 * @param AbstractAddon $addon    The addon.
	 * @param object        $module   The module the callback belongs to.
	 * @param callable      $callback The callback.
	 * @return \Closure Returns the first argument back when the callback throws or is off.
	 */
	private static function guard( AbstractAddon $addon, object $module, callable $callback ): \Closure {
		return static function ( ...$args ) use ( $addon, $module, $callback ) {
			if ( isset( self::$disabled[ self::id( $addon, $module ) ] ) ) {
				return $args[0] ?? null;
			}
			try {
				return $callback( ...$args );
			} catch ( \Throwable $e ) {
				self::fail( $addon, $module, $e );
				return $args[0] ?? null;
			}
		};
	}

	/**
	 * Switch a module off and say so.
	 *
	 * @param AbstractAddon $addon  The addon.
	 * @param object        $module The module.
	 * @param \Throwable    $e      What it threw.
	 * @return void
	 */
	private static function fail( AbstractAddon $addon, object $module, \Throwable $e ): void {
		self::$disabled[ self::id( $addon, $module ) ] = true;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the only trace of why a module went quiet.
		error_log( sprintf( 'BuddyNext addon "%s": module %s switched off: %s', $addon->slug(), get_class( $module ), $e->getMessage() ) );

		/**
		 * A module threw and has been switched off for the rest of the request.
		 *
		 * @since 1.2.7
		 *
		 * @param string     $slug   Addon slug.
		 * @param string     $module Module class.
		 * @param \Throwable $e      The error.
		 */
		do_action( 'buddynext_addon_module_failed', $addon->slug(), get_class( $module ), $e );
	}

	/**
	 * Whether a module has been switched off by a failure.
	 *
	 * @param AbstractAddon $addon  The addon.
	 * @param object        $module The module.
	 * @return bool
	 */
	public static function is_disabled( AbstractAddon $addon, object $module ): bool {
		return isset( self::$disabled[ self::id( $addon, $module ) ] );
	}

	/**
	 * Forget failures. Test-only.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$disabled = array();
	}

	/**
	 * Identity of a module for the failure list.
	 *
	 * @param AbstractAddon $addon  The addon.
	 * @param object        $module The module.
	 * @return string
	 */
	private static function id( AbstractAddon $addon, object $module ): string {
		return $addon->slug() . ':' . get_class( $module ) . '#' . spl_object_id( $module );
	}

	/**
	 * Feed cards: allowed type, label and icon, body renderer, Explore preview.
	 *
	 * @param AbstractAddon  $addon  The addon.
	 * @param FeedCardModule $module The module.
	 * @return void
	 */
	private static function feed_cards( AbstractAddon $addon, FeedCardModule $module ): void {
		$cards = array();
		foreach ( $module->card_types() as $type => $card ) {
			$type = sanitize_key( (string) $type );
			if ( '' === $type || ! is_array( $card ) || empty( $card['label'] ) ) {
				continue;
			}
			// Never take a built-in type, or one another bridge already renders.
			if ( in_array( $type, PostService::allowed_types(), true ) || has_filter( 'buddynext_render_post_body_' . $type ) ) {
				_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Card type "%s" is already taken.', $type ) ), '1.2.7' );
				continue;
			}
			$cards[ $type ] = $card;
		}
		if ( ! $cards ) {
			return;
		}

		add_filter( 'buddynext_feed_allowed_post_types', static fn( $types ) => array_merge( (array) $types, array_keys( $cards ) ) );
		add_filter(
			'buddynext_integration_card_types',
			static function ( $types ) use ( $cards ) {
				foreach ( $cards as $type => $card ) {
					$types[ $type ] = array(
						'label' => (string) $card['label'],
						'icon'  => (string) ( $card['icon'] ?? 'link' ),
					);
				}
				return $types;
			}
		);

		foreach ( $cards as $type => $card ) {
			add_filter(
				'buddynext_render_post_body_' . $type,
				self::guard(
					$addon,
					$module,
					static function ( $html, $args = array() ) use ( $card ): string {
						$args = (array) $args;
						return isset( $card['render'] ) && is_callable( $card['render'] )
							? (string) ( $card['render'] )( $args )
							: IntegrationActivity::render_bridge_card( $args );
					}
				),
				10,
				2
			);
		}

		add_filter(
			'buddynext_integration_card_preview',
			self::guard(
				$addon,
				$module,
				static function ( $meta, $type = '' ) use ( $cards ) {
					return isset( $cards[ $type ]['preview'] ) && is_callable( $cards[ $type ]['preview'] )
						? (array) ( $cards[ $type ]['preview'] )( (array) $meta )
						: $meta;
				}
			),
			10,
			2
		);
	}

	/**
	 * Notifications: the contract source, its types, and its visibility answer.
	 *
	 * @param AbstractAddon      $addon  The addon.
	 * @param NotificationSource $module The module.
	 * @return void
	 */
	private static function notifications( AbstractAddon $addon, NotificationSource $module ): void {
		$prefix = sanitize_key( $module->notification_prefix() );
		if ( '' === $prefix ) {
			return;
		}

		add_filter(
			'buddynext_notification_sources',
			static function ( $sources ) use ( $addon, $module, $prefix ) {
				$sources[ $addon->slug() ] = array(
					'hook'        => $module->notification_hook(),
					'integration' => $addon->slug(),
					'prefix'      => $prefix,
					'label'       => $addon->label(),
					'icon'        => $module->notification_icon(),
				);
				return $sources;
			}
		);
		// Declaring the types is what adopts the contract for this source.
		add_filter(
			$prefix . '_community_notification_types',
			self::guard( $addon, $module, static fn( $types ) => (array) $types + $module->notification_types() )
		);
		add_filter(
			$prefix . '_community_notification_visible',
			self::guard( $addon, $module, static fn( $visible, $viewer = 0, $targets = array() ) => $module->notification_visible( (array) $visible, (int) $viewer, (array) $targets ) ),
			10,
			3
		);
	}

	/**
	 * Search: result links and the re-index protocol, for this addon's key only.
	 *
	 * @param AbstractAddon $addon  The addon.
	 * @param SearchSource  $module The module.
	 * @return void
	 */
	private static function search( AbstractAddon $addon, SearchSource $module ): void {
		$slug  = $addon->slug();
		$types = array_map( 'sanitize_key', $module->search_types() );

		add_filter(
			'buddynext_search_item_url',
			self::guard(
				$addon,
				$module,
				static fn( $url, $type = '', $id = 0 ) => ( '' === (string) $url && in_array( $type, $types, true ) ) ? $module->search_url( (string) $type, (int) $id ) : $url
			),
			10,
			3
		);
		add_filter(
			'buddynext_integration_reindex_ids',
			self::guard(
				$addon,
				$module,
				static fn( $ids, $key = '', $after = 0, $limit = 0 ) => $slug === $key ? $module->reindex_ids( (int) $after, (int) $limit ) : $ids
			),
			10,
			4
		);
		// Switching search off must also remove what was indexed, or it surfaces forever.
		add_action(
			'buddynext_integration_search_disabled',
			self::guard(
				$addon,
				$module,
				static function ( $key = '' ) use ( $slug, $types ): void {
					if ( $slug === $key ) {
						foreach ( $types as $type ) {
							( new SearchService() )->deindex_type( $type );
						}
					}
				}
			)
		);
		add_action(
			'buddynext_integration_reindex_item',
			self::guard(
				$addon,
				$module,
				static function ( $key = '', $id = 0 ) use ( $slug, $module ): void {
					if ( $slug === $key ) {
						$module->reindex_item( (int) $id );
					}
				}
			),
			10,
			2
		);
	}

	/**
	 * The fields of a settings module whose option keys sit in the addon's namespace.
	 *
	 * @param AbstractAddon  $addon  The addon.
	 * @param SettingsModule $module The module.
	 * @return Field[]
	 */
	public static function valid_settings_fields( AbstractAddon $addon, SettingsModule $module ): array {
		$out    = array();
		$prefix = 'buddynext_' . $addon->slug() . '_';
		foreach ( $module->settings_fields() as $field ) {
			if ( $field instanceof Field && 0 === strpos( $field->key, $prefix ) ) {
				$out[] = $field;
			} else {
				_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Setting keys of addon "%s" must start with "%s".', $addon->slug(), $prefix ) ), '1.2.7' );
			}
		}
		return $out;
	}

	/**
	 * Settings: register the fields so sanitising, defaults and ⌘K search see them.
	 *
	 * @param AbstractAddon  $addon  The addon.
	 * @param SettingsModule $module The module.
	 * @return void
	 */
	private static function settings( AbstractAddon $addon, SettingsModule $module ): void {
		$fields = self::valid_settings_fields( $addon, $module );
		if ( ! $fields ) {
			return;
		}
		$page = new class( $fields ) implements ProvidesSettings {
			/**
			 * Fields of the addon.
			 *
			 * @var Field[]
			 */
			private array $fields;

			/**
			 * Constructor.
			 *
			 * @param Field[] $fields Fields.
			 */
			public function __construct( array $fields ) {
				$this->fields = $fields;
			}

			/**
			 * One section on the Integrations tab.
			 *
			 * @return Section[]
			 */
			public function settings_fields(): array {
				return array( new Section( 'integrations', '', $this->fields ) );
			}

			/**
			 * AdminHub section.
			 *
			 * @return string
			 */
			public function settings_page_section(): string {
				return 'settings';
			}
		};
		SettingsRegistry::register( $page );
		SettingsDriver::register_page( $page, 'buddynext' );
	}

	/**
	 * Discovery: a banner under the space header, and the paths that must full-load.
	 *
	 * @param AbstractAddon   $addon  The addon.
	 * @param DiscoveryModule $module The module.
	 * @return void
	 */
	private static function discovery( AbstractAddon $addon, DiscoveryModule $module ): void {
		add_action(
			'buddynext_part_space_hero_after',
			self::guard(
				$addon,
				$module,
				static function ( $args = array() ) use ( $module ): void {
					$args = (array) $args;
					echo wp_kses_post( $module->space_banner( (int) ( $args['space_id'] ?? 0 ), (int) ( $args['current_user_id'] ?? 0 ) ) );
				}
			)
		);
		add_filter(
			'buddynext_client_nav_deny',
			self::guard(
				$addon,
				$module,
				static function ( $deny ) use ( $addon, $module ) {
					$paths = array_values( array_filter( array_map( 'strval', $module->nav_deny_paths() ) ) );
					if ( $paths ) {
						$deny[ 'addon_' . $addon->slug() ] = $paths;
					}
					return $deny;
				}
			)
		);
	}

	/**
	 * Profile: the addon's panels on the member's Portfolio tab.
	 *
	 * @param AbstractAddon      $addon  The addon.
	 * @param ProfilePanelModule $module The module.
	 * @return void
	 */
	private static function profile_panels( AbstractAddon $addon, ProfilePanelModule $module ): void {
		add_filter(
			'buddynext_member_suite_panels',
			self::guard(
				$addon,
				$module,
				static function ( $panels, $member_id = 0 ) use ( $addon, $module ) {
					if ( ! buddynext_integration_enabled( $addon->slug(), 'nav' ) ) {
						return $panels;
					}
					return array_merge( (array) $panels, array_values( array_filter( $module->panels( (int) $member_id ), 'is_array' ) ) );
				}
			),
			10,
			2
		);
	}
}
