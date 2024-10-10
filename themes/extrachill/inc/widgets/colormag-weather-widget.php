<?php
/**
 * Weather Widget
 */

class colormag_weather_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'                   => 'widget_weather',
			'description'                 => __( 'Display weather.', 'colormag-pro' ),
			'customize_selective_refresh' => true,
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Weather', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['title']    = '';
		$tg_defaults['city_id']  = '';
		$tg_defaults['unit']     = 'imperial';
		$tg_defaults['forecast'] = 4;

		$instance = wp_parse_args( ( array ) $instance, $tg_defaults );
		$title    = $instance['title'];
		$city_id  = $instance['city_id'];
		$unit     = $instance['unit'];
		$forecast = $instance['forecast'];
		?>
		<p>
		<?php
		$API_KEY = get_theme_mod( 'colormag_openweathermap_api_key' );

		if ( empty( $API_KEY ) ) :
			$query['autofocus[section]'] = 'colormag_openweathermap_section';
			$section_link = add_query_arg( $query, admin_url( 'customize.php' ) );
			?>
			<div class="weather-api-error">
				<?php
				$url  = 'http://openweathermap.org/appid';
				$link = sprintf( __( 'OpenWeatherMap requires <a href="%s" target="_blank">API Key</a> to work', 'colormag-pro' ), esc_url( $url ) );
				echo $link;
				?><br />
				<a href="<?php echo esc_url( $section_link ); ?>"><?php esc_html_e( 'Enter API Key here', 'colormag-pro' ); ?></a><br /><br />
			</div>
		<?php endif; ?>
		<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'colormag-pro' ); ?></label>
		<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'city_id' ); ?>"><?php _e( 'OpenWeatherMap City ID:', 'colormag-pro' ); ?>
				<a href="http://openweathermap.org/find"><?php esc_html_e( 'Get City ID', 'colormag-pro' ); ?></a><br /></label>
			<input id="<?php echo $this->get_field_id( 'city_id' ); ?>" name="<?php echo $this->get_field_name( 'city_id' ); ?>" type="text" value="<?php echo esc_attr( $city_id ); ?>" />
		</p>
		<p>
			<input type="radio" <?php checked( $unit, 'imperial' ) ?> id="<?php echo $this->get_field_id( 'unit' ); ?>" name="<?php echo $this->get_field_name( 'unit' ); ?>" value="imperial" /><?php esc_html_e( 'Fahrenheit', 'colormag-pro' ); ?>
			<br />
			<input type="radio" <?php checked( $unit, 'metric' ) ?> id="<?php echo $this->get_field_id( 'unit' ); ?>" name="<?php echo $this->get_field_name( 'unit' ); ?>" value="metric" /><?php esc_html_e( 'Celsius', 'colormag-pro' ); ?>
			<br />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'forecast' ); ?>"><?php esc_html_e( 'Forecast Days:', 'colormag-pro' ); ?></label>
			<select id="<?php echo $this->get_field_id( 'forecast' ); ?>" name="<?php echo $this->get_field_name( 'forecast' ); ?>">
				<option value="0" <?php selected( $instance['forecast'], '0' ); ?>><?php esc_html_e( 'Hide', 'colormag-pro' ); ?></option>
				<option value="1" <?php selected( $instance['forecast'], '1' ); ?>><?php esc_html_e( '1', 'colormag-pro' ); ?></option>
				<option value="2" <?php selected( $instance['forecast'], '2' ); ?>><?php esc_html_e( '2', 'colormag-pro' ); ?></option>
				<option value="3" <?php selected( $instance['forecast'], '3' ); ?>><?php esc_html_e( '3', 'colormag-pro' ); ?></option>
				<option value="4" <?php selected( $instance['forecast'], '4' ); ?>><?php esc_html_e( '4', 'colormag-pro' ); ?></option>
			</select>
		</p>
		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance             = $old_instance;
		$instance['title']    = sanitize_text_field( $new_instance['title'] );
		$instance['city_id']  = absint( $new_instance['city_id'] );
		$instance['unit']     = sanitize_text_field( $new_instance['unit'] );
		$instance['forecast'] = sanitize_text_field( $new_instance['forecast'] );

		$weather_transient_name = 'colormag_weather_' . $new_instance['city_id'] . "_" . $new_instance['forecast'] . "_" . strtolower( $new_instance['unit'] );
		delete_transient( $weather_transient_name );

		return $instance;
	}

	function widget( $args, $instance ) {
		extract( $args );
		extract( $instance );

		$title         = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );
		$city_id       = isset( $instance['city_id'] ) ? $instance['city_id'] : '';
		$unit          = isset( $instance['unit'] ) ? $instance['unit'] : 'imperial';
		$forecast_days = isset( $instance['forecast'] ) ? $instance['forecast'] : 5;

		if ( $unit == 'imperial' ) {
			$temp_unit        = esc_html__( 'Fahrenheit', 'colormag-pro' );
			$wind_unit        = esc_html__( 'mph', 'colormag-pro' );
			$temp_unit_symbol = esc_html__( 'F', 'colormag-pro' );
		} else {
			$temp_unit        = esc_html__( 'Celsius', 'colormag-pro' );
			$wind_unit        = esc_html__( 'm/s', 'colormag-pro' );
			$temp_unit_symbol = esc_html__( 'C', 'colormag-pro' );
		}

		$API_KEY = get_theme_mod( 'colormag_openweathermap_api_key' );

		if ( empty( $API_KEY ) ) :
			?>
			<div class="weather-api-error">
				<?php esc_html_e( 'OpenWeatherMap requires API Key to work.', 'colormag-pro' ); ?>
				<a href="http://openweathermap.org/appid"><?php esc_html_e( 'Get API Key', 'colormag-pro' ); ?></a>
			</div>
			<?php
			return false;
		endif;
		?>

		<?php if ( empty( $city_id ) ) : ?>
			<div class="weather-api-error">
				<?php esc_html_e( 'OpenWeatherMap requires City ID to work.', 'colormag-pro' ); ?>
				<a href="http://openweathermap.org/find"><?php esc_html_e( 'Get City ID', 'colormag-pro' ); ?></a>
			</div>
			<?php
			return false;
		endif;
		?>

		<?php
		// Transient
		$weather_transient_name = 'colormag_weather_' . $city_id . "_" . $forecast_days . "_" . strtolower( $unit );

		if ( get_transient( $weather_transient_name ) ) {
			$weather_data = get_transient( $weather_transient_name );
		} else {
			$weather_data['today']    = array();
			$weather_data['forecast'] = array();

			// Today Data
			$today_api_url      = "http://api.openweathermap.org/data/2.5/weather?" . "id=" . $city_id . "&units=" . $unit . "&APPID=" . $API_KEY;
			$today_api_response = wp_remote_get( $today_api_url );

			if ( is_wp_error( $today_api_response ) ) :
				echo $today_api_response->get_error_message();

				return false;
			endif;

			$today_data            = json_decode( $today_api_response['body'] );
			$weather_data['today'] = $today_data;

			// Forecast Data
			$forecast_api_url      = "http://api.openweathermap.org/data/2.5/forecast/daily?" . "id=" . $city_id . "&units=" . $unit . "&APPID=" . $API_KEY;
			$forecast_api_response = wp_remote_get( $forecast_api_url );

			if ( is_wp_error( $forecast_api_response ) ) :
				echo $forecast_api_response->get_error_message();

				return false;
			endif;

			$forecast_data            = json_decode( $forecast_api_response['body'] );
			$weather_data['forecast'] = $forecast_data;

			if ( $weather_data['today'] || $weather_data['forecast'] ) {
				set_transient( $weather_transient_name, $weather_data, apply_filters( 'colormag_weather_cache_time', 3 * HOUR_IN_SECONDS ) );
			}
		}

		$today_data    = $weather_data['today'];
		$forecast_data = $weather_data['forecast'];

		// Checks for any error
		if ( isset( $today_data->cod ) && substr( $today_data->cod, 0, 1 ) == "4" ) {
			echo $today_data->message;

			return false;
		}

		// Checks for any error
		if ( isset( $forecast_data->cod ) && substr( $forecast_data->cod, 0, 1 ) == "4" ) {
			echo $forecast_data->message;

			return false;
		}

		$today_temperature  = isset( $today_data->main->temp ) ? round( $today_data->main->temp ) : false;
		$today_description  = isset( $today_data->weather[0]->description ) ? $today_data->weather[0]->description : '';
		$today_humidity     = isset( $today_data->main->humidity ) ? $today_data->main->humidity : '';
		$today_wind_speed   = isset( $today_data->wind->speed ) ? $today_data->wind->speed : '';
		$today_wind_degree  = isset( $today_data->wind->deg ) ? $today_data->wind->deg : '';
		$today_wind_compass = round( ( $today_wind_degree - 11.25 ) / 22.5 );
		$today_weather_code = $today_data->weather[0]->id;

		$wind_label = array(
			__( 'N', 'colormag-pro' ),
			__( 'NNE', 'colormag-pro' ),
			__( 'NE', 'colormag-pro' ),
			__( 'ENE', 'colormag-pro' ),
			__( 'E', 'colormag-pro' ),
			__( 'ESE', 'colormag-pro' ),
			__( 'SE', 'colormag-pro' ),
			__( 'SSE', 'colormag-pro' ),
			__( 'S', 'colormag-pro' ),
			__( 'SSW', 'colormag-pro' ),
			__( 'SW', 'colormag-pro' ),
			__( 'WSW', 'colormag-pro' ),
			__( 'W', 'colormag-pro' ),
			__( 'WNW', 'colormag-pro' ),
			__( 'NW', 'colormag-pro' ),
			__( 'NNW', 'colormag-pro' ),
		);

		$today_wind_direction = $wind_label[ $today_wind_compass ];

		// Get Today High and Low Temperature from Forecast if available
		if ( isset( $forecast_data->list ) && isset( $forecast_data->list[0] ) ) {
			$forecast_today = $forecast_data->list[0];
			$today_high     = round( $forecast_today->temp->max );
			$today_low      = round( $forecast_today->temp->min );
		} else {
			$today_high = isset( $today_data->main->temp_max ) ? round( $today_data->main->temp_max ) : false;
			$today_low  = isset( $today_data->main->temp_min ) ? round( $today_data->main->temp_min ) : false;
		}

		$units_display_symbol = apply_filters( 'colormag_weather_widget_deg_symbol', '&deg;' );

		if ( is_active_widget( false, false, $this->id_base ) || is_customize_preview() || ( function_exists( 'colormag_elementor_active_page_check' ) && colormag_elementor_active_page_check() ) ) {
			wp_enqueue_style( 'owfont' );
		}

		$style = '';
		if ( colormag_get_weather_color( $today_weather_code ) !== '' ) {
			$style = ' style=background-color:' . colormag_get_weather_color( $today_weather_code ) . '';
		}

		echo $before_widget;
		?>
		<div class="weather-forecast"<?php echo esc_attr( $style ); ?>>
			<?php
			if ( ! empty( $title ) ) {
				echo '<header class="weather-forecast-header">' . esc_html( $title ) . '</header>';
			}
			?>
			<div class="weather-info">
				<div class="weather-location">
					<span class="weather-icon"><span class="owf owf-<?php echo esc_attr( $today_weather_code ); ?>"></span></span>
					<span class="weather-location-name"><?php echo esc_html( $today_data->name ); ?></span>
					<span class="weather-desc"><?php echo esc_html( $today_data->weather[0]->description ); ?></span>
				</div>
				<div class="weather-today">
					<span class="weather-current-temp"><?php echo esc_html( $today_temperature ); ?>
						<sup><?php echo esc_html( $units_display_symbol ); ?><?php echo esc_html( $temp_unit_symbol ); ?></sup></span>
					<div class="weather-temp">
						<span class="fa fa-thermometer-full"></span><?php echo absint( $today_high ); ?> -
						<?php echo absint( $today_low ); ?></div>
					<div class="weather_highlow">
						<span class="fa fa-tint"></span><?php echo esc_html( $today_humidity . '%' ); ?></div>
					<div class="weather_wind">
						<span class="owf owf-231"></span><?php echo esc_html( round( $today_wind_speed ) . $wind_unit ); ?>
					</div>
				</div>
			</div>
			<?php if ( $forecast_days != 0 ) : ?>
				<footer class="weather-forecast-footer">
					<?php
					$days_shown   = 1;
					$days_of_week = array(
						esc_html__( 'Sun', 'colormag-pro' ),
						esc_html__( 'Mon', 'colormag-pro' ),
						esc_html__( 'Tue', 'colormag-pro' ),
						esc_html__( 'Wed', 'colormag-pro' ),
						esc_html__( 'Thu', 'colormag-pro' ),
						esc_html__( 'Fri', 'colormag-pro' ),
						esc_html__( 'Sat', 'colormag-pro' ),
					);
					foreach ( ( array ) $forecast_data->list as $forecast ) {

						$forecast_weather_code = $forecast->weather[0]->id;
						$day                   = $days_of_week[ date( 'w', $forecast->dt ) ];
						if ( $days_shown <= $forecast_days ) {
							?>
							<div class="weather-forecast-day weather-forecast-day--<?php echo esc_html( $day ); ?>">
								<span class="weather-icon"><i class="owf owf-<?php echo esc_attr( $today_weather_code ); ?>"></i></span>
								<div class="weather-forecast-day-temp">
									<span class="weather-forecast-temp"><?php echo esc_html( $forecast->temp->day ); ?>
										<sup><?php echo esc_html( $units_display_symbol ); ?><?php echo esc_html( $temp_unit_symbol ); ?></sup></span>
									<span class="weather-forecast-day-abbr"><?php echo esc_html( $day ); ?></span>
								</div>
							</div>
							<?php
						}
						$days_shown ++;
					}
					?>
				</footer>
			<?php endif; ?>
		</div>
		<?php
		echo $after_widget;
	}

}
