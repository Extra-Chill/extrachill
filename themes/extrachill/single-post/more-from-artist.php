<?php
if (!isset($artist_id) || !isset($post_id)) return;
$artist_term = get_term($artist_id, 'artist');
$artist_link = get_term_link($artist_term);
$artist_name = esc_html($artist_term->name);
// Cache related artist posts for better performance
$cache_key = 'artist_posts_' . $artist_id . '_' . $post_id;
$artist_posts_data = get_transient($cache_key);

if ($artist_posts_data === false) {
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
    
    // Store the posts array for caching
    $artist_posts_data = $artist_posts->posts;
    set_transient($cache_key, $artist_posts_data, 3600); // 1 hour cache
} else {
    // Create mock query object for template compatibility
    $artist_posts = new WP_Query();
    $artist_posts->posts = $artist_posts_data;
    $artist_posts->post_count = count($artist_posts_data);
    
    // Initialize required query variables to prevent PHP 8+ undefined array key warnings
    $artist_posts->query_vars = array_merge([
        'fields' => '',
        'update_post_term_cache' => true,
        'update_post_meta_cache' => true,
        'lazy_load_term_meta' => false,
        'ignore_sticky_posts' => false
    ], $artist_posts->query_vars ?? []);
    
    // Set current post index for proper iteration
    $artist_posts->current_post = -1;
}
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