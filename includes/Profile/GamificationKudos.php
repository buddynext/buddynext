<?php
/**
 * Gamification "Kudos" profile tab.
 *
 * Peer recognition, in the BuddyNext shell (no trip to /gamification/):
 *
 *  - The kudos a member has received (KudosEngine::get_received).
 *  - A "give kudos" form when viewing someone else's profile — posts to the giver's
 *    session via admin-post (web) with a matching REST route for the app.
 *
 * wb-gamification owns the kudos engine (rate limits, point awards, moderation);
 * BuddyNext only renders the surface and forwards the send.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

/**
 * Renders the Kudos profile tab + handles the give action.
 */
class GamificationKudos {

	private const TAB_SLUG = 'kudos';
	private const NONCE    = 'bn_give_kudos';

	/**
	 * Wire the tab, the web give-handler, and the REST route (gamification only).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! is_callable( array( '\WBGam\Engine\KudosEngine', 'send' ) ) ) {
			return;
		}
		add_action( 'buddynext_register_nav', array( $this, 'register_nav' ) );
		add_action( 'admin_post_bn_give_kudos', array( $this, 'handle_give_kudos' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the Kudos tab (shown on every member profile).
	 *
	 * @param \BuddyNext\Nav\NavRegistry $registry Shared nav registry.
	 * @return void
	 */
	public function register_nav( \BuddyNext\Nav\NavRegistry $registry ): void {
		$registry->register(
			array(
				'id'        => self::TAB_SLUG,
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Kudos', 'buddynext' ),
				'icon'      => 'heart',
				'priority'  => 74,
				'condition' => static fn( \BuddyNext\Nav\NavContext $c ): bool =>
					buddynext_integration_enabled( 'gamification', 'nav' ) && $c->subject_id > 0,
				'url'       => static fn( \BuddyNext\Nav\NavContext $c ): string =>
					trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . self::TAB_SLUG . '/',
				'count'     => static fn( \BuddyNext\Nav\NavContext $c ): int =>
					is_callable( array( '\WBGam\Engine\KudosEngine', 'get_received_count' ) )
						? (int) \WBGam\Engine\KudosEngine::get_received_count( $c->subject_id )
						: 0,
				'render'    => function ( \BuddyNext\Nav\NavContext $c ): void {
					$this->render_panel( $c->subject_id );
				},
			)
		);
	}

