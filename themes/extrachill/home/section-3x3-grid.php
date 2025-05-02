<?php
// home/section-3x3-grid.php - Homepage 3x3 Content Grid (restructured)
?>
<div class="home-3x3-grid-container">
  <div class="home-3x3-grid">
    <!-- Live Reviews Column -->
    <div class="home-3x3-col">
      <div class="home-3x3-header">
        <span class="home-3x3-label">Live Reviews</span>
        <a class="home-3x3-archive-link" href="<?php echo esc_url( get_category_link(2608) ); ?>">View All</a>
      </div>
      <div class="home-3x3-list">
        <?php
        $live_reviews = new WP_Query([
          'cat' => 2608, // TODO: Replace with actual Live Reviews category ID
          'posts_per_page' => 3,
          'post_status' => 'publish',
        ]);
        if ($live_reviews->have_posts()) :
          while ($live_reviews->have_posts()) : $live_reviews->the_post(); ?>
            <a href="<?php the_permalink(); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php the_title_attribute(); ?>">
              <?php if ( has_post_thumbnail() ) : ?>
                <span class="home-3x3-thumb"><?php the_post_thumbnail('medium'); ?></span>
              <?php endif; ?>
              <span class="home-3x3-title"><?php the_title(); ?></span>
              <span class="home-3x3-meta"><?php echo get_the_date(); ?></span>
            </a>
        <?php endwhile; wp_reset_postdata(); else: ?>
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
        $interviews = new WP_Query([
          'cat' => 723, // TODO: Replace with actual Interviews category ID
          'posts_per_page' => 3,
          'post_status' => 'publish',
        ]);
        if ($interviews->have_posts()) :
          while ($interviews->have_posts()) : $interviews->the_post(); ?>
            <a href="<?php the_permalink(); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php the_title_attribute(); ?>">
              <?php if ( has_post_thumbnail() ) : ?>
                <span class="home-3x3-thumb"><?php the_post_thumbnail('medium'); ?></span>
              <?php endif; ?>
              <span class="home-3x3-title"><?php the_title(); ?></span>
              <span class="home-3x3-meta"><?php echo get_the_date(); ?></span>
            </a>
        <?php endwhile; wp_reset_postdata(); else: ?>
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
            <?php
            $activity_html = do_shortcode('[extrachill_recent_activity]');
            if (preg_match_all('/<li>(.*?)<\/li>/s', $activity_html, $matches)) {
              $count = 0;
              foreach ($matches[0] as $li) {
                if ($count++ < 3) {
                  echo '<div class="home-3x3-card home-3x3-community-card">' . $li . '</div>';
                }
              }
            } else {
              echo '<div class="home-3x3-card home-3x3-empty">No recent activity.</div>';
            }
            ?>
          </div>
        </div>
      </div>
      <div class="home-3x3-outer-card">
        <div class="home-3x3-stacked-section">
          <div class="home-3x3-header">
            <span class="home-3x3-label">Latest Newsletters</span>
            <a class="home-3x3-archive-link" href="<?php echo esc_url( get_post_type_archive_link('newsletter') ); ?>">View All</a>
          </div>
          <div class="home-3x3-list">
            <?php
            $newsletters = new WP_Query([
              'post_type' => 'newsletter',
              'posts_per_page' => 3,
              'post_status' => 'publish',
            ]);
            if ($newsletters->have_posts()) :
              while ($newsletters->have_posts()) : $newsletters->the_post(); ?>
                <a href="<?php the_permalink(); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php the_title_attribute(); ?>">
                  <span class="home-3x3-title"><?php the_title(); ?></span>
                  <span class="home-3x3-meta">Sent <?php echo get_the_date(); ?></span>
                </a>
            <?php endwhile; wp_reset_postdata(); else: ?>
                <div class="home-3x3-card home-3x3-empty">No newsletters yet.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div> 