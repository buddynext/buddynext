<?php
/**
 * BuddyNext Recent Activity sidebar widget.
 *
 * @package BuddyNext\Widgets
 */

declare( strict_types=1 );

namespace BuddyNext\Widgets;

/**
 * Widget: Recent community activity feed.
 */
class RecentActivityWidget extends \WP_Widget {

	/**
	 * Constructor — register the widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'buddynext_recent_activity',
			__( 'BuddyNext: Recent Activity', 'buddynext' ),
			array(
				'description' => __( 'Displays recent site-wide community activity.', 'buddynext' ),
				'classname'   => 'buddynext-widget buddynext-recent-activity',
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
	public function widget( $args, $instance ): void {
		global $wpdb;

		$title = apply_filters( 'widget_title', $instance['title'] ?? __( 'Recent Activity', 'buddynext' ) );
		$limit = absint( $instance['limit'] ?? 5 );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$table = $wpdb->prefix . 'bn_posts';
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, user_id, content, created_at FROM {$table} WHERE status = 'published' ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		);

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No recent activity yet.', 'buddynext' ) . '</p>';
		} else {
			echo '<ul class="buddynext-activity-list">';
			foreach ( (array) $rows as $row ) {
				$excerpt = wp_trim_words( (string) $row->content, 10 );
				$author  = get_userdata( (int) $row->user_id );
				$name    = $author ? $author->display_name : __( 'Unknown', 'buddynext' );
				printf(
					'<li><strong>%s</strong>: %s</li>',
					esc_html( $name ),
					esc_html( $excerpt )
				);
			}
			echo '</ul>';
		}

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

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
		$title = esc_attr( $instance['title'] ?? __( 'Recent Activity', 'buddynext' ) );
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
			esc_html__( 'Number of items to show:', 'buddynext' ),
			esc_attr( $this->get_field_name( 'limit' ) ),
			absint( $limit )
		);
		return '';
	}
}
