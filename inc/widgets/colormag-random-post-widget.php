<?php
/**
 * Random Post widget
 */

class colormag_random_post_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'                   => 'widget_random_post_colormag widget_featured_posts',
			'description'                 => __( 'Displays the random posts from your site. Suitable for the Right/Left sidebar.', 'colormag-pro' ),
			'customize_selective_refresh' => true,
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Random Posts Widget', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['number'] = 4;
		$tg_defaults['title']  = '';
		$instance              = wp_parse_args( ( array ) $instance, $tg_defaults );
		$number                = $instance['number'];
		$title                 = esc_attr( $instance['title'] );
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo $title; ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'number' ); ?>"><?php _e( 'Number of random posts to display:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'number' ); ?>" name="<?php echo $this->get_field_name( 'number' ); ?>" type="text" value="<?php echo $number; ?>" size="3" />
		</p>
		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance           = $old_instance;
		$instance['number'] = absint( $new_instance['number'] );
		$instance['title']  = strip_tags( $new_instance['title'] );

		return $instance;
	}

	function widget( $args, $instance ) {
		extract( $args );
		extract( $instance );
		$number = empty( $instance['number'] ) ? 4 : $instance['number'];
		$title  = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );

		echo $before_widget;
		?>

		<div class="random-posts-widget">
			<?php
			global $post;

			// adding the excluding post function
			$post__not_in = colormag_exclude_duplicate_posts();

			$get_featured_posts = new WP_Query( array(
				'posts_per_page'      => $number,
				'post_type'           => 'post',
				'ignore_sticky_posts' => true,
				'orderby'             => 'rand',
				'no_found_rows'       => true,
				'post__not_in'        => $post__not_in,
			) );

			colormag_append_excluded_duplicate_posts( $get_featured_posts );
			?>
			<?php $featured = 'colormag-featured-post-small'; ?>
			<?php
			if ( ! empty( $title ) ) {
				echo $before_title . esc_html( $title ) . $after_title;
			}
			?>
			<div class="random_posts_widget_inner_wrap">
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
					<div class="single-article clearfix <?php echo $reading_time_class; ?>" <?php echo $reading_time; ?>>
						<?php
						if ( has_post_thumbnail() ) {
							$image           = '';
							$title_attribute = get_the_title( $post->ID );
							$image           .= '<figure class="random-images">';
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
