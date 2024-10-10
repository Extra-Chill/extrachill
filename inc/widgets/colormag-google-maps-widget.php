<?php
/**
 * Add the ColorMag Google Maps widget
 */

class colormag_google_maps_widget extends WP_Widget {

	/**
	 * Constructor to register Google Maps widget
	 */
	function __construct() {
		$widget_ops  = array(
			'classname'                   => 'widget_google_maps widget_colormag_google_maps',
			'description'                 => esc_html__( 'Display the Google Maps for your site.', 'colormag-pro' ),
			'customize_selective_refresh' => true,
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = esc_html__( 'TG: Google Maps', 'colormag-pro' ), $widget_ops );
	}

	/**
	 * Back-end widget form.
	 *
	 * @param array $instance Previously saved values from database.
	 */
	function form( $instance ) {
		$tg_defaults['title']     = '';
		$tg_defaults['text']      = '';
		$tg_defaults['longitude'] = '';
		$tg_defaults['latitude']  = '';
		$tg_defaults['height']    = 350;
		$tg_defaults['zoom_size'] = 15;

		$instance = wp_parse_args( ( array ) $instance, $tg_defaults );

		$title     = esc_attr( $instance['title'] );
		$text      = esc_textarea( $instance['text'] );
		$longitude = esc_attr( $instance['longitude'] );
		$latitude  = esc_attr( $instance['latitude'] );
		$height    = absint( $instance['height'] );
		$zoom_size = absint( $instance['zoom_size'] );
		?>

		<p>
			<?php
			$API_KEY          = get_theme_mod( 'colormag_googlemap_api_key' );

			if ( empty( $API_KEY ) ) :
				$query['autofocus[section]'] = 'colormag_googlemap_section';
				$section_link = add_query_arg( $query, admin_url( 'customize.php' ) );
				?>

				<span class="googlemaps-api-error">
					<?php
					$url  = esc_url( 'https://developers.google.com/maps/documentation/javascript/get-api-key' );
					$link = sprintf( __( 'GoogleMap requires <a href="%s" target="_blank">API Key</a> to work', 'colormag-pro' ), esc_url( $url ) );
					echo $link;
					?><br />
					<a href="<?php echo esc_url( $section_link ); ?>"><?php esc_html_e( 'Enter API Key here', 'colormag-pro' ); ?></a><br /><br />
				</span>

			<?php
			endif;
			?>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php esc_html_e( 'Title:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo $title; ?>" />
		</p>

		<?php esc_html_e( 'Description', 'colormag-pro' ); ?>
		<textarea class="widefat" rows="5" cols="20" id="<?php echo $this->get_field_id( 'text' ); ?>" name="<?php echo $this->get_field_name( 'text' ); ?>"><?php echo $text; ?></textarea>

		<p>
			<label for="<?php echo $this->get_field_id( 'longitude' ); ?>"><?php esc_html_e( 'Longitude:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'longitude' ); ?>" name="<?php echo $this->get_field_name( 'longitude' ); ?>" type="text" value="<?php echo $longitude; ?>" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'latitude' ); ?>"><?php esc_html_e( 'Latitude:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'latitude' ); ?>" name="<?php echo $this->get_field_name( 'latitude' ); ?>" type="text" value="<?php echo $latitude; ?>" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'height' ); ?>"><?php esc_html_e( 'Google Maps height in px:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'height' ); ?>" name="<?php echo $this->get_field_name( 'height' ); ?>" type="text" value="<?php echo $height; ?>" size="3" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'zoom_size' ); ?>"><?php esc_html_e( 'Google Maps Zoom Size:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'zoom_size' ); ?>" name="<?php echo $this->get_field_name( 'zoom_size' ); ?>" type="text" value="<?php echo $zoom_size; ?>" size="3" />
		</p>

		<?php
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 */
	function update( $new_instance, $old_instance ) {
		$instance          = $old_instance;
		$instance['title'] = strip_tags( $new_instance['title'] );
		if ( current_user_can( 'unfiltered_html' ) ) {
			$instance['text'] = $new_instance['text'];
		} else {
			$instance['text'] = stripslashes( wp_filter_post_kses( addslashes( $new_instance['text'] ) ) );
		}
		$instance['longitude'] = strip_tags( $new_instance['longitude'] );
		$instance['latitude']  = strip_tags( $new_instance['latitude'] );
		$instance['height']    = absint( $new_instance['height'] );
		$instance['zoom_size'] = absint( $new_instance['zoom_size'] );

		return $instance;
	}

	/**
	 * Front-end display of widget.
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	function widget( $args, $instance ) {
		extract( $args );
		extract( $instance );

		$title     = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );
		$text      = isset( $instance['text'] ) ? $instance['text'] : '';
		$longitude = isset( $instance['longitude'] ) ? $instance['longitude'] : '';
		$latitude  = isset( $instance['latitude'] ) ? $instance['latitude'] : '';
		$height    = isset( $instance['height'] ) ? $instance['height'] : 350;
		$zoom_size = isset( $instance['zoom_size'] ) ? $instance['zoom_size'] : 15;

		echo $before_widget;

		?>

		<?php
		// For WPML plugin compatibility register the string
		if ( function_exists( 'icl_register_string' ) ) {
			icl_register_string( 'ColorMag Pro', 'TG: Google Maps Description' . $this->id, $text );
		}

		// For WPML plugin compatibility use the assigned registered string
		if ( function_exists( 'icl_t' ) ) {
			$text = icl_t( 'ColorMag Pro', 'TG: Google Maps Description' . $this->id, $text );
		}

		// Display the title
		if ( ! empty( $title ) ) {
			?>
			<p><?php echo $before_title . esc_html( $title ) . $after_title; ?></p>
			<?php
		}

		// Display the description text
		if ( ! empty( $text ) ) {
			?>
			<p><?php echo esc_html( $text ); ?></p>
		<?php }
		?>

		<?php if ( current_user_can( 'edit_theme_options' ) ) { ?>
			<?php
			$googlemap_api_key = get_theme_mod( 'colormag_googlemap_api_key' );
			if ( empty( $googlemap_api_key ) ) {
				?>
				<div class="google-maps-api-error">
					<?php esc_html_e( 'GoogleMaps requires API Key to work.', 'colormag-pro' ); ?>
					<a href="<?php echo esc_url( 'https://developers.google.com/maps/documentation/javascript/get-api-key' ); ?>" target="_blank"><?php esc_html_e( 'Get API Key', 'colormag-pro' ); ?></a>
				</div>
			<?php } ?>

			<?php if ( empty( $longitude ) || empty( $latitude ) ) { ?>
				<div class="google-maps-lon-lat-error">
					<?php esc_html_e( 'You need to add longitude and latitude value to display the Google Maps. You can set it up via the widget setting.', 'colormag-pro' ); ?>
				</div>
			<?php } ?>
		<?php } ?>

		<div class="GoogleMaps-wrapper">
			<div id="GoogleMaps"></div>
		</div>

		<?php
		echo $after_widget;
	}

}
