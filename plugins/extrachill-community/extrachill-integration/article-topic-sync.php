<?php
add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/sync-article', array(
        'methods' => 'POST',
        'callback' => 'handle_article_sync',
        'permission_callback' => '__return_true',
    ));
});

function handle_article_sync($request) {
    $params = $request->get_json_params();
    $title = $params['title'] ?? '';
    $content = $params['content'] ?? '';
    $author_id = $params['author_id'] ?? 0;
    $main_site_post_id = $params['main_site_post_id'] ?? 0;
    $post_url = $params['post_url'] ?? '';
    $posted_on = $params['published_date'] ?? '';

    if (empty($title) || empty($content) || empty($author_id) || empty($main_site_post_id) || empty($post_url) || empty($posted_on)) {
        return new WP_Error('missing_data', 'Missing required data', array('status' => 400));
    }

    $content = clean_up_content($content);
    $formatted_posted_on_date = format_posted_on_date($posted_on);
    $prepend_note = create_prepend_note($title, $post_url, $formatted_posted_on_date);
    $content = $prepend_note . $content;

    return process_article_sync($title, $content, $author_id, $main_site_post_id, 1494, $post_url);
}

function extrachill_yoast_canonical_override($canonical) {
    if (function_exists('is_singular') && is_singular('topic')) {
        $forum_id = bbp_get_forum_id(bbp_get_topic_forum_id());
        if ($forum_id == 1494) {
            $topic_id = get_the_ID();
            $canonical_url = get_post_meta($topic_id, 'extrachill_post_url', true);
            if (!empty($canonical_url)) {
                return $canonical_url;
            }
        }
    }
    return $canonical; // Return default if not a topic in forum 1494 or if no URL is set
}
add_filter('wpseo_canonical', 'extrachill_yoast_canonical_override');


function clean_up_content($content) {
    // Remove unnecessary classes
    $content = preg_replace('/class="[^"]*aligncenter[^"]*"/i', '', $content);

    // Extended pattern to match iframe embeds or direct URLs in the wp-block-embed wrapper
    $blockPattern = '/<figure class="[^"]*wp-block-embed[^"]*">.*?<div class="wp-block-embed__wrapper">(.*?)<\/div>.*?<\/figure>/is';

    // Replacement pattern to convert iframes or direct URLs to plain text URLs
    $content = preg_replace_callback($blockPattern, function ($matches) {
        // Check if the matched content is a URL or an iframe
        $innerContent = trim($matches[1]);

        // If it's an iframe, extract the src attribute
        if (preg_match('/<iframe[^>]+src="([^"]+)"[^>]*><\/iframe>/', $innerContent, $iframeMatch)) {
            $url = html_entity_decode($iframeMatch[1]);
        } else {
            // Assume it's a direct URL
            $url = html_entity_decode(strip_tags($innerContent));
        }

        // Extract the main URL before any parameters (for YouTube, Spotify, etc.)
        $parsedUrl = parse_url($url);
        $cleanUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $parsedUrl['path'];

        // Special handling for Spotify URLs to convert embed URLs to standard URLs
        if (strpos($cleanUrl, 'open.spotify.com/embed') !== false) {
            $cleanUrl = str_replace('/embed', '', $cleanUrl);
        }

        // Return the clean URL for insertion as plain text
        return $cleanUrl;
    }, $content);

    // Return the modified content
    return $content;
}




function format_posted_on_date($posted_on) {
    return date_i18n('F j, Y', strtotime($posted_on));
}

function create_prepend_note($title, $post_url, $formatted_posted_on_date) {
    return "<b><a href='{$post_url}'>{$title}</a></b>\n<b>Published:</b> {$formatted_posted_on_date}\n\n";
}

function process_article_sync($title, $content, $author_id, $main_site_post_id, $forum_id, $post_url) { // Include $post_url as a parameter
    $existing_topic_query = check_existing_topic($main_site_post_id);

    if ($existing_topic_query->have_posts()) {
        $existing_topic = array_shift($existing_topic_query->posts);
        $update_data = [
            'ID' => $existing_topic->ID,
            'post_title' => $title,
            'post_content' => $content,
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', 1),
        ];
        wp_update_post($update_data);

        // Update main site post ID and post URL meta
        update_post_meta($existing_topic->ID, 'main_site_post_id', $main_site_post_id);
        update_post_meta($existing_topic->ID, 'extrachill_post_url', $post_url); // Saving post URL as meta

        $topic_id = $existing_topic->ID;
        $response_message = 'Topic updated successfully.';
    } else {
        $topic_id = bbp_insert_topic([
            'post_parent' => $forum_id,
            'post_author' => $author_id,
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => bbp_get_public_status_id(),
            'post_type' => bbp_get_topic_post_type(),
            'comment_status' => 'closed',
        ]);
        if (!is_wp_error($topic_id)) {
            // Update main site post ID and post URL meta for new topics
            update_post_meta($topic_id, 'main_site_post_id', $main_site_post_id);
            update_post_meta($topic_id, 'extrachill_post_url', $post_url); // Saving post URL as meta

            $response_message = 'New topic created successfully, initial date set.';
        } else {
            return new WP_Error('error_creating_topic', $topic_id->get_error_message(), ['status' => 500]);
        }
    }
    update_forum_and_topic_meta($forum_id, $topic_id);
    $topic_url = get_permalink($topic_id);
    if (!$topic_url) {
        // If get_permalink did not work, construct a fallback URL or return an error/message.
        $topic_url = "https://community.extrachill.com";
    }
    
    return new WP_REST_Response([
        'message' => 'Topic processed successfully.',
        'topic_url' => $topic_url, // Ensure this generates the full URL
    ], 200);
    
}

