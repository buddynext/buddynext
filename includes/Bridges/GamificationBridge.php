<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WBGamification bridge.
 *
 * Translates BuddyNext social actions into wb-gamification events so the
 * gamification rules engine can award points, badges, and levels.
 *
 * BuddyNext fires buddynext_* actions → bridge calls wb_gam_submit_event()
 * with a registered bn_* action slug. wb-gamification admin configures the
 * point value per action. Nothing is hard-coded — BuddyNext ships zero
 * gamification logic, it only registers the action catalogue and emits events.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

/**
 * wb-gamification ↔ BuddyNext event bridge.
 */
class GamificationBridge {

	/**
	 * BuddyNext action catalogue.
	 *
	 * Each entry is a bn_* action that BuddyNext submits manually via
	 * wb_gam_submit_event(). They are registered with wb-gamification so the
	 * engine recognises the slug and admins can configure points per action.
	 *
	 * wb_gam_register_action() requires a 'hook' + callable 'user_callback'
	 * (it auto-hooks the named source action). BuddyNext does NOT want the
	 * engine to auto-award on a WordPress hook — the per-action handlers below
	 * resolve the correct recipient(s) (the followed user, BOTH connected
	 * users, etc.) and submit manually. We therefore register against an inert
	 * BuddyNext-only hook that is never fired, so the engine never auto-awards
	 * and each event fires exactly once (from fire()).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const ACTION_CATALOGUE = array(
		array(
			'id'             => 'bn_followed',
			'label'          => 'Followed by a member',
			'default_points' => 5,
		),
		array(
			'id'             => 'bn_connected',
			'label'          => 'Connection accepted',
			'default_points' => 10,
		),
		array(
			'id'             => 'bn_post_created',
			'label'          => 'Post created',
			'default_points' => 5,
		),
		array(
			'id'             => 'bn_space_joined',
			'label'          => 'Joined a space',
			'default_points' => 5,
		),
		array(
			'id'             => 'bn_strike_issued',
			'label'          => 'Moderation strike issued',
			'default_points' => 0,
		),
		array(
			'id'             => 'bn_profile_updated',
			'label'          => 'Profile updated',
			'default_points' => 2,
		),
		array(
			'id'             => 'bn_profile_completed',
			'label'          => 'Profile completed',
			'default_points' => 25,
		),
	);

	/**
	 * Inert hook the catalogue actions auto-bind to.
	 *
	 * wb-gamification's Registry::register_action() mandates a real hook +
	 * user_callback and auto-hooks it. BuddyNext submits manually instead, so
	 * the actions bind to this hook which BuddyNext never fires. This keeps
	 * registration valid (admins get configurable point rows) while ensuring
	 * the engine never double-awards from an auto-hook.
	 */
	private const NOOP_HOOK = 'buddynext_gamification_noop';

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		if ( ! function_exists( 'wb_gam_submit_event' ) ) {
			return;
		}

		$this->register_actions();

