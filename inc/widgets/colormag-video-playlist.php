<?php
/**
 * Video Playlist Widget
 */

class colormag_video_playlist extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'                   => 'widget_video_player',
			'description'                 => __( 'Display video playlist from Video Post Formats.', 'colormag-pro' ),
			'customize_selective_refresh' => true,
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Featured Videos Playlist', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['title']           = '';
		$tg_defaults['text']            = '';
		$tg_defaults['number']          = 4;
		$tg_defaults['type']            = 'latest';
		$tg_defaults['category']        = '';
		$tg_defaults['layout']          = 'vertical';
		$tg_defaults['random_posts']    = '0';
		$tg_defaults['child_category']  = '0';
		$tg_defaults['tag']             = '';
		$tg_defaults['view_all_button'] = '0';
		$instance                       = wp_parse_args( ( array ) $instance, $tg_defaults );
		$title                          = $instance['title'];
		$text                           = esc_textarea( $instance['text'] );
		$number                         = $instance['number'];
		$type                           = $instance['type'];
		$category                       = $instance['category'];
		$layout                         = $instance['layout'];
		$random_posts                   = $instance['random_posts'] ? 'checked="checked"' : '';
		$child_category                 = $instance['child_category'] ? 'checked="checked"' : '';
		$tag                            = $instance['tag'];
		$view_all_button                = $instance['view_all_button'] ? 'checked="checked"' : '';
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>

		<?php esc_html_e( 'Description', 'colormag-pro' ); ?>
		<textarea class="widefat" rows="5" cols="20" id="<?php echo $this->get_field_id( 'text' ); ?>" name="<?php echo $this->get_field_name( 'text' ); ?>"><?php echo $text; ?></textarea>

		<p>
			<label for="<?php echo $this->get_field_id( 'number' ); ?>"><?php _e( 'Number of posts to display:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'number' ); ?>" name="<?php echo $this->get_field_name( 'number' ); ?>" type="text" value="<?php echo absint( $number ); ?>" size="3" />
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
			<label for="<?php echo $this->get_field_id( 'layout' ); ?>"><?php esc_html_e( 'Layout:', 'colormag-pro' ); ?></label>
			<select id="<?php echo $this->get_field_id( 'layout' ); ?>" name="<?php echo $this->get_field_name( 'layout' ); ?>">
				<option value="vertical" <?php selected( $instance['layout'], 'vertical' ); ?>><?php esc_html_e( 'Vertical', 'colormag-pro' ); ?></option>
				<option value="horizontal" <?php selected( $instance['layout'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal', 'colormag-pro' ); ?></option>
			</select>
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

		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance          = $old_instance;
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		if ( current_user_can( 'unfiltered_html' ) ) {
			$instance['text'] = $new_instance['text'];
		} else {
			$instance['text'] = stripslashes( wp_filter_post_kses( addslashes( $new_instance['text'] ) ) );
		}
		$instance['number']          = absint( $new_instance['number'] );
		$instance['type']            = $new_instance['type'];
		$instance['category']        = $new_instance['category'];
		$instance['layout']          = sanitize_text_field( $new_instance['layout'] );
		$instance['random_posts']    = isset( $new_instance['random_posts'] ) ? 1 : 0;
		$instance['child_category']  = isset( $new_instance['child_category'] ) ? 1 : 0;
		$instance['tag']             = $new_instance['tag'];
		$instance['view_all_button'] = isset( $new_instance['view_all_button'] ) ? 1 : 0;

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
		$layout          = isset( $instance['layout'] ) ? $instance['layout'] : 'vertical';
		$random_posts    = ! empty( $instance['random_posts'] ) ? 'true' : 'false';
		$child_category  = ! empty( $instance['child_category'] ) ? 'true' : 'false';
		$tag             = isset( $instance['tag'] ) ? $instance['tag'] : '';
		$view_all_button = ! empty( $instance['view_all_button'] ) ? 'true' : 'false';

		// For WPML plugin compatibility
		if ( function_exists( 'icl_register_string' ) ) {
			icl_register_string( 'ColorMag Pro', 'TG: Featured Videos Playlist Description' . $this->id, $text );
		}

		// adding the excluding post function
		$post__not_in = colormag_exclude_duplicate_posts();

		$args = array(
			'posts_per_page'      => $number,
			'post_type'           => 'post',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'post__not_in'        => $post__not_in,
			'tax_query'           => array(
				array(
					'taxonomy' => 'post_format',
					'field'    => 'slug',
					'terms'    => array( 'post-format-video' ),
				),
			),
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

		if ( is_active_widget( false, false, $this->id_base ) || is_customize_preview() || ( function_exists( 'colormag_elementor_active_page_check' ) && colormag_elementor_active_page_check() ) ) {
			wp_enqueue_script( 'jquery-video' );
		}

		echo $before_widget;
		?>
		<?php
		if ( $category != '-1' ) {
			$border_color = 'style="border-bottom-color:' . colormag_category_color( $category ) . ';"';
			$title_color  = 'style="background-color:' . colormag_category_color( $category ) . ';"';
		} else {
			$border_color = '';
			$title_color  = '';
		}

		// For WPML plugin compatibility
		if ( function_exists( 'icl_t' ) ) {
			$text = icl_t( 'ColorMag Pro', 'TG: Featured Videos Playlist Description' . $this->id, $text );
		}

		// Assign the view all link to be displayed in the widget title
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
		?>
		<div class="video-player video-player--<?php echo esc_attr( $layout ); ?>">
			<?php
			$video_count     = 1;
			$player_output   = '';
			$playlist_output = '';
			while ( $get_featured_posts->have_posts() ):$get_featured_posts->the_post();

				$video_url  = get_post_meta( get_the_ID(), 'video_url', true );
				$embed_data = colormag_get_embed_data( $video_url );

				if ( ! empty( $embed_data ) ) {

					if ( $video_count == 1 ) {
						$player_output .= '<div class="video-frame video-playing">';
						$player_output .= '<iframe class="player-frame" id="video-item-' . $video_count . '" src="' . esc_url( $embed_data['url'] ) . '" frameborder="0" width="100%"" height="434" webkitallowfullscreen mozallowfullscreen allowfullscreen></iframe>';
						$player_output .= '</div><!-- .video-player-wrapper -->';
					}

					$playlist_output .= '<div class="video-playlist-item" data-id="video-item-' . $video_count . '" data-src="' . esc_url( $embed_data['url'] ) . '">';
					$playlist_output .= '<img src="' . esc_url( $embed_data['thumb'] ) . '" />';
					$playlist_output .= '<div class="video-playlist-info">';
					$playlist_output .= '<h2 class="video-playlist-title">' . esc_html( get_the_title() ) . '</h2>';
					$playlist_output .= '<span class="video-duration">Time: 1:04</span>';
					$playlist_output .= '</div>';
					$playlist_output .= '</div>';
				}
				$video_count ++;
			endwhile;
			wp_reset_postdata();

			if ( ! empty( $player_output ) ) {
				echo $player_output;
			}
			if ( ! empty( $playlist_output ) ) {
				?>
				<div class="video-playlist">
					<?php echo $playlist_output; ?>
				</div>
			<?php } ?>
		</div>
		<?php
		echo $after_widget;
	}

}
