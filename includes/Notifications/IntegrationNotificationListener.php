<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * One inbox for every integration: the receiver of the integration
 * notification contract.
 *
 * Each of our plugins already fires its own notification hook (for example
 * `jetonomy_notification_created`); under the contract it passes one shared
 * payload array as that hook's LAST argument, so its existing listeners are
 * untouched. Whenever it creates a notification a member should see,
 * BuddyNext shows it in the bell (grouped, with the plugin's words, link, icon
 * and a settings switch per type) and push sends it, while the plugin keeps
 * sending its own email. The contract, with the payload, the types / visibility
 * / removal hooks and the per-plugin checklist, is the spec
 * INTEGRATION-NOTIFICATIONS.md on the Pro internal shelf.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Receives contract notifications from every registered integration.
 */
class IntegrationNotificationListener implements ListenerInterface {

	/**
	 * The integrations that speak the contract: source slug => the plugin's own
	 * notification hook, its key in the owner's Integrations switches, the prefix
	 * of its types / visible / removed hooks, the
	 * label of its settings section and bell rows, and its icon.
	 *
	 * @var array<string,array{hook:string,integration?:string,prefix:string,label:string,icon:string}>
	 */
	private const SOURCES = array(
		'jetonomy'        => array(
			'hook'        => 'jetonomy_notification_created',
			'integration' => 'jetonomy',
			'prefix'      => 'jetonomy',
			'label'       => 'Forums',
			'icon'        => 'messages-square',
		),
		'career_board'    => array(
			'hook'        => 'wcb_notification_created',
			'integration' => 'careerboard',
			'prefix'      => 'wcb',
			'label'       => 'Jobs',
			'icon'        => 'briefcase',
		),
		'learnomy'        => array(
			'hook'        => 'learnomy_send_notification',
			'integration' => 'learnomy',
			'prefix'      => 'learnomy',
			'label'       => 'Courses',
			'icon'        => 'graduation-cap',
		),
		'eventonomy'      => array(
			'hook'        => 'evnm_notification_dispatch',
			'integration' => 'eventonomy',
			'prefix'      => 'evnm',
			'label'       => 'Events',
			'icon'        => 'calendar',
		),
		'wb_gamification' => array(
			'hook'        => 'wb_gam_notification_created',
			'integration' => 'gamification',
			'prefix'      => 'wb_gam',
			'label'       => 'Achievements',
			'icon'        => 'award',
		),
		'mediaverse'      => array(
			'hook'        => 'mvs_notification_created',
			'integration' => 'media',
			'prefix'      => 'mvs',
			'label'       => 'Media',
			'icon'        => 'image',
		),
	);

	/**
	 * Most arguments any plugin's notification hook fires with, plus the payload.
	 */
	private const MAX_HOOK_ARGS = 12;

