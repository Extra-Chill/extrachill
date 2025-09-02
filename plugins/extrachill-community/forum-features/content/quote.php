<?php
/**
 * Quote Notification System
 * 
 * Handles notifications when users are quoted in forum posts.
 * Manages AJAX quote processing and user notification delivery.
 * 
 * @package Extra ChillCommunity
 */

add_action('wp_ajax_notify_quoted_user', 'handle_quote_notification');

function handle_quote_notification() {
    // Verify nonce for security
    check_ajax_referer('extrachill_quote_nonce_' . $_POST['post_id'], 'nonce');

    $quoted_user_id = isset($_POST['quoted_user_id']) ? intval($_POST['quoted_user_id']) : 0;
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $current_user_id = get_current_user_id(); // Get the ID of the current user
    
    // Correctly fetch the current user's display name and username (nicename)
    $current_user_display_name = get_the_author_meta('display_name', $current_user_id); // Now correctly defined
    $current_user_username = get_the_author_meta('user_nicename', $current_user_id); // Correctly fetch the user's nicename/slug.
    $current_user_profile_link = bbp_get_user_profile_url( $current_user_id );
    
    $post_permalink = isset($_POST['post_permalink']) ? esc_url_raw($_POST['post_permalink']) : '';
    $topic_name = get_the_title($post_id); // Assumes $post_id is the ID of the reply or topic being quoted.

    // Ensure all necessary data is present
    if ($quoted_user_id && $post_id && $current_user_display_name && $current_user_profile_link && $post_permalink && $topic_name) {
        $notifications = get_user_meta($quoted_user_id, 'extrachill_notifications', true) ?: [];
        
        // Append new notification to the user's array
        $notifications[] = array(
            'type' => 'quote',
            'user_display_name' => $current_user_display_name,
            'topic_title' => $topic_name,
            'time' => current_time('mysql'),
            'read' => false,
            'quote_link' => $post_permalink,
            'user_profile_link' => $current_user_profile_link,
        );

        // Update the user meta with the new notification
        update_user_meta($quoted_user_id, 'extrachill_notifications', $notifications);

        wp_send_json_success('Notification added successfully.');
    } else {
        wp_send_json_error('Failed to add notification. Missing data.');
    }
}


// Add custom "Quote" link to replies and topics
// add_filter('bbp_reply_admin_links', 'extrachill_add_custom_quote_link_to_replies_and_topics', 10, 2);
//add_filter('bbp_topic_admin_links', 'extrachill_add_custom_quote_link_to_replies_and_topics', 10, 2);


function extrachill_add_custom_quote_link_to_replies_and_topics($links, $args) {
    if (is_user_logged_in()) {


        $post_id = get_the_ID();
        $author_name = bbp_get_reply_author_display_name($post_id);
        $post_permalink = bbp_get_reply_url($post_id);
        $nonce = wp_create_nonce('extrachill_quote_nonce_' . $post_id);

        // Assume $post_id refers to the reply or topic being quoted
        $quoted_user_id = bbp_get_reply_author_id($post_id); // Example for bbPress replies

        $quote_link_html = sprintf(
            '<a href="#" class="bbp-quote-link" data-post-id="%d" data-author-name="%s" data-post-permalink="%s" data-nonce="%s" data-quoted-user-id="%d">Quote</a>',
            esc_attr($post_id),
            esc_attr($author_name),
            esc_url($post_permalink),
            esc_attr($nonce),
            esc_attr($quoted_user_id) // Include the quoted user's ID
        );

        $links['quote'] = $quote_link_html;
    }

    return $links;
}



