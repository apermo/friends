<?php
/**
 * Friends Reactions
 *
 * This contains the functions for Reactions.
 *
 * @package Friends
 */

/**
 * This is the class for the Reactions part of the Friends Plugin.
 *
 * @since 0.8
 *
 * @package Friends
 * @author Alex Kirk
 */
class Friends_Reactions {
	/**
	 * Contains a reference to the Friends class.
	 *
	 * @var Friends
	 */
	private $friends;

	/**
	 * Constructor
	 *
	 * @param Friends $friends A reference to the Friends object.
	 */
	public function __construct( Friends $friends ) {
		$this->friends = $friends;
		$this->register_hooks();
	}

	/**
	 * Register the WordPress hooks
	 */
	private function register_hooks() {
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_action( 'wp_ajax_friends_toggle_react', array( $this, 'toggle_react' ) );

	}

	/**
	 * Register the taxonomies necessary
	 */
	public function register_taxonomies() {
		$args = array(
			'labels'            => array(
				'name'          => _x( 'Reactions', 'taxonomy general name' ),
				'singular_name' => _x( 'Reaction', 'taxonomy singular name' ),
				'menu_name'     => __( 'Reaction' ),
			),
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
		);
		register_taxonomy( 'friend-reaction-' . get_current_user_id(), array( 'post', Friends::FRIEND_POST_CACHE ), $args );
	}

	/**
	 * Display Post reactions under a post.
	 *
	 * @param  string  $text The post content.
	 * @param  boolean $echo Whether the content should be echoed.
	 * @return string        The post content with buttons or nothing if echoed.
	 */
	public function post_reactions( $text = '', $echo = false ) {
		$reactions  = array();
		$term_query = new WP_Term_Query(
			array(
				'object_ids' => get_the_ID(),
			)
		);
		foreach ( $term_query->get_terms() as $term ) {
			if ( substr( $term->taxonomy, 0, 16 ) !== 'friend-reaction-' ) {
				continue;
			}
			$user_id = substr( $term->taxonomy, 16 );
			if ( ! isset( $reactions[ $term->slug ] ) ) {
				$reactions[ $term->slug ] = array();
			}
			$reactions[ $term->slug ][ $user_id ] = 1;
		}

		ob_start();
		include apply_filters( 'friends_template_path', 'friends/reactions.php' );
		$reactions_text = ob_get_contents();
		ob_end_clean();

		$text .= $reactions_text;

		if ( ! $echo ) {
			return $text;
		}

		echo $text;
	}

	/**
	 * Store a reaction.
	 */
	public function toggle_react() {
		if ( ! current_user_can( 'edit_posts' ) && current_user_can( 'friend' ) ) {
			return new WP_Error( 'unauthorized', 'You are not authorized to send a reaction.' );
		}

		if ( is_numeric( $_POST['post_id'] ) && is_string( $_POST['reaction'] ) ) {
			// TODO: Whitelist Emojis.
			$term = false;
			foreach ( wp_get_object_terms( $_POST['post_id'], 'friend-reaction-' . get_current_user_id() ) as $t ) {
				if ( $t->slug === $_POST['reaction'] ) {
					$term = $t;
					break;
				}
			}
			if ( ! $term ) {
				wp_set_object_terms( $_POST['post_id'], $_POST['reaction'], 'friend-reaction-' . get_current_user_id(), true );
			} else {
				wp_remove_object_terms( $_POST['post_id'], $term->term_id, 'friend-reaction-' . get_current_user_id() );
			}
			return true;
		}
	}
}