	/**
	 * Register the contract hooks of every source, and the bell seams.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::sources() as $source => $info ) {
			$prefix = $info['prefix'];
			// The payload is the hook's LAST argument; firings without one (a plugin
			// that has not adopted the contract yet) are left to the older route.
			add_action(
				$info['hook'],
				function ( ...$args ) use ( $source ): void {
					$payload = end( $args );
					if ( is_array( $payload ) && isset( $payload['recipient_id'], $payload['type'], $payload['message'] ) ) {
						$this->receive( $source, $payload );
					}
				},
				10,
				self::MAX_HOOK_ARGS
			);
			add_action(
				$prefix . '_community_notification_removed',
				static function ( $object_type, $object_id ) use ( $source ): void {
					self::purge( $source, (string) $object_type, (int) $object_id );
				},
				10,
				2
			);
		}

		add_filter( 'buddynext_notification_prefs_catalogue', array( $this, 'filter_catalogue' ) );
		add_filter( 'buddynext_notification_message', array( $this, 'filter_message' ), 10, 5 );
		add_filter( 'buddynext_notification_url', array( $this, 'filter_url' ), 10, 5 );
		add_filter( 'buddynext_notification_meta', array( $this, 'filter_meta' ), 10, 2 );
		add_filter( 'buddynext_notification_visible_rows', array( $this, 'filter_visible_rows' ) );
		add_filter( 'buddynext_notification_group_label', array( $this, 'filter_group_label' ), 10, 2 );
	}

	/**
	 * The registered sources, extendable by a third-party integration.
	 *
	 * @return array<string,array{hook:string,integration?:string,prefix:string,label:string,icon:string}>
	 */
	public static function sources(): array {
		$sources = self::SOURCES;
		foreach ( $sources as $slug => $info ) {
			// Labels are translated at read time, not in the constant.
			$sources[ $slug ]['label'] = self::translated_label( $slug, $info['label'] );
		}

		/**
		 * Filter the integrations that send notifications through the contract.
		 *
		 * Add one to have BuddyNext read the contract payload from its notification
		 * hook. Each entry: array( 'hook' => 'myplugin_notification_created',
		 * 'prefix' => 'myplugin', 'label' => 'My plugin', 'icon' => 'bell' ), keyed
		 * by a source slug (a-z, 0-9, _).
		 *
		 * @since 1.2.2
		 *
		 * @param array<string,array{hook:string,integration?:string,prefix:string,label:string,icon:string}> $sources Sources.
		 */
		return (array) apply_filters( 'buddynext_notification_sources', $sources );
	}

	/**
	 * Whether a plugin has adopted the contract: it declares its types on
	 * `{prefix}_community_notification_types`. BuddyNext's older routes for that
	 * plugin (JetonomyBridgeListener, the Pro suite bridges, BuddyNext's own
	 * WB Gamification and MediaVerse builders) stand down once it has, so a
	 * member never gets two rows for one event while plugins switch over.
	 *
	 * @param string $source Source slug.
	 * @return bool
	 */
	public static function adopted( string $source ): bool {
		$sources = self::sources();
		return isset( $sources[ $source ] ) && has_filter( $sources[ $source ]['prefix'] . '_community_notification_types' );
	}

