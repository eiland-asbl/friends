<?php
/**
 * Friend List Widget
 *
 * A widget that displays a list of your friends.
 *
 * @package Friends
 * @since 0.3
 */

namespace Friends;

/**
 * This is the class for the Friend List Widget.
 *
 * @package Friends
 * @author Alex Kirk
 */
class Widget_Favorite_Friend_List extends Widget_Base_Friend_List {
	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			'friends-widget-favorite-friend-list',
			__( 'Favorite Friend List', 'friends' ),
			array(
				'description' => __( 'Shows a list of your favorite friends and subscriptions.', 'friends' ),
			)
		);
	}

	/**
	 * Render the widget.
	 *
	 * @param array $args Sidebar arguments.
	 * @param array $instance Widget instance settings.
	 */
	public function widget( $args, $instance ) {
		$friends = User_Query::favorite_friends_subscriptions();
		if ( ! $friends->get_total() ) {
			return;
		}
		$instance = wp_parse_args( $instance, $this->defaults() );

		echo $args['before_widget'];

		$this->list_friends(
			$args,
			_x( 'Favorite', 'Favorite Friends', 'friends' ),
			$friends
		);

		do_action( 'friends_widget_favorite_friend_list_after', $this, $args );

		echo $args['after_widget'];
	}
}

