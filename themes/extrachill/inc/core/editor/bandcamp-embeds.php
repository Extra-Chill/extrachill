<?php
// Custom embed handler for Bandcamp
function custom_bandcamp_embed_handler($matches, $attr, $url, $rawattr) {
    // Fetch the Bandcamp page content
    $response = wp_remote_get($matches[0]);

    if (is_wp_error($response)) {
        return ''; // Return an empty string if there is an error
    }

    $body = wp_remote_retrieve_body($response);

    // Extract the album or track ID from the meta tag
    $pattern = '/<meta name="bc-page-properties" content="({.*?})"/i';
    preg_match($pattern, $body, $meta_matches);

    if (empty($meta_matches)) {
        return ''; // Return an empty string if no meta tag is found
    }

    $page_properties = json_decode(html_entity_decode($meta_matches[1]), true);

    if (empty($page_properties) || !isset($page_properties['item_id']) || !isset($page_properties['item_type'])) {
        return ''; // Return an empty string if the necessary properties are not found
    }

    $type = $page_properties['item_type'] === 'a' ? 'album' : 'track';
    $id = $page_properties['item_id'];

    // Construct the embed URL
    $embed_url = "https://bandcamp.com/EmbeddedPlayer/{$type}={$id}/size=large/bgcol=ffffff/linkcol=0687f5/artwork=small/transparent=true/";

    // Construct the iframe embed code
    $embed_code = sprintf(
        '<iframe style="border: 0; width: 400px; height: 350px;" src="%s" seamless><a href="%s">%s</a></iframe>',
        esc_url($embed_url),
        esc_url($matches[0]),
        esc_html($matches[0])
    );

    return apply_filters('custom_bandcamp_embed', $embed_code, $matches, $attr, $url, $rawattr);
}

// Register the custom embed handler for Bandcamp
function register_custom_bandcamp_embed_handler() {
    wp_embed_register_handler(
        'bandcamp_album',
        '#https?://([a-zA-Z0-9-]+)\.bandcamp\.com/album/([a-zA-Z0-9-]+)#i',
        'custom_bandcamp_embed_handler'
    );
    wp_embed_register_handler(
        'bandcamp_track',
        '#https?://([a-zA-Z0-9-]+)\.bandcamp\.com/track/([a-zA-Z0-9-]+)#i',
        'custom_bandcamp_embed_handler'
    );
}
add_action('init', 'register_custom_bandcamp_embed_handler');
