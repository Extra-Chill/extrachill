<?php
/**
 * Homepage More Recent Posts Section
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

global $more_recent_posts;
?>
<div class="home-more-recent-container">
	<h2 class="home-more-recent-header">More Recent Posts</h2>
	<div class="home-more-recent-row">
	<?php
	global $post;
	if ( ! empty( $more_recent_posts ) ) :
		foreach ( $more_recent_posts as $recent_post ) :
			$post = $recent_post;
			setup_postdata( $post );
			?>
		<a href="<?php the_permalink(); ?>" class="home-more-recent-card home-more-recent-card-link" aria-label="<?php the_title_attribute(); ?>">
			<?php if ( has_post_thumbnail() ) : ?>
			<span class="home-more-recent-thumb"><?php the_post_thumbnail( 'medium' ); ?></span>
			<?php endif; ?>
			<span class="home-more-recent-title"><?php the_title(); ?></span>
			<span class="home-more-recent-meta"><?php echo get_the_date(); ?></span>
		</a>
			<?php
		endforeach;
		wp_reset_postdata(); else :
			?>
		<div class="home-more-recent-card home-more-recent-empty">No recent posts.</div>
			<?php endif; ?>
	</div>
	<div class="home-more-recent-footer">
	<a href="/blog" class="home-more-recent-viewall button-3 button-small">View All</a>
	</div>
</div>
