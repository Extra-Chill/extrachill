<?php

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
        // Additional mappings as your team expands
    ];

    return $id_mapping[$community_user_id] ?? null;
}

// mapping array for main site author IDs to user nicenames
function get_author_nicename_by_id($author_id) {
    $nicename_mapping = [
        1 => 'chubes', // Example: 'admin' is the user_nicename for author ID 1
        38 => 'qrisg',
        30 => 'katebryan',
        35 => 'sgsherbondy',
        34 => 'indigxld',
        33 => 'elliotthay',
        37 => 'meghanveino',
        // Add more mappings as needed
    ];

    return $nicename_mapping[$author_id] ?? null;
}

function get_author_slug_by_id($author_id) {
    $user_info = get_userdata($author_id);
    return $user_info ? $user_info->user_nicename : null;
}

function fetch_main_site_post_count_for_user($author_id) {
    $request_url = "https://extrachill.com/wp-json/wp/v2/posts?author={$author_id}&per_page=1";
    $response = wp_remote_get($request_url);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return 0;
    }

    $headers = wp_remote_retrieve_headers($response);
    return isset($headers['x-wp-total']) ? (int) $headers['x-wp-total'] : 0;
}

function display_main_site_post_count_on_profile() {
    $community_user_id = bbp_get_displayed_user_id();
    $author_id = convert_community_user_id_to_author_id($community_user_id);

    if ($author_id !== null) {
        $post_count = fetch_main_site_post_count_for_user($author_id);

        // Use the new mapping to get the author slug (user nicename)
        $author_slug = get_author_nicename_by_id($author_id);
        $author_url = "https://extrachill.com/author/{$author_slug}/"; // Adjust URL structure as needed

        if ($post_count > 0) {
            echo "<p>Extra Chill Articles: $post_count <a href='" . esc_url($author_url) . "'>(View All)</a></p>";
        }
    }
}

