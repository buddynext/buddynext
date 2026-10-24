<?php
/**
 * Base class for a BuddyNext addon.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

/**
 * An addon declares who it is, which partner it wraps, and which modules it uses.
 * BuddyNext does the wiring that is easy to forget: the Integrations entry, the
 * version floor and its "Update needed" badge, the per-aspect owner switches, and a
 * boundary so a module that throws is switched off instead of taking the site down.
 *
 * Register with `buddynext_register_addons`:
 *
 *     add_action( 'buddynext_register_addons', function ( AddonRegistry $addons ) {
 *         $addons->register( new My_Addon() );
 *     } );
 *
 * @api
 * @since 1.2.7
 */
abstract class AbstractAddon {

	/**
	 * Resolved modules, built once.
	 *
	 * @var object[]|null
	 */
	private ?array $resolved = null;

	/**
	 * Integration key, stable forever (a-z, 0-9, _; starts with a letter).
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Name shown in Integration Settings. May be translated: it is read lazily.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Whether the partner plugin this addon wraps is active.
	 *
	 * @return bool
	 */
	abstract public function partner_active(): bool;

	/**
	 * The module objects this addon uses (see the interfaces in this folder).
	 *
	 * @return object[]
	 */
	abstract public function modules(): array;

	/**
	 * The BUDDYNEXT_ADDON_API value this addon was written against.
	 *
	 * @return int
	 */
	public function api_version(): int {
		return 1;
	}

	/**
	 * The partner's installed version, '' when unknown.
	 *
	 * @return string
	 */
	public function partner_version(): string {
		return '';
	}

	/**
	 * Lowest partner version the addon's seams work on, '' for none.
	 *
	 * @return string
	 */
	public function min_version(): string {
		return '';
	}

	/**
	 * The partner release the addon was last verified against, '' for none.
	 *
	 * @return string
	 */
	public function tested_version(): string {
		return '';
	}

	/**
	 * Features that need a newer partner than the floor: label => version.
	 *
	 * @return array<string,string>
	 */
	public function feature_versions(): array {
		return array();
	}

	/**
	 * What the addon's navigation tab is called on screen, '' when it adds none.
	 *
	 * @return string
	 */
	public function nav_label(): string {
		return '';
	}

	/**
	 * Whether new cards may be posted to the feed (the owner's switch).
	 *
	 * @return bool
	 */
	final public function feed_enabled(): bool {
		return buddynext_integration_enabled( $this->slug(), 'feed' );
	}

	/**
	 * Whether content may be indexed for search (the owner's switch).
	 *
	 * @return bool
	 */
	final public function search_enabled(): bool {
		return buddynext_integration_enabled( $this->slug(), 'search' );
	}

	/**
	 * The addon's modules, validated and built once.
	 *
	 * @return object[]
	 */
	final public function resolved_modules(): array {
		if ( null === $this->resolved ) {
			$this->resolved = array_values( array_filter( $this->modules(), 'is_object' ) );
		}
		return $this->resolved;
	}

	/**
	 * Whether the installed partner is older than the addon's floor.
	 *
	 * @return bool
	 */
	final public function below_floor(): bool {
		$floor     = $this->min_version();
		$installed = $this->partner_version();
		return '' !== $floor && '' !== $installed && version_compare( $installed, $floor, '<' );
	}

	/**
	 * Attach the addon to BuddyNext. Called once by the registry.
	 *
	 * @return void
	 */
	final public function boot(): void {
		if ( ! $this->partner_active() ) {
			return;
		}
		// Listed even below the floor, so the owner sees the "Update needed" badge.
		add_filter( 'buddynext_integrations', array( $this, 'add_integration' ) );

		if ( $this->below_floor() ) {
			return;
		}
		// Labels are translated, so modules wire once translations may load.
		add_action( 'init', array( $this, 'wire' ), 0 );
	}

	/**
	 * Wire every module. Public for the init hook and for tests.
	 *
	 * @return void
	 */
	final public function wire(): void {
		foreach ( $this->resolved_modules() as $module ) {
			ModuleWiring::wire( $this, $module );
		}
	}

	/**
	 * The `buddynext_integrations` entry.
	 *
	 * @param array<string,array<string,mixed>> $items Registered integrations.
	 * @return array<string,array<string,mixed>>
	 */
	final public function add_integration( array $items ): array {
		$modules = $this->resolved_modules();
		$has     = static function ( string $module_type ) use ( $modules ): bool {
			foreach ( $modules as $module ) {
				if ( $module instanceof $module_type ) {
					return true;
				}
			}
			return false;
		};

		$items[ $this->slug() ] = array(
			'label'            => $this->label(),
			'version'          => $this->partner_version(),
			'min_version'      => $this->min_version(),
			'tested_version'   => $this->tested_version(),
			'feature_versions' => $this->feature_versions(),
			'nav_label'        => $this->nav_label(),
			'has_nav'          => $has( ProfilePanelModule::class ),
			'has_feed'         => $has( FeedCardModule::class ),
			'has_search'       => $has( SearchSource::class ),
		);
		return $items;
	}
}
