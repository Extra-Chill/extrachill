<?php

// Meta box for custom forum description
function bbp_add_custom_forum_description_meta_box() {
    add_meta_box(
        'bbp_custom_description',            // ID of the meta box
        'Custom Forum Description',          // Title of the meta box
        'bbp_custom_forum_description_callback', // Callback function
        'forum',                             // Post type 
        'normal',                            // Context
        'high'                               // Priority
    );
}
add_action('add_meta_boxes', 'bbp_add_custom_forum_description_meta_box');

// Meta box callback to display the textarea for description
function bbp_custom_forum_description_callback($post) {
    // Add nonce for security
    wp_nonce_field('bbp_save_custom_description', 'bbp_custom_description_nonce');

    // Retrieve the current description
    $value = get_post_meta($post->ID, '_bbp_custom_description', true);

    // Display the form
    echo '<textarea style="width:100%;" rows="5" name="bbp_custom_description">' . esc_textarea($value) . '</textarea>';
}

// Save the custom forum description
function bbp_save_custom_forum_description($post_id) {
    // Security check for nonce
    if (!isset($_POST['bbp_custom_description_nonce']) || !wp_verify_nonce($_POST['bbp_custom_description_nonce'], 'bbp_save_custom_description')) {
        return;
    }

    // Check for permission to edit the post
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save the custom description
    if (isset($_POST['bbp_custom_description'])) {
        update_post_meta($post_id, '_bbp_custom_description', wp_kses_post($_POST['bbp_custom_description']));
    }
}
add_action('save_post', 'bbp_save_custom_forum_description');

// Function to display the custom description before the forum content
function custom_bbp_single_forum_description() {
    // Get the custom description
    $custom_description = get_post_meta(bbp_get_forum_id(), '_bbp_custom_description', true);

    // Output custom description if available
    if (!empty($custom_description)) {
        echo "<div class='bbp-custom-description'>" . wpautop(wp_kses_post($custom_description)) . "</div>";
    }
}
// Hook the custom description to display before the forum content
add_action('bbp_template_before_single_forum', 'custom_bbp_single_forum_description');
