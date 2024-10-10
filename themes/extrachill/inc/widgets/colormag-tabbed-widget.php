<?php
/**
 * Tabbed widget
 */

class colormag_tabbed_widget extends WP_Widget {

	function __construct() {
		$widget_ops  = array(
			'classname'   => 'widget_tabbed_colormag widget_featured_posts',
			'description' => __( 'Displays the popular posts, latest posts and the recent comments in tab. Suitable for the Right/Left sidebar.', 'colormag-pro' ),
		);
		$control_ops = array( 'width' => 200, 'height' => 250 );
		parent::__construct( false, $name = __( 'TG: Tabbed Widget', 'colormag-pro' ), $widget_ops );
	}

	function form( $instance ) {
		$tg_defaults['number']             = 4;
		$tg_defaults['popular_view_count'] = '0';
		$instance                          = wp_parse_args( ( array ) $instance, $tg_defaults );
		$number                            = $instance['number'];
		$popular_view_count                = $instance['popular_view_count'] ? 'checked="checked"' : '';
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'number' ); ?>"><?php _e( 'Number of popular posts, recent posts and comments to display:', 'colormag-pro' ); ?></label>
			<input id="<?php echo $this->get_field_id( 'number' ); ?>" name="<?php echo $this->get_field_name( 'number' ); ?>" type="text" value="<?php echo $number; ?>" size="3" />
		</p>

		<p>
			<input class="checkbox" <?php echo $popular_view_count; ?> id="<?php echo $this->get_field_id( 'popular_view_count' ); ?>" name="<?php echo $this->get_field_name( 'popular_view_count' ); ?>" type="checkbox" />
			<label for="<?php echo $this->get_field_id( 'popular_view_count' ); ?>"><?php _e( 'Check to enable the popular post by view count.', 'colormag-pro' ); ?></label>
		</p>
		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance                       = $old_instance;
		$instance['number']             = absint( $new_instance['number'] );
		$instance['popular_view_count'] = isset( $new_instance['popular_view_count'] ) ? 1 : 0;

		return $instance;
	}

	function widget( $args, $instance ) {
		if ( is_active_widget( false, false, $this->id_base ) || is_customize_preview() || ( function_exists( 'colormag_elementor_active_page_check' ) && colormag_elementor_active_page_check() ) ) {
			wp_enqueue_script( 'colormag-easy-tabs' );
		}

		extract( $args );
		extract( $instance );
		$number             = empty( $instance['number'] ) ? 4 : $instance['number'];
		$popular_view_count = ! empty( $instance['popular_view_count'] ) ? 'true' : 'false';

		echo $before_widget;
		?>

		<div class="tabbed-widget">
			<ul class="widget-tabs">
				<li class="tabs popular-tabs">
					<a href="#popular"><?php _e( '<i class="fa fa-star"></i>Popular', 'colormag-pro' ); ?></a></li>
				<li class="tabs recent-tabs">
					<a href="#recent"><?php _e( '<i class="fa fa-history"></i>Recent', 'colormag-pro' ); ?></a></li>
				<li class="tabs comment-tabs">
					<a href="#comment"><?php _e( '<svg>
    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#comment-solid"></use>
</svg>Comment', 'colormag-pro' ); ?></a></li>
			</ul>

			<div class="tabbed-widget-popular" id="popular">
				<?php
				global $post;

				$args = array();
				if ( $popular_view_count == 'false' ) {
					$args = array(
						'posts_per_page'      => $number,
						'post_type'           => 'post',
						'ignore_sticky_posts' => true,
						'orderby'             => 'comment_count',
						'no_found_rows'       => true,
					);
				} else {
					$args = array(
						'posts_per_page'      => $number,
						'post_type'           => 'post',
						'ignore_sticky_posts' => true,
						'meta_key'            => 'total_number_of_views',
						'orderby'             => 'meta_value_num',
						'order'               => 'DESC',
						'no_found_rows'       => true,
					);
				}

				$get_featured_posts = new WP_Query( $args );
				?>
				<?php $featured = 'colormag-featured-post-small'; ?>
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

					</div>
					<?php
					$i ++;
				endwhile;
				// Reset Post Data
				wp_reset_query();
				?>
			</div>

			<div class="tabbed-widget-recent" id="recent">
				<?php
				global $post;

				$get_featured_posts = new WP_Query( array(
					'posts_per_page'      => $number,
					'post_type'           => 'post',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				) );
				?>
				<?php $featured = 'colormag-featured-post-small'; ?>
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

			<div class="tabbed-widget-comment" id="comment">
				<?php
				$comments_query = new WP_Comment_Query();
				$comments       = $comments_query->query( array( 'number' => $number, 'status' => 'approve' ) );
				$commented      = '';
				if ( $comments ) : foreach ( $comments as $comment ) :
					$commented .= '<li class="tabbed-comment-widget"><a class="author" href="' . get_permalink( $comment->comment_post_ID ) . '#comment-' . $comment->comment_ID . '">';
					$commented .= get_avatar( $comment->comment_author_email, '50' );
					$commented .= get_comment_author( $comment->comment_ID ) . '</a>' . ' ' . __( 'says:', 'colormag-pro' );
					$commented .= '<p class="commented">' . strip_tags( substr( apply_filters( 'get_comment_text', $comment->comment_content ), 0, '50' ) ) . '...</p></li>';
				endforeach;
				else :
					$commented .= __( 'No comments', 'colormag-pro' );
				endif;
				echo $commented;
				?>
			</div>

		</div>
		<?php
		echo $after_widget;
	}

}
