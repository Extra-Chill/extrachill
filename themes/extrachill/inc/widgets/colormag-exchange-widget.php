<?php
/**
 * Currency Exchange Widget
 */

class colormag_exchange_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'                   => 'widget_exchange',
			'description'                 => __( 'Display Currency Exchange.', 'colormag-pro' ),
			'customize_selective_refresh' => true,
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Currency Exchange', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['title']               = '';
		$tg_defaults['base_currency']       = 'USD';
		$tg_defaults['exchange_currencies'] = '';
		$tg_defaults['column']              = 1;

		$instance            = wp_parse_args( ( array ) $instance, $tg_defaults );
		$title               = $instance['title'];
		$base_currency       = $instance['base_currency'];
		$exchange_currencies = $instance['exchange_currencies'];
		$column              = $instance['column'];

		$available_currencies = colormag_get_available_currencies();
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'base_currency' ); ?>"><?php esc_html_e( 'Base Currency:', 'colormag-pro' ); ?></label>
			<select id="<?php echo $this->get_field_id( 'base_currency' ); ?>" name="<?php echo $this->get_field_name( 'base_currency' ); ?>">
				<?php foreach ( $available_currencies as $value => $name ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $instance['base_currency'], $value ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'exchange_currencies' ); ?>"><?php _e( 'Exchange Currencies:', 'colormag-pro' ); ?></label>
			<?php
			printf(
				'<select multiple="multiple" name="%s[]" id="%s" %s>', $this->get_field_name( 'exchange_currencies' ), $this->get_field_id( 'exchange_currencies' ), 'style="width:100%"'
			);

			$exchange_currencies_value = ! empty( $instance['exchange_currencies'] ) ? $instance['exchange_currencies'] : array();

			// Each individual option
			foreach ( $available_currencies as $value => $name ) {
				printf(
					'<option value="%s"%s>%s</option>', $value, in_array( $value, $exchange_currencies_value ) ? ' selected="selected"' : '', $name
				);
			}

			echo '</select>';
			?>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'column' ); ?>"><?php esc_html_e( 'Column:', 'colormag-pro' ); ?></label>
			<select id="<?php echo $this->get_field_id( 'column' ); ?>" name="<?php echo $this->get_field_name( 'column' ); ?>">
				<option value="1" <?php selected( $instance['column'], '1' ); ?>><?php esc_html_e( '1', 'colormag-pro' ); ?></option>
				<option value="2" <?php selected( $instance['column'], '2' ); ?>><?php esc_html_e( '2', 'colormag-pro' ); ?></option>
				<option value="3" <?php selected( $instance['column'], '3' ); ?>><?php esc_html_e( '3', 'colormag-pro' ); ?></option>
			</select>
		</p>
		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance                        = $old_instance;
		$instance['title']               = sanitize_text_field( $new_instance['title'] );
		$instance['base_currency']       = sanitize_text_field( $new_instance['base_currency'] );
		$instance['exchange_currencies'] = isset( $new_instance['exchange_currencies'] ) ? $new_instance['exchange_currencies'] : array();
		$instance['column']              = absint( $new_instance['column'] );

		$exchange_transient_name = 'colormag_exchange_' . $new_instance['base_currency'] . "_" . current_time( 'Y-m-d' );
		delete_transient( $exchange_transient_name );

		return $instance;
	}

	function widget( $args, $instance ) {
		extract( $args );
		extract( $instance );

		$title               = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );
		$base_currency       = isset( $instance['base_currency'] ) ? $instance['base_currency'] : 'usd';
		$exchange_currencies = isset( $instance['exchange_currencies'] ) ? $instance['exchange_currencies'] : array();
		$column              = isset( $instance['column'] ) ? $instance['column'] : 1;

		$available_currencies = colormag_get_available_currencies();

		$exchange_transient_name = 'colormag_exchange_' . $base_currency . "_" . current_time( 'Y-m-d' );

		if ( get_transient( $exchange_transient_name ) ) {
			$currency_data = get_transient( $exchange_transient_name );
		} else {
			$api_url = add_query_arg( 'base', strtoupper( $base_currency ), 'http://api.fixer.io/latest' );

			if ( count( $exchange_currencies ) > 0 ) {
				$currency_to_fetch = strtoupper( implode( ',', $exchange_currencies ) );

				$api_url = add_query_arg( 'symbols', esc_html( $currency_to_fetch ), $api_url );
			}

			$json_response = wp_remote_get( $api_url );

			if ( is_wp_error( $json_response ) ) :
				echo $json_response->get_error_message();

				return false;
			endif;

			$currency_data = json_decode( $json_response['body'] );

			if ( $currency_data ) {
				set_transient( $exchange_transient_name, $currency_data, apply_filters( 'colormag_exchange_cache_time', DAY_IN_SECONDS ) );
			}
		}

		echo $before_widget;
		?>
		<?php
		if ( ! empty( $title ) ) {
			echo '<h3 class="widget-title"><span>' . esc_html( $title ) . '</span></h3>';
		}
		?>
		<div class="exchange-currency exchange-column-<?php echo esc_attr( $column ); ?>">
			<div class="base-currency">
				<?php echo esc_html( $available_currencies[ strtolower( $base_currency ) ] ); ?>
			</div>
			<div class="currency-list">
				<?php
				foreach ( $currency_data->rates as $country => $rate ) {
					?>
					<div class="currency-table">
						<div class="currency--country">
							<span class="currency--flag currency--flag-<?php echo strtolower( $country ); ?>"></span>
							<?php echo esc_html( $country ); ?>
						</div>
						<div class="currency--rate">
							<?php echo esc_html( $rate ); ?>
						</div>
					</div>
					<?php
				}
				?>
			</div>
		</div>
		<?php
		echo $after_widget;
	}

}
