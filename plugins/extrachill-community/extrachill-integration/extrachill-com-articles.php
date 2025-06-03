<?php
// all functions related to mapping extra chill user IDs and fetching post counts
// [SYNC LOGIC REMOVED JUNE 2025]
// This file previously contained logic to push posts to the live site via REST API on post save.
// All such logic has been fully removed as of June 2025.
// Only user ID mapping and post count fetching remain. See @todo in project-plan.mdc for future refactor.

/**
 * @todo (see project-plan.mdc)
 * The user ID mapping system here is legacy and should be refactored to use REST API user fetch and admin mapping UI.
 * All article sync/publishing logic has been removed (June 2025).
 * This file now only provides mapping helpers for user identification between extrachill.com and the forum.
 */

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