function check_existing_topic($main_site_post_id) {
    return new WP_Query([
        'post_type' => bbp_get_topic_post_type(),
        'meta_query' => [['key' => 'main_site_post_id', 'value' => $main_site_post_id]],
        'posts_per_page' => 1,
    ]);
}

function update_forum_and_topic_meta($forum_id, $topic_id) {
    bbp_update_forum_last_active_id($forum_id, $topic_id);
    bbp_update_forum_last_active_time($forum_id, current_time('mysql'));
    update_post_meta($topic_id, '_bbp_last_active_time', current_time('mysql'));

    // Recalculate forum and topic counts
    bbp_update_forum_reply_count($forum_id);
    bbp_update_forum_topic_count($forum_id);
    bbp_update_topic_reply_count($topic_id);
    bbp_update_topic_voice_count($topic_id);

}

function fetch_upvote_counts_from_extrachill($post_ids) {
    if (empty($post_ids)) {
        return [];
    }

    $adjusted_data = [];
    $uncached_post_ids = [];
    $user_id = get_current_user_id(); // Assuming user-specific data is needed

    // First, check which post IDs need to be fetched from the external API
    foreach ($post_ids as $post_id) {
        $cache_key = 'upvote_counts_' . $post_id;
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            $adjusted_data[$post_id] = $cached_data;
        } else {
            $uncached_post_ids[] = $post_id;
        }
    }

    // If there are uncached post IDs, fetch their upvote counts in a single batch request
    if (!empty($uncached_post_ids)) {
        $post_ids_param = implode(',', $uncached_post_ids);
        $url = "https://extrachill.com/wp-json/extrachill/v1/upvote-counts/?post_ids={$post_ids_param}&community_user_id={$user_id}";

        $response = wp_remote_get($url);
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (is_array($data)) {
                foreach ($uncached_post_ids as $post_id) {
                    if (isset($data[$post_id])) {
                        $info = $data[$post_id];
                        // Store fetched data locally and in cache
                        update_post_meta($post_id, 'local_upvote_count', $info['count'] ?? 0);
                        update_post_meta($post_id, 'local_has_upvoted', $info['has_upvoted'] ?? false);

                        $cache_data = [
                            'count' => $info['count'] ?? 0,
                            'has_upvoted' => $info['has_upvoted'] ?? false,
                        ];

                        set_transient($cache_key, $cache_data, HOUR_IN_SECONDS * 12); // Cache for 12 hours

                        $adjusted_data[$post_id] = $cache_data;
                    }
                }
            }
        }
    }

    return $adjusted_data;
}






function get_main_site_post_ids_for_forum($forum_id = 1494) {
    $cache_key = 'main_site_post_ids_' . $forum_id;
    $cached_post_ids = get_transient($cache_key);

    // Check if cache exists and return it to avoid database query
    if (false !== $cached_post_ids) {
        return $cached_post_ids;
    }

    // If cache doesn't exist, perform the query
    $args = array(
        'post_type' => 'topic',
        'meta_key' => 'main_site_post_id',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => array(
            array(
                'taxonomy' => 'forum',
                'field' => 'term_id',
                'terms' => $forum_id,
            ),
        ),
    );

    $query = new WP_Query($args);
    $post_ids = wp_list_pluck(get_posts($query), 'ID');

    // Cache the result for a very long time (e.g., 10 years)
    set_transient($cache_key, $post_ids, 365 * DAY_IN_SECONDS * 10);

    return $post_ids;
}


function fetch_upvote_counts_and_modify_loop() {
    // Get main site post IDs for topics in forum 1494
    $topic_ids = get_main_site_post_ids_for_forum();
    $main_site_post_ids = array_map(function($id) {
        return get_post_meta($id, 'main_site_post_id', true);
    }, $topic_ids);

    // Fetch upvote counts from external API
    $upvote_counts = fetch_upvote_counts_from_extrachill($main_site_post_ids);

}

function clear_upvote_count_cache() {
    $post_id = isset($_POST['post_id']) ? sanitize_text_field($_POST['post_id']) : null;

    if (!$post_id) {
        wp_send_json_error(['message' => 'Post ID is missing.']);
    }

    // Generate the cache key based on the post ID
    $cache_key = 'upvote_counts_' . $post_id;

    // Clear the cache
    delete_transient($cache_key);

    wp_send_json_success(['message' => 'Cache cleared successfully.']);
}

// Hook the above function to WordPress AJAX actions for logged-in and logged-out users.
add_action('wp_ajax_clear_upvote_cache', 'clear_upvote_count_cache');
add_action('wp_ajax_nopriv_clear_upvote_cache', 'clear_upvote_count_cache');