	/**
	 * Store one bell row for a contract payload.
	 *
	 * @param string              $source  Source slug.
	 * @param array<string,mixed> $payload Contract payload.
	 * @return int Notification id, or 0 when dropped.
	 */
	public function receive( string $source, array $payload ): int {
		$recipient = (int) ( $payload['recipient_id'] ?? 0 );
		$type      = sanitize_key( (string) ( $payload['type'] ?? '' ) );
		$message   = trim( wp_strip_all_tags( (string) ( $payload['message'] ?? '' ) ) );
		$url       = esc_url_raw( (string) ( $payload['url'] ?? '' ) );
		$actor     = (int) ( $payload['actor_id'] ?? 0 );
		if ( $recipient <= 0 || '' === $type || '' === $message || '' === $url || $actor === $recipient || ! function_exists( 'buddynext_service' ) ) {
			return 0;
		}

		// The owner switched this integration off: nothing from it reaches the bell.
		$integration = (string) ( self::sources()[ $source ]['integration'] ?? '' );
		if ( '' !== $integration && ! buddynext_integration_enabled( $integration, 'nav' ) ) {
			return 0;
		}

		if ( $actor > 0 ) {
			$blocks = buddynext_service( 'blocks' );
			if ( is_object( $blocks ) && method_exists( $blocks, 'has_blocked' )
				&& ( $blocks->has_blocked( $recipient, $actor ) || $blocks->has_blocked( $actor, $recipient ) ) ) {
				return 0;
			}
		}

		$object_type = sanitize_key( (string) ( $payload['object_type'] ?? '' ) );
		$group_key   = sanitize_key( (string) ( $payload['group_key'] ?? '' ) );
		$context     = is_array( $payload['context'] ?? null ) ? $payload['context'] : array();

		return (int) buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $recipient,
				'sender_id'    => $actor > 0 ? $actor : null,
				'type'         => $source . '.' . $type,
				// Namespaced: a plugin's id is never read as a BuddyNext object.
				'object_type'  => '' !== $object_type ? $source . '_' . $object_type : $source,
				'object_id'    => (int) ( $payload['object_id'] ?? 0 ),
				'group_key'    => '' !== $group_key ? $source . '_' . $group_key : null,
				'data'         => array(
					'source'          => $source,
					'subtype'         => $type,
					'message'         => $message,
					'message_grouped' => trim( wp_strip_all_tags( (string) ( $payload['message_grouped'] ?? '' ) ) ),
					'url'             => $url,
					'context'         => array(
						'type'  => sanitize_key( (string) ( $context['type'] ?? '' ) ),
						'id'    => (int) ( $context['id'] ?? 0 ),
						'label' => sanitize_text_field( (string) ( $context['label'] ?? '' ) ),
					),
					'notification_id' => (int) ( $payload['notification_id'] ?? 0 ),
				),
			)
		);
	}

	/**
	 * Delete every bell row about a permanently deleted plugin object.
	 *
	 * @param string $source      Source slug.
	 * @param string $object_type The plugin's object type.
	 * @param int    $object_id   The plugin's object id.
	 * @return void
	 */
	private static function purge( string $source, string $object_type, int $object_id ): void {
		$object_type = sanitize_key( $object_type );
		if ( '' === $object_type || $object_id <= 0 || ! function_exists( 'buddynext_service' ) ) {
			return;
		}
		buddynext_service( 'notifications' )->delete_for_object( $source . '_' . $object_type, $object_id );
	}

	/**
	 * Add each plugin's declared types to the settings catalogue: one section
	 * per plugin, one switch per type, never emailed (the plugin emails).
	 *
	 * @param array<string,array<string,mixed>> $catalogue Catalogue.
	 * @return array<string,array<string,mixed>>
	 */
	public function filter_catalogue( array $catalogue ): array {
		foreach ( self::sources() as $source => $info ) {
			/**
			 * The plugin declares its notification types on its own filter:
			 * `{prefix}_community_notification_types` ( slug => label, description, default_on ).
			 */
			$types = (array) apply_filters( $info['prefix'] . '_community_notification_types', array() );
			foreach ( $types as $slug => $type ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug || ! is_array( $type ) ) {
					continue;
				}
				$catalogue[ $source . '.' . $slug ] = array(
					'label'              => (string) ( $type['label'] ?? $slug ),
					'description'        => (string) ( $type['description'] ?? '' ),
					'group'              => $source,
					'default_on_site'    => (bool) ( $type['default_on'] ?? true ),
					'default_email_freq' => 'off',
					'can_email'          => false,
				);
			}
		}
		return $catalogue;
	}

	/**
	 * A contract row's message: the plugin's own words.
	 *
	 * @param string              $message    Message so far.
	 * @param string              $type       Notification type.
	 * @param string              $actor_name Actor name (unused; the plugin wrote the sentence).
	 * @param int                 $object_id  Object id (unused).
	 * @param array<string,mixed> $data       Row data.
	 * @return string
	 */
	public function filter_message( $message, string $type, string $actor_name, int $object_id, array $data ) {
		return self::is_contract_type( $type ) && '' !== (string) ( $data['message'] ?? '' ) ? (string) $data['message'] : $message;
	}

	/**
	 * A contract row's link: the plugin's own.
	 *
	 * @param string              $url       URL so far.
	 * @param string              $type      Notification type.
	 * @param int                 $actor_id  Actor (unused).
	 * @param int                 $object_id Object id (unused).
	 * @param array<string,mixed> $data      Row data.
	 * @return string
	 */
	public function filter_url( $url, string $type, int $actor_id, int $object_id, array $data ) {
		return self::is_contract_type( $type ) && '' !== (string) ( $data['url'] ?? '' ) ? (string) $data['url'] : $url;
	}

	/**
	 * A contract row's icon and label: the plugin's.
	 *
	 * @param array<string,string> $meta Meta so far.
	 * @param string               $type Notification type.
	 * @return array<string,string>
	 */
	public function filter_meta( array $meta, string $type ): array {
		$source  = self::source_of( $type );
		$sources = self::sources();
		if ( '' === $source ) {
			return $meta;
		}
		$icon = (string) $sources[ $source ]['icon'];
		return array(
			'icon'  => \BuddyNext\Core\IconService::has( $icon ) ? $icon : 'bell',
			'tone'  => 'info',
			'label' => (string) $sources[ $source ]['label'],
		);
	}

	/**
	 * Ask each plugin which of its rows the recipient may still see.
	 *
	 * @param array<int,array<string,mixed>> $rows One bell page (one recipient).
	 * @return array<int,array<string,mixed>>
	 */
	public function filter_visible_rows( array $rows ): array {
		$by_source = array();
		foreach ( $rows as $i => $row ) {
			$source = self::source_of( (string) ( $row['type'] ?? '' ) );
			if ( '' === $source ) {
				continue;
			}
			$data                       = is_array( $row['data'] ?? null ) ? $row['data'] : (array) json_decode( (string) ( $row['data'] ?? '' ), true );
			$by_source[ $source ][ $i ] = array(
				'type'        => (string) ( $data['subtype'] ?? '' ),
				'object_type' => (string) preg_replace( '/^' . preg_quote( $source, '/' ) . '_/', '', (string) ( $row['object_type'] ?? '' ) ),
				'object_id'   => (int) ( $row['object_id'] ?? 0 ),
				'actor_id'    => (int) ( $row['sender_id'] ?? 0 ),
			);
		}
		if ( empty( $by_source ) ) {
			return $rows;
		}

		$first   = reset( $rows );
		$viewer  = (int) ( $first['recipient_id'] ?? 0 );
		$sources = self::sources();
		foreach ( $by_source as $source => $targets ) {
			/**
			 * The plugin answers for its own objects: `{prefix}_community_notification_visible`
			 * ( array $visible key => true, int $viewer_id, array $targets ): key => bool.
			 */
			$visible = (array) apply_filters( $sources[ $source ]['prefix'] . '_community_notification_visible', array_fill_keys( array_keys( $targets ), true ), $viewer, $targets );
			foreach ( array_keys( $targets ) as $i ) {
				if ( array_key_exists( $i, $visible ) && ! $visible[ $i ] ) {
					unset( $rows[ $i ] );
				}
			}
		}
		return $rows;
	}

	/**
	 * The settings-section label of a plugin's group.
	 *
	 * @param string $label Label so far.
	 * @param string $group Group key.
	 * @return string
	 */
	public function filter_group_label( $label, $group ): string {
		$sources = self::sources();
		return isset( $sources[ (string) $group ] ) ? (string) $sources[ (string) $group ]['label'] : (string) $label;
	}

	/**
	 * The source a contract type belongs to ('jetonomy' for 'jetonomy.reply_to_post'), or ''.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	private static function source_of( string $type ): string {
		$dot = strpos( $type, '.' );
		if ( false === $dot ) {
			return '';
		}
		$source = substr( $type, 0, $dot );
		return array_key_exists( $source, self::sources() ) ? $source : '';
	}

	/**
	 * Whether a notification type came through the contract.
	 *
	 * @param string $type Notification type.
	 * @return bool
	 */
	private static function is_contract_type( string $type ): bool {
		return '' !== self::source_of( $type );
	}

	/**
	 * A source's label, translated.
	 *
	 * @param string $slug    Source slug.
	 * @param string $fallback English label.
	 * @return string
	 */
	private static function translated_label( string $slug, string $fallback ): string {
		switch ( $slug ) {
			case 'jetonomy':
				return __( 'Forums', 'buddynext' );
			case 'career_board':
				return __( 'Jobs', 'buddynext' );
			case 'learnomy':
				return __( 'Courses', 'buddynext' );
			case 'eventonomy':
				return __( 'Events', 'buddynext' );
			case 'wb_gamification':
				return __( 'Achievements', 'buddynext' );
			case 'mediaverse':
				return __( 'Media', 'buddynext' );
			default:
				return $fallback;
		}
	}
}
