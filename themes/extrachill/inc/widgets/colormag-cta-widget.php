<?php
/**
 * Call To Action Widget
 */

class colormag_cta_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'                   => 'widget_call_to_action',
			'description'                 => __( 'Display Call To Action Widget.', 'colormag-pro' ),
			'customize_selective_refresh' => true,
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Call To Action', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['background_image'] = '';
		$tg_defaults['title']            = '';
		$tg_defaults['text']             = '';
		$tg_defaults['btn_text']         = '';
		$tg_defaults['btn_link']         = '';
		$tg_defaults['new_tab']          = 0;
		$tg_defaults['align']            = 'call-to-action--left';

		$instance         = wp_parse_args( ( array ) $instance, $tg_defaults );
		$background_image = $instance['background_image'];
		$title            = $instance['title'];
		$text             = $instance['text'];
		$btn_text         = $instance['btn_text'];
		$btn_link         = $instance['btn_link'];
		$new_tab          = $instance['new_tab'] ? 'checked="checked"' : '';
		$align            = $instance['align'];
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'background_image' ) ); ?>"> <?php esc_html_e( 'Background Image ', 'colormag-pro' ); ?></label>
		<div class="media-uploader" id="<?php echo esc_attr( $this->get_field_id( 'background_image' ) ); ?>">
			<div class="custom_media_preview">
				<?php if ( $background_image != '' ) : ?>
					<img class="custom_media_preview_default" src="<?php echo esc_url( $background_image ); ?>" style="max-width:100%;" />
				<?php endif; ?>
			</div>
			<input type="text" class="widefat custom_media_input" id="<?php echo esc_attr( $this->get_field_id( 'background_image' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'background_image' ) ); ?>" value="<?php echo esc_url( $background_image ); ?>" style="margin-top:5px;" />
			<button class="custom_media_upload button button-secondary button-large" id="<?php echo esc_attr( $this->get_field_id( 'background_image' ) ); ?>" data-choose="<?php esc_attr_e( 'Choose an image', 'colormag-pro' ); ?>" data-update="<?php esc_attr_e( 'Use image', 'colormag-pro' ); ?>" style="width:100%;margin-top:6px;margin-right:30px;"><?php esc_html_e( 'Select an Image', 'colormag-pro' ); ?></button>
		</div>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'colormag-pro' ); ?></label>
			<input id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php esc_html_e( 'Description', 'colormag-pro' ); ?>
		<textarea class="widefat" rows="5" cols="20" id="<?php echo esc_attr( $this->get_field_id( 'text' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'text' ) ); ?>"><?php echo esc_textarea( $text ); ?></textarea>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'btn_text' ) ); ?>"><?php esc_html_e( 'Button Text:', 'colormag-pro' ); ?></label>
			<input id="<?php echo esc_attr( $this->get_field_id( 'btn_text' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'btn_text' ) ); ?>" type="text" value="<?php echo esc_attr( $btn_text ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'btn_link' ) ); ?>"><?php esc_html_e( 'Button URL:', 'colormag-pro' ); ?></label>
			<input id="<?php echo esc_attr( $this->get_field_id( 'btn_link' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'btn_link' ) ); ?>" type="text" value="<?php echo esc_url( $btn_link ); ?>" />
		</p>
		<p>
			<input class="checkbox" type="checkbox" <?php echo esc_attr( $new_tab ); ?> id="<?php echo esc_attr( $this->get_field_id( 'new_tab' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'new_tab' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'new_tab' ) ); ?>"><?php esc_html_e( 'Open in new tab', 'colormag-pro' ); ?></label>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'align' ) ); ?>"><?php esc_html_e( 'Text Align:', 'colormag-pro' ); ?></label>
			<select id="<?php echo esc_attr( $this->get_field_id( 'align' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'align' ) ); ?>">
				<option value="call-to-action--left" <?php selected( $instance['align'], 'call-to-action--left' ); ?>><?php esc_html_e( 'Left', 'colormag-pro' ); ?></option>
				<option value="call-to-action--center" <?php selected( $instance['align'], 'call-to-action--center' ); ?>><?php esc_html_e( 'Center', 'colormag-pro' ); ?></option>
				<option value="call-to-action--right" <?php selected( $instance['align'], 'call-to-action--right' ); ?>><?php esc_html_e( 'Right', 'colormag-pro' ); ?></option>
			</select>
		</p>
		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance                     = $old_instance;
		$instance['background_image'] = esc_url_raw( $new_instance['background_image'] );
		$instance['title']            = sanitize_text_field( $new_instance['title'] );
		if ( current_user_can( 'unfiltered_html' ) ) {
			$instance['text'] = $new_instance['text'];
		} else {
			$instance['text'] = stripslashes( wp_filter_post_kses( addslashes( $new_instance['text'] ) ) );
		}
		$instance['btn_link'] = esc_url_raw( $new_instance['btn_link'] );
		$instance['btn_text'] = sanitize_text_field( $new_instance['btn_text'] );
		$instance['new_tab']  = isset( $new_instance['new_tab'] ) ? 1 : 0;
		$instance['align']    = sanitize_text_field( $new_instance['align'] );

		return $instance;
	}

	function widget( $args, $instance ) {
		extract( $args );
		extract( $instance );

		global $post;
		$background_image = isset( $instance['background_image'] ) ? $instance['background_image'] : '';
		$title            = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );
		$text             = isset( $instance['text'] ) ? $instance['text'] : '';
		$btn_link         = isset( $instance['btn_link'] ) ? $instance['btn_link'] : '';
		$btn_text         = isset( $instance['btn_text'] ) ? $instance['btn_text'] : '';
		$new_tab          = ! empty( $instance['new_tab'] ) ? 'target="_blank"' : '';
		$align            = isset( $instance['align'] ) ? $instance['align'] : 'call-to-action--left';

		// For WPML plugin compatibility
		if ( function_exists( 'icl_register_string' ) ) {
			icl_register_string( 'ColorMag Pro', 'TG: CTA Description' . $this->id, $text );
			icl_register_string( 'ColorMag Pro', 'TG: CTA Button Text' . $this->id, $btn_text );
			icl_register_string( 'ColorMag Pro', 'TG: CTA Button URL' . $this->id, $btn_link );
			icl_register_string( 'ColorMag Pro', 'TG: CTA Background Image' . $this->id, $background_image );
		}

		// For WPML plugin compatibility
		if ( function_exists( 'icl_t' ) ) {
			$text             = icl_t( 'ColorMag Pro', 'TG: CTA Description' . $this->id, $text );
			$btn_text         = icl_t( 'ColorMag Pro', 'TG: CTA Button Text' . $this->id, $btn_text );
			$btn_link         = icl_t( 'ColorMag Pro', 'TG: CTA Button URL' . $this->id, $btn_link );
			$background_image = icl_t( 'ColorMag Pro', 'TG: CTA Background Image' . $this->id, $background_image );
		}

		$style = '';
		if ( ! empty( $background_image ) ) {
			$style = " style=background-image:url({$background_image})";
		}
		echo $before_widget;
		?>
		<div class="call-to-action"<?php echo esc_attr( $style ); ?>>
			<div class="call-to-action-border  <?php echo esc_attr( $align ); ?>">
				<?php if ( ! empty( $title ) ) : ?>
					<h3 class="call-to-action__title"><?php echo esc_html( $title ); ?></h3>
				<?php endif; ?>

				<?php if ( ! empty( $text ) ) : ?>
					<div class="call-to-action-content">
						<p><?php echo esc_html( $text ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $btn_link ) ) : ?>
					<a href="<?php echo esc_url( $btn_link ); ?>" class="btn--primary" <?php echo esc_attr( $new_tab ); ?>><?php echo esc_html( $btn_text ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<!-- </div> -->
		<?php
		echo $after_widget;
	}

}
