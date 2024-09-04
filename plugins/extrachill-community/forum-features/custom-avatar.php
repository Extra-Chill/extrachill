<?php

add_action('wp_ajax_custom_avatar_upload', 'wp_surgeon_custom_avatar_upload');


function wp_surgeon_custom_avatar_upload() {
    if (!function_exists('wp_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }

    $uploadedfile = $_FILES['custom_avatar'];
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    $file_type = wp_check_filetype_and_ext($uploadedfile['tmp_name'], $uploadedfile['name']);
    if (!in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error(array('message' => 'Error: Invalid file type. Only JPG, PNG, GIF, and WebP files are allowed.'));
        return;
    }

    $upload_overrides = array('test_form' => false);
    $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

    if ($movefile && !isset($movefile['error'])) {
        $attachment = array(
            'guid'           => $movefile['url'],
            'post_mime_type' => $movefile['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($movefile['file'])),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $movefile['file']);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        update_user_meta(get_current_user_id(), 'custom_avatar_id', $attach_id); // Save attachment ID instead of URL

        wp_send_json_success(array('url' => wp_get_attachment_url($attach_id)));
    } else {
        wp_send_json_error(array('message' => isset($movefile['error']) ? $movefile['error'] : 'Unknown error'));
    }
}

function wp_surgeon_custom_avatar($avatar, $id_or_email, $size, $default, $alt) {
    $user = false;

    // Identifying the user
    if (is_numeric($id_or_email)) {
        $user = get_user_by('id', (int)$id_or_email);
    } elseif (is_object($id_or_email) && !empty($id_or_email->user_id)) {
        $user = get_user_by('id', (int)$id_or_email->user_id);
    } elseif (is_object($id_or_email)) {
        $user = $id_or_email;
    } else {
        $user = get_user_by('email', $id_or_email);
    }

    if ($user && is_object($user)) {
        $custom_avatar_id = get_user_meta($user->ID, 'custom_avatar_id', true);
        
        // If a custom avatar ID exists and is valid
        if ($custom_avatar_id && wp_attachment_is_image($custom_avatar_id)) {
            // Get the thumbnail size URL of the custom avatar
            $thumbnail_src = wp_get_attachment_image_url($custom_avatar_id, 'thumbnail');

            if ($thumbnail_src) {
                // Construct the image tag with the thumbnail size URL
                $avatar_html = '<img src="' . esc_url($thumbnail_src) . '" alt="' . esc_attr($alt) . '" class="avatar avatar-' . esc_attr($size) . ' photo">';

                return $avatar_html;
            }
        }
    }

    // Fallback to Gravatar if no custom avatar is set or found
    return $avatar;
}
add_filter('get_avatar', 'wp_surgeon_custom_avatar', 10, 5);


// Define a function to generate and add custom avatar IDs
function generate_custom_avatar_ids() {
    // Query all users who have a custom avatar URL but missing custom_avatar_id in meta
    $users_with_custom_avatars = get_users(array(
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'custom_avatar_id',
                'compare' => 'NOT EXISTS', // Ensure the meta key does not exist
            ),
            array(
                'key'     => 'custom_avatar',
                'compare' => 'EXISTS', // Ensure the meta key exists for custom avatar
            ),
        ),
    ));

    foreach ($users_with_custom_avatars as $user) {
        // Get the user's custom avatar URL
        $custom_avatar_url = get_user_meta($user->ID, 'custom_avatar', true);

        // Get the attachment ID corresponding to the custom avatar URL
        $attachment_id = attachment_url_to_postid($custom_avatar_url);

        // Add the custom_avatar_id meta for the user
        if ($attachment_id && wp_attachment_is_image($attachment_id)) {
            add_user_meta($user->ID, 'custom_avatar_id', $attachment_id, true);
            echo "User {$user->ID}: Added custom avatar ID.\n";
        } else {
            echo "User {$user->ID}: Failed to add custom avatar ID.\n";
        }
    }

    echo "Custom avatar ID generation completed.\n";
}

// Add a custom action to trigger the function via admin URL parameter
add_action('admin_init', 'handle_custom_avatar_id_generation');
function handle_custom_avatar_id_generation() {
    if (isset($_GET['generate_custom_avatar_ids']) && current_user_can('administrator')) {
        generate_custom_avatar_ids();
        exit; // Prevent any further execution after generating custom avatar IDs
    }
}

