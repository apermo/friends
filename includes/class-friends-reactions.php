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
		add_action( 'wp_ajax_friends-toggle-react', array( $this, 'toggle_react' ) );
		add_action( 'wp_footer', array( $this, 'reactions_picker' ), 20 );
	}

	/**
	 * Register the taxonomies necessary
	 */
	public function register_taxonomies() {
		$this->register_user_taxonomy( get_current_user_id() );
	}

	/**
	 * Register the taxonomy for a certain user
	 *
	 * @param  int $user_id The user id.
	 */
	public function register_user_taxonomy( $user_id ) {
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
		register_taxonomy( 'friend-reaction-' . $user_id, Friends::get_frontend_post_types(), $args );
	}

	/**
	 * Get my reactions for a post.
	 *
	 * @param  int $post_id The post ID.
	 * @return array The users' reactions.
	 */
	public function get_my_reactions( $post_id ) {
		$my_reactions = wp_get_object_terms( $post_id, 'friend-reaction-' . get_current_user_id() );
		if ( is_wp_error( $my_reactions ) ) {
			return array();
		}
		$reactions = array();
		foreach ( $my_reactions as $term ) {
			$reactions[] = $term->slug;
		}

		return $reactions;
	}

	/**
	 * Get the reactions for a post.
	 *
	 * @param  int|WP_Post $post The post.
	 * @param  int|false   $exclude_user_id Whether to exclude a certain user_id.
	 * @return array The users' reactions.
	 */
	public static function get_post_reactions( $post = null, $exclude_user_id = false ) {
		$post = get_post( $post );

		if ( ! is_object( $post ) ) {
			return false;
		}

		$reactions  = array();
		$term_query = new WP_Term_Query(
			array(
				'object_ids' => $post->ID,
			)
		);

		if ( false !== $exclude_user_id ) {
			$excluded_user = new WP_User( $exclude_user_id );
		} else {
			$excluded_user = wp_get_current_user();
		}

		foreach ( $term_query->get_terms() as $term ) {
			if ( substr( $term->taxonomy, 0, 16 ) !== 'friend-reaction-' ) {
				continue;
			}
			if ( ! isset( $reactions[ $term->slug ] ) ) {
				$reactions[ $term->slug ] = array();
			}

			$user_id = intval( substr( $term->taxonomy, 16 ) );
			if ( $exclude_user_id === $user_id || ( false === $exclude_user_id && get_current_user_id() === $user_id ) ) {
				$user_reactions[ $term->slug ] = true;
				continue;
			}

			$user = new WP_User( $user_id );
			if ( ! $user || is_wp_error( $user ) ) {
				continue;
			}

			if ( ! isset( $reactions[ $term->slug ] ) ) {
				$reactions[ $term->slug ] = array();
			}
			$reactions[ $term->slug ][ $user_id ] = $user->display_name;
		}

		$remote_reactions = maybe_unserialize( get_post_meta( $post, 'remote_reactions', true ) );
		foreach ( $reactions as $emoji => $reacting_usernames ) {
			$user_reacted = isset( $user_reactions[ $emoji ] );

			$count = count( $reacting_usernames );
			if ( false === $exclude_user_id && $user_reacted ) {
				$count += 1;
			}

			$usernames = array_values( $reacting_usernames );
			if ( false === $exclude_user_id && $user_reacted ) {
				$usernames[] = $excluded_user->display_name;
			}

			if ( is_array( $remote_reactions ) && isset( $remote_reactions[ $emoji ] ) ) {
				$count      += $remote_reactions[ $emoji ]->count;
				$usernames[] = $remote_reactions[ $emoji ]->usernames;
				unset( $remote_reactions[ $emoji ] );
			}

			$usernames = array_filter( $usernames );
			if ( empty( $usernames ) ) {
				unset( $reactions[ $emoji ] );
				continue;
			}

			$reactions[ $emoji ] = (object) array(
				'count'        => intval( $count ),
				'emoji'        => self::validate_emoji( $emoji ),
				'usernames'    => implode( ', ', $usernames ),
				'user_reacted' => isset( $user_reactions[ $emoji ] ),
			);
		}
		if ( is_array( $remote_reactions ) ) {
			foreach ( $remote_reactions as $emoji => $reaction ) {
				$reaction->user_reacted = false;
				$reaction->emoji  = self::validate_emoji( $emoji );
				$reactions[ $emoji ]     = $reaction;
			}
		}

		ksort( $reactions );

		return $reactions;
	}

	/**
	 * Output the reactions picker.
	 */
	public function reactions_picker() {
		if ( is_user_logged_in() ) {
			Friends::template_loader()->get_template_part( 'frontend/reactions-picker' );
		}
	}

	/**
	 * Store a reaction.
	 */
	public function toggle_react() {
		check_ajax_referer( 'friends-reaction' );

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'You are not authorized to send a reaction.' );
		}

		if ( ! isset( $_POST['post_id'] ) || ! isset( $_POST['reaction'] ) ) {
			wp_send_json_error(
				array(
					'result' => false,
				)
			);
		}
		if ( ! is_numeric( $_POST['post_id'] ) || $_POST['post_id'] <= 0 ) {
			wp_send_json_error(
				array(
					'result' => false,
				)
			);
		}

		$post_id = intval( $_POST['post_id'] );
		$available_emojis = self::get_available_emojis();

		if ( ! self::validate_emoji( $_POST['reaction'] ) ) {
			// This emoji is not defined in emoji.json.
			return new WP_Error( 'invalid-emoji', 'This emoji is unknown.' );
		}

		$taxonomy = 'friend-reaction-' . get_current_user_id();
		$term = false;
		foreach ( wp_get_object_terms( $post_id, $taxonomy ) as $t ) {
			if ( $t->slug === $_POST['reaction'] ) {
				$term = $t;
				break;
			}
		}

		if ( ! $term ) {
			wp_set_object_terms( $post_id, $_POST['reaction'], $taxonomy, true );
		} else {
			wp_remove_object_terms( $post_id, $term->term_id, $taxonomy );
		}

		do_action( 'friends_user_post_reaction', $post_id );

		wp_send_json_success(
			array(
				'result' => true,
			)
		);
	}

	/**
	 * Fetches the Emojis from the JSON file.
	 *
	 * @return array The emojis.
	 */
	public static function get_all_emojis() {
		static $emojis;
		if ( ! $emojis ) {
			$emojis = json_decode( file_get_contents( __DIR__ . '/../emojis.json' ) );
		}
		return $emojis;
	}

	/**
	 * Get the emoji data to be stored.
	 *
	 * @param  string $slug The emoji shortname to look up.
	 * @return string|false The data or false if it doesn't exist.
	 */
	public static function get_emoji_data( $slug ) {
		$emojis = self::get_all_emojis();

		$slug = strtolower( $slug );
		if ( ! isset( $emojis->$slug ) ) {
			return false;
		}

		return $emojis->$slug;
	}

	/**
	 * Get the emojis selected to be available.
	 *
	 * @return array The emojis.
	 */
	public static function get_available_emojis() {
		static $available_emojis;
		if ( ! $available_emojis ) {
			$available_emojis = get_option( 'friends_selected_emojis' );
			if ( ! is_array( $available_emojis ) ) {
				$available_emojis = array(
					'1f44d' => (object) array(
						'char' => '👍',
						'name' => 'THUMBS UP SIGN',
					),
				);
			}
		}
		return $available_emojis;
	}

	/**
	 * Get the UTF8 for an emoji.
	 *
	 * @param  string $slug The emoji shortname to look up.
	 * @return string|false The emoji or false if it doesn't exist.
	 */
	public static function validate_emoji( $slug ) {
		$emojis = self::get_available_emojis();
		$slug = strtolower( $slug );
		if ( ! isset( $emojis[ $slug ] ) ) {
			return false;
		}

		return $emojis[ $slug ]->char;
	}

	/**
	 * Store remote reactions in post_meta.
	 *
	 * @param  int   $post_id   The post id.
	 * @param  array $feed_data The feed data as delivered by SimplePie.
	 * @return array The parsed reactions.
	 */
	public function update_remote_feed_reactions( $post_id, array $feed_data ) {
		$reactions = array();

		foreach ( $feed_data as $feed_reaction ) {
			$attribs = $feed_reaction['attribs'][ Friends_Feed::XMLNS ];
			$slug    = $attribs['slug'];
			if ( ! preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
				continue;
			}

			$reactions[ $slug ] = (object) array(
				'count'        => $attribs['count'],
				'usernames'    => $feed_reaction['data'],
				'user_reacted' => isset( $attribs['you-reacted'] ) && $attribs['you-reacted'],
			);
		}

		return $this->update_remote_reactions( $post_id, $reactions );
	}

	/**
	 * Store remote reactions in post_meta and update the main user taxonomy.
	 *
	 * @param  int   $post_id   The post id.
	 * @param  array $reactions The reactions data to be updated.
	 * @return array The parsed reactions.
	 */
	public function update_remote_reactions( $post_id, array $reactions ) {
		$main_user_id = $this->friends->get_main_friend_user_id();
		$this->register_user_taxonomy( $main_user_id );
		$main_user_reactions = wp_get_object_terms( $post_id, 'friend-reaction-' . $main_user_id );
		if ( is_wp_error( $main_user_reactions ) ) {
			$main_user_reactions = array();
		}
		$changed = false;

		foreach ( $reactions as $slug => $reaction ) {
			if ( is_array( $reaction ) ) {
				$reaction = (object) $reaction;
			}

			if (
				! preg_match( '/^[a-z0-9_-]+$/', $slug )
				|| ! isset( $reaction->count )
				|| $reaction->count < 0
				|| ! isset( $reaction->usernames )
			) {
				unset( $reactions[ $slug ] );
				continue;
			}

			$term = false;
			foreach ( $main_user_reactions as $k => $t ) {
				if ( $t->slug === $slug ) {
					$term = $t;
					unset( $main_user_reactions[ $k ] );
					break;
				}
			}

			if ( $reaction->user_reacted && ! $term ) {
				// Someone reacted on the remote site which hasn't been recorded here yet.
				wp_set_object_terms( $post_id, $slug, 'friend-reaction-' . $main_user_id, true );
				$changed = true;
			} elseif ( ! $reaction->user_reacted && $term ) {
				// Someone removed our reaction on the remote site so we need to delete it here.
				wp_remove_object_terms( $post_id, $term->term_id, 'friend-reaction-' . $main_user_id );
				$changed = true;
			}

			unset( $reaction->user_reacted );
			$reactions[ $slug ] = $reaction;

			if ( ! $reaction->count ) {
				unset( $reactions[ $slug ] );
			}
		}

		// Remove all remaining reactions as they have not been reported by remote.
		foreach ( $main_user_reactions as $term ) {
			wp_remove_object_terms( $post_id, $term->term_id, 'friend-reaction-' . $main_user_id );
		}

		update_post_meta( $post_id, 'remote_reactions', $reactions );
		return $reactions;
	}

	/**
	 * Store reactions of a friend.
	 *
	 * @param  int   $post_id   The post id.
	 * @param  int   $friend_user_id The friend who reacted.
	 * @param  array $reactions The reactions data to be updated.
	 * @return array The parsed reactions.
	 */
	public function update_friend_reactions( $post_id, $friend_user_id, array $reactions ) {
		$this->register_user_taxonomy( $friend_user_id );
		$friend_user_reactions = wp_get_object_terms( $post_id, 'friend-reaction-' . $friend_user_id );

		if ( is_wp_error( $friend_user_reactions ) ) {
			return false;
		}
		foreach ( $reactions as $slug ) {
			if ( ! preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
				continue;
			}

			$term = false;
			foreach ( $friend_user_reactions as $k => $t ) {
				if ( $t->slug === $slug ) {
					$term = $t;
					unset( $friend_user_reactions[ $k ] );
					break;
				}
			}

			if ( ! $term ) {
				wp_set_object_terms( $post_id, $slug, 'friend-reaction-' . $friend_user_id, true );
			}
		}

		// Remove all remaining reactions as they have not been reported by remote.
		foreach ( $friend_user_reactions as $term ) {
			wp_remove_object_terms( $post_id, $term->term_id, 'friend-reaction-' . $friend_user_id );
		}

		return $reactions;
	}
}