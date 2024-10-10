<?php $related_posts = colormag_flyout_related_post_query(); ?>

<?php if ( $related_posts->have_posts() ): ?>

	<div id="related-posts-wrapper-flyout" class="related-posts-wrapper-flyout">
		<h4 class="related-posts-flyout-main-title">
			<span><?php echo esc_html__( 'Read Next', 'colormag-pro' ); ?></span>
			<span id="flyout-related-post-close" class="flyout-related-post-close"><i class="fa fa-times" aria-hidden="true"></i></span>
		</h4>

		<div class="related-posts-flyout clearfix">

			<?php while ( $related_posts->have_posts() ) : $related_posts->the_post(); ?>
				<div class="single-related-posts-flyout">

					<?php if ( has_post_thumbnail() ): ?>
						<div class="related-posts-thumbnail">
							<a href="<?php the_permalink(); ?>" title="<?php the_title(); ?>">
								<?php the_post_thumbnail( 'colormag-featured-post-small' ); ?>
							</a>
						</div>
					<?php endif; ?>

					<div class="article-content">

						<h3 class="entry-title">
							<a href="<?php the_permalink(); ?>" rel="bookmark" title="<?php the_title(); ?>"><?php the_title(); ?></a>
						</h3><!--/.post-title-->

						<?php
						$human_diff_time = '';
						if ( get_theme_mod( 'colormag_post_meta_date_setting', 'post_date' ) == 'post_human_readable_date' ) {
							$human_diff_time = 'human-diff-time';
						}
						?>

						<div class="below-entry-meta <?php echo esc_attr( $human_diff_time ); ?>">
							<?php
							$time_string = '<time class="entry-date published" datetime="%1$s">%2$s</time>';
							$time_string = sprintf( $time_string,
								esc_attr( get_the_date( 'c' ) ),
								esc_html( get_the_date() )
							);
							printf( __( '<span class="posted-on"><a href="%1$s" title="%2$s" rel="bookmark"><i class="fa fa-calendar-o"></i> %3$s</a></span>', 'colormag-pro' ),
								esc_url( get_permalink() ),
								esc_attr( get_the_time() ),
								$time_string
							);
							if ( get_theme_mod( 'colormag_post_meta_date_setting', 'post_date' ) == 'post_human_readable_date' ) {
								printf( _x( '<span class="posted-on human-diff-time-display">%s ago</span>', '%s = human-readable time difference', 'colormag-pro' ), human_time_diff( get_the_time( 'U' ), current_time( 'timestamp' ) ) );
							}
							?>

							<span class="comments"><svg>
    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#comment-solid"></use>
</svg><?php comments_popup_link( '0', '1', '%' ); ?></span>
						</div>

					</div>

				</div><!--/.related-->
			<?php endwhile; ?>

		</div><!--/.post-related-->
	</div>
<?php endif; ?>

<?php wp_reset_query(); ?>
