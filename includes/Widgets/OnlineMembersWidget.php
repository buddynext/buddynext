<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext Online Members sidebar widget.
 *
 * @package BuddyNext\Widgets
 */

declare( strict_types=1 );

namespace BuddyNext\Widgets;

/**
 * Widget: Recently active community members.
 */
class OnlineMembersWidget extends \WP_Widget {

	/**
	 * Constructor — register the widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'buddynext_online_members',
			__( 'BuddyNext: Online Members', 'buddynext' ),
			array(
				'description' => __( 'Displays recently active community members.', 'buddynext' ),
				'classname'   => 'buddynext-widget buddynext-online-members',
			)
		);
	}

	/**
	 * Render the widget front-end.
	 *
	 * @param array<string, mixed> $args     Display arguments.
	 * @param array<string, mixed> $instance Saved widget settings.
	 * @return void
	 */
	public function widget( $args, $instance ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$title = apply_filters( 'widget_title', $instance['title'] ?? __( 'Online Members', 'buddynext' ) );
		$limit = absint( $instance['limit'] ?? 5 );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Actually-online members (active within the presence window), newest
		// first — read from the indexed bn_presence table, not "recently
		// registered". The bounded id list is cached briefly so a busy sidebar
		// hits the table at most once per TTL regardless of traffic; the online
		// window is 300s, so 30s of staleness is invisible.
		$cache_key = 'online_widget_' . $limit;
		$ids       = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false === $ids ) {
			$ids = \BuddyNext\Realtime\PresenceService::recent_online_ids( $limit );
			wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::CACHE_TTL );
		}

		$users = empty( $ids )
			? array()
			: get_users(
				array(
					'include' => $ids,
					'orderby' => 'include', // Preserve the most-recently-active order.
				)
			);

		if ( empty( $users ) ) {
			printf( '<p class="buddynext-online-members-empty">%s</p>', esc_html__( 'No members are online right now.', 'buddynext' ) );
			echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo '<ul class="buddynext-online-members-list">';
		foreach ( $users as $user ) {
			printf(
				'<li><a href="%s">%s</a></li>',
				esc_url( \BuddyNext\Core\PageRouter::profile_url( $user->ID ) ),
				esc_html( $user->display_name )
			);
		}
		echo '</ul>';

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Object-cache group for the bounded online-id list.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'buddynext_presence';

	/**
	 * TTL for the cached online-id list, in seconds. Well under the 300s presence
	 * window so the list is never meaningfully stale.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 30;

	/**
	 * Sanitise and save widget settings.
	 *
	 * @param array<string, mixed> $new_instance New settings.
	 * @param array<string, mixed> $old_instance Old settings.
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return array(
			'title' => sanitize_text_field( $new_instance['title'] ?? '' ),
			'limit' => absint( $new_instance['limit'] ?? 5 ),
		);
	}

	/**
	 * Render the widget settings form.
	 *
	 * @param array<string, mixed> $instance Current settings.
	 * @return string Return value ignored by WP_Widget.
	 */
	public function form( $instance ): string {
		$title = sanitize_text_field( $instance['title'] ?? __( 'Online Members', 'buddynext' ) );
		$limit = absint( $instance['limit'] ?? 5 );
		printf(
			'<p><label for="%1$s">%2$s</label>
			<input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s"></p>
			<p><label for="%5$s">%6$s</label>
			<input class="tiny-text" id="%5$s" name="%7$s" type="number" min="1" max="20" value="%8$d"></p>',
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_html__( 'Title:', 'buddynext' ),
			esc_attr( $this->get_field_name( 'title' ) ),
			esc_attr( $title ),
			esc_attr( $this->get_field_id( 'limit' ) ),
			esc_html__( 'Number of members to show:', 'buddynext' ),
			esc_attr( $this->get_field_name( 'limit' ) ),
			absint( $limit )
		);
		return '';
	}
}
