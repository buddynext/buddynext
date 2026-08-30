<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for user profiles.
 *
 * Routes (all under buddynext/v1):
 *   GET    /users/{id}/profile              — get a user's profile (public)
 *   PUT    /users/{id}/profile              — update any user's profile (edit-any capability)
 *   POST   /users/{id}/avatar               — set avatar for any user (edit-any capability)
 *   DELETE /users/{id}/avatar               — remove avatar for any user (edit-any capability)
 *   POST   /users/{id}/cover                — upload cover for any user (edit-any capability)
 *   DELETE /users/{id}/cover                — remove cover for any user (edit-any capability)
 *   PUT    /me/profile                      — update own profile (auth required)
 *   POST   /me/avatar                       — upload own avatar (auth required)
 *   DELETE /me/avatar                       — remove own avatar (auth required)
 *   POST   /me/cover                        — upload own cover photo (auth required)
 *   DELETE /me/cover                        — remove own cover photo (auth required)
 *   GET    /me/profile-slug                 — get own profile slug + URL (auth required)
 *   PUT    /me/profile-slug                 — set own profile slug (auth required)
 *   GET    /profile-slug/check              — check if a slug is available (auth required)
 *   GET    /profile-fields                  — list all field definitions grouped by group (public)
 *   POST   /profile-fields                  — create a field definition (admin only)
 *   PUT    /profile-fields/{id}             — update a field definition (admin only)
 *   DELETE /profile-fields/{id}             — delete a field definition (admin only)
 *   POST   /profile-fields/{id}/reorder     — reorder a field (admin only)
 *   GET    /profile-groups                  — list all groups (public)
 *   POST   /profile-groups                  — create a group (admin only)
 *   PUT    /profile-groups/{id}             — update a group (admin only)
 *   DELETE /profile-groups/{id}             — delete a group (admin only)
 *   POST   /profile-groups/{id}/reorder     — reorder a group (admin only)
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use BuddyNext\Core\RateLimiter;
use BuddyNext\REST\BaseRestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles profile reads and writes over REST.
 */
class ProfileController extends BaseRestController {

	/**
	 * Audience preference metas accepted by a profile write.
	 *
	 * Hoisted out of update_profile() so the write loop and the strict-input
	 * allowlist read the SAME list. When they were separate, one could accept a
	 * key the other refused.
	 *
	 * @since 1.1.6
	 *
	 * @var string[]
	 */
	private const PROFILE_META_AUDIENCE = array( 'bn_privacy_dm', 'bn_privacy_mention' );

	/**
	 * Gate metas accepted by a profile write, each with its own vocabulary.
	 *
	 * Profile-view / follow / connect gates. Each key accepts only the values its
	 * PrivacyService gate honours (can_view_profile / can_follow / can_connect).
	 *
	 * @since 1.1.6
	 *
	 * @var array<string, string[]>
	 */
	private const PROFILE_META_GATES = array(
		'bn_privacy_profile_visibility' => array( 'public', 'followers', 'connections', 'private' ),
		'bn_privacy_who_can_follow'     => array( 'everyone', 'nobody' ),
		'bn_privacy_who_can_connect'    => array( 'everyone', 'followers', 'nobody' ),
	);

	/**
	 * Boolean preference metas accepted by a profile write.
	 *
	 * @since 1.1.6
	 *
	 * @var string[]
	 */
	private const PROFILE_META_BOOLS = array(
		'bn_account_private',
		'bn_privacy_show_in_directory',
		'bn_privacy_search_indexable',
		'bn_pro_hide_profile_views',
		'bn_pref_email_replies',
		'bn_pref_email_mentions',
		'bn_pref_email_follows',
		'bn_pref_email_digest',
	);

	/**
	 * Non-field top-level keys a profile write accepts.
	 *
	 * The full_write key is a mode flag, not data; display_name is a WP core user
	 * field; profile_slug is consumed by save_profile().
	 *
	 * @since 1.1.6
	 *
	 * @var string[]
	 */
	private const PROFILE_CONTROL_KEYS = array( 'full_write', 'display_name', 'profile_slug' );

	/**
	 * Every top-level key a profile write understands.
	 *
	 * Derived live from the same registries save_profile() consults, so an
	 * owner-created field, a code-registered virtual field and an added repeater
	 * group are all accepted the moment they exist — there is no second list to
	 * keep in step.
	 *
	 * Verified against what the shipped editors actually send: the full profile
	 * editor posts field_key + field_key__visibility + display_name + repeater
	 * group keys + full_write, and the privacy tab posts the nine privacy metas.
	 *
	 * @since 1.1.6
	 *
	 * @return array<int, string>
	 */
	private function profile_write_allowlist(): array {
		$service = buddynext_service( 'profiles' );

		$keys = array_merge(
			self::PROFILE_CONTROL_KEYS,
			self::PROFILE_META_AUDIENCE,
			array_keys( self::PROFILE_META_GATES ),
			self::PROFILE_META_BOOLS
		);

		// Flat fields — exactly the set save_profile() resolves by field_key.
		foreach ( (array) $service->get_flat_fields() as $field ) {
			$key = (string) ( $field['field_key'] ?? '' );
			if ( '' !== $key ) {
				$keys[] = $key;
				$keys[] = $key . '__visibility';
			}
		}

		// Repeater groups arrive keyed by group_key; virtual (code-registered)
		// fields arrive flat. save_profile() layers both in the same way.
		foreach ( (array) $service->get_fields() as $group ) {
			$group_key = (string) ( $group['group_key'] ?? '' );
			if ( 'repeater' === (string) ( $group['type'] ?? '' ) && '' !== $group_key ) {
				$keys[] = $group_key;
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$key = (string) ( $field['field_key'] ?? '' );
				if ( ! empty( $field['is_virtual'] ) && '' !== $key ) {
					$keys[] = $key;
					$keys[] = $key . '__visibility';
				}
			}
		}

		/**
		 * Filters the top-level keys a profile write accepts.
		 *
		 * An add-on that teaches save_profile() a new key (via its own listener)
		 * must declare it here, or the strict-input gate will refuse the request.
		 *
		 * @since 1.1.6
		 *
		 * @param array<int, string> $keys Accepted top-level keys.
		 */
		$keys = (array) apply_filters( 'buddynext_profile_write_allowlist', $keys );

		return array_values( array_unique( array_map( 'strval', $keys ) ) );
	}

