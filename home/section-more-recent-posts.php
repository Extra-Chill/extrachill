<?php
// home/section-more-recent-posts.php - More Recent Posts Row
?>
<div class="home-more-recent-container">
  <h2 class="home-more-recent-header">More Recent Posts</h2>
  <div class="home-more-recent-row">
    <?php
    $exclude_ids = isset($homepage_exclude_ids) ? $homepage_exclude_ids : [];
    $recent_posts = new WP_Query([
      'post_type' => 'post',
      'posts_per_page' => 4,
      'post_status' => 'publish',
      'orderby' => 'date',
      'order' => 'DESC',
      'post__not_in' => $exclude_ids,
    ]);
    if ($recent_posts->have_posts()) :
      while ($recent_posts->have_posts()) : $recent_posts->the_post(); ?>
        <a href="<?php the_permalink(); ?>" class="home-more-recent-card home-more-recent-card-link" aria-label="<?php the_title_attribute(); ?>">
          <?php if ( has_post_thumbnail() ) : ?>
            <span class="home-more-recent-thumb"><?php the_post_thumbnail('medium'); ?></span>
          <?php endif; ?>
          <span class="home-more-recent-title"><?php the_title(); ?></span>
          <span class="home-more-recent-meta"><?php echo get_the_date(); ?></span>
        </a>
    <?php endwhile; wp_reset_postdata(); else: ?>
        <div class="home-more-recent-card home-more-recent-empty">No recent posts.</div>
    <?php endif; ?>
  </div>
  <div class="home-more-recent-footer">
    <a href="/all" class="home-more-recent-viewall">View All</a>
  </div>
</div> 