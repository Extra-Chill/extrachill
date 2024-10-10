<?php
/**
 * Colormag Video Widget
 */

class colormag_video_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'                   => 'widget_video_colormag',
			'description'                 => __( 'Add the videos here, Youtube and Vimeo Videos is only accepted for now.', 'colormag-pro' ),
			'customize_selective_refresh' => true,
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Videos', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$instance = wp_parse_args( ( array ) $instance, array(
			'title'       => '',
			'link'        => '',
			'vimeo_title' => '',
			'vimeo_link'  => '',
		) );
		$title    = esc_attr( $instance['title'] );

		$link                    = 'link';
		$instance[ $link ]       = strip_tags( $instance[ $link ] );
		$vimeo_link              = 'vimeo_link';
		$instance[ $vimeo_link ] = strip_tags( $instance[ $vimeo_link ] );
		?>

		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo $title; ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( $link ); ?>"> <?php _e( 'Youtube Video ID:', 'colormag-pro' ); ?></label>
			<input type="text" class="widefat" id="<?php echo $this->get_field_id( $link ); ?>" name="<?php echo $this->get_field_name( $link ); ?>" value="<?php echo $instance[ $link ]; ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( $vimeo_link ); ?>"> <?php _e( 'Vimeo Video ID:', 'colormag-pro' ); ?></label>
			<input type="text" class="widefat" id="<?php echo $this->get_field_id( $vimeo_link ); ?>" name="<?php echo $this->get_field_name( $vimeo_link ); ?>" value="<?php echo $instance[ $vimeo_link ]; ?>" />
		</p>

		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance          = $old_instance;
		$instance['title'] = strip_tags( $new_instance['title'] );

		$link                    = 'link';
		$instance[ $link ]       = strip_tags( $new_instance[ $link ] );
		$vimeo_link              = 'vimeo_link';
		$instance[ $vimeo_link ] = strip_tags( $new_instance[ $vimeo_link ] );

		return $instance;
	}

	function widget( $args, $instance ) {
		extract( $args );
		extract( $instance );

		$title = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );

		$link       = 'link';
		$link       = isset( $instance[ $link ] ) ? $instance[ $link ] : '';
		$vimeo_link = 'vimeo_link';
		$vimeo_link = isset( $instance[ $vimeo_link ] ) ? $instance[ $vimeo_link ] : '';

		echo $before_widget;
		?>

		<div class="fitvids-video">
			<?php if ( ! empty( $title ) ) { ?>
				<div class="video-title">
					<?php echo $before_title . esc_html( $title ) . $after_title; ?>
				</div>
				<?php
			}
			if ( ! empty( $link ) ) {
				$output = '<div class="video"><iframe src="https://www.youtube.com/embed/' . $link . '"></iframe></div>';
				echo $output;
			}
			if ( ! empty( $vimeo_link ) ) {
				$output = '<div class="video"><iframe src="https://player.vimeo.com/video/' . $vimeo_link . '"></iframe></div>';
				echo $output;
			}
			?>
		</div>
		<?php
		echo $after_widget;
	}

}