	/**
	 * Render the Kudos panel: give form (for other members) + received feed.
	 *
	 * @param int $member_id Profile being viewed.
	 * @return void
	 */
	public function render_panel( int $member_id ): void {
		if ( $member_id <= 0 ) {
			return;
		}

		$viewer   = get_current_user_id();
		$is_self  = $viewer > 0 && $viewer === $member_id;
		$can_give = $viewer > 0 && ! $is_self;

		echo '<div class="bn-gam-kudos">';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result flag.
		$sent = isset( $_GET['kudos'] ) && 'sent' === sanitize_key( wp_unslash( $_GET['kudos'] ) );
		$err  = isset( $_GET['kudos_err'] ) ? sanitize_text_field( wp_unslash( $_GET['kudos_err'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $sent ) {
			echo '<div class="bn-notice bn-notice--success">' . esc_html__( 'Kudos sent — nice!', 'buddynext' ) . '</div>';
		} elseif ( '' !== $err ) {
			echo '<div class="bn-notice bn-notice--error">' . esc_html( $this->error_message( $err ) ) . '</div>';
		}

		if ( $can_give ) {
			$this->render_give_form( $member_id );
		}

		$this->render_received( $member_id, $is_self );

		echo '</div>';
	}

	/**
	 * The "give kudos to this member" form (web → admin-post).
	 *
	 * @param int $member_id Receiver.
	 * @return void
	 */
	private function render_give_form( int $member_id ): void {
		$name = (string) get_the_author_meta( 'display_name', $member_id );

		echo '<form class="bn-card bn-gam-kudos__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="bn_give_kudos" />';
		echo '<input type="hidden" name="receiver_id" value="' . esc_attr( (string) $member_id ) . '" />';
		wp_nonce_field( self::NONCE );

		echo '<label class="bn-gam-kudos__form-label" for="bn-kudos-msg">' . esc_html(
			sprintf(
				/* translators: %s: member display name. */
				__( 'Give kudos to %s', 'buddynext' ),
				$name
			)
		) . '</label>';
		echo '<textarea id="bn-kudos-msg" name="message" class="bn-input" rows="2" maxlength="255" placeholder="' . esc_attr__( 'Say something nice (optional)', 'buddynext' ) . '"></textarea>';
		echo '<button type="submit" class="bn-btn" data-variant="primary">' . esc_html__( 'Send kudos', 'buddynext' ) . '</button>';
		echo '</form>';
	}

	/**
	 * The received-kudos feed.
	 *
	 * @param int  $member_id Receiver.
	 * @param bool $is_self   Whether the viewer is the profile owner.
	 * @return void
	 */
	private function render_received( int $member_id, bool $is_self ): void {
		$rows = is_callable( array( '\WBGam\Engine\KudosEngine', 'get_received' ) )
			? \WBGam\Engine\KudosEngine::get_received( $member_id, 20 )
			: array();

		echo '<div class="bn-card bn-gam-points__panel">';
		echo '<div class="bn-widget-title">';
		if ( function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( 'heart' );
		}
		echo ' ' . esc_html__( 'Kudos received', 'buddynext' );
		echo '</div>';

		if ( empty( $rows ) ) {
			echo '<p class="bn-achievements__empty">' . esc_html(
				$is_self
					? __( 'No kudos yet — keep helping others and it will come.', 'buddynext' )
					: __( 'No kudos yet — be the first to recognise them.', 'buddynext' )
			) . '</p>';
			echo '</div>';
			return;
		}

		echo '<ul class="bn-gam-kudos__feed" role="list">';
		foreach ( $rows as $row ) {
			$giver_id   = (int) ( $row['giver_id'] ?? 0 );
			$giver_name = (string) ( $row['giver_name'] ?? __( 'Someone', 'buddynext' ) );
			$message    = isset( $row['message'] ) ? (string) $row['message'] : '';
			$when       = ! empty( $row['created_at'] ) ? (int) strtotime( (string) $row['created_at'] ) : 0;

			echo '<li class="bn-gam-kudos__item">';
			echo '<img class="bn-gam-kudos__avatar" src="' . esc_url( get_avatar_url( $giver_id, array( 'size' => 72 ) ) ) . '" alt="" loading="lazy" width="36" height="36" />';
			echo '<div class="bn-gam-kudos__body">';
			echo '<span class="bn-gam-kudos__from">' . esc_html(
				sprintf(
					/* translators: %s: giver display name. */
					__( '%s gave kudos', 'buddynext' ),
					$giver_name
				)
			);
			if ( $when > 0 ) {
				echo ' <span class="bn-gam-kudos__time">' . esc_html(
					sprintf(
						/* translators: %s: human-readable time difference. */
						__( '· %s ago', 'buddynext' ),
						human_time_diff( $when )
					)
				) . '</span>';
			}
			echo '</span>';
			if ( '' !== $message ) {
				echo '<span class="bn-gam-kudos__msg">' . esc_html( $message ) . '</span>';
			}
			echo '</div>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Handle the web give-kudos form: send, then redirect back to the Kudos tab.
	 *
	 * @return void
	 */
	public function handle_give_kudos(): void {
		check_admin_referer( self::NONCE );

		$receiver = isset( $_POST['receiver_id'] ) ? absint( wp_unslash( $_POST['receiver_id'] ) ) : 0;
		$message  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$giver    = get_current_user_id();

		$tab_url = $receiver > 0
			? trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $receiver ) ) . self::TAB_SLUG . '/'
			: home_url( '/' );

		$result = $this->give( $giver, $receiver, $message );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'kudos_err', rawurlencode( $result->get_error_code() ), $tab_url ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'kudos', 'sent', $tab_url ) );
		exit;
	}

	/**
	 * Register the REST give route (app coverage).
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/kudos',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_give_kudos' ),
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'args'                => array(
					'receiver_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'message'     => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * REST: give kudos to a member.
	 *
	 * @param \WP_REST_Request $request Request (receiver_id, message).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_give_kudos( \WP_REST_Request $request ) {
		$result = $this->give(
			get_current_user_id(),
			absint( $request['receiver_id'] ),
			(string) ( $request['message'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$status = 'invalid_receiver' === $result->get_error_code() ? 400 : 429;
			$result->add_data( array( 'status' => $status ) );
			return $result;
		}
		return new \WP_REST_Response( array( 'sent' => true ), 201 );
	}

	/**
	 * Shared send guard used by both the web and REST paths.
	 *
	 * @param int    $giver    Giver user ID.
	 * @param int    $receiver Receiver user ID.
	 * @param string $message  Optional message.
	 * @return true|\WP_Error
	 */
	private function give( int $giver, int $receiver, string $message ) {
		if ( $giver <= 0 || $receiver <= 0 || $giver === $receiver || ! get_userdata( $receiver ) ) {
			return new \WP_Error( 'invalid_receiver', __( 'You cannot send kudos to that member.', 'buddynext' ) );
		}

		$result = \WBGam\Engine\KudosEngine::send( $giver, $receiver, $message );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new \WP_Error( 'kudos_failed', __( 'Could not send kudos right now — you may have hit the limit.', 'buddynext' ) );
		}
		return true;
	}

	/**
	 * Human message for a give-kudos error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	private function error_message( string $code ): string {
		switch ( $code ) {
			case 'invalid_receiver':
			case 'wb_gam_kudos_self':
			case 'wb_gam_kudos_invalid_user':
				return __( 'You cannot send kudos to that member.', 'buddynext' );
			case 'wb_gam_kudos_cooldown':
				return __( 'You have already sent kudos to this member recently — try again later.', 'buddynext' );
			default:
				return __( 'Could not send kudos right now. Please try again.', 'buddynext' );
		}
	}
}
