<?php
if (!isset($artist_id) || !isset($post_id)) return;
$artist_term = get_term($artist_id, 'artist');
$artist_link = get_term_link($artist_term);
$artist_name = esc_html($artist_term->name);
$artist_posts = new WP_Query([
    'post_type' => 'post',
    'posts_per_page' => 3,
    'post_status' => 'publish',
    'tax_query' => [[
        'taxonomy' => 'artist',
        'field' => 'term_id',
        'terms' => $artist_id,
    ]],
    'post__not_in' => [$post_id],
]);
if ($artist_posts->have_posts()) : ?>
<div class="related-tax-section">
  <h3 class="related-tax-header">More from <a href="<?php echo esc_url($artist_link); ?>" class="sidebar-tax-link"><?php echo $artist_name; ?></a></h3>
  <div class="related-tax-grid">
    <?php while ($artist_posts->have_posts()) : $artist_posts->the_post(); ?>
      <a href="<?php the_permalink(); ?>" class="related-tax-card">
        <?php if (has_post_thumbnail()) : ?>
          <span class="related-tax-thumb"><?php the_post_thumbnail('medium'); ?></span>
        <?php endif; ?>
        <span class="related-tax-title"><?php the_title(); ?></span>
        <span class="related-tax-meta"><?php echo get_the_date(); ?></span>
      </a>
    <?php endwhile; wp_reset_postdata(); ?>
  </div>
</div>
<?php endif; ?> 