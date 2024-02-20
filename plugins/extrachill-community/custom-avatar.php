<?php

add_action('wp_ajax_custom_avatar_upload', 'wp_surgeon_custom_avatar_upload');


function wp_surgeon_custom_avatar_upload() {
    if (!function_exists('wp_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }

    $uploadedfile = $_FILES['custom_avatar'];

    // Check MIME type of the file to ensure it's an image
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    $file_type = wp_check_filetype_and_ext($uploadedfile['tmp_name'], $uploadedfile['name']);

    if (!in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error(array('message' => 'Error: Invalid file type. Only JPG, PNG, GIF, and WebP files are allowed.'));
        return;
    }

    $upload_overrides = array('test_form' => false);
    $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

    if ($movefile && !isset($movefile['error'])) {
        $user_id = get_current_user_id();
        $old_avatar = get_user_meta($user_id, 'custom_avatar', true);

        // Delete the old avatar file if it exists
        if ($old_avatar) {
            $upload_dir = wp_upload_dir();
            $old_avatar_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $old_avatar);
            if (file_exists($old_avatar_path)) {
                unlink($old_avatar_path);
            }
        }

        // Save the new avatar URL
        update_user_meta($user_id, 'custom_avatar', $movefile['url']);

        // Return JSON response with the URL of the new avatar
        wp_send_json_success(array('url' => $movefile['url']));
    } else {
        // Handle errors and return JSON response
        wp_send_json_error(array('message' => isset($movefile['error']) ? $movefile['error'] : 'Unknown error'));
    }
}
add_action('wp_ajax_custom_avatar_upload', 'wp_surgeon_custom_avatar_upload');





function wp_surgeon_custom_avatar($avatar, $id_or_email, $size, $default, $alt) {
    $user = false;

    if (is_numeric($id_or_email)) {
        $id = (int) $id_or_email;
        $user = get_user_by('id', $id);
    } elseif (is_object($id_or_email)) {
        if (!empty($id_or_email->user_id)) {
            $id = (int) $id_or_email->user_id;
            $user = get_user_by('id', $id);
        }
    } else {
        $user = get_user_by('email', $id_or_email);
    }

    if ($user && is_object($user)) {
        $custom_avatar = get_user_meta($user->ID, 'custom_avatar', true);
        if ($custom_avatar) {
            $avatar = "<img src='{$custom_avatar}' alt='{$alt}' class='avatar avatar-{$size} photo' height='{$size}' width='{$size}' />";
        }
    }

    return $avatar;
}

add_filter('get_avatar', 'wp_surgeon_custom_avatar', 10, 5);
