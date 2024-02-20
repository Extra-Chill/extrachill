<?php

// gets a user's upvoted posted from extrachill.com

function fetch_extrachill_upvoted_posts() {
    // Get the current logged-in user's ID in the community.extrachill.com WordPress environment
    $community_user_id = get_current_user_id();

    // If there's no logged-in user, return an empty array
    if (!$community_user_id) {
        return [];
    }

    // Construct the API URL, including the current logged-in user's ID
    $api_url = 'https://extrachill.com/wp-json/extrachill/v1/upvotes/' . $community_user_id;

    // Make a request to the REST API endpoint
    $response = wp_remote_get($api_url);

    // Check for a successful response
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
        return []; // Return an empty array in case of error
    }

    // Decode the JSON response into an array
    $posts = json_decode(wp_remote_retrieve_body($response), true);

    // Ensure posts is an array before returning
    return is_array($posts) ? $posts : [];
}



