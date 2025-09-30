<?php
// home/section-3x3-grid.php - Homepage 3x3 Content Grid (restructured)
// This template now receives its data from front-page.php to avoid redundant queries.
global $post, $live_reviews_posts, $interviews_posts; // Ensure variables are available from homepage-queries.php
?>
<div class="full-width-breakout">
  <div class="home-3x3-grid">
    <!-- Live Reviews Column -->
    <div class="home-3x3-col">
      <div class="home-3x3-header">
        <span class="home-3x3-label">Live Reviews</span>
        <a class="home-3x3-archive-link" href="<?php echo esc_url( get_category_link(2608) ); ?>">View All</a>
      </div>
      <div class="home-3x3-list">
        <?php
        if (!empty($live_reviews_posts)) :
          foreach ($live_reviews_posts as $post) :
            setup_postdata($post); ?>
            <a href="<?php the_permalink(); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php the_title_attribute(); ?>">
              <?php if ( has_post_thumbnail() ) : ?>
                <span class="home-3x3-thumb"><?php the_post_thumbnail('medium'); ?></span>
              <?php endif; ?>
              <span class="home-3x3-title"><?php the_title(); ?></span>
              <span class="home-3x3-meta"><?php echo get_the_date(); ?></span>
            </a>
        <?php endforeach; wp_reset_postdata(); else: ?>
            <div class="home-3x3-card home-3x3-empty">No reviews yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Interviews Column -->
    <div class="home-3x3-col">
      <div class="home-3x3-header">
        <span class="home-3x3-label">Interviews</span>
        <a class="home-3x3-archive-link" href="<?php echo esc_url( get_category_link(723) ); ?>">View All</a>
      </div>
      <div class="home-3x3-list">
        <?php
        if (!empty($interviews_posts)) :
          foreach ($interviews_posts as $post) :
            setup_postdata($post); ?>
            <a href="<?php the_permalink(); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php the_title_attribute(); ?>">
              <?php if ( has_post_thumbnail() ) : ?>
                <span class="home-3x3-thumb"><?php the_post_thumbnail('medium'); ?></span>
              <?php endif; ?>
              <span class="home-3x3-title"><?php the_title(); ?></span>
              <span class="home-3x3-meta"><?php echo get_the_date(); ?></span>
            </a>
        <?php endforeach; wp_reset_postdata(); else: ?>
            <div class="home-3x3-card home-3x3-empty">No interviews yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right Column: Stacked Community Activity + Newsletters -->
    <div class="home-3x3-col home-3x3-col-stacked">
      <div class="home-3x3-outer-card">
        <div class="home-3x3-stacked-section">
          <div class="home-3x3-header">
            <span class="home-3x3-label">Community Activity</span>
            <a class="home-3x3-archive-link" href="https://community.extrachill.com">View All</a>
          </div>
          <div class="home-3x3-list home-3x3-community-list">
            <?php include get_template_directory() . '/inc/home/templates/community-activity.php'; ?>
          </div>
        </div>
      </div>
      <div class="home-3x3-outer-card">
        <?php do_action( 'extrachill_home_grid_bottom_right' ); ?>
      </div>
    </div>
  </div>
</div> 