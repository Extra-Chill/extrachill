<?php
/**
 * Highlighted Posts widget
 */

class colormag_highlighted_posts_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'                   => 'widget_highlighted_posts widget_featured_meta',
			'description'                 => __( 'Display latest posts or posts of specific category. Suitable for the Area Beside Slider Sidebar.', 'colormag-pro' ),
			'customize_selective_refresh' => true,
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Highligted Posts', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['number']         = 4;
		$tg_defaults['type']           = 'latest';
		$tg_defaults['category']       = '';
		$tg_defaults['random_posts']   = '0';
		$tg_defaults['child_category'] = '0';
		$tg_defaults['tag']            = '';
		$instance                      = wp_parse_args( ( array ) $instance, $tg_defaults );
		$number                        = $instance['number'];
		$type                          = $instance['type'];
		$category                      = $instance['category'];
		$random_posts                  = $instance['random_posts'] ? 'checked="checked"' : '';
		$child_category                = $instance['child_category'] ? 'checked="checked"' : '';
$tag = $instance['tag'] ?? '';
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'number' ); ?>"><?php _e( 'Number of posts to display:', 'colormag-pro' ); ?></label>
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
			<label for="<?php echo $this->get_field_id( 'random_posts' ); ?>"><?php _e( 'Check to display the random post from either the chosen category or from latest post.', 'colormag-pro' ); ?></label>
		</p>

		<p>
			<input class="checkbox" <?php echo $child_category; ?> id="<?php echo $this->get_field_id( 'child_category' ); ?>" name="<?php echo $this->get_field_name( 'child_category' ); ?>" type="checkbox" />
			<label for="<?php echo $this->get_field_id( 'child_category' ); ?>"><?php _e( 'Check to display the posts from child category of the chosen category.', 'colormag-pro' ); ?></label>
		</p>
		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance                   = $old_instance;
		$instance['number']         = absint( $new_instance['number'] );
		$instance['type']           = $new_instance['type'];
		$instance['category']       = $new_instance['category'];
		$instance['random_posts']   = isset( $new_instance['random_posts'] ) ? 1 : 0;
		$instance['child_category'] = isset( $new_instance['child_category'] ) ? 1 : 0;
		$instance['tag']            = $new_instance['tag'];

		return $instance;
	}

	function widget( $args, $instance ) {
		extract( $args );
		extract( $instance );

		global $post;
		$number         = empty( $instance['number'] ) ? 4 : $instance['number'];
		$type           = isset( $instance['type'] ) ? $instance['type'] : 'latest';
		$category       = isset( $instance['category'] ) ? $instance['category'] : '';
		$random_posts   = ! empty( $instance['random_posts'] ) ? 'true' : 'false';
		$child_category = ! empty( $instance['child_category'] ) ? 'true' : 'false';
		$tag            = isset( $instance['tag'] ) ? $instance['tag'] : '';

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
		<div class="widget_highlighted_post_area">
			<?php $featured = 'colormag-highlighted-post'; ?>
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
						$image           .= '<div class="highlights-featured-image">';
						$image           .= '<a href="' . get_permalink() . '" title="' . the_title( '', '', false ) . '">';
						$image           .= get_the_post_thumbnail( $post->ID, $featured, array(
								'title' => esc_attr( $title_attribute ),
								'alt'   => esc_attr( $title_attribute ),
							) ) . '</a>';
						$image           .= '</div>';
						echo $image;
					} else {
						?>
						<a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
							<img src="<?php echo esc_url(get_template_directory_uri()); ?>/img/highlights-featured-image.png">
						</a>
					<?php }
					?>
					<div class="article-content">
<div class="upvote">
        <span class="upvote-icon" data-post-id="<?php the_ID(); ?>" data-nonce="<?php echo wp_create_nonce('upvote_nonce'); ?>" data-community-user-id="">
            <svg>
    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#circle-up-regular"></use>
</svg>
        </span>
        <span class="upvote-count"><?php echo get_upvote_count(get_the_ID()); ?></span> | 
	</div>
							<h3 class="entry-title">
								<a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>"><?php the_title(); ?></a>
							</h3>

						<?php
						$human_diff_time = '';
						if ( get_theme_mod( 'colormag_post_meta_date_setting', 'post_date' ) == 'post_human_readable_date' ) {
							$human_diff_time = 'human-diff-time';
						}
						?>

					</div>

				</div>
				<?php
				$i ++;
			endwhile;
			// Reset Post Data
			wp_reset_query();
			?>
		</div>
		<?php
		echo $after_widget;
	}

}
