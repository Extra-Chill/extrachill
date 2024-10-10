<?php
/**
 * Slider News Widget
 */

class colormag_slider_news_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'   => 'widget_slider_news_colormag widget_featured_posts',
			'description' => __( 'Display latest posts or posts of specific category.', 'colormag-pro' ),
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Featured Posts (Style 6)', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['title']           = '';
		$tg_defaults['text']            = '';
		$tg_defaults['number']          = 5;
		$tg_defaults['type']            = 'latest';
		$tg_defaults['category']        = '';
		$tg_defaults['random_posts']    = '0';
		$tg_defaults['child_category']  = '0';
		$tg_defaults['tag']             = '';
		$tg_defaults['view_all_button'] = '0';
		$tg_defaults['slider_mode']     = 'horizontal';
		$tg_defaults['slider_speed']    = 500;
		$tg_defaults['slider_pause']    = 4000;
		$tg_defaults['slider_auto']     = '0';
		$tg_defaults['slider_hover']    = '0';
		$instance                       = wp_parse_args( ( array ) $instance, $tg_defaults );
		$title                          = esc_attr( $instance['title'] );
		$text                           = esc_textarea( $instance['text'] );
		$number                         = $instance['number'];
		$type                           = $instance['type'];
		$category                       = $instance['category'];
		$random_posts                   = $instance['random_posts'] ? 'checked="checked"' : '';
		$child_category                 = $instance['child_category'] ? 'checked="checked"' : '';
		$tag                            = $instance['tag'];
		$view_all_button                = $instance['view_all_button'] ? 'checked="checked"' : '';
		$slider_mode                    = $instance['slider_mode'];
		$slider_speed                   = $instance['slider_speed'];
		$slider_pause                   = $instance['slider_pause'];
		$slider_auto                    = $instance['slider_auto'] ? 'checked="checked"' : '';
		$slider_hover                   = $instance['slider_hover'] ? 'checked="checked"' : '';
		?>
		<p><?php _e( 'Layout will be as below:', 'colormag-pro' ) ?></p>
		<div style="text-align: center;"><img src="<?php echo esc_url(get_template_directory_uri() . '/img/style-6.jpg' ) ?>">
		</div>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo $title; ?>" />
		</p>
		<?php _e( 'Description', 'colormag-pro' ); ?>
		<textarea class="widefat" rows="5" cols="20" id="<?php echo $this->get_field_id( 'text' ); ?>" name="<?php echo $this->get_field_name( 'text' ); ?>"><?php echo $text; ?></textarea>
		<p>
			<label for="<?php echo $this->get_field_id( 'number' ); ?>"><?php _e( 'Number of posts to display:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'number' ); ?>" name="<?php echo $this->get_field_name( 'number' ); ?>" type="text" value="<?php echo $number; ?>" size="3" />
		</p>

		<p>
			<input type="radio" <?php checked( $type, 'latest' ) ?> id="<?php echo $this->get_field_id( 'type' ); ?>" name="<?php echo $this->get_field_name( 'type' ); ?>" value="latest" /><?php _e( 'Show latest Posts', 'colormag-pro' ); ?>
			<br />
			<input type="radio" <?php checked( $type, 'category' ) ?> id="<?php echo $this->get_field_id( 'type' ); ?>" name="<?php echo $this->get_field_name( 'type' ); ?>" value="category" /><?php _e( 'Show posts from a category', 'colormag-pro' ); ?>
			<br />
			<input type="radio" <?php checked( $type, 'tag' ) ?> id="<?php echo $this->get_field_id( 'type' ); ?>" name="<?php echo $this->get_field_name( 'type' ); ?>" value="tag" /><?php _e( 'Show posts from tag', 'colormag-pro' ); ?>
			<br />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'category' ); ?>"><?php _e( 'Select category', 'colormag-pro' ); ?>
				:</label>
			<?php wp_dropdown_categories( array(
				'show_option_none' => ' ',
				'name'             => $this->get_field_name( 'category' ),
				'selected'         => $category,
			) ); ?>
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'tag' ) ); ?>"><?php esc_html_e( 'Select tag', 'colormag-pro' ); ?></label>
			<?php wp_dropdown_categories( array(
				'show_option_none' => ' ',
				'name'             => $this->get_field_name( 'tag' ),
				'selected'         => $tag,
				'taxonomy'         => 'post_tag',
			) ); ?>
		</p>

		<p>
			<input class="checkbox" <?php echo $random_posts; ?> id="<?php echo $this->get_field_id( 'random_posts' ); ?>" name="<?php echo $this->get_field_name( 'random_posts' ); ?>" type="checkbox" />
			<label for="<?php echo $this->get_field_id( 'random_posts' ); ?>"><?php _e( 'Check to display the random post from either the chosen category or from latest post.', 'colormag-pro' ); ?></label>
		</p>

		<p>
			<input class="checkbox" <?php echo $child_category; ?> id="<?php echo $this->get_field_id( 'child_category' ); ?>" name="<?php echo $this->get_field_name( 'child_category' ); ?>" type="checkbox" />
			<label for="<?php echo $this->get_field_id( 'child_category' ); ?>"><?php _e( 'Check to display the posts from child category of the chosen category.', 'colormag-pro' ); ?></label>
		</p>

		<p>
			<input class="checkbox" <?php echo $view_all_button; ?> id="<?php echo $this->get_field_id( 'view_all_button' ); ?>" name="<?php echo $this->get_field_name( 'view_all_button' ); ?>" type="checkbox" />
			<label for="<?php echo $this->get_field_id( 'view_all_button' ); ?>"><?php esc_html_e( 'Check to display the view all button to link that button to the specific category chosen in this widget.', 'colormag-pro' ); ?></label>
		</p>

		<h2>
			<?php esc_html_e( 'Slider Options', 'colormag-pro' ); ?>
			<hr>
		</h2>
		<p>
			<label for="<?php echo $this->get_field_id( 'slider_mode' ); ?>"><?php esc_html_e( 'Slide Mode:', 'colormag-pro' ); ?></label>
			<select id="<?php echo $this->get_field_id( 'slider_mode' ); ?>" name="<?php echo $this->get_field_name( 'slider_mode' ); ?>">
				<option value="horizontal" <?php selected( $instance['slider_mode'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal', 'colormag-pro' ); ?></option>
				<option value="vertical" <?php selected( $instance['slider_mode'], 'vertical' ); ?>><?php esc_html_e( 'Vertical', 'colormag-pro' ); ?></option>
				<option value="fade" <?php selected( $instance['slider_mode'], 'fade' ); ?>><?php esc_html_e( 'Fade', 'colormag-pro' ); ?></option>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'slider_speed' ); ?>"><?php esc_html_e( 'Transition Speed Time (in ms):', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'slider_speed' ); ?>" name="<?php echo $this->get_field_name( 'slider_speed' ); ?>" type="text" value="<?php echo esc_attr( $slider_speed ); ?>" size="3" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'slider_pause' ); ?>"><?php esc_html_e( 'Transition Pause Time (in ms):', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'slider_pause' ); ?>" name="<?php echo $this->get_field_name( 'slider_pause' ); ?>" type="text" value="<?php echo esc_attr( $slider_pause ); ?>" size="3" />
		</p>

		<p>
			<input class="checkbox" <?php echo $slider_auto; ?> id="<?php echo $this->get_field_id( 'slider_auto' ); ?>" name="<?php echo $this->get_field_name( 'slider_auto' ); ?>" type="checkbox" />
			<label for="<?php echo $this->get_field_id( 'slider_auto' ); ?>"><?php esc_html_e( 'Check to enable auto slide.', 'colormag-pro' ); ?></label>
		</p>

		<p>
			<input class="checkbox" <?php echo $slider_hover; ?> id="<?php echo $this->get_field_id( 'slider_hover' ); ?>" name="<?php echo $this->get_field_name( 'slider_hover' ); ?>" type="checkbox" />
			<label for="<?php echo $this->get_field_id( 'slider_hover' ); ?>"><?php esc_html_e( 'Check to disable auto slide when mouse hover.', 'colormag-pro' ); ?></label>
		</p>

		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance          = $old_instance;
		$instance['title'] = strip_tags( $new_instance['title'] );
		if ( current_user_can( 'unfiltered_html' ) ) {
			$instance['text'] = $new_instance['text'];
		} else {
			$instance['text'] = stripslashes( wp_filter_post_kses( addslashes( $new_instance['text'] ) ) );
		}
		$instance['number']          = absint( $new_instance['number'] );
		$instance['type']            = $new_instance['type'];
		$instance['category']        = $new_instance['category'];
		$instance['random_posts']    = isset( $new_instance['random_posts'] ) ? 1 : 0;
		$instance['child_category']  = isset( $new_instance['child_category'] ) ? 1 : 0;
		$instance['tag']             = $new_instance['tag'];
		$instance['view_all_button'] = isset( $new_instance['view_all_button'] ) ? 1 : 0;
		$instance['slider_mode']     = $new_instance['slider_mode'];
		$instance['slider_speed']    = absint( $new_instance['slider_speed'] );
		$instance['slider_pause']    = absint( $new_instance['slider_pause'] );
		$instance['slider_auto']     = isset( $new_instance['slider_auto'] ) ? 1 : 0;
		$instance['slider_hover']    = isset( $new_instance['slider_hover'] ) ? 1 : 0;

		return $instance;
	}

	function widget( $args, $instance ) {
		if ( is_active_widget( false, false, $this->id_base ) || is_customize_preview() || ( function_exists( 'colormag_elementor_active_page_check' ) && colormag_elementor_active_page_check() ) ) {
			wp_enqueue_script( 'colormag-bxslider' );
		}

		extract( $args );
		extract( $instance );

		global $post;
		$title           = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );
		$text            = isset( $instance['text'] ) ? $instance['text'] : '';
		$number          = empty( $instance['number'] ) ? 4 : $instance['number'];
		$type            = isset( $instance['type'] ) ? $instance['type'] : 'latest';
		$category        = isset( $instance['category'] ) ? $instance['category'] : '';
		$random_posts    = ! empty( $instance['random_posts'] ) ? 'true' : 'false';
		$child_category  = ! empty( $instance['child_category'] ) ? 'true' : 'false';
		$tag             = isset( $instance['tag'] ) ? $instance['tag'] : '';
		$view_all_button = ! empty( $instance['view_all_button'] ) ? 'true' : 'false';
		$slider_mode     = isset( $instance['slider_mode'] ) ? $instance['slider_mode'] : 'horizontal';
		$slider_speed    = empty( $instance['slider_speed'] ) ? 500 : $instance['slider_speed'];
		$slider_pause    = empty( $instance['slider_pause'] ) ? 4000 : $instance['slider_pause'];
		$slider_auto     = ! empty( $instance['slider_auto'] ) ? 'true' : 'false';
		$slider_hover    = ! empty( $instance['slider_hover'] ) ? 'true' : 'false';

		// For WPML plugin compatibility
		if ( function_exists( 'icl_register_string' ) ) {
			icl_register_string( 'ColorMag Pro', 'TG: Featured Posts (Style 6) Description' . $this->id, $text );
		}

		// adding the excluding post function
		$post__not_in = colormag_exclude_duplicate_posts();

		$args = array(
			'posts_per_page'      => $number,
			'post_type'           => 'post',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'post__not_in'        => $post__not_in,
		);

		// Display from category chosen.
		if ( $type == 'category' && $child_category == 'false' ) {
			$args['category__in'] = $category;
		}

		// Displays random posts.
		if ( $random_posts == 'true' ) {
			$args['orderby'] = 'rand';
		}

		// Displays post from parent as well as child category.
		if ( $type == 'category' && $child_category == 'true' ) {
			$args['cat'] = $category;
		}

		// Displays from tag chosen.
		if ( $type == 'tag' ) {
			$args['tag__in'] = $tag;
		}

		$get_featured_posts = new WP_Query( $args );

		colormag_append_excluded_duplicate_posts( $get_featured_posts );
		echo $before_widget;
		?>
		<?php $featured = 'colormag-featured-image'; ?>
		<?php
		if ( $type != 'latest' ) {
			$border_color = 'style="border-bottom-color:' . colormag_category_color( $category ) . ';"';
			$title_color  = 'style="background-color:' . colormag_category_color( $category ) . ';"';
		} else {
			$border_color = '';
			$title_color  = '';
		}
		// For WPML plugin compatibility
		if ( function_exists( 'icl_t' ) ) {
			$text = icl_t( 'ColorMag Pro', 'TG: Featured Posts (Style 6) Description' . $this->id, $text );
		}

		// assign the view all link to be displayed in the widget title
		$category_link = '';
		if ( $view_all_button == 'true' && ( ! empty( $category ) && $type != 'latest' ) ) {
			$category_link = '<a href="' . esc_url( get_category_link( $category ) ) . '" class="view-all-link">' . get_theme_mod( 'colormag_view_all_text', esc_html__( 'View All', 'colormag-pro' ) ) . '</a>';
		}

		if ( ! empty( $title ) ) {
			echo '<h3 class="widget-title" ' . $border_color . '><span ' . $title_color . '>' . esc_html( $title ) . '</span>' . $category_link . '</h3>';
		}
		if ( ! empty( $text ) ) {
			?> <p> <?php echo esc_textarea( $text ); ?> </p> <?php } ?>

		<div class="thumbnail-slider-news">
			<?php
			$i                = 1;
			$big_image        = '';
			$big_image_output = '';
			$thumbnail_image  = '';
			$post_count       = $get_featured_posts->post_count;
			while ( $get_featured_posts->have_posts() ):$get_featured_posts->the_post();
				$j               = $i - 1;
				$title_attribute = get_the_title( $post->ID );
				if ( has_post_thumbnail() ) {
					$big_image       = '<a href="' . get_permalink( $post->ID ) . '">' . get_the_post_thumbnail( $post->ID, 'colormag-featured-image', array(
							'title' => esc_attr( $title_attribute ),
							'alt'   => esc_attr( $title_attribute ),
						) ) . '</a>';
					$thumbnail_image .= '<a data-slide-index="' . $j . '" href="">' . get_the_post_thumbnail( $post->ID, 'thumbnail', array(
							'title' => esc_attr( $title_attribute ),
							'alt'   => esc_attr( $title_attribute ),
						) ) . '<span class="title">' . $title_attribute . '</span></a>';
				} else {
					$big_image       = '<a href="' . get_permalink( $post->ID ) . '"><img src="' . get_template_directory_uri() . '/img/thumbnail-big-slider.png">' . '</a>';
					$thumbnail_image .= '<a data-slide-index="' . $j . '" href=""><img src="' . get_template_directory_uri() . '/img/thumbnail-small-slider.png">' . '<span class="title">' . $title_attribute . '</span></a>';
				}
				$big_image_output .= '<li class="single-article">' . $big_image . '<div class="article-content">' . colormag_colored_category_return() . '<h3 class="entry-title"><a href="' . get_permalink() . '" title="' . esc_attr( $title_attribute ) . '">' . get_the_title() . '</a></h3></div></li>';
				if ( $i == $number || $i == $post_count ) {
					?>
					<ul id="style6_slider_<?php echo $this->id; ?>" class="thumbnail-big-sliders" data-mode="<?php echo esc_attr( $slider_mode ); ?>" data-speed="<?php echo esc_attr( $slider_speed ); ?>" data-pause="<?php echo esc_attr( $slider_pause ); ?>" data-auto="<?php echo esc_attr( $slider_auto ); ?>" data-hover="<?php echo esc_attr( $slider_hover ); ?>">
						<?php echo $big_image_output; ?>
					</ul>
					<div id="style6_pager_<?php echo $this->id; ?>" class="thumbnail-slider">
						<?php echo $thumbnail_image; ?>
					</div>
					<?php
				}
				$i ++;
			endwhile;
			?>
			<?php
			// Reset Post Data
			wp_reset_query();
			?>
		</div>
		<?php
		echo $after_widget;
	}

}
