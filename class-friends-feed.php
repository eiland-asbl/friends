<?php
/**
 * Friends Feed
 *
 * This contains the feed functions.
 *
 * @package Friends
 */

/**
 * This is the class for the feed part of the Friends Plugin.
 *
 * @since 0.6
 *
 * @package Friends
 * @author Alex Kirk
 */
class Friends_Feed {
	const XMLNS = 'wordpress-plugin-friends:feed-additions:1';

	/**
	 * Contains a reference to the Friends class.
	 */
	private $friends;

	/**
	 * Constructor
	 */
	public function __construct( Friends $friends ) {
		$this->friends = $friends;
		$this->register_hooks();
	}
	/**
	 * Register the WordPress hooks
	 */
	private function register_hooks() {
		add_filter( 'pre_get_posts',              array( $this, 'private_feed_query' ), 1 );
		add_filter( 'private_title_format',       array( $this, 'private_title_format' ) );
		add_filter( 'pre_option_rss_use_excerpt', array( $this, 'feed_use_excerpt' ), 90 );
		add_action( 'rss_item',                   array( $this, 'feed_additional_fields' ) );
		add_action( 'rss2_item',                  array( $this, 'feed_additional_fields' ) );
		add_action( 'rss_ns',                     array( $this, 'additional_feed_namespaces' ) );
		add_action( 'rss2_ns',                    array( $this, 'additional_feed_namespaces' ) );

		add_action( 'friends_refresh_feeds',      array( $this, 'cron_friends_refresh_feeds' ) );
		add_action( 'set_user_role',              array( $this, 'retrieve_new_friends_posts' ), 999, 3 );
	}

	/**
	 * Cron function to refresh the feeds of the friends' blogs
	 */
	public function cron_friends_refresh_feeds() {
		$this->retrieve_friend_posts();
	}

	/**
	 * Retrieve posts from a remote WordPress for a user or all friend users.
	 *
	 * @param  WP_User|null $single_user A single user or null to fetch all.
	 */
	public function retrieve_friend_posts( WP_User $single_user = null ) {
		if ( $single_user ) {
			$friends = array(
				$single_user,
			);
		} else {
			$friends = new WP_User_Query( array( 'role__in' => array( 'friend', 'pending_friend_request', 'subscription' ) ) );
			$friends = $friends->get_results();

			if ( empty( $friends ) ) {
				return;
			}
		}

		foreach ( $friends as $friend_user ) {
			$feed_url = get_user_option( 'friends_feed_url', $friend_user->ID );
			if ( ! $feed_url ) {
				$feed_url = rtrim( $friend_user->user_url, '/' ) . '/feed/';
			}

			$token = get_user_option( 'friends_out_token', $friend_user->ID );
			if ( $token ) {
				$feed_url .= '?friend=' . $token;
			}
			$feed_url = apply_filters( 'friends_friend_feed_url', $feed_url, $friend_user );

			$feed = fetch_feed( $feed_url );
			if ( is_wp_error( $feed ) ) {
				continue;
			}
			$feed = apply_filters( 'friends_feed_content', $feed, $friend_user );
			$this->process_friend_feed( $friend_user, $feed );
		}
	}

	/**
	 * Retrieve the remote post ids.
	 *
	 * @param  WP_User $friend_user The friend user.
	 * @return array A mapping of the remote post ids.
	 */
	private function get_remote_post_ids( WP_User $friend_user ) {
		$remote_post_ids = array();
		$existing_posts = new WP_Query( array(
			'post_type' => $this->friends::FRIEND_POST_CACHE,
			'post_status' => array( 'publish', 'private' ),
			'author' => $friend_user->ID,
		));

		if ( $existing_posts->have_posts() ) {
			while ( $existing_posts->have_posts() ) {
				$post = $existing_posts->next_post();
				$remote_post_id = get_post_meta( $post->ID, 'remote_post_id', true );
				$remote_post_ids[ $remote_post_id ] = $post->ID;
				$remote_post_ids[ get_permalink( $post ) ] = $post->ID;
			}
		}

		do_action( 'friends_remote_post_ids', $remote_post_ids );
		return $remote_post_ids;
	}