	/**
	 * The schema for a profile field DEFINITION, shared by create and update.
	 *
	 * One declaration for both verbs. They used to disagree in three ways, all of
	 * which reached members: POST accepted eleven attributes (six declared, five
	 * arriving undeclared and unsanitised through get_params()) while PUT read
	 * only six and threw the rest away; POST validated `type` against the
	 * registry while PUT would write any slug at all; and `options` meant an
	 * array on POST but a newline-separated string on PUT.
	 *
	 * ProfileService::update_field() supports all eleven attributes. The gap was
	 * entirely in this layer, so an admin client could set a description over the
	 * admin screen and be silently refused over the API.
	 *
	 * @since 1.1.6
	 *
	 * @param bool $creating True for POST (group_id/field_key/label required).
	 * @return array<string, array<string, mixed>>
	 */
	private static function field_definition_args( bool $creating ): array {
		$args = array(
			'label'            => array(
				'required'          => $creating,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type'             => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				// Whitelisted at the edge on BOTH verbs. The value is written
				// straight into the type column, so an unregistered slug produces a
				// field with no render or save pipeline — one nobody can fill in,
				// nothing knows how to display, and the admin UI cannot repair.
				'validate_callback' => static fn( $value ): bool => array_key_exists( (string) $value, FieldType::types() ),
			),
			// Accepted as an array OR as the newline-separated string the admin
			// textarea produces; normalise_field_options() resolves both.
			'options'          => array(
				'required' => false,
			),
			'description'      => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'placeholder'      => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'is_required'      => array(
				'required' => false,
				'type'     => 'boolean',
			),
			// Stored only where it can do something — see
			// FieldType::is_searchable_applicable(), applied in the service.
			'is_searchable'    => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'show_on_register' => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'show_in_header'   => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'visibility'       => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $value ): bool => in_array( (string) $value, self::FIELD_VISIBILITY, true ),
			),
			'sort_order'       => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			// Valid on BOTH create and update. It used to be declared only when
			// creating, so a field could be born into a group and then never
			// leave it: the update route dropped the param before the service
			// ever saw it, and answered {"updated":true} regardless.
			'group_id'         => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);

		if ( $creating ) {
			$args = array_merge(
				array(
					// create_field() resolves (or creates) a group by key when no
					// group_id is given. It was never declared, so a client using it
					// was relying on an undeclared param.
					'group_name' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'field_key'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
				$args
			);
			$args['type']['default']        = 'text';
			$args['is_required']['default'] = false;
			$args['sort_order']['default']  = 0;
		} else {
			$args = array_merge(
				array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
				$args
			);
		}

		return $args;
	}

	/**
	 * Field visibility vocabulary.
	 *
	 * Matches the admin builder's list; a value outside it has no gate to honour it.
	 *
	 * @since 1.1.6
	 *
	 * @var string[]
	 */
	private const FIELD_VISIBILITY = array( 'public', 'members', 'followers', 'connections', 'private' );

	/**
	 * Normalise a submitted options payload to the array the service stores.
	 *
	 * Accepts the admin textarea's newline-separated string and a JSON array
	 * alike, so the two verbs no longer mean different things by the same key.
	 *
	 * @since 1.1.6
	 *
	 * @param mixed $raw Submitted options value.
	 * @return array<int, string>|null Normalised list, or null to clear.
	 */
	private static function normalise_field_options( $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}

		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'trim', array_map( 'strval', $raw ) ) ) );
		}

		return array_values(
			array_filter(
				array_map( 'trim', explode( "\n", (string) $raw ) )
			)
		);
	}

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/profile',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_profile' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/profile',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'admin_update_profile' ),
				'permission_callback' => array( $this, 'require_edit_any_profile' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/avatar',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'admin_upload_avatar' ),
				'permission_callback' => array( $this, 'require_edit_any_profile' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// DELETE merges onto the same path as the POST above.
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/avatar',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'admin_delete_avatar' ),
				'permission_callback' => array( $this, 'require_edit_any_profile' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/cover',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'admin_upload_cover' ),
					'permission_callback' => array( $this, 'require_edit_any_profile' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'admin_delete_cover' ),
					'permission_callback' => array( $this, 'require_edit_any_profile' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/avatar',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_avatar' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_avatar' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/cover',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_cover' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_cover' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/profile',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_own_profile' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_profile' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/profile-slug',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_profile_slug' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_profile_slug' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'slug' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_title',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-slug/check',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'check_slug_availability' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-fields',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_fields' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_field' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => self::field_definition_args( true ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-groups',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_groups' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_group' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'group_key'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'label'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'type'       => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'flat',
							'sanitize_callback' => 'sanitize_key',
						),
						'visibility' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'public',
							'sanitize_callback' => 'sanitize_key',
						),
						'sort_order' => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-groups/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_group' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'label'      => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'visibility' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'sort_order' => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_group' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-groups/(?P<id>[\d]+)/reorder',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reorder_group' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'        => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'direction' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'up', 'down' ),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-fields/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_field' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => self::field_definition_args( false ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_field' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-fields/(?P<id>[\d]+)/reorder',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reorder_field' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'        => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'direction' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'up', 'down' ),
					),
				),
			)
		);

		// Member self-service privacy: export my data / delete my account.
		register_rest_route(
			'buddynext/v1',
			'/me/data-export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_my_data' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		/*
		 * Deleting an account re-verifies the password, exactly as disabling 2FA
		 * already does (TwoFactorController: "Sensitive transitions re-verify the
		 * password so a hijacked session cannot silently weaken the account").
		 *
		 * Before this, the most destructive action a member has took an EMPTY body:
		 * no password, no confirmation, no re-auth. Deleting the whole account
		 * asked less than turning 2FA off, and any context that could make one
		 * authenticated request could end the account irreversibly
		 * (Basecamp 10252058720).
		 *
		 * The parameter is declared but NOT `required`, and the enforcement lives in
		 * the handler instead. `required` is evaluated when the route registers,
		 * which bakes in whatever the filter said at that moment — so an owner whose
		 * filter loads after rest_api_init would turn the requirement off and still
		 * get a 400 for the parameter they had just removed. Reading the filter at
		 * request time cannot drift that way, and it lets the handler answer with
		 * "password required" rather than a generic missing-parameter error.
		 */
		register_rest_route(
			'buddynext/v1',
			'/me/account',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_my_account' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'password' => array(
						'type'        => 'string',
						'description' => __( 'The account password, re-entered to confirm an irreversible deletion.', 'buddynext' ),
					),
				),
			)
		);
	}

	/**
	 * GET /me/data-export — return the current member's own data for download.
	 *
	 * Gated by the Privacy → "Allow data export" setting. Reuses the existing
	 * PrivacyTools exporter (the same data WordPress's personal-data export uses),
	 * walked to completion across its pages.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_my_data(): WP_REST_Response|WP_Error {
		if ( ! (bool) get_option( 'buddynext_allow_data_export', true ) ) {
			return new WP_Error(
				'export_disabled',
				__( 'Data export is not available on this community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$user = wp_get_current_user();

		// Per-user cooldown: a full data export walks every table for the member,
		// so an unthrottled endpoint is a cheap self-inflicted DoS. Allow one
		// export per user per window (default 5 min). The shared RateLimiter keeps
		// the marker in the object cache when one is present (no wp_options write
		// per export at scale), transient fallback otherwise; a flush only lets a
		// member re-export a little sooner, which is harmless.
		$cooldown = (int) apply_filters( 'buddynext_data_export_cooldown', 5 * MINUTE_IN_SECONDS );
		if ( $cooldown > 0 ) {
			$throttle_key = 'bn_data_export_' . (int) $user->ID;
			if ( RateLimiter::is_marked( $throttle_key ) ) {
				return new WP_Error(
					'export_rate_limited',
					__( 'You recently requested an export. Please wait a few minutes before trying again.', 'buddynext' ),
					array( 'status' => 429 )
				);
			}
			RateLimiter::mark( $throttle_key, $cooldown );
		}

		$privacy = new \BuddyNext\Privacy\PrivacyTools();
		$items   = array();
		$page    = 1;
		do {
			$result = $privacy->export( $user->user_email, $page );
			if ( ! empty( $result['data'] ) && is_array( $result['data'] ) ) {
				$items = array_merge( $items, $result['data'] );
			}
			$done = ! isset( $result['done'] ) || (bool) $result['done'];
			++$page;
		} while ( ! $done && $page < 100 );

		return new WP_REST_Response(
			array(
				'generated_at' => current_time( 'mysql', true ),
				'user'         => array(
					'id'           => (int) $user->ID,
					'username'     => $user->user_login,
					'email'        => $user->user_email,
					'display_name' => $user->display_name,
					'registered'   => $user->user_registered,
				),
				'items'        => $items,
			),
			200
		);
	}

	/**
	 * DELETE /me/account — let a member delete their own account.
	 *
	 * Gated by the Privacy → "Allow account deletion" setting. Scrubs the member's
	 * BuddyNext data (including their authored posts + comments) via the privacy
	 * eraser, then removes the WP account and deletes any remaining WP-core authored
	 * content — standard GDPR erasure, the same uniform hard-delete every other delete
	 * path uses. Content is never reassigned/kept. Administrators cannot self-delete.
	 *
	 * Re-verifies the account password before doing any of it — see
	 * deletion_requires_password() for why, and for the one case where an owner
	 * turns that off.
	 *
	 * @param WP_REST_Request $request Carries the password being re-verified.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_my_account( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! (bool) get_option( 'buddynext_allow_account_deletion', true ) ) {
			return new WP_Error(
				'deletion_disabled',
				__( 'Account deletion is not available on this community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'no_user', __( 'No account found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// Never let an administrator self-delete through this member-facing route.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'admin_cannot_self_delete',
				__( 'Administrators cannot delete their own account here.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// Re-verify the password. LAST gate before the irreversible call, and after
		// the cheap refusals so a member on a deletion-disabled community is told
		// that, rather than being asked for a password it will not use.
		if ( self::deletion_requires_password() ) {
			$password = (string) $request->get_param( 'password' );

			// Absent and wrong are different answers. "You did not send one" is a
			// client error the caller can fix; "that is not your password" is a
			// failed check, and saying so plainly is what lets the dialog put the
			// message on the field.
			if ( '' === $password ) {
				return new WP_Error(
					'password_required',
					__( 'Enter your password to confirm deleting your account.', 'buddynext' ),
					array(
						'status' => 400,
						'fields' => array( 'password' => __( 'Your password is required.', 'buddynext' ) ),
					)
				);
			}

			if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
				return new WP_Error(
					'incorrect_password',
					__( 'Your password was not correct.', 'buddynext' ),
					array(
						'status' => 422,
						'fields' => array( 'password' => __( 'Incorrect password.', 'buddynext' ) ),
					)
				);
			}
		}

		// Delete the WP account. This fires `deleted_user`, which runs the ONE canonical
		// MemberCleanupService::purge_user_relations() - hard-deleting the member's
		// BuddyNext data (follows, connections, blocks, prefs, and their authored posts +
		// comments) and firing buddynext_purge_user_data exactly once, so Free and Pro
		// per-user rows are purged together - and DELETES (never reassigns) any remaining
		// WP-core authored content. Standard GDPR erasure: a member who deletes their
		// account takes their content with them, the same uniform policy on every path.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );

		// The session is now invalid; tell the client to send the user home.
		return new WP_REST_Response(
			array(
				'deleted'     => true,
				'redirect_to' => home_url( '/' ),
			),
			200
		);
	}

	/**
	 * Whether deleting an account re-verifies the member's password.
	 *
	 * Default TRUE, and it should stay true on any community where members sign in
	 * with a password: account deletion is irreversible and takes the member's
	 * content with it, so it earns at least the friction that disabling 2FA has.
	 *
	 * The filter exists because not every install authenticates that way. On a site
	 * where members arrive through SSO or a social provider, the WordPress password
	 * is a random string nobody has ever seen — requiring it there does not add a
	 * check, it removes the member's ability to delete their own account at all,
	 * which is its own (and in some jurisdictions, legal) problem. Those owners turn
	 * this off and gate deletion their own way.
	 *
	 * Read at BOTH route registration and in the handler, so the declared parameter
	 * and the enforced check can never disagree.
	 *
	 * @since 1.1.6
	 *
	 * @return bool
	 */
	private static function deletion_requires_password(): bool {
		/**
		 * Filter whether account deletion re-verifies the account password.
		 *
		 * @since 1.1.6
		 *
		 * @param bool $required Default true.
		 */
		return (bool) apply_filters( 'buddynext_account_deletion_requires_password', true );
	}

	/**
	 * Get a user's profile.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$profile_user_id = (int) $request->get_param( 'id' );
		$viewer_id       = get_current_user_id();

		// SECURITY: gate the read with the canonical profile-visibility check (block +
		// public/followers/connections) — the same gate the profile template uses
		// (PrivacyService::can_view_profile). REST previously skipped it, leaking full
		// profile data to a blocked viewer / a non-follower of a private account. Return
		// the same 404 as a missing user so existence isn't leaked either.
		$privacy = buddynext_service( 'privacy' );
		if ( $privacy instanceof \BuddyNext\SocialGraph\PrivacyService
			&& ! $privacy->can_view_profile( $viewer_id, $profile_user_id ) ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$service = buddynext_service( 'profiles' );
		$profile = $service->get_profile( $profile_user_id, $viewer_id );

		if ( null === $profile ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$profile['completion'] = $service->get_completion_score( $profile_user_id );

		// Member-facing strength checklist (the Profile Strength ring) — own
		// profile only: it is an owner surface, and the tasks derive from
		// owner-scoped field data, not this viewer-scoped payload.
		if ( $viewer_id === $profile_user_id ) {
			$profile['strength'] = $service->get_strength( $profile_user_id, $profile );
		}

		// Social-graph + post counts and bio — consumed by the member hover card,
		// member directory, and native app profile header. Computed at the REST
		// layer (not the cached profile payload) so follow/post changes are
		// reflected immediately via FollowService's own cache.
		$follows = buddynext_service( 'follows' );
		if ( $follows instanceof \BuddyNext\SocialGraph\FollowService ) {
			$profile['follower_count']  = $follows->follower_count( $profile_user_id );
			$profile['following_count'] = $follows->following_count( $profile_user_id );
			// Viewer relationship — consumed by the hover card so its Follow button
			// mirrors the post-card / profile-hero state instead of always reading
			// "Follow". Computed here (not in the cached profile payload) so it stays
			// current with FollowService's own cache after a follow/unfollow.
			$profile['is_self']      = ( $viewer_id === $profile_user_id );
			$profile['is_following'] = ( $viewer_id && ! $profile['is_self'] )
				? $follows->is_following( $viewer_id, $profile_user_id )
				: false;
		}
		$profile['post_count'] = $this->user_post_count( $profile_user_id );

		// Connection block — the SAME {state, can_message} shape the member
		// directory ships. Without it the app had to follow every profile open
		// with a /connection/status call AND a scan of /me/connection-requests
		// just to decide whether the button says Connect, Requested or Respond.
		$connections = buddynext_service( 'connections' );
		if ( $connections instanceof \BuddyNext\SocialGraph\ConnectionService ) {
			$profile['connection'] = $connections->connection_block( $viewer_id, $profile_user_id );
		}

		/*
		 * The follow side of the same problem.
		 *
		 * `is_following` above answers "am I following them" but not "may I" or
		 * "have I already asked" — so a follow button could not be drawn from this
		 * payload alone. The app filled the gap with a conditional
		 * GET /users/{id}/follow/status on every profile open, purely to decide
		 * whether the button reads Follow, Requested, or nothing at all.
		 *
		 * These are the two values that endpoint returns, from the same services
		 * it uses, so the app can drop that request. Both are false for a guest
		 * and on one's own profile, where neither question means anything.
		 */
		if ( $viewer_id && empty( $profile['is_self'] ) ) {
			$privacy = buddynext_service( 'privacy' );

			$profile['is_pending'] = $follows instanceof \BuddyNext\SocialGraph\FollowService
				? $follows->has_pending_request( $viewer_id, $profile_user_id )
				: false;

			$profile['can_follow'] = $privacy instanceof \BuddyNext\SocialGraph\PrivacyService
				? $privacy->can_follow( $viewer_id, $profile_user_id )
				: false;
		} else {
			$profile['is_pending'] = false;
			$profile['can_follow'] = false;
		}
		if ( ! isset( $profile['bio'] ) ) {
			// The bio lives in bn_profile_values; the bn_field_bio usermeta is written by nothing
			// (see ProfileService::bios_for). Reading it here handed the app an empty bio for
			// every member - and BuddyNext is REST-first, so the app renders from exactly this.
			$profile['bio'] = buddynext_service( 'profiles' )->bio_for( $profile_user_id );
		}

		// Member type — a member's identity (Staff, Contributor, …) belongs on the
		// profile header. Built from the SAME MemberTypeService::badge_for() the
		// directory uses, so the two responses cannot drift. Null (present key,
		// null value) when the member has no type — never absent, never a
		// half-populated object.
		$member_types           = buddynext_service( 'member_types' );
		$profile['member_type'] = ( $member_types instanceof \BuddyNext\MemberTypes\MemberTypeService )
			? $member_types->badge_for( $profile_user_id )
			: null;

		// Account moderation status (strikes / suspension / shadow-ban). PRIVILEGED:
		// present only for the profile owner (their own sanctions) or a viewer who
		// can moderate; a present key with a null value for everyone else, so a
		// regular member's client never even learns the field exists.
		$profile['account_status'] = $this->build_account_status( $profile_user_id, $viewer_id );

		/**
		 * Fires after a user's profile is loaded and the response is built.
		 *
		 * Only fires when the viewer is different from the profile owner (self-views
		 * are not counted). Use: Pro analytics reach tracking and profile view events.
		 *
		 * @since 1.0.0
		 *
		 * @param int $profile_user_id User ID of the profile being viewed.
		 * @param int $viewer_id       User ID of the person viewing the profile (0 = anonymous).
		 */
		if ( $viewer_id !== $profile_user_id ) {
			do_action( 'buddynext_profile_viewed', $profile_user_id, $viewer_id );
		}

		return new WP_REST_Response( $profile, 200 );
	}

	/**
	 * Build the privileged account-moderation status for a profile.
	 *
	 * Surfaces active strikes, an active suspension (reason + expiry), and — for
	 * moderators only — shadow-ban state, so admins/moderators can see an account's
	 * standing on the profile and the owner sees their own sanctions. Returns null
	 * for anyone who is neither the owner nor a moderator, and (for the owner) when
	 * there is nothing to report, so a clean profile carries no banner.
	 *
	 * Shadow-ban is deliberately withheld from the owner: telling a shadow-banned
	 * member they are shadow-banned defeats the mechanism. It is only ever included
	 * for a moderator viewer.
	 *
	 * @param int $profile_user_id The profile being viewed.
	 * @param int $viewer_id       The viewer (0 = anonymous).
	 * @return array<string, mixed>|null Status payload, or null when not disclosable.
	 */
	private function build_account_status( int $profile_user_id, int $viewer_id ): ?array {
		$mod = buddynext_service( 'moderation' );
		if ( ! $mod instanceof \BuddyNext\Moderation\ModerationService ) {
			return null;
		}
		return $mod->account_status_for( $profile_user_id, $viewer_id );
	}

	/**
	 * GET /me/profile — return the authenticated user's own full profile.
	 *
	 * Returns all field groups regardless of visibility (owner view), plus
	 * completion score and social graph counts. Equivalent to calling
	 * GET /users/{id}/profile as the profile owner.
	 *
	 * @return WP_REST_Response
	 */
	public function get_own_profile(): WP_REST_Response {
		$user_id = get_current_user_id();
		$service = buddynext_service( 'profiles' );
		$profile = $service->get_profile( $user_id, $user_id );

		if ( null === $profile ) {
			// Should not happen for an authenticated user, but guard defensively.
			$profile = array(
				'user_id' => $user_id,
				'groups'  => array(),
				'fields'  => array(),
			);
		}

		$profile['completion'] = $service->get_completion_score( $user_id );
		$profile['strength']   = $service->get_strength( $user_id, is_array( $profile ) && ! empty( $profile['groups'] ) ? $profile : null );

		return new WP_REST_Response( $profile, 200 );
	}

	/**
	 * Permission callback: editing anyone's profile (admin route).
	 *
	 * Resolves through the role map so a non-admin community role granted the
	 * edit-any capability on the Roles & Capabilities tab can use it; site
	 * admins always pass. Defaults to admins only.
	 *
	 * @return true|WP_Error
	 */
	public function require_edit_any_profile(): bool|WP_Error {
		$auth = $this->require_auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		return $this->require_cap( 'buddynext-profile/edit-any' );
	}

	/**
	 * Update the current user's profile.
	 *
	 * Body params are treated as field_key => value pairs for flat fields, or
	 * group_key => [ [field_key => value, ...], ... ] for repeater groups.
	 * A key this endpoint cannot write is REFUSED with 400 and nothing is saved
	 * (see profile_write_allowlist()); it used to be ignored in silence.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$service = buddynext_service( 'profiles' );

		// Refuse a payload carrying keys this endpoint cannot write, BEFORE any
		// persistence. A wrapped body — PUT {"fields":{"headline":"x"}} — used to
		// answer 200 {"saved":true,"errors":[]} having written nothing at all, so
		// an integration could "succeed" indefinitely while every edit was
		// discarded. All-or-nothing: one unknown key changes nothing.
		$unknown = $this->reject_unknown_body_params( $request, $this->profile_write_allowlist() );
		if ( $unknown instanceof WP_Error ) {
			return $unknown;
		}

		$json = $request->get_json_params();
		$data = is_array( $json ) && ! empty( $json ) ? $json : (array) $request->get_body_params();

		// A full profile write (the complete editor, web or app) declares itself via
		// full_write so required fields are enforced across ABSENT keys too. A
		// partial update (privacy tab, per-field autosave, onboarding step, app
		// PATCH) omits the flag and keeps present-only enforcement.
		$full_write = ! empty( $data['full_write'] ) && rest_sanitize_boolean( (string) $data['full_write'] );
		unset( $data['full_write'] );

		// Validate input before any persistence. Field-level errors are
		// returned as a 422 payload the JS store can map to inline errors.
		$errors = $this->validate_profile_payload( $data, $full_write, $user_id );
		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'saved'  => false,
					'errors' => $errors,
				),
				422
			);
		}

		// Handle display_name — WP core field, not a profile value row.
		if ( isset( $data['display_name'] ) ) {
			$display_name = sanitize_text_field( (string) $data['display_name'] );
			if ( '' !== $display_name ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $display_name,
					)
				);
			}
			unset( $data['display_name'] );
		}

		// Handle privacy + notification preference keys — stored as usermeta,
		// not profile-field rows. Audience enums are constrained to the
		// canonical four values; boolean toggles are coerced.
		// One list, two consumers: these same constants build the strict-input
		// allowlist above, so a key can never be accepted by the gate and then
		// ignored by the writer (or the reverse).
		$audience_keys = self::PROFILE_META_AUDIENCE;
		$gate_keys     = self::PROFILE_META_GATES;
		$bool_keys     = self::PROFILE_META_BOOLS;
		foreach ( $audience_keys as $aud_key ) {
			if ( array_key_exists( $aud_key, $data ) ) {
				update_user_meta( $user_id, $aud_key, sanitize_key( (string) $data[ $aud_key ] ) );
				unset( $data[ $aud_key ] );
			}
		}
		$privacy = buddynext_service( 'privacy' );
		foreach ( $gate_keys as $gate_key => $allowed ) {
			if ( array_key_exists( $gate_key, $data ) ) {
				$gate_val = sanitize_key( (string) $data[ $gate_key ] );
				if ( in_array( $gate_val, $allowed, true ) ) {
					// Route through PrivacyService (single source of truth) so the
					// buddynext_privacy_preference_changed action fires on the live edit flow.
					$privacy->set_preference( $user_id, substr( $gate_key, strlen( 'bn_privacy_' ) ), $gate_val );
				}
				unset( $data[ $gate_key ] );
			}
		}
		// Capture the search-visibility toggles before the write so we can fire a
		// reindex only when one actually changes (not on every profile save).
		$search_vis_keys   = array( 'bn_account_private', 'bn_privacy_search_indexable' );
		$search_vis_before = array();
		foreach ( $search_vis_keys as $svk ) {
			$search_vis_before[ $svk ] = (string) get_user_meta( $user_id, $svk, true );
		}

		foreach ( $bool_keys as $bk ) {
			if ( array_key_exists( $bk, $data ) ) {
				update_user_meta( $user_id, $bk, ! empty( $data[ $bk ] ) ? '1' : '0' );
				unset( $data[ $bk ] );
			}
		}

		foreach ( $search_vis_keys as $svk ) {
			if ( (string) get_user_meta( $user_id, $svk, true ) !== $search_vis_before[ $svk ] ) {
				/**
				 * An account-level setting governing whether a member's posts
				 * appear in global search changed. Listeners (SearchIndexListener)
				 * reindex the member's existing posts so their stored search
				 * visibility follows the new setting.
				 *
				 * @param int $user_id The member whose search visibility changed.
				 */
				do_action( 'buddynext_user_search_visibility_changed', $user_id );
				break;
			}
		}

		// Cap long-form PLAIN-TEXT fields at sensible lengths before they hit the
		// service. These caps only make sense for text/textarea: they truncate the
		// raw string, which corrupts a STRUCTURED value. The built-in `location`
		// key stores JSON once its field type is switched to the Pro Map type
		// ("location"); a blind mb_substr( ..., 120 ) sliced that JSON mid-string,
		// json_decode() then failed, and the whole profile save was rejected with
		// "Location value is not valid JSON." Advanced/structured field types own
		// their own length rules in the sanitise/validate layer, so resolve each
		// capped field's current type and skip the cap for anything but plain text.
		$caps = array(
			'bio'      => 1000,
			'headline' => 160,
			'location' => 120,
			'pronouns' => 40,
		);

		$bn_field_types = array();
		foreach ( $service->get_flat_fields() as $bn_flat_field ) {
			$bn_field_types[ (string) ( $bn_flat_field['field_key'] ?? '' ) ] = (string) ( $bn_flat_field['type'] ?? 'text' );
		}

		foreach ( $caps as $field_key => $max ) {
			if ( ! isset( $data[ $field_key ] ) || ! is_string( $data[ $field_key ] ) ) {
				continue;
			}

			// A built-in field re-typed to a structured type (Location -> Map, etc.)
			// stores a non-plain-text payload the cap would corrupt.
			$bn_field_type = $bn_field_types[ $field_key ] ?? 'text';
			if ( ! in_array( $bn_field_type, array( 'text', 'textarea' ), true ) ) {
				continue;
			}

			$data[ $field_key ] = mb_substr( $data[ $field_key ], 0, $max );
		}

		// Normalise every URL-type field — accept input without a protocol by
		// prefixing https. Keyed on field TYPE, not a hardcoded key list, so an
		// owner-created url field is normalised exactly like the seeded website /
		// social_* fields (which are all type 'url').
		foreach ( $this->url_field_keys() as $url_key ) {
			if ( isset( $data[ $url_key ] ) && is_string( $data[ $url_key ] ) && '' !== trim( $data[ $url_key ] ) ) {
				$data[ $url_key ] = esc_url_raw( self::ensure_url_scheme( $data[ $url_key ] ) );
			}
		}

		// save_profile() can REJECT the write (moderation safeguard, per-field
		// sanitise/validate failures). Its return value used to be discarded here, so
		// the member was told "Profile saved" while the server had thrown the write
		// away. Mirror admin_update_profile(): a rejected save is a 422 carrying the
		// field => message map the editor paints as inline errors — never a
		// 200 {"saved":true}.
		$result = $service->save_profile( $user_id, $data );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'saved'   => false,
					'errors'  => $this->map_save_error_to_fields( $result, $data, $user_id ),
					'message' => $result->get_error_message(),
				),
				422
			);
		}

		/**
		 * Fire indexing as a pluggable action so Action Scheduler (Phase 6+)
		 * can override the synchronous fallback with an async job.
		 *
		 * @param int $user_id User whose search index entry should be refreshed.
		 */
		do_action( 'buddynext_index_user', $user_id );

		$profile = $service->get_profile( $user_id, $user_id );

		return new WP_REST_Response(
			array(
				'saved'   => true,
				'errors'  => array(),
				'profile' => $profile,
			),
			200
		);
	}

	/**
	 * Field keys whose type is 'url'.
	 *
	 * Both the sanitize and validate passes normalise/validate URL values keyed
	 * on this list, so every url-type field - the seeded website / social_* ones
	 * and any the owner creates - is treated identically (https auto-prefix +
	 * scheme validation), rather than only a hardcoded set of keys.
	 *
	 * @return array<int, string>
	 */
	private function url_field_keys(): array {
		$keys = array();
		foreach ( (array) buddynext_service( 'profiles' )->get_flat_fields() as $field ) {
			if ( 'url' === (string) ( $field['type'] ?? '' ) ) {
				$keys[] = (string) ( $field['field_key'] ?? '' );
			}
		}

		return array_values( array_filter( $keys ) );
	}

	/**
	 * Prefix https:// when a URL value carries no scheme (input convenience).
	 *
	 * @param string $raw Raw URL value.
	 * @return string Trimmed value with a scheme, or '' when empty.
	 */
	private static function ensure_url_scheme( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw || preg_match( '#^https?://#i', $raw ) ) {
			return $raw;
		}

		return 'https://' . ltrim( $raw, '/' );
	}

	/**
	 * Validate an incoming profile payload.
	 *
	 * Returns an associative array of `field => message` for every field that
	 * fails validation. An empty array means the payload is clean.
	 *
	 * Rules:
	 *   - display_name (when present) must be non-empty after trimming.
	 *   - Every url-type field (see url_field_keys()) must pass the scheme check
	 *     when non-empty. Empty strings are allowed (they clear the field).
	 *
	 * On a full write (profile create / complete-editor save) a required field
	 * that is ABSENT from the payload fails validation, closing the bypass where
	 * an app or partial PUT omits the key entirely. On a partial update only a
	 * submitted-empty required value is flagged, so a partial save never demands
	 * every field.
	 *
	 * @param array<string, mixed> $data           Raw request payload.
	 * @param bool                 $full_write     Whether this is a full profile write
	 *                                             (create / complete-editor save). When
	 *                                             true, absent required fields also fail.
	 *                                             Defaults to false (partial update).
	 * @param int                  $target_user_id User whose profile is being saved. Used
	 *                                             to resolve their member type so
	 *                                             type-restricted groups are only enforced
	 *                                             against members who hold that type.
	 *                                             0 = unknown (no type resolution).
	 * @return array<string, string> Field-keyed error messages (possibly empty).
	 */
	private function validate_profile_payload( array $data, bool $full_write = false, int $target_user_id = 0 ): array {
		$errors = array();

		if ( array_key_exists( 'display_name', $data ) ) {
			$dn = trim( (string) $data['display_name'] );
			if ( '' === $dn ) {
				$errors['display_name'] = __( 'Display name is required.', 'buddynext' );
			}
		}

		foreach ( $this->url_field_keys() as $url_key ) {
			if ( ! isset( $data[ $url_key ] ) || ! is_string( $data[ $url_key ] ) ) {
				continue;
			}
			$value = trim( $data[ $url_key ] );
			if ( '' === $value ) {
				continue;
			}
			$candidate = self::ensure_url_scheme( $value );

			// Validate the URL's FORM, not whether our server may fetch it.
			//
			// This was wp_http_validate_url(), which is WordPress's SSRF guard: it
			// exists to answer "is it safe for THIS SERVER to make a request to that
			// address", so it rejects private hosts, loopback, non-standard ports and
			// unusual TLDs. None of that is a statement about whether a member may
			// PUT the link on their profile — we never fetch it, we render it.
			//
			// The damage was not limited to the odd URL. The edit form submits EVERY
			// field, so one stale link anywhere in the payload 422'd the ENTIRE
			// profile save, and the error did not name the URL field — so the member
			// saw an unrelated change (a radio, a bio) refuse to stick, with no clue
			// why. Our own demo seeder shipped a value its own validator rejected
			// (a .example TLD), which means a fresh demo install had a member who
			// could not save his own profile.
			//
			// wp_http_validate_url() stays where the server genuinely does the
			// fetching (outbound webhooks, avatar-by-URL). Not here.
			$parts  = wp_parse_url( $candidate );
			$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
			$host   = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';

			if ( ! filter_var( $candidate, FILTER_VALIDATE_URL )
				|| ! in_array( $scheme, array( 'http', 'https' ), true )
				|| '' === $host
				|| ! str_contains( $host, '.' )
			) {
				$errors[ $url_key ] = __( 'Enter a valid URL (https://example.com).', 'buddynext' );
			}
		}

		// Audience enums: must match the canonical four-value vocabulary.
		$audiences     = array( 'everyone', 'members', 'connections', 'nobody' );
		$audience_keys = array( 'bn_privacy_dm', 'bn_privacy_mention' );
		foreach ( $audience_keys as $aud_key ) {
			if ( ! array_key_exists( $aud_key, $data ) ) {
				continue;
			}
			$val = sanitize_key( (string) $data[ $aud_key ] );
			if ( ! in_array( $val, $audiences, true ) ) {
				$errors[ $aud_key ] = __( 'Choose a valid audience.', 'buddynext' );
			}
		}

		// Profile-view / follow / connect gates: each key has its own enum,
		// mirroring the PrivacyService gate that reads it back.
		$gate_enums = array(
			'bn_privacy_profile_visibility' => array( 'public', 'followers', 'connections', 'private' ),
			'bn_privacy_who_can_follow'     => array( 'everyone', 'nobody' ),
			'bn_privacy_who_can_connect'    => array( 'everyone', 'followers', 'nobody' ),
		);
		foreach ( $gate_enums as $gate_key => $allowed ) {
			if ( ! array_key_exists( $gate_key, $data ) ) {
				continue;
			}
			$gval = sanitize_key( (string) $data[ $gate_key ] );
			if ( ! in_array( $gval, $allowed, true ) ) {
				$errors[ $gate_key ] = __( 'Choose a valid privacy option.', 'buddynext' );
			}
		}

		// Validate the dynamic profile fields against their definitions. Enforce
		// is_required and run the field-type sanitiser/validator HERE so an
		// invalid or missing-required value returns a 422 the form can map to an
		// inline error — previously save_profile() dropped such values with a
		// bare `continue` and still returned 200 {"saved":true}.
		$profiles    = function_exists( 'buddynext_service' ) ? buddynext_service( 'profiles' ) : null;
		$flat_fields = ( $profiles instanceof \BuddyNext\Profile\ProfileService ) ? $profiles->get_flat_fields() : array();

		foreach ( $flat_fields as $field_def ) {
			$fkey = (string) ( $field_def['field_key'] ?? '' );
			if ( '' === $fkey || isset( $errors[ $fkey ] ) ) {
				continue;
			}

			// Skip fields whose group is restricted to a member type the target user does not hold —
			// the field is invisible to them, so neither required-enforcement nor field validation
			// applies. Delegated to ProfileService so this and the persistence layer cannot answer
			// the question differently: the same bug (Zoho #40859) was fixed here and left live in
			// save_profile(), which is what the admin member editor and onboarding actually call.
			if ( $profiles instanceof \BuddyNext\Profile\ProfileService
				&& ! $profiles->field_applies_to_user( $field_def, $target_user_id )
			) {
				continue;
			}

			/**
			 * Filter whether a profile field is ACTIVE for this submission.
			 *
			 * A field that is not active is invisible to the member for this save,
			 * so neither is_required enforcement nor field validation may apply to
			 * it — exactly like the member-type skip above. Returning false is the
			 * only way an add-on can stop a field it hides client-side from 422-ing
			 * the whole profile save when it submits empty (Pro's `conditional`
			 * field type is the canonical case: its wrapper is hidden by JS but its
			 * inner input still posts an empty string).
			 *
			 * @since 1.0.8
			 *
			 * @param bool                 $active         Whether the field applies to this submission.
			 * @param array<string, mixed> $field_def      Flat field definition (type, options, is_required, …).
			 * @param array<string, mixed> $data           The full submitted payload.
			 * @param int                  $target_user_id User whose profile is being saved (0 when unknown).
			 */
			$field_active = apply_filters( 'buddynext_profile_field_is_active', true, $field_def, $data, $target_user_id );
			if ( false === $field_active ) {
				continue;
			}

			// A required `member_type` field must not be enforced against the payload
			// when the member has nothing to submit it WITH.
			//
			// member_type is assignment-backed and set-once, so the editor renders no
			// input in two of its three states: once a member is classified it is a
			// read-only badge ("Set by the community"), and an unclassified member with
			// no self-selectable types gets a plain sentence. In both cases the key is
			// absent from every payload, and the full-write rule below ("an omitted
			// required key is a bypass") then 422'd EVERY save — one changed field or
			// none, member or admin, self-assign on or off. The profile simply could
			// not be saved while a required member_type field existed.
			//
			// Resolve from the live assignment instead, exactly as the group-restriction
			// and field_is_active skips above resolve from state rather than payload.
			// The middle state — unclassified WITH self-selectable types — still falls
			// through and is enforced, because there the member really can choose one.
			// Registration is unaffected: signup enforces through
			// RegistrationPolicy::missing(), not this validator.
			if ( 'member_type' === (string) ( $field_def['type'] ?? '' ) ) {
				$bn_mt_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'member_types' ) : null;
				$bn_has_type   = is_object( $bn_mt_service )
					&& method_exists( $bn_mt_service, 'get_user_type' )
					&& null !== $bn_mt_service->get_user_type( $target_user_id );

				if ( $bn_has_type || array() === \BuddyNext\Profile\FieldType::member_type_self_select_options() ) {
					continue;
				}
			}

			// A repeater sub-field's value is NEVER a top-level payload key: it lives
			// inside its group's entries (data[group_key][i][field_key], written by
			// collectRepeaterEntries in the profile store). Measuring it against the
			// flat payload therefore reports it as absent on EVERY save — so a single
			// owner toggling Required on any repeater sub-field 422'd every member's
			// profile, including members who had filled it in and members with no
			// entries at all. Enforce it per entry instead.
			if ( 'repeater' === (string) ( $field_def['group_type'] ?? '' ) ) {
				$gkey = (string) ( $field_def['group_key'] ?? '' );

				// Group not submitted at all -> nothing to validate. A partial update
				// leaves it untouched; a full write with no entries is a member who
				// legitimately has none, and a required SUB-field must not force them
				// to invent one.
				if ( '' === $gkey || ! array_key_exists( $gkey, $data ) || ! is_array( $data[ $gkey ] ) ) {
					continue;
				}

				if ( empty( $field_def['is_required'] ) ) {
					continue;
				}

				foreach ( $data[ $gkey ] as $entry_index => $entry ) {
					$entry_val   = is_array( $entry ) && array_key_exists( $fkey, $entry ) ? $entry[ $fkey ] : null;
					$entry_empty = ( null === $entry_val
						|| ( is_string( $entry_val ) && '' === trim( $entry_val ) )
						|| ( is_array( $entry_val ) && array() === $entry_val ) );

					if ( $entry_empty ) {
						// Key the error to the entry so the editor can highlight the
						// offending row instead of showing an unattributable toast.
						$errors[ $gkey . '.' . (int) $entry_index . '.' . $fkey ] = sprintf(
							/* translators: %s: field label. */
							__( '%s is required.', 'buddynext' ),
							(string) ( $field_def['label'] ?? $fkey )
						);
					}
				}

				continue;
			}

			$present  = array_key_exists( $fkey, $data );
			$raw      = $present ? $data[ $fkey ] : null;
			$is_empty = ( null === $raw
				|| ( is_string( $raw ) && '' === trim( $raw ) )
				|| ( is_array( $raw ) && array() === $raw ) );

			// Required: flagged when a submitted value is empty (both modes) and,
			// on a full write, when the key is ABSENT too — an omitted required key
			// on a create / complete-editor save is a bypass, not a cleared field.
			// A partial update leaves absent keys untouched, so it never demands
			// fields the caller did not submit.
			if ( ! empty( $field_def['is_required'] ) && $is_empty && ( $present || $full_write ) ) {
				/* translators: %s: field label. */
				$errors[ $fkey ] = sprintf( __( '%s is required.', 'buddynext' ), (string) ( $field_def['label'] ?? $fkey ) );
				continue;
			}

			if ( ! $present || $is_empty ) {
				continue;
			}

			// Surface field-type sanitise/validate failures instead of silently
			// discarding the value during save.
			$sanitized = \BuddyNext\Profile\FieldType::sanitize( $field_def, $raw );
			if ( is_wp_error( $sanitized ) ) {
				$errors[ $fkey ] = $sanitized->get_error_message();
				continue;
			}
			$validation = apply_filters(
				'buddynext_profile_field_validate',
				true,
				(string) ( $field_def['type'] ?? 'text' ),
				(string) $sanitized,
				$field_def,
				get_current_user_id()
			);
			if ( is_wp_error( $validation ) ) {
				$errors[ $fkey ] = $validation->get_error_message();
			}
		}

		return $errors;
	}

	/**
	 * Turn a save_profile() rejection into a field => message map the editor can
	 * paint as inline errors.
	 *
	 * ProfileService::save_profile() rejects a write in two shapes:
	 *
	 *   1. Per-field failures (required / sanitise / validate). The WP_Error data
	 *      already carries a `fields` map — return it untouched.
	 *   2. The moderation safeguard (banned word, blocked link, blocked hashtag).
	 *      That check runs once over the JOINED text of every submitted value, so
	 *      the WP_Error has no field attribution at all. An unattributed 422 leaves
	 *      the member with a red toast and no idea WHICH field to fix — a different
	 *      dead end. So the same safeguard is re-run per submitted value (only on
	 *      this already-failing path) to name the offending field(s).
	 *
	 * An empty map is a valid outcome (the caller still returns the WP_Error's
	 * message alongside it); it is never a claim that the save succeeded.
	 *
	 * @param \WP_Error            $error   The WP_Error returned by save_profile().
	 * @param array<string, mixed> $data    The payload that was submitted (post-sanitisation).
	 * @param int                  $user_id User whose profile was being saved.
	 * @return array<string, string> Field-keyed error messages (possibly empty).
	 */
	private function map_save_error_to_fields( \WP_Error $error, array $data, int $user_id ): array {
		// Lives on the service now: the admin member editor needs the identical
		// mapping, and this was private here. See ProfileService for the reasoning.
		return buddynext_service( 'profiles' )->map_save_error_to_fields( $error, $data, $user_id );
	}

	/**
	 * Update any user's profile (admin only).
	 *
	 * Body params: display_name + any field_key => value pairs (same format as PUT /me/profile),
	 * and the same strict-input rule — an unwritable key is refused with 400 rather
	 * than ignored. The two endpoints share one payload contract, so they share
	 * one allowlist; an admin editing a member must not get looser behaviour than
	 * the member editing themselves.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_update_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$unknown = $this->reject_unknown_body_params( $request, $this->profile_write_allowlist() );
		if ( $unknown instanceof WP_Error ) {
			return $unknown;
		}

		$service = buddynext_service( 'profiles' );
		$json    = $request->get_json_params();
		$data    = is_array( $json ) && ! empty( $json ) ? $json : (array) $request->get_body_params();

		// Mirror PUT /me/profile: a full write enforces required fields across ABSENT
		// keys, a partial update stays present-only. Validate BEFORE mutating anything
		// (display_name included) so a 422 leaves the record untouched. Previously this
		// admin route ran no payload validation and shared the same absent-key bypass.
		$full_write = ! empty( $data['full_write'] ) && rest_sanitize_boolean( (string) $data['full_write'] );
		unset( $data['full_write'] );

		$errors = $this->validate_profile_payload( $data, $full_write, $user_id );
		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'saved'  => false,
					'errors' => $errors,
				),
				422
			);
		}

		// Handle display_name separately — it's a WP core field, not a profile value.
		if ( isset( $data['display_name'] ) ) {
			$display_name = sanitize_text_field( (string) $data['display_name'] );
			if ( '' !== $display_name ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $display_name,
					)
				);
			}
			unset( $data['display_name'] );
		}

		$result = $service->save_profile( $user_id, $data );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'saved'   => false,
					'errors'  => $this->map_save_error_to_fields( $result, $data, $user_id ),
					'message' => $result->get_error_message(),
				),
				422
			);
		}

		do_action( 'buddynext_index_user', $user_id );
		$profile = $service->get_profile( $user_id, $user_id );

		return new WP_REST_Response( $profile, 200 );
	}

	/**
	 * Return the current user's profile slug and canonical URL.
	 *
	 * @return WP_REST_Response
	 */
	public function get_profile_slug(): WP_REST_Response {
		$user_id = get_current_user_id();
		$slug    = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		$url     = \BuddyNext\Core\PageRouter::profile_url( $user_id );

		return new WP_REST_Response(
			array(
				'slug' => '' !== $slug ? $slug : null,
				'url'  => $url,
			),
			200
		);
	}

	/**
	 * Set the current user's custom profile slug.
	 *
	 * Slug must be unique and not match the reserved user-{id} pattern.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile_slug( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id        = get_current_user_id();
		$requested_slug = sanitize_title( (string) $request->get_param( 'slug' ) );

		if ( '' === $requested_slug ) {
			return new WP_Error(
				'invalid_slug',
				__( 'Slug cannot be empty.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		// Some communities fix handles to real names — an intranet, a course
		// cohort. Default is that members own their own handle.
		/**
		 * Filter whether members may change their own handle.
		 *
		 * @since 1.1.6
		 *
		 * @param bool $allowed Default true.
		 * @param int  $user_id Member attempting the change.
		 */
		if ( ! (bool) apply_filters( 'buddynext_members_can_change_handle', true, $user_id ) ) {
			return new WP_Error(
				'handle_change_disabled',
				__( 'Handles cannot be changed on this community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// Unusable and unavailable are different answers, and the member needs to
		// know which: "already taken" sends them looking for another name, when
		// the real problem may be that they typed two characters or an emoji.
		$valid = \BuddyNext\Profile\Handle::validate( $requested_slug );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! \BuddyNext\Core\PageRouter::is_slug_available( $requested_slug, $user_id ) ) {
			return new WP_Error(
				'slug_taken',
				__( 'That profile URL is already taken.', 'buddynext' ),
				array( 'status' => 409 )
			);
		}

		// One writer for the handle: it also keeps user_nicename in step and
		// records the handle being left behind.
		$saved = \BuddyNext\Profile\Handle::set( $user_id, $requested_slug );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response(
			array(
				'slug' => $requested_slug,
				'url'  => \BuddyNext\Core\PageRouter::profile_url( $user_id ),
			),
			200
		);
	}

	/**
	 * Check whether a profile slug is available for the current user to claim.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function check_slug_availability( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$slug      = sanitize_title( (string) $request->get_param( 'slug' ) );
		$available = '' !== $slug && \BuddyNext\Core\PageRouter::is_slug_available( $slug, $user_id );

		return new WP_REST_Response(
			array(
				'slug'      => $slug,
				'available' => $available,
			),
			200
		);
	}

	/**
	 * Return all profile field definitions grouped by their parent group.
	 *
	 * Response shape: { "groups": [ { "id", "group_key", "label", "type", "fields": [...] } ] }
	 *
	 * @return WP_REST_Response
	 */
	public function list_fields(): WP_REST_Response {
		$groups = buddynext_service( 'profiles' )->get_fields();

		return new WP_REST_Response( array( 'groups' => $groups ), 200 );
	}

	/**
	 * Create a new profile field definition.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function create_field( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$unknown = $this->reject_unknown_body_params( $request );
		if ( $unknown instanceof WP_Error ) {
			return $unknown;
		}

		// Build the payload from the DECLARED schema rather than forwarding
		// get_params() wholesale. The old call handed the service every param on
		// the request — route params, WordPress' own _locale, anything a caller
		// invented — and the five attributes it did honour arrived undeclared, so
		// they skipped the route's sanitisation entirely.
		$data = array();
		foreach ( array_keys( self::field_definition_args( true ) ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$data[ $key ] = $value;
			}
		}

		if ( isset( $data['options'] ) ) {
			$data['options'] = self::normalise_field_options( $data['options'] );
		}

		// Same rule as every other door to this table: a flag that cannot be
		// honoured is not stored as though it were. A date or number field cannot
		// back a free-text mirror, and a followers/connections/private field never
		// reaches an index — storing 1 there would tell an owner their field is
		// findable when search can never return it.
		if ( ! empty( $data['is_searchable'] )
			&& ! FieldType::is_searchable_applicable(
				(string) ( $data['type'] ?? 'text' ),
				(string) ( $data['visibility'] ?? 'members' )
			) ) {
			$data['is_searchable'] = false;
		}

		$field_id = buddynext_service( 'profiles' )->create_field( $data );

		return new WP_REST_Response( array( 'id' => $field_id ), 201 );
	}

	/**
	 * Return all profile group definitions (metadata only, no fields).
	 *
	 * @return WP_REST_Response
	 */
	public function list_groups(): WP_REST_Response {
		$groups = buddynext_service( 'profiles' )->get_groups();

		return new WP_REST_Response( array( 'groups' => $groups ), 200 );
	}

	/**
	 * Create a new profile group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function create_group( WP_REST_Request $request ): WP_REST_Response {
		$group_id = buddynext_service( 'profiles' )->create_group( $request->get_params() );

		return new WP_REST_Response( array( 'id' => $group_id ), 201 );
	}

	/**
	 * Update an existing profile group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_group( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$data = array();

		$label = $request->get_param( 'label' );
		if ( null !== $label ) {
			$data['label'] = sanitize_text_field( (string) $label );
		}

		$visibility = $request->get_param( 'visibility' );
		if ( null !== $visibility ) {
			$data['visibility'] = sanitize_key( (string) $visibility );
		}

		$sort_order = $request->get_param( 'sort_order' );
		if ( null !== $sort_order ) {
			$data['sort_order'] = absint( $sort_order );
		}

		buddynext_service( 'profiles' )->update_group( $id, $data );

		return new WP_REST_Response( array( 'updated' => true ), 200 );
	}

	/**
	 * Delete a profile group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function delete_group( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		$result = buddynext_service( 'profiles' )->delete_group( $id );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), $status );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Reorder a profile group by moving it up or down.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function reorder_group( WP_REST_Request $request ): WP_REST_Response {
		$id        = (int) $request->get_param( 'id' );
		$direction = sanitize_key( (string) $request->get_param( 'direction' ) );

		buddynext_service( 'profiles' )->reorder_group( $id, $direction );

		return new WP_REST_Response( array( 'reordered' => true ), 200 );
	}

	/**
	 * Update an existing profile field definition.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_field( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$unknown = $this->reject_unknown_body_params( $request );
		if ( $unknown instanceof WP_Error ) {
			return $unknown;
		}

		$id   = (int) $request->get_param( 'id' );
		$data = array();

		// Every attribute the service can write, read from one list. Five of them
		// (description, placeholder, is_searchable, show_on_register,
		// show_in_header) were accepted by the route, dropped here, and answered
		// with {"updated":true} — the admin screen could set them and the API
		// could not, with nothing saying so.
		foreach ( self::FIELD_TEXT_ATTRIBUTES as $attribute ) {
			$value = $request->get_param( $attribute );
			if ( null !== $value ) {
				$data[ $attribute ] = sanitize_text_field( (string) $value );
			}
		}

		foreach ( self::FIELD_BOOL_ATTRIBUTES as $attribute ) {
			$value = $request->get_param( $attribute );
			if ( null !== $value ) {
				$data[ $attribute ] = (bool) $value;
			}
		}

		$type = $request->get_param( 'type' );
		if ( null !== $type ) {
			$data['type'] = sanitize_key( (string) $type );
		}

		$visibility = $request->get_param( 'visibility' );
		if ( null !== $visibility ) {
			$data['visibility'] = sanitize_key( (string) $visibility );
		}

		if ( null !== $request->get_param( 'options' ) ) {
			$data['options'] = self::normalise_field_options( $request->get_param( 'options' ) );
		}

		$sort_order = $request->get_param( 'sort_order' );
		if ( null !== $sort_order ) {
			$data['sort_order'] = absint( $sort_order );
		}

		$group_id = $request->get_param( 'group_id' );
		if ( null !== $group_id ) {
			$data['group_id'] = absint( $group_id );
		}

		// The service is the authority on what a definition may become (registry
		// type, applicable is_searchable, value migration on a type change, and
		// whether a group move would hide entries), and it reports a refusal
		// instead of writing something unusable.
		$result = buddynext_service( 'profiles' )->update_field( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'updated' => true ), 200 );
	}

	/**
	 * Plain-text field attributes accepted on a definition write.
	 *
	 * @since 1.1.6
	 *
	 * @var string[]
	 */
	private const FIELD_TEXT_ATTRIBUTES = array( 'label', 'description', 'placeholder' );

	/**
	 * Boolean field attributes accepted on a definition write.
	 *
	 * @since 1.1.6
	 *
	 * @var string[]
	 */
	private const FIELD_BOOL_ATTRIBUTES = array( 'is_required', 'is_searchable', 'show_on_register', 'show_in_header' );

	/**
	 * Delete a profile field definition.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function delete_field( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		$result = buddynext_service( 'profiles' )->delete_field( $id );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), $status );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Reorder a profile field by moving it up or down within its group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function reorder_field( WP_REST_Request $request ): WP_REST_Response {
		$id        = (int) $request->get_param( 'id' );
		$direction = sanitize_key( (string) $request->get_param( 'direction' ) );

		buddynext_service( 'profiles' )->reorder_field( $id, $direction );

		return new WP_REST_Response( array( 'reordered' => true ), 200 );
	}

	/**
	 * Upload an avatar for the current user.
	 *
	 * Expects a multipart/form-data POST with a single file field named "avatar".
	 * Max size 2 MB. Allowed MIME types: JPEG, PNG, GIF, WebP.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_avatar(): WP_REST_Response|WP_Error {
		return $this->handle_avatar_upload( get_current_user_id() );
	}

	/**
	 * Remove the current user's custom avatar.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_avatar(): WP_REST_Response {
		buddynext_service( 'profiles' )->delete_avatar( get_current_user_id() );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Upload an avatar on behalf of any user (admin only).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_upload_avatar( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		return $this->handle_avatar_upload( $user_id );
	}

	/**
	 * DELETE /users/{id}/avatar — remove any user's avatar (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete_avatar( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'user_not_found', __( 'User not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		buddynext_service( 'profiles' )->delete_avatar( $user_id );

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'user_id' => $user_id,
			),
			200
		);
	}

	/**
	 * POST /users/{id}/cover — upload cover photo for any user (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_upload_cover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = absint( $request->get_param( 'id' ) );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		return $this->handle_cover_upload( $user_id );
	}

	/**
	 * DELETE /users/{id}/cover — remove cover photo for any user (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete_cover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = absint( $request->get_param( 'id' ) );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$this->purge_user_cover( $user_id );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Upload a cover photo for the current user.
	 *
	 * Expects a multipart/form-data POST with a single file field named "avatar".
	 * Max size 5 MB. Allowed MIME types: JPEG, PNG, WebP.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_cover(): WP_REST_Response|WP_Error {
		return $this->handle_cover_upload( get_current_user_id() );
	}

	/**
	 * Remove the current user's cover photo.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_cover(): WP_REST_Response {
		$this->purge_user_cover( get_current_user_id() );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Fully remove a user's cover photo: usermeta URL, the stored focal point,
	 * and the image variations on disk.
	 *
	 * Deleting only the `buddynext_cover_url` usermeta would leave the
	 * uploads/bn-covers/{user_id}/ files orphaned forever and keep a stale
	 * `buddynext_cover_focal` object-position that profile-hero.php would apply
	 * to the next uploaded cover.
	 *
	 * @param int $user_id Target user ID.
	 * @return void
	 */
	private function purge_user_cover( int $user_id ): void {
		( new AvatarService() )->delete_cover( $user_id );
		delete_user_meta( $user_id, 'buddynext_cover_focal' );
		( new \BuddyNext\Media\ImageStorageService() )->delete( 'cover', 'user', $user_id );
	}

	/**
	 * Count a user's authored posts (top-level activity).
	 *
	 * @param int $user_id Target user ID.
	 * @return int
	 */
	private function user_post_count( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d",
				$user_id
			)
		);
	}

	/**
	 * Gate an uploaded image on size and pixel count.
	 *
	 * Replaces a pair of hardcoded dimension caps — 1920x1080 for covers,
	 * 1024x1024 for avatars — that predate the phones people actually upload
	 * from. A current handset shoots 4032x3024, so BOTH were rejecting an
	 * ordinary photo and asking a non-technical member to go and crop it before
	 * they could finish their profile.
	 *
	 * The caps could not simply be lifted, though the card asking for it argued
	 * they could: `ImageStorageService` does downscale every stored image
	 * (avatar 512, cover 1600), but that happens AFTER the file is decoded, and
	 * decoding is the step that costs the memory. A 20000x20000 PNG is a few MB
	 * on disk and about 1.6GB decoded — the byte cap cannot catch it, because
	 * compression ratio is precisely what varies.
	 *
	 * So the guard stays and gets measured limits instead of arbitrary ones:
	 *
	 * - a MEGAPIXEL ceiling, because decode cost is proportional to pixel count
	 *   and nothing else. 50MP passes every phone including 48MP high-res modes
	 *   and stops the decompression bombs, which run to hundreds of megapixels.
	 * - a single-side ceiling, because 100000x500 is 50MP and still pathological.
	 * - both filterable, along with the byte cap, which is what the reporting
	 *   owner actually needed.
	 *
	 * Deliberately NOT derived from `memory_get_usage()` or `WP_MEMORY_LIMIT`,
	 * which was the first design and was wrong: measured on this stack, GD's
	 * buffers are invisible to PHP's accounting — allocating 900 megapixels
	 * moved PHP's reported usage by 0.00 MB. A budget computed from PHP's
	 * memory limit would be arithmetic about a number that does not govern the
	 * allocation, and would read as principled while protecting nothing.
	 *
	 * @param array<string,mixed> $file Entry from $_FILES.
	 * @param string              $kind 'avatar' or 'cover' — selects the defaults
	 *                                  and is passed to every filter so a site can
	 *                                  treat the two differently.
	 * @return true|WP_Error
	 */
	private function validate_image_upload( array $file, string $kind ): bool|WP_Error {
		$default_bytes = 'avatar' === $kind ? 4 * 1024 * 1024 : 5 * 1024 * 1024;

		/**
		 * Filter the maximum accepted upload size for a profile image.
		 *
		 * @since 1.1.5
		 *
		 * @param int    $bytes Maximum bytes.
		 * @param string $kind  'avatar' or 'cover'.
		 */
		$max_bytes = (int) apply_filters( 'buddynext_upload_max_bytes', $default_bytes, $kind );

		if ( $max_bytes > 0 && (int) ( $file['size'] ?? 0 ) > $max_bytes ) {
			return new WP_Error(
				'avatar' === $kind ? 'avatar_too_large' : 'cover_too_large',
				sprintf(
					/* translators: %s: maximum file size, e.g. "5 MB". */
					__( 'That file is larger than %s. Please choose a smaller one.', 'buddynext' ),
					size_format( $max_bytes )
				),
				array( 'status' => 422 )
			);
		}

		$tmp  = (string) ( $file['tmp_name'] ?? '' );
		$dims = ( '' !== $tmp && is_readable( $tmp ) ) ? @getimagesize( $tmp ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $dims ) ) {
			return true;
		}

		$width  = (int) $dims[0];
		$height = (int) $dims[1];

		/**
		 * Filter the maximum pixel count accepted for a profile image.
		 *
		 * @since 1.1.5
		 *
		 * @param float  $megapixels Maximum megapixels.
		 * @param string $kind       'avatar' or 'cover'.
		 */
		$max_megapixels = (float) apply_filters( 'buddynext_upload_max_megapixels', 50.0, $kind );

		/**
		 * Filter the maximum length of either side of a profile image.
		 *
		 * @since 1.1.5
		 *
		 * @param int    $pixels Maximum width or height.
		 * @param string $kind   'avatar' or 'cover'.
		 */
		$max_side = (int) apply_filters( 'buddynext_upload_max_dimension', 10000, $kind );

		$megapixels = ( $width * $height ) / 1000000;

		if ( $max_megapixels > 0 && $megapixels > $max_megapixels ) {
			return new WP_Error(
				'avatar' === $kind ? 'avatar_dimensions' : 'cover_dimensions',
				sprintf(
					/* translators: 1: the image's megapixels, 2: the maximum allowed. */
					__( 'That image is %1$s megapixels, and the limit is %2$s. Please scale it down before uploading.', 'buddynext' ),
					number_format_i18n( $megapixels, 1 ),
					number_format_i18n( $max_megapixels, 0 )
				),
				array( 'status' => 422 )
			);
		}

		if ( $max_side > 0 && ( $width > $max_side || $height > $max_side ) ) {
			return new WP_Error(
				'avatar' === $kind ? 'avatar_dimensions' : 'cover_dimensions',
				sprintf(
					/* translators: %s: maximum pixels on one side. */
					__( 'That image is longer than %s pixels on one side. Please scale it down before uploading.', 'buddynext' ),
					number_format_i18n( $max_side )
				),
				array( 'status' => 422 )
			);
		}

		return true;
	}

	/**
	 * Shared cover upload logic.
	 *
	 * Validates the uploaded file, moves it to the uploads directory via the
	 * WordPress upload handler, and stores the resulting URL in usermeta. The
	 * file is read from the `cover` field when present, falling back to the
	 * `avatar` field that the bundled web editor currently posts under, so both
	 * the canonical key and the existing client work.
	 *
	 * @param int $user_id Target user ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_cover_upload( int $user_id ): WP_REST_Response|WP_Error {
		/*
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		 */
		if ( isset( $_FILES['cover'] ) && is_array( $_FILES['cover'] ) ) {
			$cover_file = $_FILES['cover'];
		} elseif ( isset( $_FILES['avatar'] ) && is_array( $_FILES['avatar'] ) ) {
			$cover_file = $_FILES['avatar'];
		} else {
			$cover_file = array();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( empty( $cover_file ) || UPLOAD_ERR_OK !== (int) ( $cover_file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error(
				'cover_missing',
				__( 'No file uploaded or upload error.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		$cover_valid = $this->validate_image_upload( $cover_file, 'cover' );
		if ( is_wp_error( $cover_valid ) ) {
			return $cover_valid;
		}

		$file_data = array(
			'name'     => sanitize_file_name( (string) ( $cover_file['name'] ?? '' ) ),
			'type'     => (string) ( $cover_file['type'] ?? '' ),
			'tmp_name' => (string) ( $cover_file['tmp_name'] ?? '' ),
			'error'    => (int) ( $cover_file['error'] ?? UPLOAD_ERR_NO_FILE ),
			'size'     => (int) ( $cover_file['size'] ?? 0 ),
		);

		$check = wp_check_filetype_and_ext(
			$file_data['tmp_name'],
			$file_data['name']
		);

		$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );

		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return new WP_Error(
				'cover_invalid_type',
				__( 'Only JPEG, PNG, or WebP images are accepted.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		// Store as organized, per-owner WebP variations (uploads/bn-covers/{id}/)
		// — no attachment rows, no orphans on replace. See ImageStorageService.
		$cover_stored = ( new \BuddyNext\Media\ImageStorageService() )->store(
			(string) $file_data['tmp_name'],
			'cover',
			'user',
			$user_id
		);
		if ( is_wp_error( $cover_stored ) ) {
			return new WP_Error(
				'cover_upload_failed',
				$cover_stored->get_error_message(),
				array( 'status' => 500 )
			);
		}

		( new AvatarService() )->save_cover_url( $user_id, (string) $cover_stored );

		/*
		 * Reposition data — `focal_x`/`focal_y` (object-position percent 0–100)
		 * and `focal_zoom` (scale factor 1–3). Stored as `buddynext_cover_focal`
		 * user meta and applied by templates/parts/profile-hero.php to the cover
		 * <img> via object-position + transform:scale (non-destructive).
		 *
		 * This is a REST callback gated by a real permission_callback
		 * (require_auth / require_edit_any_profile); WP core validates the
		 * X-WP-Nonce cookie nonce before the callback runs. Each raw value is
		 * cast to float and range-clamped below, so no further sanitization
		 * helper applies.
		 *
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 */
		$focal_x_raw    = isset( $_POST['focal_x'] ) ? (float) wp_unslash( (string) $_POST['focal_x'] ) : -1;
		$focal_y_raw    = isset( $_POST['focal_y'] ) ? (float) wp_unslash( (string) $_POST['focal_y'] ) : -1;
		$focal_zoom_raw = isset( $_POST['focal_zoom'] ) ? (float) wp_unslash( (string) $_POST['focal_zoom'] ) : 1.0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $focal_x_raw >= 0 && $focal_x_raw <= 100 && $focal_y_raw >= 0 && $focal_y_raw <= 100 ) {
			update_user_meta(
				$user_id,
				'buddynext_cover_focal',
				array(
					'x'    => round( $focal_x_raw, 2 ),
					'y'    => round( $focal_y_raw, 2 ),
					'zoom' => round( max( 1.0, min( 3.0, $focal_zoom_raw ) ), 3 ),
				)
			);
		}

		$focal = (array) get_user_meta( $user_id, 'buddynext_cover_focal', true );

		return new WP_REST_Response(
			array(
				'cover_url'  => $cover_stored,
				'focal_x'    => isset( $focal['x'] ) ? (float) $focal['x'] : 50.0,
				'focal_y'    => isset( $focal['y'] ) ? (float) $focal['y'] : 50.0,
				'focal_zoom' => isset( $focal['zoom'] ) ? (float) $focal['zoom'] : 1.0,
			),
			200
		);
	}

	/**
	 * Shared avatar upload logic.
	 *
	 * Validates the uploaded file, moves it to the uploads directory via the
	 * WordPress upload handler, and stores the resulting URL in usermeta.
	 *
	 * @param int $user_id Target user ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_avatar_upload( int $user_id ): WP_REST_Response|WP_Error {
		/*
		 * The WP REST API verifies the X-WP-Nonce header before this callback
		 * fires; nonce handling is therefore already done. WPCS cannot see the
		 * REST authentication layer, so we suppress its nonce and index checks
		 * only for the $_FILES reads below.
		 *
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		 */
		$avatar_file = isset( $_FILES['avatar'] ) && is_array( $_FILES['avatar'] )
			? $_FILES['avatar']
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( empty( $avatar_file ) || UPLOAD_ERR_OK !== (int) ( $avatar_file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error(
				'avatar_missing',
				__( 'No file uploaded or upload error.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		$avatar_valid = $this->validate_image_upload( $avatar_file, 'avatar' );
		if ( is_wp_error( $avatar_valid ) ) {
			return $avatar_valid;
		}

		$file_data = array(
			'name'     => sanitize_file_name( (string) ( $avatar_file['name'] ?? '' ) ),
			'type'     => (string) ( $avatar_file['type'] ?? '' ),
			'tmp_name' => (string) ( $avatar_file['tmp_name'] ?? '' ),
			'error'    => (int) ( $avatar_file['error'] ?? UPLOAD_ERR_NO_FILE ),
			'size'     => (int) ( $avatar_file['size'] ?? 0 ),
		);

		$check = wp_check_filetype_and_ext(
			$file_data['tmp_name'],
			$file_data['name']
		);

		$allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return new WP_Error(
				'avatar_invalid_type',
				__( 'Only JPEG, PNG, GIF, or WebP images are accepted.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		// Store as organized, per-owner WebP variations (uploads/bn-avatars/{id}/)
		// instead of a wp_handle_upload() file in uploads/YYYY/MM — no
		// attachment rows, no orphans on replace. See Media\ImageStorageService.
		$stored = ( new \BuddyNext\Media\ImageStorageService() )->store(
			(string) $file_data['tmp_name'],
			'avatar',
			'user',
			$user_id
		);
		if ( is_wp_error( $stored ) ) {
			return new WP_Error(
				'avatar_upload_failed',
				$stored->get_error_message(),
				array( 'status' => 500 )
			);
		}

		buddynext_service( 'profiles' )->update_avatar( $user_id, esc_url_raw( $stored ) );

		return new WP_REST_Response( array( 'avatar_url' => $stored ), 200 );
	}
}
