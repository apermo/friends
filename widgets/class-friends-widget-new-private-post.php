<?php
/**
 * Friend New Private Post Widget
 *
 * A widget that allows you to create a new private post.
 *
 * @package Friends
 * @since 0.8
 */

/**
 * This is the class for the Friend New Private Post Widget.
 *
 * @package Friends
 * @author Alex Kirk
 */
class Friends_Widget_New_Private_Post extends WP_Widget {
	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			'friends-widget-new-private-post',
			__( 'New Private Post', 'friends' ),
			array(
				'description' => __( 'Allows the creation of a new private post from within the page.', 'friends' ),
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
		$instance = wp_parse_args( $instance, $this->defaults() );

		$title = apply_filters( 'widget_title', $instance['title'] );
		echo $args['before_widget'];
		if ( ! empty( $title ) ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" class="friends-post-inline">
			<?php wp_nonce_field( 'friends_publish' ); ?>
			<input type="hidden" name="action" value="friends_publish" />
			<input type="text" name="title" value="" placeholder="<?php echo esc_attr( __( 'Title' ) ); ?>" /><br />
			<textarea name="content" rows="5" cols="70" placeholder="<?php echo /* translators: %s is a user display name. */ esc_attr( sprintf( __( 'What are you up to, %s?', 'friends' ), wp_get_current_user()->display_name ) ); ?>"></textarea><br />
			<button>Post to your friends</button>
			<input type="hidden" name="status" value="private" /></span>
		</form>
		<?php
		echo $args['after_widget'];
	}


	/**
	 * Update widget configuration.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 * @return array Sanitized instance settings.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = $this->defaults;

		return $instance;
	}

	/**
	 * Return an associative array of default values
	 *
	 * These values are used in new widgets.
	 *
	 * @return array Array of default values for the Widget's options
	 */
	public function defaults() {
		return array(
			'title' => __( 'Friends', 'friends' ),
		);
	}

	/**
	 * Register this widget.
	 */
	public static function register() {
		register_widget( __CLASS__ );
	}
}