	/**
	 * Process the feed of a friend user.
	 *
	 * @param  WP_User   $friend_user The friend user.
	 * @param  SimplePie $feed        The RSS feed object of the friend user.
	 */
	public function process_friend_feed( WP_User $friend_user, SimplePie $feed ) {
		$new_friend = get_user_option( 'friends_new_friend', $friend_user->ID );

		$remote_post_ids = $this->get_remote_post_ids( $friend_user );

		foreach ( $feed->get_items() as $item ) {
			if ( ! apply_filters( 'friends_use_feed_item', true, $item, $feed, $friend_user ) ) {
				continue;
			}
			$permalink = $item->get_permalink();
			$content = wp_kses_post( $item->get_content() );

			// Fallback, when no friends plugin is installed.
			$item->{'post-id'} = $permalink;
			$item->{'post-status'} = 'publish';
			if ( ! isset( $item->comment_count ) ) {
				$item->comment_count = 0;
			}

			if ( ! $content || ! $permalink ) {
				continue;
			}

			foreach ( array( 'gravatar', 'comments', 'post-status', 'post-id' ) as $key ) {
				if ( ! isset( $item->{$key} ) ) {
					$item->{$key} = false;
				}

				foreach ( array( self::XMLNS, 'com-wordpress:feed-additions:1' ) as $xmlns ) {
					if ( isset( $item->data['child'][ $xmlns ][ $key ][0]['data'] ) ) {
						$item->{$key} = $item->data['child'][ $xmlns ][ $key ][0]['data'];
						continue 2;
					}
				}
			}

			$item->comments_count = isset( $item->data['child']['http://purl.org/rss/1.0/modules/slash/']['comments'][0]['data'] ) ? $item->data['child']['http://purl.org/rss/1.0/modules/slash/']['comments'][0]['data'] : 0;

			$post_id = null;
			if ( isset( $remote_post_ids[ $item->{'post-id'} ] ) ) {
				$post_id = $remote_post_ids[ $item->{'post-id'} ];
			}
			if ( is_null( $post_id ) && isset( $remote_post_ids[ $permalink ] ) ) {
				$post_id = $remote_post_ids[ $permalink ];
			}
			$post_data = array(
				'post_title'        => $item->get_title(),
				'post_content'      => $item->get_content(),
				'post_modified_gmt' => $item->get_updated_gmdate( 'Y-m-d H:i:s' ),
				'post_status'       => $item->{'post-status'},
				'guid'              => $permalink,
			);
			if ( ! is_null( $post_id ) ) {
				$post_data['ID'] = $post_id;
				wp_update_post( $post_data );
			} else {
				$post_data['post_author']   = $friend_user->ID;
				$post_data['post_type']     = $this->friends::FRIEND_POST_CACHE;
				$post_data['post_date_gmt'] = $item->get_gmdate( 'Y-m-d H:i:s' );
				$post_data['comment_count'] = $item->comment_count;
				$post_id = wp_insert_post( $post_data );
				if ( is_wp_error( $post_id ) ) {
					continue;
				}
			}
			$author = $item->get_author();
			update_post_meta( $post_id, 'author', $author->name );
			update_post_meta( $post_id, 'gravatar', $item->gravatar );

			update_post_meta( $post_id, 'remote_post_id', $item->{'post-id'} );
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'comment_count' => $item->comment_count ), array( 'ID' => $post_id ) );

			$new_post = ! isset( $post_data['ID'] );
			$notify_users = apply_filters( 'notify_about_new_friend_post', $new_post && ! $new_friend, $friend_user, $post_id );
			if ( $notify_users ) {
				do_action( 'notify_new_friend_post', WP_Post::get_instance( $post_id ) );
			}
		}

		if ( $new_friend ) {
			delete_user_option( $friend_user->ID, 'friends_new_friend' );
		}
	}

	/**
	 * Remove the Private: when sending a private feed.
	 *
	 * @param  string $title_format The title format for a private post title.
	 * @return string The modified title format for a private post title.
	 */
	public function private_title_format( $title_format ) {
		if ( $this->friends->access_control->feed_is_authenticated() ) {
			return '%s';
		}
		return $title_format;
	}

	/**
	 * Disable excerpted feeds for friend feeds
	 *
	 * @param  boolean $feed_use_excerpt Whether to only have excerpts in feeds.
	 * @return boolean The modified flag whether to have excerpts in feeds.
	 */
	public function feed_use_excerpt( $feed_use_excerpt ) {
		if ( $this->friends->access_control->feed_is_authenticated() ) {
			return 0;
		}

		return $feed_use_excerpt;
	}

	/**
	 * Output an additional XMLNS for the feed.
	 */
	public function additional_feed_namespaces() {
		if ( $this->friends->access_control->feed_is_authenticated() ) {
			echo 'xmlns:friends="' . esc_attr( self::XMLNS ) . '"';
		}
	}

	/**
	 * Additional fields for the friends feed.
	 */
	public function feed_additional_fields() {
		if ( $this->friends->access_control->feed_is_authenticated() ) {
			global $post;
			echo '<friends:gravatar>' . esc_html( get_avatar_url( $post->post_author ) ) . '</friends:gravatar>';
			echo '<friends:post-status>' . esc_html( $post->post_status ) . '</friends:post-status>';
			echo '<friends:post-id>' . esc_html( $post->ID ) . '</friends:post-id>';
		}
	}

	/**
	 * Modify the main query for the friends feed
	 *
	 * @param  WP_Query $query The main query.
	 * @return WP_Query The modified main query.
	 */
	public function private_feed_query( WP_Query $query ) {
		if ( ! $this->friends->access_control->feed_is_authenticated() ) {
			return $query;
		}

		if ( ! $query->is_admin && $query->is_feed ) {
			$query->set( 'post_status', array( 'publish', 'private' ) );
		}

		return $query;
	}

	/**
	 * Retrieve new friend's posts after changing roles
	 *
	 * @param  int    $user_id   The user id.
	 * @param  string $new_role  The new role.
	 * @param  string $old_roles The old roles.
	 */
	public function retrieve_new_friends_posts( $user_id, $new_role, $old_roles ) {
		if ( 'friend' === $new_role && apply_filters( 'friends_immediately_fetch_feed', true ) ) {
			update_user_option( $user_id, 'friends_new_friend', true );
			$this->retrieve_friend_posts( new WP_User( $user_id ) );
		}
	}
}