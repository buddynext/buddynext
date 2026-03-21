<?php
/**
 * BuddyNext Trending Hashtags sidebar widget.
 *
 * @package BuddyNext\Widgets
 */

declare( strict_types=1 );

namespace BuddyNext\Widgets;

/**
 * Widget: Trending community hashtags.
 */
class TrendingHashtagsWidget extends \WP_Widget {

	/**
	 * Constructor — register the widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'buddynext_trending_hashtags',
			__( 'BuddyNext: Trending Hashtags', 'buddynext' ),
			array(
				'description' => __( 'Displays trending community hashtags.', 'buddynext' ),
				'classname'   => 'buddynext-widget buddynext-trending-hashtags',
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

		$title = apply_filters( 'widget_title', $instance['title'] ?? __( 'Trending Hashtags', 'buddynext' ) );
		$limit = absint( $instance['limit'] ?? 10 );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$table = $wpdb->prefix . 'bn_hashtags';
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT name, post_count FROM {$table} ORDER BY post_count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		);

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No trending hashtags yet.', 'buddynext' ) . '</p>';
		} else {
			echo '<div class="buddynext-hashtag-cloud">';
			foreach ( (array) $rows as $row ) {
				printf(
					'<a class="buddynext-hashtag" href="%s">#%s <span>(%d)</span></a> ',
					esc_url( home_url( '/hashtag/' . rawurlencode( (string) $row->name ) ) ),
					esc_html( (string) $row->name ),
					(int) $row->post_count
				);
			}
			echo '</div>';
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
			'limit' => absint( $new_instance['limit'] ?? 10 ),
		);
	}

	/**
	 * Render the widget settings form.
	 *
	 * @param array<string, mixed> $instance Current settings.
	 * @return string Return value ignored by WP_Widget.
	 */
	public function form( $instance ): string {
		$title = esc_attr( $instance['title'] ?? __( 'Trending Hashtags', 'buddynext' ) );
		$limit = absint( $instance['limit'] ?? 10 );
		printf(
			'<p><label for="%1$s">%2$s</label>
			<input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s"></p>
			<p><label for="%5$s">%6$s</label>
			<input class="tiny-text" id="%5$s" name="%7$s" type="number" min="1" max="30" value="%8$d"></p>',
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_html__( 'Title:', 'buddynext' ),
			esc_attr( $this->get_field_name( 'title' ) ),
			esc_attr( $title ),
			esc_attr( $this->get_field_id( 'limit' ) ),
			esc_html__( 'Number of hashtags to show:', 'buddynext' ),
			esc_attr( $this->get_field_name( 'limit' ) ),
			absint( $limit )
		);
		return '';
	}
}
