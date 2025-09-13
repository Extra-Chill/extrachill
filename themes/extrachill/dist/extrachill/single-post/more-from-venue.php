<?php
if (!isset($venue_id) || !isset($post_id)) return;
$venue_term = get_term($venue_id, 'venue');
$venue_link = get_term_link($venue_term);
$venue_name = esc_html($venue_term->name);
// Cache related venue posts for better performance
$cache_key = 'venue_posts_' . $venue_id . '_' . $post_id;
$venue_posts_data = get_transient($cache_key);

if ($venue_posts_data === false) {
    $venue_posts = new WP_Query([
        'post_type' => 'post',
        'posts_per_page' => 3,
        'post_status' => 'publish',
        'tax_query' => [[
            'taxonomy' => 'venue',
            'field' => 'term_id',
            'terms' => $venue_id,
        ]],
        'post__not_in' => [$post_id],
    ]);
    
    // Store the posts array for caching
    $venue_posts_data = $venue_posts->posts;
    set_transient($cache_key, $venue_posts_data, 3600); // 1 hour cache
} else {
    // Create mock query object for template compatibility
    $venue_posts = new WP_Query();
    $venue_posts->posts = $venue_posts_data;
    $venue_posts->post_count = count($venue_posts_data);
    
    // Initialize required query variables to prevent PHP 8+ undefined array key warnings
    $venue_posts->query_vars = array_merge([
        'fields' => '',
        'update_post_term_cache' => true,
        'update_post_meta_cache' => true,
        'lazy_load_term_meta' => false,
        'ignore_sticky_posts' => false
    ], $venue_posts->query_vars ?? []);
    
    // Set current post index for proper iteration
    $venue_posts->current_post = -1;
}
if ($venue_posts->have_posts()) : ?>
<div class="related-tax-section">
  <h3 class="related-tax-header">More from <a href="<?php echo esc_url($venue_link); ?>" class="sidebar-tax-link"><?php echo $venue_name; ?></a></h3>
  <div class="related-tax-grid">
    <?php while ($venue_posts->have_posts()) : $venue_posts->the_post(); ?>
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