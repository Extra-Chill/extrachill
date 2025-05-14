<?php
add_action('template_redirect', 'extrachill_direct_db_redirect');
function extrachill_direct_db_redirect() {
    if (!is_404()) {
        return;
    }

    global $wpdb;

    // Extract the slug from the URL.
    $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $request_slug = basename(trim($request_uri, '/'));

    if (!$request_slug) {
        return;
    }

    // Directly query the database for a topic with the given slug and meta.
    $post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID 
         FROM $wpdb->posts p 
         INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id 
         WHERE p.post_type = %s 
           AND p.post_name = %s 
           AND pm.meta_key = %s 
         LIMIT 1",
        'topic', $request_slug, 'extrachill_post_url'
    ));

    if ($post_id) {
        $redirect_url = get_post_meta($post_id, 'extrachill_post_url', true);
        if ($redirect_url && filter_var($redirect_url, FILTER_VALIDATE_URL)) {
            wp_redirect($redirect_url, 301);
            exit;
        }
    }
}

