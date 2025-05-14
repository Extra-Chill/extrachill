<?php
// all functions related to syncing extra chill content with the community
// this includes both syncing posts and adding extra chill post counts to corresponding community user pages

function convert_community_user_id_to_author_id($community_user_id) {
    // Mapping between community user IDs (left) and Extra Chill main site IDs (right)
    $id_mapping = [
        1 => 1,
        28 => 38,
        53 => 30,
        50 => 35,
        52 => 34,
        82 => 33,
        51 => 37,
        180 => 32,
        61 => 40,
        // Additional mappings as your team expands
    ];

    return $id_mapping[$community_user_id] ?? null;
}

// Mapping array for main site author IDs to user nicenames
function get_author_nicename_by_id($author_id) {
    $nicename_mapping = [
        1 => 'chubes', // Example: 'admin' is the user_nicename for author ID 1
        38 => 'qrisg',
        30 => 'katebryan',
        35 => 'sgsherbondy',
        34 => 'indigxld',
        33 => 'elliotthay',
        37 => 'meghanveino',
        32 => 'extra-chill-staff',
        40 => 'cluckin-chuck'
        // Add more mappings as needed
    ];

    return $nicename_mapping[$author_id] ?? null;
}

function get_author_slug_by_id($author_id) {
    $user_info = get_userdata($author_id);
    return $user_info ? $user_info->user_nicename : null;
}

function fetch_main_site_post_count_for_user($author_id) {
    // Check if post count is already cached in a transient
    $cached_post_count = get_transient('main_site_post_count_' . $author_id);
    if (false !== $cached_post_count) {
        return $cached_post_count; // Return cached post count if available
    }

    $request_url = "https://extrachill.com/wp-json/extrachill/v1/author-posts-count/{$author_id}";
    $response = wp_remote_get($request_url);

    if (is_wp_error($response)) {
        error_log("fetch_main_site_post_count_for_user: Request failed for author ID $author_id with error: " . $response->get_error_message());
        return 0;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log("fetch_main_site_post_count_for_user: Request failed for author ID $author_id with response code: $response_code");
        return 0;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['post_count'])) {
        $post_count = (int) $data['post_count'];
        // Cache the successful response for 7 days (604800 seconds)
        set_transient('main_site_post_count_' . $author_id, $post_count, 604800);
        return $post_count;
    } else {
        // Cache a value of 0 for 7 days on error to avoid repeated API calls
        set_transient('main_site_post_count_' . $author_id, 0, 604800);
        error_log("fetch_main_site_post_count_for_user: Invalid response format for author ID $author_id: " . $body);
        return 0;
    }
}



function should_sync_article($post_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || wp_is_post_revision($post_id) || 'post' !== $post->post_type || $post->post_status !== 'publish') {
        return false;
    }

    if (function_exists('get_coauthors') && count(get_coauthors($post_id)) > 1) {
        return false;
    }

    // Check if the author is mapped to a community ID
    $author_id = get_post_field('post_author', $post_id);
    if (null === map_author_id_to_community_id($author_id)) {
        return false;
    }

    return true;
}

function map_author_id_to_community_id($author_id) {
    $id_mapping = [
        1 => 1, 
        38 => 28, 
        30 => 53, 
        35 => 50, 
        34 => 52, 
        33 => 82, 
        37 => 51, 
        32 => 180,
        40 => 61,
    ];

    return $id_mapping[$author_id] ?? null;
}

function sync_article_to_community_forum($post_id, $post, $update) {
    if (!should_sync_article($post_id, $post)) {
        return;
    }

    $community_user_id = map_author_id_to_community_id(get_post_field('post_author', $post_id));

    if (null === $community_user_id) {
        // Skip syncing if there's no corresponding community user ID
        return;
    }

    $api_url = 'https://community.extrachill.com/wp-json/extrachill/v1/sync-article';
    $credentials = ['username' => 'chubes', 'app_password' => 'RJkvKGQPWybAorJ2xQxFBtbK'];

    $response = sync_article_to_api($post_id, $community_user_id, $api_url, $credentials);

    if (is_wp_error($response)) {
        error_log('Error syncing article to community forum: ' . $response->get_error_message());
        return;
    }

    handle_sync_response($response, $post_id);
}

function sync_article_to_api($post_id, $community_user_id, $api_url, $credentials) {
    $post_url = get_permalink($post_id);
    $published_date = get_the_date('c', $post_id);

    $payload = json_encode([
        'title' => get_the_title($post_id),
        'content' => apply_filters('the_content', get_post_field('post_content', $post_id)),
        'author_id' => $community_user_id,
        'main_site_post_id' => $post_id,
        'post_url' => $post_url,
        'published_date' => $published_date,
    ]);

    return wp_remote_post($api_url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($credentials['username'] . ':' . $credentials['app_password']),
            'Content-Type' => 'application/json',
        ],
        'body' => $payload,
    ]);
}

function handle_sync_response($response, $post_id) {
    $body = wp_remote_retrieve_body($response);
    error_log('Article sync successful: ' . $body);
    $data = json_decode($body, true);

    if (!empty($data['topic_url'])) {
        $topic_url = $data['topic_url'];
        update_post_meta($post_id, 'extrachill_forum_topic_url', $topic_url);
    }
}

add_action('wp_insert_post', 'sync_article_to_community_forum', 10, 3);
?>
