<?php
/**
 * News In Picture widget
 */

class colormag_news_in_picture_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'   => 'widget_block_picture_news widget_highlighted_posts widget_featured_meta widget_featured_posts',
			'description' => __( 'Display latest posts or posts of specific category.', 'colormag-pro' ),
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Featured Posts (Style 5)', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['title']           = '';
		$tg_defaults['text']            = '';
		$tg_defaults['number']          = 4;
		$tg_defaults['type']            = 'latest';
		$tg_defaults['category']        = '';
		$tg_defaults['slide']           = '1';
		$tg_defaults['random_posts']    = '0';
		$tg_defaults['child_category']  = '0';
		$tg_defaults['tag']             = '';
		$tg_defaults['view_all_button'] = '0';
		$tg_defaults['slider_speed']    = 1500;
		$tg_defaults['slider_pause']    = 5000;
		$tg_defaults['slider_auto']     = '0';
		$tg_defaults['slider_hover']    = '0';
		$instance                       = wp_parse_args( ( array ) $instance, $tg_defaults );
		$title                          = esc_attr( $instance['title'] );
		$text                           = esc_textarea( $instance['text'] );
		$number                         = $instance['number'];
		$type                           = $instance['type'];
		$category                       = $instance['category'];
		$slide                          = $instance['slide'] ? 'checked="checked"' : '';
		$random_posts                   = $instance['random_posts'] ? 'checked="checked"' : '';
		$child_category                 = $instance['child_category'] ? 'checked="checked"' : '';
		$tag                            = $instance['tag'];
		$view_all_button                = $instance['view_all_button'] ? 'checked="checked"' : '';
		$slider_speed                   = $instance['slider_speed'];
		$slider_pause                   = $instance['slider_pause'];
		$slider_auto                    = $instance['slider_auto'] ? 'checked="checked"' : '';
		$slider_hover                   = $instance['slider_hover'] ? 'checked="checked"' : '';
		?>
		<p><?php _e( 'Layout will be as below:', 'colormag-pro' ) ?></p>
		<div style="text-align: center;"><img src="<?php echo esc_url(get_template_directory_uri() . '/img/style-5.jpg' ) ?>">
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
			<input class="checkbox" <?php echo $slide; ?> id="<?php echo $this->get_field_id( 'slide' ); ?>" name="<?php echo $this->get_field_name( 'slide' ); ?>" type="checkbox" />
			<label for="<?php echo $this->get_field_id( 'slide' ); ?>"><?php _e( 'Check not to have the slider effect for this widget', 'colormag-pro' ); ?></label>
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
		$instance['slide']           = isset( $new_instance['slide'] ) ? 1 : 0;
		$instance['random_posts']    = isset( $new_instance['random_posts'] ) ? 1 : 0;
		$instance['child_category']  = isset( $new_instance['child_category'] ) ? 1 : 0;
		$instance['tag']             = $new_instance['tag'];
		$instance['view_all_button'] = isset( $new_instance['view_all_button'] ) ? 1 : 0;
		$instance['slider_speed']    = absint( $new_instance['slider_speed'] );
		$instance['slider_pause']    = absint( $new_instance['slider_pause'] );
		$instance['slider_auto']     = isset( $new_instance['slider_auto'] ) ? 1 : 0;
		$instance['slider_hover']    = isset( $new_instance['slider_hover'] ) ? 1 : 0;

		return $instance;
	}

	function widget( $args, $instance ) {
		extract( $args );
		extract( $instance );

		global $post;
		$title           = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );
		$text            = isset( $instance['text'] ) ? $instance['text'] : '';
		$number          = empty( $instance['number'] ) ? 4 : $instance['number'];
		$type            = isset( $instance['type'] ) ? $instance['type'] : 'latest';
		$category        = isset( $instance['category'] ) ? $instance['category'] : '';
		$slide           = ! empty( $instance['slide'] ) ? 'true' : 'false';
		$random_posts    = ! empty( $instance['random_posts'] ) ? 'true' : 'false';
		$child_category  = ! empty( $instance['child_category'] ) ? 'true' : 'false';
		$tag             = isset( $instance['tag'] ) ? $instance['tag'] : '';
		$view_all_button = ! empty( $instance['view_all_button'] ) ? 'true' : 'false';
		$slider_speed    = empty( $instance['slider_speed'] ) ? 1500 : $instance['slider_speed'];
		$slider_pause    = empty( $instance['slider_pause'] ) ? 5000 : $instance['slider_pause'];
		$slider_auto     = ! empty( $instance['slider_auto'] ) ? 'true' : 'false';
		$slider_hover    = ! empty( $instance['slider_hover'] ) ? 'true' : 'false';

		if ( ( $slide == 1 ) && ( is_active_widget( false, false, $this->id_base ) || is_customize_preview() || ( function_exists( 'colormag_elementor_active_page_check' ) && colormag_elementor_active_page_check() ) ) ) {
			wp_enqueue_script( 'colormag-bxslider' );
		}
		// For WPML plugin compatibility
		if ( function_exists( 'icl_register_string' ) ) {
			icl_register_string( 'ColorMag Pro', 'TG: Featured Posts (Style 5) Description' . $this->id, $text );
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
		<?php $featured = 'colormag-featured-post-medium'; ?>
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
			$text = icl_t( 'ColorMag Pro', 'TG: Featured Posts (Style 5) Description' . $this->id, $text );
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
			?> <p> <?php echo esc_textarea( $text ); ?> </p> <?php
		}

		if ( $slide == 'false' ) {
			$class       = 'widget_highlighted_post_area';
			$extra_field = ' data-speed="' . absint( $slider_speed ) . '" data-pause="' . absint( $slider_pause ) . '" data-auto="' . esc_html( $slider_auto ) . '" data-hover="' . esc_html( $slider_hover ) . '"';
		} else {
			$class       = 'widget_highlighted_post_area_no_slide';
			$extra_field = '';
		}
		?>
		<div class="widget_block_picture_news_inner_wrap">
			<div id="style5_slider_<?php echo esc_attr( $this->id ); ?>" class="<?php echo $class; ?>" <?php echo $extra_field; ?>>
				<?php
				$i = 1;
				while ( $get_featured_posts->have_posts() ):$get_featured_posts->the_post();

					// Display the reading time dynamically.
					$reading_time       = '';
					$reading_time_class = '';
					if ( get_theme_mod( 'colormag_reading_time_setting', 0 ) == 1 ) {
						$reading_time       = 'data-file="' . get_the_permalink() . '" data-target="article"';
						$reading_time_class = 'readingtime';
					}
					?>
					<div class="single-article <?php echo $reading_time_class; ?>" <?php echo $reading_time; ?>>
						<?php
						if ( has_post_thumbnail() ) {
							$image           = '';
							$title_attribute = get_the_title( $post->ID );
							$image           .= '<figure>';
							$image           .= '<a href="' . get_permalink() . '" title="' . the_title( '', '', false ) . '">';
							$image           .= get_the_post_thumbnail( $post->ID, $featured, array(
									'title' => esc_attr( $title_attribute ),
									'alt'   => esc_attr( $title_attribute ),
								) ) . '</a>';
							$image           .= '</figure>';
							echo $image;
						}
						?>
						<div class="article-content">
							<?php colormag_colored_category(); ?>
							<h3 class="entry-title">
								<a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>"><?php the_title(); ?></a>
							</h3>

							<?php
							$human_diff_time = '';
							if ( get_theme_mod( 'colormag_post_meta_date_setting', 'post_date' ) == 'post_human_readable_date' ) {
								$human_diff_time = 'human-diff-time';
							}
							?>

							<div class="below-entry-meta <?php echo esc_attr( $human_diff_time ); ?>">
								<?php
								$time_string = '<time class="entry-date published" datetime="%1$s">%2$s</time>';
								$time_string = sprintf( $time_string, esc_attr( get_the_date( 'c' ) ), esc_html( get_the_date() )
								);
								printf( __( '<span class="posted-on"><a href="%1$s" title="%2$s" rel="bookmark"><i class="fa fa-calendar-o"></i> %3$s</a></span>', 'colormag-pro' ), esc_url( get_permalink() ), esc_attr( get_the_time() ), $time_string
								);

								if ( get_theme_mod( 'colormag_post_meta_date_setting', 'post_date' ) == 'post_human_readable_date' ) {
									printf( _x( '<span class="posted-on human-diff-time-display">%s ago</span>', '%s = human-readable time difference', 'colormag-pro' ), human_time_diff( get_the_time( 'U' ), current_time( 'timestamp' ) ) );
								}
								?>

								<span class="byline"><span class="author vcard"><a class="url fn n" href="<?php echo esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ); ?>" title="<?php echo get_the_author(); ?>"><?php echo esc_html( get_the_author() ); ?></a></span></span>
								<span class="comments"><svg>
    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#comment-solid"></use>
</svg><?php comments_popup_link( '0', '1', '%' ); ?></span>
								<?php if ( get_theme_mod( 'colormag_reading_time_setting', 0 ) == 1 ) { ?>
									<span class="reading-time">
										<span class="eta"></span> <?php esc_html_e( 'min read', 'colormag-pro' ); ?>
									</span>
								<?php } ?>
							</div>
						</div>
					</div>
					<?php
					$i ++;
				endwhile;
				// Reset Post Data
				wp_reset_query();
				?>
			</div>
		</div>
		<?php
		echo $after_widget;
	}

}
