<?php
/**
 * Related Posts Display for Single Posts
 *
 * Shows related posts based on shared taxonomy terms (artist, venue).
 * Includes 1-hour caching for performance optimization.
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Display related posts from the same taxonomy term
 *
 * Shows up to 3 related posts with thumbnails and meta information.
 * Uses transient caching for improved performance.
 *
 * @param string $taxonomy The taxonomy to query (e.g., 'artist', 'venue')
 * @param int $post_id The current post ID to exclude from results
 * @since 1.0
 */
function extrachill_display_related_posts($taxonomy, $post_id) {
    if (!in_array($taxonomy, ['artist', 'venue'])) {
        return;
    }

    $terms = get_the_terms($post_id, $taxonomy);
    if (!$terms || is_wp_error($terms)) {
        return;
    }

    $term = $terms[0];
    $term_id = $term->term_id;
    $term_link = get_term_link($term);
    $term_name = esc_html($term->name);

    // Cache related posts for better performance
    $cache_key = $taxonomy . '_posts_' . $term_id . '_' . $post_id;
    $related_posts_data = get_transient($cache_key);

    if ($related_posts_data === false) {
        $related_posts = new WP_Query([
            'post_type' => 'post',
            'posts_per_page' => 3,
            'post_status' => 'publish',
            'tax_query' => [[
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term_id,
            ]],
            'post__not_in' => [$post_id],
        ]);

        // Store the posts array for caching
        $related_posts_data = $related_posts->posts;
        set_transient($cache_key, $related_posts_data, 3600); // 1 hour cache
    } else {
        // Create mock query object for template compatibility
        $related_posts = new WP_Query();
        $related_posts->posts = $related_posts_data;
        $related_posts->post_count = count($related_posts_data);

        // Initialize required query variables to prevent PHP 8+ undefined array key warnings
        $related_posts->query_vars = array_merge([
            'fields' => '',
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
            'lazy_load_term_meta' => false,
            'ignore_sticky_posts' => false
        ], $related_posts->query_vars ?? []);

        // Set current post index for proper iteration
        $related_posts->current_post = -1;
    }

    if ($related_posts->have_posts()) : ?>
        <div class="related-tax-section">
            <h3 class="related-tax-header">More from <a href="<?php echo esc_url($term_link); ?>" class="sidebar-tax-link"><?php echo $term_name; ?></a></h3>
            <div class="related-tax-grid">
                <?php while ($related_posts->have_posts()) : $related_posts->the_post(); ?>
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
    <?php endif;
}