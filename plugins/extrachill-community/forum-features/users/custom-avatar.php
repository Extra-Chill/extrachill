<?php
/**
 * Custom Avatar Upload System
 * 
 * AJAX-powered avatar upload and management for user profiles.
 * Handles file validation, upload processing, and avatar deletion.
 * 
 * @package Extra ChillCommunity
 */
add_action('wp_ajax_custom_avatar_upload', 'extrachill_custom_avatar_upload');
function extrachill_custom_avatar_upload() {
    // Ensure file-handling functions exist
    if (!function_exists('wp_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }

    // Validate file type
    $uploadedfile = $_FILES['custom_avatar'];
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    $file_type = wp_check_filetype_and_ext($uploadedfile['tmp_name'], $uploadedfile['name']);
    if (!in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error(array('message' => 'Error: Invalid file type. Only JPG, PNG, GIF, and WebP files are allowed.'));
        return;
    }

    // Handle the file upload
    $upload_overrides = array('test_form' => false);
    $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

    if ($movefile && !isset($movefile['error'])) {
        // Create the attachment post
        $attachment = array(
            'guid'           => $movefile['url'],
            'post_mime_type' => $movefile['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($movefile['file'])),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        // Insert attachment in the DB
        $attach_id = wp_insert_attachment($attachment, $movefile['file']);

        // Generate metadata using WordPress defaults (thumbnail, medium, large, etc.)
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Save the attachment ID to user meta
        update_user_meta(get_current_user_id(), 'custom_avatar_id', $attach_id);

        // Return success + full URL
        wp_send_json_success(array('url' => wp_get_attachment_url($attach_id)));
    } else {
        wp_send_json_error(array('message' => isset($movefile['error']) ? $movefile['error'] : 'Unknown error'));
    }
}

/**
 * Override pre_get_avatar to provide custom avatars before WordPress processes Gravatar.
 * Uses proper WordPress hook for custom avatar systems with multisite support.
 */
function extrachill_custom_avatar($avatar, $id_or_email, $args) {
    $user = false;

    // Identify the user
    if (is_numeric($id_or_email)) {
        $user = get_user_by('id', (int) $id_or_email);
    } elseif (is_object($id_or_email) && !empty($id_or_email->user_id)) {
        $user = get_user_by('id', (int) $id_or_email->user_id);
    } elseif (is_object($id_or_email)) {
        $user = $id_or_email; // Potentially user object
    } else {
        $user = get_user_by('email', $id_or_email);
    }

    if ($user && is_object($user)) {
        // Use get_user_option for proper multisite support (checks current site first, then network-wide)
        $custom_avatar_id = get_user_option('custom_avatar_id', $user->ID);

        if ($custom_avatar_id && wp_attachment_is_image($custom_avatar_id)) {
            // Get the WordPress "thumbnail" size as the base URL
            $thumbnail_src = wp_get_attachment_image_url($custom_avatar_id, 'thumbnail');

            if ($thumbnail_src) {
                // Extract size and alt from args array
                $size = isset($args['size']) ? (int) $args['size'] : 96;
                $alt = isset($args['alt']) ? $args['alt'] : '';

                // Build avatar HTML matching WordPress standards
                $avatar_html = sprintf(
                    '<img src="%1$s" alt="%2$s" width="%3$d" height="%3$d" class="avatar avatar-%3$d photo" />',
                    esc_url($thumbnail_src),
                    esc_attr($alt),
                    $size
                );
                return $avatar_html;
            }
        }
    }

    // Return null to let WordPress handle Gravatar fallback
    return null;
}
add_filter('pre_get_avatar', 'extrachill_custom_avatar', 10, 3);

/**
 * (Optional) Generate custom avatar IDs for existing user meta
 */
function generate_custom_avatar_ids() {
    // Query all users who have a custom avatar URL but missing custom_avatar_id in meta
    $users_with_custom_avatars = get_users(array(
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'custom_avatar_id',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => 'custom_avatar',
                'compare' => 'EXISTS',
            ),
        ),
    ));

    foreach ($users_with_custom_avatars as $user) {
        $custom_avatar_url = get_user_meta($user->ID, 'custom_avatar', true);
        $attachment_id = attachment_url_to_postid($custom_avatar_url);

        if ($attachment_id && wp_attachment_is_image($attachment_id)) {
            add_user_meta($user->ID, 'custom_avatar_id', $attachment_id, true);
            echo "User {$user->ID}: Added custom avatar ID.\n";
        } else {
            echo "User {$user->ID}: Failed to add custom avatar ID.\n";
        }
    }

    echo "Custom avatar ID generation completed.\n";
}

add_action('admin_init', 'handle_custom_avatar_id_generation');
function handle_custom_avatar_id_generation() {
    if (isset($_GET['generate_custom_avatar_ids']) && current_user_can('administrator')) {
        generate_custom_avatar_ids();
        exit;
    }
}
