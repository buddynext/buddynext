<?php
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

		$users = get_users(
			array(
				'number'  => $limit,
				'orderby' => 'registered',
				'order'   => 'DESC',
			)
		);

		echo '<ul class="buddynext-online-members-list">';
		foreach ( $users as $user ) {
			printf(
				'<li><a href="%s">%s</a></li>',
				esc_url( get_author_posts_url( $user->ID ) ),
				esc_html( $user->display_name )
			);
		}
		echo '</ul>';

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
		$title = esc_attr( $instance['title'] ?? __( 'Online Members', 'buddynext' ) );
		$limit = absint( $instance['limit'] ?? 5 );
		printf( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'<p><label for="%1$s">%2$s</label>
			<input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s"></p>
			<p><label for="%5$s">%6$s</label>
			<input class="tiny-text" id="%5$s" name="%7$s" type="number" min="1" max="20" value="%8$d"></p>',
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_html__( 'Title:', 'buddynext' ),
			esc_attr( $this->get_field_name( 'title' ) ),
			$title,
			esc_attr( $this->get_field_id( 'limit' ) ),
			esc_html__( 'Number of members to show:', 'buddynext' ),
			esc_attr( $this->get_field_name( 'limit' ) ),
			$limit
		);
		return '';
	}
}
