<?php
if (function_exists('get_coauthors')) {
    $related_tags = get_the_tags();
    $related_categories = get_the_category();
    $shown_posts = array(); // Create an array to store shown post IDs
    $current_post_id = get_the_ID(); // Store the current post ID
    $current_categories = wp_get_post_categories($current_post_id); // Get the current post categories

    $related_terms = array_merge($related_tags ?: array(), $related_categories ?: array());
    $term_count = 0;

    $current_in_category_2650 = in_array(2650, $current_categories); // Check if the current post is in category 2650

    if ($related_terms) {
        foreach ($related_terms as $term) {
            if ($term_count >= 6) break; // Only show for the first three tags and three categories
            $term_count++;

            $args = array(
                'post_type'      => 'post',
                'posts_per_page' => 10, // Fetch more posts to account for exclusions and randomness
                'post__not_in'   => array_merge(array($current_post_id), $shown_posts), // Exclude current post and already shown posts
            );

            // Check if the term is a tag or a category and set the query accordingly
            if (isset($term->taxonomy)) {
                if ($term->taxonomy == 'category') {
                    $args['cat'] = $term->term_id;
                } elseif ($term->taxonomy == 'post_tag') {
                    $args['tag_id'] = $term->term_id;

                    // Exclude posts by author ID 39 if the current post is not in category 2650 and the term is tag ID 722
                    if ($term->term_id == 722 && !$current_in_category_2650) {
                        $args['author__not_in'] = array(39);
                    }
                }
            }

            // Run the query for related posts
            $related_posts = new WP_Query($args);

            if ($related_posts->have_posts()) {
                $randomized_posts = array();
                while ($related_posts->have_posts()) : $related_posts->the_post();
                    $post_id = get_the_ID();

                    // Check if the post should be excluded based on authorship
                    $exclude_post = false;
                    if ($term->taxonomy == 'post_tag' && $term->term_id == 722 && !$current_in_category_2650) {
                        $coauthors = get_coauthors($post_id);
                        foreach ($coauthors as $coauthor) {
                            if ($coauthor->ID == 39) {
                                $exclude_post = true;
                                break;
                            }
                        }
                    }

                    if ($post_id == $current_post_id || in_array($post_id, $shown_posts) || $exclude_post) continue; // Skip current, duplicate, and excluded posts

                    $randomized_posts[] = $post_id; // Collect valid post IDs for randomization
                endwhile;

                // Shuffle the collected post IDs to randomize
                shuffle($randomized_posts);

                if (!empty($randomized_posts)) {
                    echo '<div class="related-posts-wrapper style-one">';
                    echo '<h2 class="related-posts-main-title"><span>More from <a href="' . esc_url(get_term_link($term)) . '">' . esc_html($term->name) . '</a></span></h2>';
                    echo '<div class="related-posts clearfix">';

                    $displayed_count = 0;
                    foreach ($randomized_posts as $post_id) {
                        if ($displayed_count >= 3) break;
                        $post = get_post($post_id);
                        setup_postdata($post);
                        $shown_posts[] = $post_id; // Add the post ID to the shown posts array
                        $displayed_count++; // Increment the count of displayed posts

                        echo '<div class="single-related-posts">';
                        if (has_post_thumbnail()) {
                            echo '<div class="related-posts-thumbnail"><a href="' . get_permalink($post_id) . '" title="' . get_the_title($post_id) . '">' . get_the_post_thumbnail($post_id, 'medium') . '</a></div>';
                        }
                        echo '<div class="article-content">';
                        echo '<div class="upvote"><span class="upvote-icon" data-post-id="' . $post_id . '" data-nonce="' . wp_create_nonce('upvote_nonce') . '" data-community-user-id=""><svg><use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#circle-up-regular"></use></svg></span> <span class="upvote-count">' . get_upvote_count($post_id) . '</span> | </div>';
                        echo '<h3 class="entry-title"><a href="' . get_permalink($post_id) . '" title="' . the_title_attribute(array('post' => $post_id, 'echo' => false)) . '">' . get_the_title($post_id) . '</a></h3>';
                        echo '<div class="below-entry-meta"><time class="entry-date published" datetime="' . esc_attr(get_the_date('c', $post_id)) . '">' . esc_html(get_the_date('', $post_id)) . '</time>';
                        echo '</div></div></div>';
                    }

                    echo '</div></div>';
                }
                wp_reset_postdata();
            }
        }
    }
}
