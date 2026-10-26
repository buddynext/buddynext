<?php
/**
 * The set of registered addons.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

/**
 * Collects addons on `buddynext_register_addons` and boots them, so load order
 * between BuddyNext, the partner and the addon never matters.
 *
 * An addon that targets a newer API than this BuddyNext supports, or whose partner
 * is older than its floor, is refused with one owner notice. It never fatals.
 *
 * @api
 * @since 1.2.7
 */
final class AddonRegistry {

	/**
	 * Oldest addon API this BuddyNext still supports (current and previous).
	 */
	public const OLDEST_SUPPORTED_API = 1;

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Accepted addons by slug.
	 *
	 * @var array<string,AbstractAddon>
	 */
	private array $addons = array();

	/**
	 * Refused addons: slug => array( reason, label, detail ).
	 *
	 * @var array<string,array{reason:string,label:string,detail:string}>
	 */
	private array $refused = array();

	/**
	 * Whether `buddynext_register_addons` has fired.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Drop all state. Test-only.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Fire `buddynext_register_addons` once and boot what registered.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/**
		 * Register addons.
		 *
		 * @since 1.2.7
		 *
		 * @param AddonRegistry $registry Call `register( new My_Addon() )`.
		 */
		do_action( 'buddynext_register_addons', $this );

		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Register one addon. Safe at any time; one registered after boot starts now.
	 *
	 * @param AbstractAddon $addon The addon.
	 * @return bool Whether it was accepted.
	 */
	public function register( AbstractAddon $addon ): bool {
		$slug  = $addon->slug();
		$label = '' !== $addon->label() ? $addon->label() : $slug;

		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{1,30}$/', $slug ) || isset( $this->addons[ $slug ] ) || isset( $this->refused[ $slug ] ) ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Addon slug "%s" is invalid or already registered.', $slug ) ), '1.2.7' );
			return false;
		}

		$api = $addon->api_version();
		if ( $api > BUDDYNEXT_ADDON_API ) {
			$this->refused[ $slug ] = array(
				'reason' => 'api_too_new',
				'label'  => $label,
				'detail' => (string) $api,
			);
			return false;
		}
		if ( $api < self::OLDEST_SUPPORTED_API ) {
			$this->refused[ $slug ] = array(
				'reason' => 'api_too_old',
				'label'  => $label,
				'detail' => (string) $api,
			);
			return false;
		}

		if ( $addon->partner_active() && $addon->below_floor() ) {
			$this->refused[ $slug ] = array(
				'reason' => 'partner_too_old',
				'label'  => $label,
				'detail' => $addon->min_version(),
			);
		}

		$this->addons[ $slug ] = $addon;
		$addon->boot();
		return true;
	}

	/**
	 * Accepted addons.
	 *
	 * @return array<string,AbstractAddon>
	 */
	public function all(): array {
		return $this->addons;
	}

	/**
	 * One accepted addon.
	 *
	 * @param string $slug Addon slug.
	 * @return AbstractAddon|null
	 */
	public function get( string $slug ): ?AbstractAddon {
		return $this->addons[ $slug ] ?? null;
	}

	/**
	 * Refused addons and why.
	 *
	 * @return array<string,array{reason:string,label:string,detail:string}>
	 */
	public function refused(): array {
		return $this->refused;
	}

	/**
	 * The settings fields an addon's SettingsModule declares.
	 *
	 * @param string $slug Addon slug.
	 * @return \BuddyNext\Admin\Settings\Field[]
	 */
	public function settings_fields( string $slug ): array {
		$fields = array();
		$addon  = $this->get( $slug );
		if ( ! $addon || ! $addon->partner_active() || $addon->below_floor() ) {
			return $fields;
		}
		foreach ( $addon->resolved_modules() as $module ) {
			if ( $module instanceof SettingsModule ) {
				$fields = array_merge( $fields, ModuleWiring::valid_settings_fields( $addon, $module ) );
			}
		}
		return $fields;
	}

	/**
	 * Tell the owner about a refused addon. Owners of the site only.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		foreach ( $this->refused as $info ) {
			switch ( $info['reason'] ) {
				case 'api_too_new':
					/* translators: %s: addon name. */
					$text = sprintf( __( '%s needs a newer version of the community plugin. Update it to turn this on.', 'buddynext' ), $info['label'] );
					break;
				case 'api_too_old':
					/* translators: %s: addon name. */
					$text = sprintf( __( '%s was built for an older version of the community plugin and needs an update from its author.', 'buddynext' ), $info['label'] );
					break;
				default:
					/* translators: 1: addon name, 2: minimum partner version. */
					$text = sprintf( __( '%1$s needs its partner plugin updated to version %2$s or newer.', 'buddynext' ), $info['label'], $info['detail'] );
			}
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $text ) );
		}
	}
}
