<?php
/**
 * Breaking News widget
 */

class colormag_breaking_news_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'   => 'widget_breaking_news_colormag widget_featured_posts',
			'description' => __( 'Displays the breaking news in the news ticker way. Suitable for the Right/Left Sidebar', 'colormag-pro' ),
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Breaking News Widget', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['number']           = 4;
		$tg_defaults['title']            = '';
		$tg_defaults['type']             = 'latest';
		$tg_defaults['category']         = '';
		$tg_defaults['random_posts']     = '0';
		$tg_defaults['child_category']   = '0';
		$tg_defaults['tag']              = '';
		$tg_defaults['view_all_button']  = '0';
		$tg_defaults['slide_direction']  = 'up';
		$tg_defaults['slide_duration']   = 4000;
		$tg_defaults['slide_row_height'] = 100;
		$tg_defaults['slide_max_rows']   = 3;
		$instance                        = wp_parse_args( ( array ) $instance, $tg_defaults );
		$number                          = $instance['number'];
		$title                           = esc_attr( $instance['title'] );
		$type                            = $instance['type'];
		$category                        = $instance['category'];
		$random_posts                    = $instance['random_posts'] ? 'checked="checked"' : '';
		$child_category                  = $instance['child_category'] ? 'checked="checked"' : '';
		$tag                             = $instance['tag'];
		$view_all_button                 = $instance['view_all_button'] ? 'checked="checked"' : '';
		$slide_direction                 = $instance['slide_direction'];
		$slide_duration                  = $instance['slide_duration'];
		$slide_row_height                = $instance['slide_row_height'];
		$slide_max_rows                  = $instance['slide_max_rows'];
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo $title; ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'number' ); ?>"><?php _e( 'Number of recent posts to show as the breaking news:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'number' ); ?>" name="<?php echo $this->get_field_name( 'number' ); ?>" type="text" value="<?php echo $number; ?>" size="3" />
		</p>

		<p>
			<input type="radio" <?php checked( $type, 'latest' ) ?> id="<?php echo $this->get_field_id( 'type' ); ?>" name="<?php echo $this->get_field_name( 'type' ); ?>" value="latest" /><?php _e( 'Show latest Posts', 'colormag-pro' ); ?>
			<br />
			<input type="radio" <?php checked( $type, 'category' ) ?> id="<?php echo $this->get_field_id( 'type' ); ?>" name="<?php echo $this->get_field_name( 'type' ); ?>" value="category" /><?php _e( 'Show posts from a category', 'colormag-pro' ); ?>
			<br />
			<input type="radio" <?php checked( $type, 'tag' ) ?> id="<?php echo $this->get_field_id( 'type' ); ?>" name="<?php echo $this->get_field_name( 'type' ); ?>" value="tag" /><?php _e( 'Show posts from a tag', 'colormag-pro' ); ?>
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
			<label for="<?php echo $this->get_field_id( 'random_posts' ); ?>"><?php esc_html_e( 'Check to display the random post from either the chosen category or from latest post.', 'colormag-pro' ); ?></label>
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
			<?php esc_html_e( 'Slide Options', 'colormag-pro' ); ?>
			<hr>
		</h2>
		<p>
			<label for="<?php echo $this->get_field_id( 'slide_direction' ); ?>"><?php esc_html_e( 'Slide Direction:', 'colormag-pro' ); ?></label>
			<select id="<?php echo $this->get_field_id( 'slide_direction' ); ?>" name="<?php echo $this->get_field_name( 'slide_direction' ); ?>">
				<option value="up" <?php selected( $instance['slide_direction'], 'up' ); ?>><?php esc_html_e( 'Up', 'colormag-pro' ); ?></option>
				<option value="down" <?php selected( $instance['slide_direction'], 'down' ); ?>><?php esc_html_e( 'Down', 'colormag-pro' ); ?></option>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'slide_duration' ); ?>"><?php esc_html_e( 'Slide Duration Time (in ms):', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'slide_duration' ); ?>" name="<?php echo $this->get_field_name( 'slide_duration' ); ?>" type="text" value="<?php echo esc_attr( $slide_duration ); ?>" size="3" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'slide_row_height' ); ?>"><?php esc_html_e( 'Slide Row Height (in px):', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'slide_row_height' ); ?>" name="<?php echo $this->get_field_name( 'slide_row_height' ); ?>" type="text" value="<?php echo esc_attr( $slide_row_height ); ?>" size="3" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'slide_max_rows' ); ?>"><?php esc_html_e( 'Maximum Slide Rows:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'slide_max_rows' ); ?>" name="<?php echo $this->get_field_name( 'slide_max_rows' ); ?>" type="text" value="<?php echo esc_attr( $slide_max_rows ); ?>" size="3" />
		</p>

		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance                     = $old_instance;
		$instance['number']           = absint( $new_instance['number'] );
		$instance['title']            = strip_tags( $new_instance['title'] );
		$instance['type']             = $new_instance['type'];
		$instance['category']         = $new_instance['category'];
		$instance['random_posts']     = isset( $new_instance['random_posts'] ) ? 1 : 0;
		$instance['child_category']   = isset( $new_instance['child_category'] ) ? 1 : 0;
		$instance['tag']              = $new_instance['tag'];
		$instance['view_all_button']  = isset( $new_instance['view_all_button'] ) ? 1 : 0;
		$instance['slide_direction']  = $new_instance['slide_direction'];
		$instance['slide_duration']   = absint( $new_instance['slide_duration'] );
		$instance['slide_row_height'] = absint( $new_instance['slide_row_height'] );
		$instance['slide_max_rows']   = absint( $new_instance['slide_max_rows'] );

		return $instance;
	}

	function widget( $args, $instance ) {
		if ( is_active_widget( false, false, $this->id_base ) || is_customize_preview() || ( function_exists( 'colormag_elementor_active_page_check' ) && colormag_elementor_active_page_check() ) ) {
			wp_enqueue_script( 'colormag-news-ticker' );
		}

		extract( $args );
		extract( $instance );
		$number           = empty( $instance['number'] ) ? 4 : $instance['number'];
		$title            = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );
		$type             = isset( $instance['type'] ) ? $instance['type'] : 'latest';
		$category         = isset( $instance['category'] ) ? $instance['category'] : '';
		$random_posts     = ! empty( $instance['random_posts'] ) ? 'true' : 'false';
		$child_category   = ! empty( $instance['child_category'] ) ? 'true' : 'false';
		$tag              = isset( $instance['tag'] ) ? $instance['tag'] : '';
		$view_all_button  = ! empty( $instance['view_all_button'] ) ? 'true' : 'false';
		$slide_direction  = isset( $instance['slide_direction'] ) ? $instance['slide_direction'] : 'up';
		$slide_duration   = empty( $instance['slide_duration'] ) ? 4000 : $instance['slide_duration'];
		$slide_row_height = empty( $instance['slide_row_height'] ) ? 100 : $instance['slide_row_height'];
		$slide_max_rows   = empty( $instance['slide_max_rows'] ) ? 3 : $instance['slide_max_rows'];

		echo $before_widget;

		global $post;

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
		?>
		<?php $featured = 'colormag-featured-post-small'; ?>
		<?php
		if ( $type != 'latest' ) {
			$border_color = 'style="border-bottom-color:' . colormag_category_color( $category ) . ';"';
			$title_color  = 'style="background-color:' . colormag_category_color( $category ) . ';"';
		} else {
			$border_color = '';
			$title_color  = '';
		}

		// assign the view all link to be displayed in the widget title
		$category_link = '';
		if ( $view_all_button == 'true' && ( ! empty( $category ) && $type != 'latest' ) ) {
			$category_link = '<a href="' . esc_url( get_category_link( $category ) ) . '" class="view-all-link">' . get_theme_mod( 'colormag_view_all_text', esc_html__( 'View All', 'colormag-pro' ) ) . '</a>';
		}

		if ( ! empty( $title ) ) {
			echo '<h3 class="widget-title" ' . $border_color . '><span ' . $title_color . '>' . esc_html( $title ) . '</span>' . $category_link . '</h3>';
		}
		?>

		<div class="breaking_news_widget_inner_wrap">
			<i class="fa fa-arrow-up" id="breaking-news-widget-prev_<?php echo $this->id; ?>"></i>
			<ul id="breaking-news-widget_<?php echo $this->id; ?>" class="breaking-news-widget-slide" data-direction="<?php echo esc_attr( $slide_direction ); ?>" data-duration="<?php echo esc_attr( $slide_duration ); ?>" data-rowheight="<?php echo esc_attr( $slide_row_height ); ?>" data-maxrows="<?php echo esc_attr( $slide_max_rows ); ?>">
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
					<li class="single-article clearfix <?php echo $reading_time_class; ?>" <?php echo $reading_time; ?>>
						<?php
						if ( has_post_thumbnail() ) {
							$image           = '';
							$title_attribute = get_the_title( $post->ID );
							$image           .= '<figure class="tabbed-images">';
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
</svg><?php comments_popup_link( __( 'No Comments', 'colormag-pro' ), __( '1 Comment', 'colormag-pro' ), __( '% Comments', 'colormag-pro' ) ); ?></span>
								<?php if ( get_theme_mod( 'colormag_reading_time_setting', 0 ) == 1 ) { ?>
									<span class="reading-time">
										<span class="eta"></span> <?php esc_html_e( 'min read', 'colormag-pro' ); ?>
									</span>
								<?php } ?>
							</div>
						</div>
					</li>
					<?php
					$i ++;
				endwhile;
				// Reset Post Data
				wp_reset_query();
				?>
			</ul>
			<i class="fa fa-arrow-down" id="breaking-news-widget-next_<?php echo $this->id; ?>"></i>
		</div>
		<?php
		echo $after_widget;
	}

}