		add_action( 'buddynext_user_followed', array( $this, 'on_user_followed' ), 10, 2 );
		add_action( 'buddynext_connection_accepted', array( $this, 'on_connection_accepted' ), 10, 3 );
		add_action( 'buddynext_post_created', array( $this, 'on_post_created' ), 10, 3 );
		add_action( 'buddynext_space_member_joined', array( $this, 'on_space_joined' ), 10, 3 );
		add_action( 'buddynext_strike_issued', array( $this, 'on_strike_issued' ), 10, 3 );
		add_action( 'buddynext_profile_completion_changed', array( $this, 'on_profile_completion_changed' ), 10, 2 );
	}

	/**
	 * Register the BuddyNext action catalogue with wb-gamification.
	 *
	 * Guards each registration so re-running (e.g. a second bridge load) never
	 * trips the engine's "already registered" notice. Registers against the
	 * inert NOOP_HOOK so the engine recognises the slug — exposing it to admins
	 * for point configuration — without auto-awarding; BuddyNext emits each
	 * event manually via fire() so awards happen exactly once.
	 */
	private function register_actions(): void {
		if ( ! function_exists( 'wb_gam_register_action' ) || ! function_exists( 'wb_gam_get_actions' ) ) {
			return;
		}

		$registered = wb_gam_get_actions();

		foreach ( self::ACTION_CATALOGUE as $action ) {
			if ( isset( $registered[ $action['id'] ] ) ) {
				continue;
			}

			wb_gam_register_action(
				array(
					'id'             => $action['id'],
					'label'          => $action['label'],
					'description'    => '',
					'category'       => 'buddynext',
					'default_points' => $action['default_points'],
					'repeatable'     => true,
					// Inert binding — BuddyNext submits manually (see NOOP_HOOK).
					'hook'           => self::NOOP_HOOK,
					'user_callback'  => '__return_zero',
				)
			);
		}
	}

	/**
	 * Translate buddynext_user_followed → bn_followed.
	 *
	 * @param int $follower_id  User who followed.
	 * @param int $following_id User who was followed (receives the points).
	 */
	public function on_user_followed( int $follower_id, int $following_id ): void {
		$this->fire( 'bn_followed', $following_id, array( 'follower_id' => $follower_id ) );
	}

	/**
	 * Translate buddynext_connection_accepted → bn_connected.
	 *
	 * @param int $connection_id Connection row ID (unused).
	 * @param int $user_a        Initiating user.
	 * @param int $user_b        Accepting user.
	 */
	public function on_connection_accepted( int $connection_id, int $user_a, int $user_b ): void {
		$this->fire( 'bn_connected', $user_a, array( 'peer_id' => $user_b ) );
		$this->fire( 'bn_connected', $user_b, array( 'peer_id' => $user_a ) );
	}

	/**
	 * Translate buddynext_post_created → bn_post_created.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id Author.
	 * @param string $type    Post type.
	 */
	public function on_post_created( int $post_id, int $user_id, string $type ): void {
		$this->fire(
			'bn_post_created',
			$user_id,
			array(
				'post_id' => $post_id,
				'type'    => $type,
			)
		);
	}

	/**
	 * Translate buddynext_space_member_joined → bn_space_joined.
	 *
	 * @param int    $space_id Space joined.
	 * @param int    $user_id  Joining user.
	 * @param string $_role    Member role assigned (unused — required by hook contract).
	 */
	public function on_space_joined( int $space_id, int $user_id, string $_role ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_role required by hook contract.
		$this->fire( 'bn_space_joined', $user_id, array( 'space_id' => $space_id ) );
	}

	/**
	 * Translate buddynext_strike_issued → bn_strike_issued (for point deduction).
	 *
	 * @param int $strike_id Strike record ID.
	 * @param int $user_id   Struck user.
	 * @param int $actor_id  Admin who issued the strike.
	 */
	public function on_strike_issued( int $strike_id, int $user_id, int $actor_id ): void {
		$this->fire(
			'bn_strike_issued',
			$user_id,
			array(
				'strike_id' => $strike_id,
				'actor_id'  => $actor_id,
			)
		);
	}

	/**
	 * Translate buddynext_profile_completion_changed → bn_profile_completed (100%) or bn_profile_updated.
	 *
	 * Fires bn_profile_completed only on first 100% milestone so WBGam can award
	 * a one-time badge. Also fires bn_profile_updated for any completion change so
	 * admins can configure incremental point rewards.
	 *
	 * @param int $user_id User whose profile completion changed.
	 * @param int $percent New completion percentage (0–100).
	 */
	public function on_profile_completion_changed( int $user_id, int $percent ): void {
		$this->fire( 'bn_profile_updated', $user_id, array( 'percent' => $percent ) );

		if ( 100 === $percent ) {
			$this->fire( 'bn_profile_completed', $user_id, array( 'percent' => 100 ) );
		}
	}

	/**
	 * Submit a BuddyNext gamification event into wb-gamification.
	 *
	 * Routes through the plugin's public submit API so the full pipeline runs
	 * (points, badges, streaks, webhooks). The action slug must be one of the
	 * registered catalogue entries; unknown slugs are ignored by the engine.
	 *
	 * @param string $action_id Registered bn_* action slug (e.g. 'bn_followed').
	 * @param int    $user_id   User the event applies to (receives the award).
	 * @param array  $context   Event metadata (source IDs, type, etc.).
	 */
	private function fire( string $action_id, int $user_id, array $context = array() ): void {
		if ( $user_id <= 0 || ! function_exists( 'wb_gam_submit_event' ) ) {
			return;
		}

		wb_gam_submit_event( $user_id, $action_id, $context );
	}
}
